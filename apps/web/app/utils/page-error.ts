export function discoveryStatus(error: unknown): number {
  if (!error || typeof error !== 'object') return 503
  const candidate = error as { status?: number; statusCode?: number }
  const status = candidate.statusCode ?? candidate.status
  return status === 404 ? 404 : status === 400 ? 400 : 503
}
