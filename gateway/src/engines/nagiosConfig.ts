import { exec, spawn } from 'child_process'
import { promisify } from 'util'
import * as fs from 'fs/promises'
import * as os from 'os'
import * as path from 'path'

const execAsync = promisify(exec)

// Env: NAGIOS_BIN or NAGIOS_BINARY_PATH; default 'nagios' (resolves via PATH in container)
const NAGIOS_BIN_ENV = process.env.NAGIOS_BIN || process.env.NAGIOS_BINARY_PATH || 'nagios'
const NAGIOS_BIN_FALLBACKS = ['/usr/local/bin/nagios', '/opt/nagios/bin/nagios']
const NAGIOS_BIN_CANDIDATES = [
  NAGIOS_BIN_ENV,
  ...NAGIOS_BIN_FALLBACKS.filter((p) => p !== NAGIOS_BIN_ENV),
]

const NAGIOS_CONTAINER_NAME = process.env.NAGIOS_CONTAINER_NAME || 'nagios-core'
const NAGIOS_CFG_PATH = '/opt/nagios/etc/nagios.cfg'

let resolvedNagiosBin: string | null = null

/**
 * Probe container: run <candidate> -v <cfgPath>. Returns true if binary executed (no "no such file").
 */
function tryNagiosBinaryInContainer(
  containerName: string,
  candidate: string,
  cfgPath: string,
  timeoutMs: number = 10000
): Promise<{ works: boolean; stdout: string; stderr: string }> {
  return new Promise((resolve) => {
    const child = spawn('docker', ['exec', containerName, candidate, '-v', cfgPath])
    let stdout = ''
    let stderr = ''
    const timer = setTimeout(() => {
      child.kill('SIGKILL')
      resolve({
        works: false,
        stdout,
        stderr: stderr + '\n(probe timed out)',
      })
    }, timeoutMs)
    child.stdout?.on('data', (chunk: Buffer | string) => {
      stdout += typeof chunk === 'string' ? chunk : chunk.toString()
    })
    child.stderr?.on('data', (chunk: Buffer | string) => {
      stderr += typeof chunk === 'string' ? chunk : chunk.toString()
    })
    child.on('close', () => {
      clearTimeout(timer)
      const combined = (stdout + '\n' + stderr).toLowerCase()
      const noSuchFile =
        combined.includes('no such file or directory') ||
        combined.includes('not found') ||
        /exec\s+failed.*no such file/i.test(combined)
      resolve({ works: !noSuchFile, stdout, stderr })
    })
    child.on('error', (err) => {
      clearTimeout(timer)
      resolve({ works: false, stdout, stderr: stderr || err?.message || 'Spawn error' })
    })
  })
}

/**
 * Resolve which Nagios binary to use inside the container. Tries env candidate then fallbacks.
 * @throws Error with message "Nagios binary not found. Attempted: ..." if none work
 */
export async function resolveNagiosBinaryInContainer(containerName: string, cfgPath: string): Promise<string> {
  for (const candidate of NAGIOS_BIN_CANDIDATES) {
    const { works } = await tryNagiosBinaryInContainer(containerName, candidate, cfgPath)
    if (works) {
      resolvedNagiosBin = candidate
      return candidate
    }
  }
  const attempted = NAGIOS_BIN_CANDIDATES.join(', ')
  throw new Error(`Nagios binary not found. Attempted: ${attempted}`)
}

/**
 * Get resolved Nagios binary path (cached). Resolves on first use if not yet resolved.
 */
async function getOrResolveNagiosBinary(): Promise<string> {
  if (resolvedNagiosBin) return resolvedNagiosBin
  return resolveNagiosBinaryInContainer(NAGIOS_CONTAINER_NAME, NAGIOS_CFG_PATH)
}

/**
 * Return currently resolved Nagios binary path for readiness (or null if not yet resolved).
 */
export function getResolvedNagiosBinaryPath(): string | null {
  return resolvedNagiosBin
}

/**
 * For readiness: resolve Nagios binary and return path or error (does not throw).
 */
export async function getNagiosBinaryForReadiness(): Promise<{ path: string | null; error?: string }> {
  try {
    const p = await getOrResolveNagiosBinary()
    return { path: p }
  } catch (e) {
    return { path: null, error: e instanceof Error ? e.message : String(e) }
  }
}

/**
 * Run nagios -v via spawn and read stdout/stderr streams so we always get full output
 * even when the process exits non-zero (avoids exec callback quirks on some systems).
 */
function runNagiosValidateInContainer(
  containerName: string,
  cfgPath: string,
  nagiosBin: string,
  timeoutMs: number = 30000
): Promise<{ stdout: string; stderr: string; code: number | null }> {
  return new Promise((resolve) => {
    const child = spawn('docker', [
      'exec',
      containerName,
      nagiosBin,
      '-v',
      cfgPath,
    ])
    let stdout = ''
    let stderr = ''
    let settled = false
    const finish = (out: string, err: string, code: number | null) => {
      if (settled) return
      settled = true
      clearTimeout(timer)
      resolve({ stdout: out.trim(), stderr: err.trim(), code })
    }
    const timer = setTimeout(() => {
      child.kill('SIGKILL')
      finish(stdout, stderr + '\n(validation timed out)', -1)
    }, timeoutMs)
    child.stdout?.on('data', (chunk: Buffer | string) => {
      stdout += typeof chunk === 'string' ? chunk : chunk.toString()
    })
    child.stderr?.on('data', (chunk: Buffer | string) => {
      stderr += typeof chunk === 'string' ? chunk : chunk.toString()
    })
    child.on('close', (code, signal) => {
      finish(stdout, stderr, code ?? (signal ? -1 : 0))
    })
    child.on('error', (err) => {
      finish(stdout, stderr || (err?.message ?? 'Spawn error'), -1)
    })
  })
}

// Resolve config dir: use absolute path, or resolve relative to process.cwd()
const rawConfigDir = process.env.NAGIOS_CONFIG_DIR || './nagios/config'
const NAGIOS_CONFIG_DIR = path.isAbsolute(rawConfigDir) ? rawConfigDir : path.resolve(process.cwd(), rawConfigDir)
const NAGIOS_CONTAINER_WORKSPACES_DIR = process.env.NAGIOS_CONTAINER_WORKSPACES_DIR || '/opt/nagios/etc/objects/quenyx/workspaces'

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

const WORKSPACES_CFG_DIR_LINE = 'cfg_dir=/opt/nagios/etc/objects/quenyx/workspaces'

function lineMatchesCfgFile(s: string): boolean {
  return /^\s*cfg_file\s*=\s*\/opt\/nagios\/etc\/objects\/quenyx\/quenyx\.cfg\s*$/.test(s.trim())
}

function lineMatchesCfgDir(s: string): boolean {
  return /^\s*cfg_dir\s*=\s*\/opt\/nagios\/etc\/objects\/quenyx\/workspaces\s*$/.test(s.trim())
}

/**
 * Verify that nagios.cfg includes our published config path (cfg_dir or cfg_file).
 * If not included and Docker is accessible, tries to add cfg_dir and re-verifies.
 */
export async function verifyNagiosCfgIncludesWorkspaces(): Promise<{ ok: boolean; message?: string }> {
  const dockerCheck = await checkDockerAccess()
  if (!dockerCheck.accessible) {
    return {
      ok: false,
      message: (dockerCheck.error ?? 'Docker access denied') + ' Add cfg_dir=/opt/nagios/etc/objects/quenyx/workspaces to nagios.cfg manually.',
    }
  }
  const checkOnce = async (): Promise<{ ok: boolean; message?: string }> => {
    try {
      const catCmd = `docker exec ${NAGIOS_CONTAINER_NAME} cat ${NAGIOS_CFG_PATH}`
      const { stdout } = await execAsync(catCmd, { timeout: 5000 })
      const lines = (stdout || '').split(/\r?\n/)
      const hasCfgDir = lines.some(lineMatchesCfgDir)
      const hasCfgFileWorkspaces = lines.some((l) => {
        const m = l.match(/^\s*cfg_file\s*=\s*(.+)\s*$/)
        return m ? (m[1] ?? '').trim().includes('quenyx/workspaces') : false
      })
      if (hasCfgDir || hasCfgFileWorkspaces) {
        return { ok: true }
      }
      return {
        ok: false,
        message: 'Published config directory is not included in nagios.cfg (missing cfg_dir/cfg_file).',
      }
    } catch (err) {
      const msg = err instanceof Error ? err.message : String(err)
      return { ok: false, message: `Could not read nagios.cfg: ${msg}` }
    }
  }

  const first = await checkOnce()
  if (first.ok) return first

  // Auto-fix: ensure cfg_dir is present, then re-verify
  await ensureNagiosIncludesWorkspacesCfgDir()
  const second = await checkOnce()
  if (second.ok) {
    console.log('[nagios] verify-includes: cfg_dir was missing; auto-added and re-verified OK')
  }
  return second
}

const NAGIOS_OBJECTS_CACHE_PATH = process.env.NAGIOS_OBJECTS_CACHE_PATH || '/opt/nagios/var/objects.cache'

/**
 * After reload: verify objects.cache contains at least one host matching the given prefix (e.g. ws84-).
 * Runs inside container: cat objects.cache and grep for prefix.
 */
export async function checkObjectsCacheForHostPrefix(hostPrefix: string): Promise<{
  contains_new_objects: boolean
  message?: string
}> {
  const dockerCheck = await checkDockerAccess()
  if (!dockerCheck.accessible) {
    return { contains_new_objects: false, message: dockerCheck.error ?? 'Docker access denied' }
  }
  if (!hostPrefix || typeof hostPrefix !== 'string') {
    return { contains_new_objects: false, message: 'Missing or invalid host_prefix' }
  }
  try {
    // Grep in container; avoid injecting prefix into shell (use safe exec)
    const escPath = NAGIOS_OBJECTS_CACHE_PATH.replace(/'/g, "'\\''")
    const escPrefix = hostPrefix.replace(/'/g, "'\\''")
    const script = `cat '${escPath}' 2>/dev/null | grep -F '${escPrefix}' || true`
    const { stdout } = await execAsync(`docker exec ${NAGIOS_CONTAINER_NAME} sh -c ${JSON.stringify(script)}`, {
      timeout: 10000,
    })
    const found = (stdout || '').trim().length > 0
    if (found) {
      console.log(`[nagios] Post-publish check: objects_cache_contains_new_objects=true (prefix ${hostPrefix})`)
      return { contains_new_objects: true }
    }
    console.log(`[nagios] Post-publish check: objects_cache_contains_new_objects=false (prefix ${hostPrefix})`)
    return {
      contains_new_objects: false,
      message:
        'Reload completed but objects.cache does not include newly published objects — config likely not loaded.',
    }
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err)
    return { contains_new_objects: false, message: `Could not check objects.cache: ${msg}` }
  }
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
      '# Quenyx workspace configs (auto-added)',
      WORKSPACES_CFG_DIR_LINE,
    ]
    const outLines = hadCfgDir ? withoutOldOrCfgDir : [...withoutOldOrCfgDir, ...block]
    const out = outLines.join('\n')
    if (out === originalContent) {
      return
    }
    const tmpPath = path.join(os.tmpdir(), `quenyx-nagios-${Date.now()}.cfg`)
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
 * Run validation only (nagios -v <cfgPath>). Does not swap or reload.
 * Uses spawn so we always capture full stdout/stderr regardless of exit code.
 * Resolves Nagios binary (env + fallbacks) on first use; on resolution failure returns clear error.
 */
async function runValidateOnly(): Promise<{ valid: boolean; stdout: string; stderr: string; errors: string[] }> {
  let nagiosBin: string
  try {
    nagiosBin = await getOrResolveNagiosBinary()
  } catch (e) {
    const msg = e instanceof Error ? e.message : String(e)
    return { valid: false, stdout: '', stderr: '', errors: [msg] }
  }
  const { stdout, stderr, code } = await runNagiosValidateInContainer(
    NAGIOS_CONTAINER_NAME,
    NAGIOS_CFG_PATH,
    nagiosBin,
    30000
  )
  const { errors } = parseNagiosValidationOutput(stdout, stderr)
  const output = stdout + '\n' + stderr
  const hasErrors = output.includes('Error:') || output.includes('Error processing config file')
  const hasSuccess = output.includes('Things look okay') || output.includes('Configuration check completed')
  const valid = code === 0 && !hasErrors && !!hasSuccess
  if (!valid && errors.length === 0) {
    // No parsed "Error:" lines; include raw output so user sees actual Nagios/Docker output
    const combined = [stdout, stderr].filter(Boolean).join('\n').trim()
    const snippet = combined.length > 2000 ? combined.slice(-2000) : combined
    errors.push(snippet || `Nagios validation failed (exit code ${code})`)
  }
  return { valid, stdout, stderr, errors }
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

  const NAGIOS_LOCK_PATH = process.env.NAGIOS_LOCK_PATH || '/opt/nagios/var/nagios.lock'

  let lastErr: any
  for (let attempt = 0; attempt <= RELOAD_RETRIES; attempt++) {
    try {
      let nagiosPid: string | null = null
      // Prefer lock file (single PID); avoid multi-line output being interpreted as multiple args
      try {
        const lockResult = await execAsync(
          `docker exec ${NAGIOS_CONTAINER_NAME} cat ${NAGIOS_LOCK_PATH} 2>/dev/null || true`,
          { timeout: 5000 }
        )
        const firstLine = lockResult.stdout.trim().split(/\r?\n/)[0]?.trim()
        if (firstLine && /^\d+$/.test(firstLine)) {
          nagiosPid = firstLine
        }
      } catch {
        // lock file missing or unreadable
      }
      if (!nagiosPid) {
        try {
          const pgrepResult = await execAsync(`docker exec ${NAGIOS_CONTAINER_NAME} pgrep -x nagios`, { timeout: 5000 })
          const firstLine = pgrepResult.stdout.trim().split(/\r?\n/)[0]?.trim()
          if (firstLine && /^\d+$/.test(firstLine)) {
            nagiosPid = firstLine
          }
        } catch {
          // fallback: restart container
        }
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
