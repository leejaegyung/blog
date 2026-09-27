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
  draft: 'bg-track',
  analyzing: 'bg-lilac-soft',
  analyzed: 'bg-lilac',
  failed: 'border border-ink bg-lemon',
}
</script>

<template>
  <span :class="colors[status]" class="shrink-0 rounded-md px-[7px] py-[3px] text-[11px] font-extrabold whitespace-nowrap">
    {{ labels[status] }}
  </span>
</template>
