import { parseWebSettings } from '#shared/config'

export default defineNitroPlugin(() => {
  parseWebSettings(useRuntimeConfig())
})
