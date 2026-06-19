/** Normalize GET/PUT observe targets API payloads into a host array. */
export function parseTargetsResponse(
  response: unknown,
): unknown[] {
  if (Array.isArray(response)) return response
  if (response != null && typeof response === 'object') {
    const obj = response as { data?: unknown; targets?: unknown }
    if (Array.isArray(obj.targets)) return obj.targets
    if (Array.isArray(obj.data)) return obj.data
  }
  return []
}
