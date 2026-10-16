import type { RouterConfig } from '@nuxt/schema'

export default <RouterConfig>{
  scrollBehavior(to, from, savedPosition) {
    if (savedPosition) return savedPosition
    if (to.path !== from.path || to.fullPath !== from.fullPath) return { top: 0, left: 0 }
    return false
  },
}
