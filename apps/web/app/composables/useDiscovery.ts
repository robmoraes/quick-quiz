import { parsePublicSettings } from '#shared/config'
import { createDiscoveryClient } from '../utils/discovery-api'

export function useDiscovery() {
  const config = useRuntimeConfig()
  const { locale } = useWebLocale()
  const publicSettings = parsePublicSettings(config.public)
  return createDiscoveryClient(import.meta.server ? config.apiBaseUrl : publicSettings.apiBaseUrl, locale.value, fetch, Number(config.apiTimeoutMs) || 5000)
}
