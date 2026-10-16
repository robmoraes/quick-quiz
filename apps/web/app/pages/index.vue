<script setup lang="ts">
import { parsePublicSettings } from '#shared/config'
const settings = parsePublicSettings(useRuntimeConfig().public)
await navigateTo(`/${settings.defaultLocale}/`, { redirectCode: 302 })
</script>
<template><span /></template>
