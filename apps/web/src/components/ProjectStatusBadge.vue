<script setup lang="ts">
import type { ProjectStatus } from '@/lib/api'

defineProps<{ status: ProjectStatus }>()

const labels: Record<ProjectStatus, string> = {
  draft: '분석 전',
  analyzing: '분석 중',
  analyzed: '분석 완료',
  failed: '분석 실패',
}

const colors: Record<ProjectStatus, string> = {
  draft: 'bg-stone-100 text-stone-600',
  analyzing: 'bg-amber-100 text-amber-800',
  analyzed: 'bg-emerald-100 text-emerald-800',
  failed: 'bg-red-100 text-red-700',
}
</script>

<template>
  <span :class="colors[status]" class="rounded-full px-2 py-0.5 text-xs font-medium">
    {{ labels[status] }}
  </span>
</template>
