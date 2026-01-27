import { exec } from 'child_process'
import { promisify } from 'util'
import * as fs from 'fs/promises'
import * as path from 'path'

const execAsync = promisify(exec)

// Resolve config dir: use absolute path, or resolve relative to process.cwd() so systemd/any CWD works when project root is set
const rawConfigDir = process.env.NAGIOS_CONFIG_DIR || './nagios/config'
const NAGIOS_CONFIG_DIR = path.isAbsolute(rawConfigDir) ? rawConfigDir : path.resolve(process.cwd(), rawConfigDir)
const NAGIOS_CONTAINER_NAME = process.env.NAGIOS_CONTAINER_NAME || 'nagios-core'

/**
 * Check if Docker socket is accessible
 */
async function checkDockerAccess(): Promise<{ accessible: boolean; error?: string }> {
  try {
    // Try a simple docker command to check access
    const testCmd = `docker ps --format "{{.Names}}" --filter "name=${NAGIOS_CONTAINER_NAME}"`
    await execAsync(testCmd, { timeout: 5000 })
    return { accessible: true }
  } catch (err: any) {
    const errorMsg = err.message || String(err)
    if (errorMsg.includes('permission denied') || errorMsg.includes('docker.sock')) {
      return {
        accessible: false,
        error: 'Docker socket permission denied. Gateway service user needs access to Docker. Action required: Add gateway user to docker group (sudo usermod -aG docker <user>) or run gateway as a user with Docker access.',
      }
    }
    return {
      accessible: false,
      error: `Docker access check failed: ${errorMsg}`,
    }
  }
}

/**
 * Ensure Nagios base config includes portshield.cfg
 */
async function ensureNagiosIncludesPortshield(): Promise<void> {
  const nagiosCfgPath = '/opt/nagios/etc/nagios.cfg'
  const portshieldInclude = 'cfg_file=/opt/nagios/etc/objects/portshield/portshield.cfg'
  
  // Check Docker access first
  const dockerCheck = await checkDockerAccess()
  if (!dockerCheck.accessible) {
    console.warn('Docker access denied, skipping nagios.cfg update:', dockerCheck.error)
    return
  }
  
  try {
    // Check if nagios.cfg already includes portshield.cfg
    const checkCmd = `docker exec ${NAGIOS_CONTAINER_NAME} grep -q "${portshieldInclude}" ${nagiosCfgPath} || echo "NOT_FOUND"`
    const checkResult = await execAsync(checkCmd, { timeout: 5000 })
    
    if (checkResult.stdout.trim() === 'NOT_FOUND') {
      // Append include line to nagios.cfg
      const appendCmd = `docker exec ${NAGIOS_CONTAINER_NAME} sh -c "echo '' >> ${nagiosCfgPath} && echo '# PortShield workspace configs (auto-added)' >> ${nagiosCfgPath} && echo '${portshieldInclude}' >> ${nagiosCfgPath}"`
      await execAsync(appendCmd, { timeout: 5000 })
      console.log('Added portshield.cfg include to nagios.cfg')
    }
  } catch (err) {
    // If container not running or command fails, log but don't throw
    // This allows config writing to continue even if Nagios isn't running yet
    console.warn('Could not ensure nagios.cfg includes portshield.cfg:', err instanceof Error ? err.message : String(err))
  }
}

/**
 * Write Nagios config file for a workspace.
 * Returns the absolute path written so callers can verify location.
 */
export async function writeNagiosConfig(workspaceId: number, config: string): Promise<{ written_path: string }> {
  const configDir = path.resolve(NAGIOS_CONFIG_DIR, 'workspaces')
  const writtenPath = path.resolve(configDir, `${workspaceId}.cfg`)

  await fs.mkdir(configDir, { recursive: true })
  await fs.writeFile(writtenPath, config, 'utf-8')

  const portshieldCfgPath = path.resolve(NAGIOS_CONFIG_DIR, 'portshield.cfg')
  try {
    await fs.access(portshieldCfgPath)
  } catch {
    const portshieldCfg = `# PortShield workspace configurations
# This file is auto-generated - DO NOT EDIT MANUALLY
# Workspace configs are loaded from the workspaces/ subdirectory

# Include all workspace configs
cfg_dir=/opt/nagios/etc/objects/portshield/workspaces
`
    await fs.writeFile(portshieldCfgPath, portshieldCfg, 'utf-8')
  }

  await ensureNagiosIncludesPortshield()

  console.log(`[nagios] Wrote config for workspace ${workspaceId} to resolved path: ${writtenPath} (NAGIOS_CONFIG_DIR=${NAGIOS_CONFIG_DIR})`)
  return { written_path: writtenPath }
}

/**
 * Reload Nagios configuration
 */
export async function reloadNagios(): Promise<{
  success: boolean
  message: string
  validated: boolean
  reloaded: boolean
  reload_skipped?: boolean
  method?: string
  stdout: string
  stderr: string
}> {
  let validated = false
  let validationStdout = ''
  let validationStderr = ''

  // Preflight check: Docker socket access
  const dockerCheck = await checkDockerAccess()
  if (!dockerCheck.accessible) {
    return {
      success: false,
      message: (dockerCheck.error || 'Docker access denied') + ' Config may have been written; reload was skipped.',
      validated: false,
      reloaded: false,
      reload_skipped: true,
      stdout: '',
      stderr: dockerCheck.error || 'Docker socket permission denied',
    }
  }

  try {
    // First, validate config
    const validateCmd = `docker exec ${NAGIOS_CONTAINER_NAME} /usr/local/nagios/bin/nagios -v /opt/nagios/etc/nagios.cfg`
    try {
      const validateResult = await execAsync(validateCmd, { timeout: 30000 })
      validated = true
      validationStdout = (validateResult.stdout || '').trim()
      validationStderr = (validateResult.stderr || '').trim()
      
      // Nagios validation outputs to stdout, check for success indicators
      const output = validationStdout + '\n' + validationStderr
      const hasErrors = output.includes('Error:') || output.includes('Error processing config file')
      const hasSuccess = output.includes('Things look okay') || 
                         output.includes('Configuration check completed') ||
                         output.includes('Total Warnings: 0')
      
      if (hasErrors || !hasSuccess) {
        return {
          success: false,
          message: 'Nagios config validation failed',
          validated: true,
          reloaded: false,
          stdout: validationStdout.substring(0, 1000),
          stderr: validationStderr.substring(0, 1000),
        }
      }
    } catch (validateErr: any) {
      // Validation command failed (non-zero exit code means validation failed)
      validated = false
      validationStdout = validateErr.stdout?.substring(0, 1000) || ''
      validationStderr = validateErr.stderr?.substring(0, 1000) || validateErr.message || 'Validation command failed'
      
      return {
        success: false,
        message: 'Nagios config validation failed',
        validated: false,
        reloaded: false,
        stdout: validationStdout,
        stderr: validationStderr,
      }
    }
    
    // If validation passed, try to reload Nagios
    // First, try to find nagios process
    let nagiosPid: string | null = null
    try {
      const pgrepCmd = `docker exec ${NAGIOS_CONTAINER_NAME} pgrep -x nagios`
      const pgrepResult = await execAsync(pgrepCmd, { timeout: 5000 })
      nagiosPid = pgrepResult.stdout.trim()
    } catch {
      // pgrep failed, try PID 1
      nagiosPid = '1'
    }
    
    // Try HUP signal
    if (nagiosPid) {
      try {
        const reloadCmd = `docker exec ${NAGIOS_CONTAINER_NAME} kill -HUP ${nagiosPid}`
        await execAsync(reloadCmd, { timeout: 5000 })
        return {
          success: true,
          message: 'Nagios reloaded successfully via HUP signal',
          validated: true,
          reloaded: true,
          method: 'hup',
          stdout: validationStdout.substring(0, 500),
          stderr: validationStderr.substring(0, 500),
        }
      } catch (hupErr: any) {
        // HUP failed, try container restart
        try {
          const restartCmd = `docker restart ${NAGIOS_CONTAINER_NAME}`
          await execAsync(restartCmd, { timeout: 30000 })
          return {
            success: true,
            message: 'Nagios reloaded via container restart',
            validated: true,
            reloaded: true,
            method: 'restart',
            stdout: validationStdout.substring(0, 500),
            stderr: validationStderr.substring(0, 500),
          }
        } catch (restartErr: any) {
          return {
            success: false,
            message: 'Failed to reload Nagios (both HUP and restart failed)',
            validated: true,
            reloaded: false,
            method: 'none',
            stdout: validationStdout.substring(0, 500),
            stderr: (hupErr.message || '') + '; ' + (restartErr.message || ''),
          }
        }
      }
    } else {
      // No PID found, try restart
      try {
        const restartCmd = `docker restart ${NAGIOS_CONTAINER_NAME}`
        await execAsync(restartCmd, { timeout: 30000 })
        return {
          success: true,
          message: 'Nagios reloaded via container restart (no PID found)',
          validated: true,
          reloaded: true,
          method: 'restart',
          stdout: validationStdout.substring(0, 500),
          stderr: validationStderr.substring(0, 500),
        }
      } catch (restartErr: any) {
        return {
          success: false,
          message: 'Failed to reload Nagios (no PID found and restart failed)',
          validated: true,
          reloaded: false,
          method: 'none',
          stdout: validationStdout.substring(0, 500),
          stderr: restartErr.message || 'Restart failed',
        }
      }
    }
  } catch (err: any) {
    return {
      success: false,
      message: err.message || 'Failed to reload Nagios',
      validated,
      reloaded: false,
      stdout: validationStdout.substring(0, 500),
      stderr: err.stderr?.substring(0, 1000) || err.message || 'Unknown error',
    }
  }
}
