<script setup lang="ts">
import type { ProjectStatus } from '@/lib/api'

const props = defineProps<{ status: ProjectStatus; learning?: boolean }>()

// learning: 학습 카테고리(관리 › 카테고리별 학습)에서는 "학습"으로 부른다
const learningLabels: Record<ProjectStatus, string> = {
  draft: '학습 전',
  analyzing: '학습 중',
  analyzed: '학습 완료',
  failed: '학습 실패',
}

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
    {{ (props.learning ? learningLabels : labels)[status] }}
  </span>
</template>
