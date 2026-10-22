export interface DiscoveryDifficulty { id: number; questionCount: number }
export interface ThemeIdentity { id: string; name: string; description: string; weight: number; tags: string[] }
export interface DiscoveryTheme extends ThemeIdentity {
  topicCount: number
  playableTopicCount: number
  questionCount: number
  difficulties: DiscoveryDifficulty[]
  playable: boolean
}
export interface DiscoveryTopic {
  theme: ThemeIdentity
  id: string
  label: string
  description: string
  weight: number
  tags: string[]
  questionCount: number
  difficulties: DiscoveryDifficulty[]
  playable: boolean
}
export interface DiscoveryTag { slug: string; themeCount: number; topicCount: number; questionCount: number }
export interface DiscoveryPagination { page: number; pageSize: number; total: number; totalPages: number }
export interface DiscoveryEnvelope { locale: string; fallbackLocale: string }
export interface ThemePage extends DiscoveryEnvelope { themes: DiscoveryTheme[]; pagination: DiscoveryPagination }
export interface TopicPage extends DiscoveryEnvelope { topics: DiscoveryTopic[]; pagination: DiscoveryPagination }
export interface TagPage extends DiscoveryEnvelope { scope: 'theme' | 'topic'; tags: DiscoveryTag[]; pagination: DiscoveryPagination }
export interface ThemeDetail extends DiscoveryEnvelope { theme: DiscoveryTheme }
export interface TopicDetail extends DiscoveryEnvelope { topic: DiscoveryTopic }
export interface DiscoveryQuery {
  q?: string
  theme?: string
  themeTag?: string
  topicTag?: string
  difficulty?: number
  page?: number
  pageSize?: number
}
