export default defineNuxtPlugin(app => {
  app.hook('page:finish', () => {
    document.querySelector<HTMLElement>('#main-content')?.focus({ preventScroll: true })
  })
})
