import { exec } from 'child_process'
import { promisify } from 'util'
import * as fs from 'fs/promises'
import * as os from 'os'
import * as path from 'path'

const execAsync = promisify(exec)

// Resolve config dir: use absolute path, or resolve relative to process.cwd()
const rawConfigDir = process.env.NAGIOS_CONFIG_DIR || './nagios/config'
const NAGIOS_CONFIG_DIR = path.isAbsolute(rawConfigDir) ? rawConfigDir : path.resolve(process.cwd(), rawConfigDir)
const NAGIOS_CONTAINER_NAME = process.env.NAGIOS_CONTAINER_NAME || 'nagios-core'
const NAGIOS_CONTAINER_WORKSPACES_DIR = process.env.NAGIOS_CONTAINER_WORKSPACES_DIR || '/opt/nagios/etc/objects/portshield/workspaces'

// Reload verification: timeout and retry (TPM hardening gate C)
const RELOAD_TIMEOUT_MS = parseInt(process.env.NAGIOS_RELOAD_TIMEOUT_MS || '15000', 10)
const RELOAD_RETRIES = parseInt(process.env.NAGIOS_RELOAD_RETRIES || '2', 10)
const RELOAD_VERIFY_SLEEP_MS = parseInt(process.env.NAGIOS_RELOAD_VERIFY_SLEEP_MS || '2000', 10)

/**
 * Check if Docker socket is accessible (exported for readiness).
 */
export async function checkDockerAccess(): Promise<{ accessible: boolean; error?: string }> {
  try {
    const testCmd = `docker ps --format "{{.Names}}" --filter "name=${NAGIOS_CONTAINER_NAME}"`
    await execAsync(testCmd, { timeout: 5000 })
    return { accessible: true }
  } catch (err: any) {
    const errorMsg = err.message || String(err)
    if (errorMsg.includes('permission denied') || errorMsg.includes('docker.sock')) {
      return {
        accessible: false,
        error: 'Docker socket permission denied. Gateway service user needs access to Docker.',
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
 * Ensure nagios.cfg loads workspace configs via cfg_dir. Idempotent.
 * After updating nagios.cfg, does NOT reload here (reload is separate in publish flow).
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
    console.log('[nagios] Updated nagios.cfg: ensured cfg_dir=.../workspaces')
  } catch (err) {
    console.warn('Could not ensure nagios.cfg cfg_dir for workspaces:', err instanceof Error ? err.message : String(err))
  }
}

/**
 * Run validation only (nagios -v). Does not swap or reload.
 */
async function runValidateOnly(): Promise<{ valid: boolean; stdout: string; stderr: string; errors: string[] }> {
  const validateCmd = `docker exec ${NAGIOS_CONTAINER_NAME} /usr/local/nagios/bin/nagios -v ${NAGIOS_CFG_PATH}`
  try {
    const result = await execAsync(validateCmd, { timeout: 30000 })
    const stdout = (result.stdout ?? '').trim()
    const stderr = (result.stderr ?? '').trim()
    const { errors } = parseNagiosValidationOutput(stdout, stderr)
    const output = stdout + '\n' + stderr
    const hasErrors = output.includes('Error:') || output.includes('Error processing config file')
    const hasSuccess = output.includes('Things look okay') || output.includes('Configuration check completed')
    const valid = !hasErrors && !!hasSuccess
    return { valid, stdout, stderr, errors }
  } catch (err: any) {
    const stdout = (err.stdout ?? '').trim()
    const stderr = (err.stderr ?? '').trim()
    const { errors } = parseNagiosValidationOutput(stdout, stderr)
    if (errors.length === 0) errors.push(err.message || 'Validation command failed')
    return { valid: false, stdout, stderr, errors }
  }
}

/**
 * In container: restore all *.cfg.bak to *.cfg (rollback on validation/reload failure).
 */
async function rollbackAllBackups(): Promise<void> {
  const esc = NAGIOS_CONTAINER_WORKSPACES_DIR.replace(/'/g, "'\\''")
  const script = `cd '${esc}' && for f in *.cfg.bak; do [ -f "$f" ] || continue; base="${'$'}{f%.cfg.bak}"; [ -f "$base.cfg" ] && mv "$base.cfg" "$base.cfg.fail" 2>/dev/null; mv "$f" "$base.cfg"; done`
  await execAsync(`docker exec ${NAGIOS_CONTAINER_NAME} sh -c ${JSON.stringify(script)}`, { timeout: 10000 })
  console.log('[nagios] Rolled back all .cfg.bak to .cfg')
}

/**
 * In container: remove all *.cfg.bak after successful reload.
 */
async function removeAllBackups(): Promise<void> {
  const esc = NAGIOS_CONTAINER_WORKSPACES_DIR.replace(/'/g, "'\\''")
  await execAsync(`docker exec ${NAGIOS_CONTAINER_NAME} sh -c "cd '${esc}' && rm -f *.cfg.bak"`, { timeout: 5000 })
}

/**
 * Atomic publish (TPM gates A, B, D): Write to temp path, swap, validate; on failure rollback. Do not reload.
 */
export async function writeNagiosConfig(workspaceId: number, config: string): Promise<{
  written_path: string
  validated: boolean
  rolled_back?: boolean
  validation_errors?: string[]
}> {
  const configDir = path.resolve(NAGIOS_CONFIG_DIR, 'workspaces')
  const tempPath = path.resolve(configDir, `${workspaceId}.cfg.new`)
  const finalPath = path.resolve(configDir, `${workspaceId}.cfg`)

  await fs.mkdir(configDir, { recursive: true })
  await ensureNagiosIncludesWorkspacesCfgDir()

  // A) Write to temp path (deterministic, idempotent content)
  await fs.writeFile(tempPath, config, 'utf-8')

  const dockerCheck = await checkDockerAccess()
  if (!dockerCheck.accessible) {
    await fs.rename(tempPath, finalPath).catch(() => {})
    return {
      written_path: finalPath,
      validated: false,
      validation_errors: [dockerCheck.error || 'Docker access denied'],
    }
  }

  const escDir = NAGIOS_CONTAINER_WORKSPACES_DIR.replace(/'/g, "'\\''")
  const swapScript = `cd '${escDir}' && cp ${workspaceId}.cfg ${workspaceId}.cfg.bak 2>/dev/null; mv ${workspaceId}.cfg.new ${workspaceId}.cfg`

  try {
    await execAsync(`docker exec ${NAGIOS_CONTAINER_NAME} sh -c ${JSON.stringify(swapScript)}`, { timeout: 5000 })
  } catch (swapErr: any) {
    await fs.unlink(tempPath).catch(() => {})
    return {
      written_path: finalPath,
      validated: false,
      validation_errors: ['Swap failed: ' + (swapErr.message || 'unknown')],
    }
  }

  // B) Validate before considering success; do not reload
  const { valid, stdout, stderr, errors } = await runValidateOnly()
  if (!valid) {
    const rollbackScript = `cd '${escDir}' && mv ${workspaceId}.cfg ${workspaceId}.cfg.new 2>/dev/null; mv ${workspaceId}.cfg.bak ${workspaceId}.cfg 2>/dev/null`
    await execAsync(`docker exec ${NAGIOS_CONTAINER_NAME} sh -c ${JSON.stringify(rollbackScript)}`, { timeout: 5000 }).catch(() => {})
    return {
      written_path: finalPath,
      validated: true,
      rolled_back: true,
      validation_errors: errors.length > 0 ? errors : [stdout.slice(0, 500), stderr.slice(0, 500)].filter(Boolean),
    }
  }

  console.log(`[nagios] Atomic write+validate OK for workspace ${workspaceId}; .bak left for reload step`)
  return { written_path: finalPath, validated: true }
}

/**
 * Reload Nagios (TPM gate C): Verify reload with timeout and retry; on failure rollback (gate D).
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

  const dockerCheck = await checkDockerAccess()
  if (!dockerCheck.accessible) {
    return {
      success: false,
      message: (dockerCheck.error || 'Docker access denied') + ' Reload skipped.',
      validated: false,
      reloaded: false,
      reload_skipped: true,
      stdout: '',
      stderr: dockerCheck.error || '',
    }
  }

  const { valid, stdout: vOut, stderr: vErr } = await runValidateOnly()
  validated = true
  validationStdout = vOut
  validationStderr = vErr
  if (!valid) {
    return {
      success: false,
      message: 'Nagios config validation failed; will not reload',
      validated: true,
      reloaded: false,
      stdout: validationStdout.substring(0, 1000),
      stderr: validationStderr.substring(0, 1000),
    }
  }

  let lastErr: any
  for (let attempt = 0; attempt <= RELOAD_RETRIES; attempt++) {
    try {
      let nagiosPid: string | null = null
      try {
        const pgrepResult = await execAsync(`docker exec ${NAGIOS_CONTAINER_NAME} pgrep -x nagios`, { timeout: 5000 })
        nagiosPid = pgrepResult.stdout.trim()
      } catch {
        nagiosPid = '1'
      }

      if (nagiosPid) {
        await execAsync(`docker exec ${NAGIOS_CONTAINER_NAME} kill -HUP ${nagiosPid}`, { timeout: RELOAD_TIMEOUT_MS })
      } else {
        await execAsync(`docker restart ${NAGIOS_CONTAINER_NAME}`, { timeout: Math.max(RELOAD_TIMEOUT_MS, 25000) })
      }

      await new Promise((r) => setTimeout(r, RELOAD_VERIFY_SLEEP_MS))
      const verify = await runValidateOnly()
      if (verify.valid) {
        await removeAllBackups()
        return {
          success: true,
          message: 'Nagios reloaded and verified',
          validated: true,
          reloaded: true,
          method: nagiosPid ? 'hup' : 'restart',
          stdout: validationStdout.substring(0, 500),
          stderr: validationStderr.substring(0, 500),
        }
      }
      lastErr = new Error('Reload verification failed: config invalid after reload')
    } catch (err: any) {
      lastErr = err
      if (attempt < RELOAD_RETRIES) {
        await new Promise((r) => setTimeout(r, 1000))
      }
    }
  }

  await rollbackAllBackups()
  const verifyAfterRollback = await runValidateOnly()
  return {
    success: false,
    message: (lastErr?.message || 'Reload or verification failed') + '; rolled back to last known good.',
    validated: true,
    reloaded: false,
    method: undefined,
    stdout: validationStdout.substring(0, 500),
    stderr: (lastErr?.stderr || lastErr?.message || '') + (verifyAfterRollback.valid ? '; rollback OK.' : '; rollback may be incomplete.'),
  }
}

const MAX_VALIDATION_ERRORS = 20
const MAX_VALIDATION_WARNINGS = 20
const ERROR_LINE_REG = /^\s*Error:\s*(.+)$/im
const WARNING_LINE_REG = /^\s*Warning:\s*(.+)$/im

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
 * Check if gateway can write to config dir (for readiness).
 */
export async function canWriteConfigDir(): Promise<{ ok: boolean; error?: string }> {
  const configDir = path.resolve(NAGIOS_CONFIG_DIR, 'workspaces')
  try {
    await fs.mkdir(configDir, { recursive: true })
    const testFile = path.join(configDir, `.write-test-${Date.now()}`)
    await fs.writeFile(testFile, 'ok', 'utf-8')
    await fs.unlink(testFile)
    return { ok: true }
  } catch (e) {
    return { ok: false, error: e instanceof Error ? e.message : String(e) }
  }
}

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
      message: dockerCheck.error ?? 'Docker access denied',
      errors: [dockerCheck.error ?? 'Docker access denied'],
      warnings: [],
    }
  }

  const { valid, stdout, stderr, errors } = await runValidateOnly()
  const { warnings } = parseNagiosValidationOutput(stdout, stderr)
  return {
    success: true,
    valid,
    message: valid ? undefined : 'Nagios config invalid',
    errors,
    warnings: warnings.slice(0, MAX_VALIDATION_WARNINGS),
  }
}
