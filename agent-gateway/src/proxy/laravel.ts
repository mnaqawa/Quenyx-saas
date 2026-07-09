import { config } from '../config'

/**
 * Forward agent requests to Laravel internal agent API.
 * Agents never communicate with Laravel directly — only through QAG.
 */
export async function forwardToLaravel(
  method: string,
  laravelPath: string,
  headers: Record<string, string>,
  body?: string | Buffer | null
): Promise<{ status: number; body: string; contentType: string | null }> {
  const url = `${config.backendBaseUrl}${laravelPath}`

  const response = await fetch(url, {
    method,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Quenyx-Agent-Gateway': '1',
      ...headers,
    },
    body: body ?? undefined,
  })

  const responseBody = await response.text()
  return {
    status: response.status,
    body: responseBody,
    contentType: response.headers.get('content-type'),
  }
}
