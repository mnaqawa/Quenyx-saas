import { exec } from 'child_process'
import { promisify } from 'util'
import * as fs from 'fs/promises'
import * as os from 'os'
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

const NAGIOS_CFG_PATH = '/opt/nagios/etc/nagios.cfg'
const WORKSPACES_CFG_DIR_LINE = 'cfg_dir=/opt/nagios/etc/objects/portshield/workspaces'

function lineMatchesCfgFile(s: string): boolean {
  return /^\s*cfg_file\s*=\s*\/opt\/nagios\/etc\/objects\/portshield\/portshield\.cfg\s*$/.test(s.trim())
}

function lineMatchesCfgDir(s: string): boolean {
  return /^\s*cfg_dir\s*=\s*\/opt\/nagios\/etc\/objects\/portshield\/workspaces\s*$/.test(s.trim())
}

/**
 * Ensure nagios.cfg loads workspace configs via cfg_dir (main directive), not via cfg_file to portshield.cfg.
 * cfg_dir is valid only in the main nagios.cfg; cfg_dir inside an included object file causes
 * "Unexpected token or statement" and Nagios loads only localhost.
 * - Removes any cfg_file=.../portshield.cfg line (legacy, invalid pattern).
 * - Ensures exactly one cfg_dir=/opt/nagios/etc/objects/portshield/workspaces in nagios.cfg.
 * Idempotent, no duplicates. After updating nagios.cfg, reloads Nagios (HUP or restart).
 */
async function ensureNagiosIncludesWorkspacesCfgDir(): Promise<void> {
  const dockerCheck = await checkDockerAccess()
  if (!dockerCheck.accessible) {
    console.warn('Docker access denied, skipping nagios.cfg update:', dockerCheck.error)
    return
  }

  try {
    const catCmd = `docker exec ${NAGIOS_CONTAINER_NAME} cat ${NAGIOS_CFG_PATH}`
    const { stdout } = await execAsync(catCmd, { timeout: 5000 })
    const lines = (stdout || '').split(/\r?\n/)
    const originalContent = lines.join('\n')
    const withoutOldOrCfgDir = lines.filter((l) => !lineMatchesCfgFile(l) && !lineMatchesCfgDir(l))
    const hadCfgDir = lines.some(lineMatchesCfgDir)
    const block = [
      '',
      '# PortShield workspace configs (auto-added)',
      WORKSPACES_CFG_DIR_LINE,
    ]
    const outLines = hadCfgDir ? withoutOldOrCfgDir : [...withoutOldOrCfgDir, ...block]
    const out = outLines.join('\n')
    if (out === originalContent) {
      return
    }
    const tmpPath = path.join(os.tmpdir(), `portshield-nagios-${Date.now()}.cfg`)
    await fs.writeFile(tmpPath, out, 'utf-8')
    try {
      await execAsync(`docker cp "${tmpPath.replace(/"/g, '\\"')}" ${NAGIOS_CONTAINER_NAME}:/tmp/nagios_ps.cfg`, { timeout: 5000 })
      await execAsync(`docker exec ${NAGIOS_CONTAINER_NAME} mv /tmp/nagios_ps.cfg ${NAGIOS_CFG_PATH}`, { timeout: 5000 })
    } finally {
      await fs.unlink(tmpPath).catch(() => {})
    }
    console.log('[nagios] Updated nagios.cfg: removed legacy cfg_file=.../portshield.cfg if present; ensured cfg_dir=.../workspaces')
    const reloadResult = await reloadNagios()
    if (!reloadResult.reloaded && !reloadResult.reload_skipped) {
      console.warn('[nagios] nagios.cfg was updated but reload failed:', reloadResult.message)
    }
  } catch (err) {
    console.warn('Could not ensure nagios.cfg cfg_dir for workspaces:', err instanceof Error ? err.message : String(err))
  }
}

/**
 * Write Nagios config file for a workspace.
 * Workspace configs are loaded by Nagios via cfg_dir in the main nagios.cfg (see ensureNagiosIncludesWorkspacesCfgDir).
 * We do not use or create portshield.cfg (cfg_dir is valid only in nagios.cfg, not inside an included object file).
 * Returns the absolute path written so callers can verify location.
 */
export async function writeNagiosConfig(workspaceId: number, config: string): Promise<{ written_path: string }> {
  const configDir = path.resolve(NAGIOS_CONFIG_DIR, 'workspaces')
  const writtenPath = path.resolve(configDir, `${workspaceId}.cfg`)

  await fs.mkdir(configDir, { recursive: true })
  await fs.writeFile(writtenPath, config, 'utf-8')

  await ensureNagiosIncludesWorkspacesCfgDir()

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

const MAX_VALIDATION_ERRORS = 20
const MAX_VALIDATION_WARNINGS = 20
const ERROR_LINE_REG = /^\s*Error:\s*(.+)$/im
const WARNING_LINE_REG = /^\s*Warning:\s*(.+)$/im

/**
 * Parse Nagios -v stdout/stderr into trimmed error and warning lines (max 20 each).
 */
function parseNagiosValidationOutput(stdout: string, stderr: string): { errors: string[]; warnings: string[] } {
  const combined = (stdout + '\n' + stderr).split(/\r?\n/)
  const errors: string[] = []
  const warnings: string[] = []
  for (const line of combined) {
    const errMatch = line.match(ERROR_LINE_REG)
    if (errMatch) {
      const trimmed = (errMatch[1] ?? line).trim()
      if (trimmed && errors.length < MAX_VALIDATION_ERRORS) errors.push(trimmed)
      continue
    }
    const warnMatch = line.match(WARNING_LINE_REG)
    if (warnMatch) {
      const trimmed = (warnMatch[1] ?? line).trim()
      if (trimmed && warnings.length < MAX_VALIDATION_WARNINGS) warnings.push(trimmed)
    }
  }
  return { errors, warnings }
}

/**
 * Run nagios -v and return parsed errors/warnings (trimmed, first 20 each).
 * Used so the publish flow can surface exact reasons (unknown check_command, duplicate object, etc.).
 */
export async function validateNagiosConfig(): Promise<{
  success: boolean
  valid: boolean
  message?: string
  errors: string[]
  warnings: string[]
}> {
  const dockerCheck = await checkDockerAccess()
  if (!dockerCheck.accessible) {
    return {
      success: false,
      valid: false,
      message: dockerCheck.error ?? 'Docker access denied; cannot run nagios -v',
      errors: [dockerCheck.error ?? 'Docker access denied; cannot run nagios -v'],
      warnings: [],
    }
  }

  const validateCmd = `docker exec ${NAGIOS_CONTAINER_NAME} /usr/local/nagios/bin/nagios -v /opt/nagios/etc/nagios.cfg`
  try {
    const result = await execAsync(validateCmd, { timeout: 30000 })
    const stdout = (result.stdout ?? '').trim()
    const stderr = (result.stderr ?? '').trim()
    const { errors, warnings } = parseNagiosValidationOutput(stdout, stderr)
    const output = stdout + '\n' + stderr
    const hasErrors = output.includes('Error:') || output.includes('Error processing config file')
    const hasSuccess =
      (output.includes('Things look okay') || output.includes('Configuration check completed')) &&
      !hasErrors
    const valid = !hasErrors && (hasSuccess || (errors.length === 0 && output.includes('Total Warnings:')))

    return {
      success: true,
      valid,
      message: valid ? undefined : 'Nagios config invalid',
      errors,
      warnings,
    }
  } catch (err: any) {
    const stdout = (err.stdout ?? '').trim()
    const stderr = (err.stderr ?? '').trim()
    const { errors, warnings } = parseNagiosValidationOutput(stdout, stderr)
    const fallback = err.message || 'Validation command failed'
    if (errors.length === 0) errors.push(fallback)
    return {
      success: false,
      valid: false,
      message: 'Nagios config invalid',
      errors: errors.slice(0, MAX_VALIDATION_ERRORS),
      warnings: warnings.slice(0, MAX_VALIDATION_WARNINGS),
    }
  }
}
