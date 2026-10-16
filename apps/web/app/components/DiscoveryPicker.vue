<script setup lang="ts">
import type { DiscoveryPagination, DiscoveryQuery } from '../types/discovery'
const props = defineProps<{ kind: 'theme' | 'themeTag' | 'topicTag'; label: string; query?: DiscoveryQuery; omitSearchContext?: boolean }>()
const model = defineModel<string>({ default: '' })
const { t, labelTag, number } = useWebLocale()
const id = useId()
const api = useDiscovery()
const open = ref(false)
const busy = ref(false)
const failed = ref(false)
const search = ref('')
const selectedLabel = ref('')
const options = ref<{ id: string; label: string; count: number }[]>([])
const pagination = ref<DiscoveryPagination>({ page: 1, pageSize: 24, total: 0, totalPages: 0 })
let sequence = 0
let debounce: ReturnType<typeof setTimeout> | undefined
async function load(page = 1) {
  const request = ++sequence
  busy.value = true
  failed.value = false
  try {
    if (props.kind === 'theme') {
      const result = await api.themes({ q: search.value.trim() || undefined, page, pageSize: 24 })
      if (request !== sequence) return
      options.value = result.themes.map(theme => ({ id: theme.id, label: theme.name, count: theme.topicCount }))
      pagination.value = result.pagination
    } else {
      const result = await api.tags(props.kind === 'themeTag' ? 'theme' : 'topic', { ...props.query, page, pageSize: 24 })
      if (request !== sequence) return
      options.value = result.tags.map(tag => ({ id: tag.slug, label: labelTag(tag.slug), count: props.kind === 'themeTag' ? tag.themeCount : tag.topicCount }))
      pagination.value = result.pagination
    }
  } catch { if (request === sequence) failed.value = true }
  finally { if (request === sequence) busy.value = false }
}
function toggle(event: Event) {
  open.value = (event.currentTarget as HTMLDetailsElement).open
  if (open.value) void load()
  else sequence++
}
function choose(value: string, label = '') {
  model.value = value
  selectedLabel.value = label
  const element = document.getElementById(id) as HTMLDetailsElement | null
  if (element) element.open = false
}
function searchThemes() {
  clearTimeout(debounce)
  debounce = setTimeout(() => { void load() }, 250)
}
watch(() => props.query, () => { if (open.value) void load() }, { deep: true })
watch(model, () => { if (!model.value) selectedLabel.value = '' })
onBeforeUnmount(() => { sequence++; clearTimeout(debounce) })
</script>
<template><div class="discovery-picker"><label :for="`${id}-summary`">{{ label }}</label><input type="hidden" :name="kind" :value="model"><details :id="id" @toggle="toggle"><summary :id="`${id}-summary`" :aria-label="label">{{ model ? selectedLabel || (kind === 'theme' ? model : labelTag(model)) : t('any') }}<UIcon name="i-lucide-chevron-down" /></summary><div class="picker-menu"><p v-if="omitSearchContext">{{ t('filterOptionsWithoutSearch') }}</p><template v-if="kind === 'theme'"><label :for="`${id}-search`" class="sr-only">{{ t('search') }} · {{ t('themes') }}</label><input :id="`${id}-search`" v-model="search" type="search" :placeholder="t('searchPlaceholder')" maxlength="200" @input="searchThemes"></template><button type="button" class="picker-option" @click="choose('')">{{ t('any') }}</button><p v-if="busy" role="status">{{ t('loading') }}</p><div v-else-if="failed" role="alert"><p>{{ t('failedDescription') }}</p><button type="button" class="text-link" @click="load()">{{ t('retry') }}</button></div><template v-else><button v-for="option in options" :key="option.id" type="button" class="picker-option" :aria-pressed="model === option.id" @click="choose(option.id, option.label)"><span>{{ option.label }}</span><small v-if="!omitSearchContext">{{ number(option.count) }}</small></button><p v-if="!options.length" role="status">{{ t('noTags') }}</p><div v-if="pagination.totalPages > 1" class="picker-pagination"><button type="button" :disabled="pagination.page <= 1" :aria-label="t('previous')" @click="load(pagination.page - 1)"><UIcon name="i-lucide-arrow-left" /></button><span>{{ pagination.page }} / {{ pagination.totalPages }}</span><button type="button" :disabled="pagination.page >= Math.min(pagination.totalPages, 1000)" :aria-label="t('next')" @click="load(pagination.page + 1)"><UIcon name="i-lucide-arrow-right" /></button></div></template></div></details></div></template>

<style scoped>
.discovery-picker{grid-column:1/-1;min-width:0}.discovery-picker details{position:relative}.discovery-picker summary{display:flex;align-items:center;justify-content:space-between;gap:8px;list-style:none;cursor:pointer;min-height:44px;padding:10px 12px;background:#fff;border:1px solid #ccd5cb;border-radius:10px;font-size:11px;overflow-wrap:anywhere}.discovery-picker summary::-webkit-details-marker{display:none}.picker-menu{position:absolute;top:calc(100% + 5px);left:0;right:0;z-index:15;border:1px solid #cddac4;border-radius:10px;padding:8px;background:#fff;box-shadow:0 12px 30px #17351b1a;max-height:370px;overflow:auto}.picker-menu p{font-size:11px;padding:10px;color:var(--muted)}.picker-menu input{font-size:11px;margin-bottom:6px}.picker-option{display:flex;justify-content:space-between;align-items:center;gap:10px;width:100%;text-align:left;font-size:11px;padding:10px 8px;min-height:40px;border-radius:6px;overflow-wrap:anywhere}.picker-option:hover,.picker-option[aria-pressed=true]{background:#edf2e7}.picker-option small{color:var(--muted)}.picker-pagination{display:flex;align-items:center;justify-content:space-between;border-top:1px solid var(--line);padding:8px 0 0;font-size:10px}.picker-pagination button{width:36px;height:36px;display:grid;place-items:center;background:#eff3ea;border-radius:6px}
</style>
