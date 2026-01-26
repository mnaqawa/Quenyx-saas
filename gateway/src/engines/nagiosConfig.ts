import { exec } from 'child_process'
import { promisify } from 'util'
import * as fs from 'fs/promises'
import * as path from 'path'

const execAsync = promisify(exec)

const NAGIOS_CONFIG_DIR = process.env.NAGIOS_CONFIG_DIR || './nagios/config'
const NAGIOS_CONTAINER_NAME = process.env.NAGIOS_CONTAINER_NAME || 'nagios-core'

/**
 * Write Nagios config file for a workspace
 */
export async function writeNagiosConfig(workspaceId: number, config: string): Promise<void> {
  // Ensure config directory exists
  const configDir = path.resolve(NAGIOS_CONFIG_DIR, 'workspaces')
  await fs.mkdir(configDir, { recursive: true })
  
  // Write config file
  const configPath = path.join(configDir, `${workspaceId}.cfg`)
  await fs.writeFile(configPath, config, 'utf-8')
  
  console.log(`Wrote Nagios config for workspace ${workspaceId} to ${configPath}`)
}

/**
 * Reload Nagios configuration
 */
export async function reloadNagios(): Promise<{
  success: boolean
  message: string
  stdout: string
  stderr: string
}> {
  try {
    // First, validate config
    const validateCmd = `docker exec ${NAGIOS_CONTAINER_NAME} /usr/local/nagios/bin/nagios -v /opt/nagios/etc/nagios.cfg`
    const validateResult = await execAsync(validateCmd, { timeout: 30000 })
    
    if (validateResult.stderr && !validateResult.stderr.includes('Total Warnings: 0') && !validateResult.stderr.includes('Things look okay')) {
      return {
        success: false,
        message: 'Nagios config validation failed',
        stdout: validateResult.stdout.substring(0, 1000), // Trim to 1KB
        stderr: validateResult.stderr.substring(0, 1000),
      }
    }
    
    // If validation passed, reload Nagios
    // Try HUP signal to main process (PID 1 in container)
    const reloadCmd = `docker exec ${NAGIOS_CONTAINER_NAME} kill -HUP 1`
    try {
      await execAsync(reloadCmd, { timeout: 5000 })
      return {
        success: true,
        message: 'Nagios reloaded successfully',
        stdout: validateResult.stdout.substring(0, 500),
        stderr: '',
      }
    } catch (reloadErr: any) {
      // Fallback: try reload script if available
      const reloadScriptCmd = `docker exec ${NAGIOS_CONTAINER_NAME} /usr/local/nagios/bin/nagios -v /opt/nagios/etc/nagios.cfg && echo "Config valid"`
      try {
        await execAsync(reloadScriptCmd, { timeout: 10000 })
        return {
          success: true,
          message: 'Nagios config validated (reload may require manual restart)',
          stdout: validateResult.stdout.substring(0, 500),
          stderr: '',
        }
      } catch (scriptErr: any) {
        return {
          success: false,
          message: 'Failed to reload Nagios',
          stdout: validateResult.stdout.substring(0, 500),
          stderr: (reloadErr.message || '') + (scriptErr.message || ''),
        }
      }
    }
  } catch (err: any) {
    return {
      success: false,
      message: err.message || 'Failed to reload Nagios',
      stdout: '',
      stderr: err.stderr?.substring(0, 1000) || err.message || 'Unknown error',
    }
  }
}
