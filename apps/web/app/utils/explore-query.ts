import type { DiscoveryQuery } from '../types/discovery'

export interface ExploreQuery extends DiscoveryQuery { kind: 'themes' | 'topics' }
export function readExploreQuery(query: Record<string, unknown>): ExploreQuery {
  const text = (key: string) => {
    const value = query[key]
    if (Array.isArray(value) || (value !== undefined && typeof value !== 'string')) throw new Error(`Invalid ${key}`)
    return typeof value === 'string' ? value.trim() || undefined : undefined
  }
  const kind = text('kind') ?? 'themes'
  if (kind !== 'themes' && kind !== 'topics') throw new Error('Invalid result kind')
  const page = Number(text('page') ?? 1)
  const difficultyText = text('difficulty')
  const difficulty = difficultyText ? Number(difficultyText) : undefined
  if (!Number.isInteger(page) || page < 1 || page > 1000 || (difficulty !== undefined && ![1, 2, 3, 4].includes(difficulty))) throw new Error('Invalid page or difficulty')
  const theme = text('theme')
  return { kind: theme ? 'topics' : kind, q: text('q'), theme, themeTag: text('themeTag'), topicTag: text('topicTag'), difficulty, page, pageSize: 24 }
}

export function discoveryQuery(query: ExploreQuery): DiscoveryQuery {
  const { kind, ...filters } = query
  if (kind === 'themes') delete filters.theme
  return filters
}
