import { describe, expect, it } from 'vitest'
import { discoveryQuery, readExploreQuery } from './explore-query'

describe('discovery route state', () => {
  it('normalizes empty form fields and switches exact themes to Topics', () => {
    expect(readExploreQuery({ kind: 'themes', theme: 'kubernetes', q: ' ', difficulty: '', themeTag: 'dev' })).toMatchObject({ kind: 'topics', theme: 'kubernetes', q: undefined, difficulty: undefined, themeTag: 'dev', page: 1 })
  })
  it('never forwards Web-only route state to strict API operations', () => {
    expect(discoveryQuery(readExploreQuery({ kind: 'themes', themeTag: 'dev', page: '2' }))).toEqual({ q: undefined, themeTag: 'dev', topicTag: undefined, difficulty: undefined, page: 2, pageSize: 24 })
  })
  it.each([{ kind: 'bank' }, { page: '1001' }, { page: '1.5' }, { difficulty: '5' }, { topicTag: ['a', 'b'] }])('rejects ambiguous route query %j', query => {
    expect(() => readExploreQuery(query)).toThrow()
  })
})
