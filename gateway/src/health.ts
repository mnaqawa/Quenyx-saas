import { checkDockerAccess, canWriteConfigDir } from './engines/nagiosConfig'
import { canReachStatusjson } from './engines/nagios'

export interface ReadinessResult {
  ready: boolean
  checks: {
    nagios_reachable: { ok: boolean; error?: string }
    config_dir_writable: { ok: boolean; error?: string }
    statusjson_cgi: { ok: boolean; error?: string }
  }
}

/**
 * Run readiness checks: can reach Nagios (Docker), can write config dir, can read statusjson.cgi.
 */
export async function runReadinessChecks(): Promise<ReadinessResult> {
  const [docker, configDir, statusjson] = await Promise.all([
    checkDockerAccess(),
    canWriteConfigDir(),
    canReachStatusjson(),
  ])
  const nagios_reachable = { ok: docker.accessible, error: docker.error }
  const config_dir_writable = { ok: configDir.ok, error: configDir.error }
  const statusjson_cgi = { ok: statusjson.ok, error: statusjson.error }
  const ready = nagios_reachable.ok && config_dir_writable.ok && statusjson_cgi.ok
  return {
    ready,
    checks: { nagios_reachable, config_dir_writable, statusjson_cgi },
  }
}
