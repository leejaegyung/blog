<script setup lang="ts">
import { computed } from 'vue'
import type { PipelineStep, Post } from '@/lib/api'
import { friendlyError } from '@/lib/errors'

const props = defineProps<{ post: Post; steps?: PipelineStep[]; retryLabel?: string }>()
defineEmits<{ retry: [] }>()

const LABELS: Record<PipelineStep, string> = {
  vision: '사진 살펴보기',
  analysis: '키워드 분석',
  plan: '목차·사진 배치 계획',
  draft: '본문 쓰기',
  quality: '게시 전 검사',
}
const list = computed(() => (props.steps ?? (Object.keys(LABELS) as PipelineStep[])).map((key) => ({ key, label: LABELS[key] })))

const currentIndex = computed(() => {
  if (props.post.pipeline_status === 'done') return list.value.length
  const index = list.value.findIndex((s) => s.key === props.post.pipeline_step)
  return index < 0 ? 0 : index
})
const failed = computed(() => props.post.pipeline_status === 'failed')
</script>

<template>
  <div class="flex flex-col gap-4">
    <ol class="flex flex-col gap-2">
      <li
        v-for="(step, index) in list"
        :key="step.key"
        class="flex items-center gap-3 rounded-2xl border-[1.5px] border-line bg-white px-4 py-3"
      >
        <span
          :class="
            index < currentIndex
              ? 'bg-ink text-lemon'
              : index === currentIndex && failed
                ? 'bg-red-600 text-white'
                : index === currentIndex
                  ? 'animate-pulse border-[1.5px] border-ink bg-lemon'
                  : 'bg-track text-[#8a7695]'
          "
          class="flex size-7 shrink-0 items-center justify-center rounded-full text-[13px] font-extrabold"
          aria-hidden="true"
        >
          {{ index < currentIndex ? '✓' : index === currentIndex && failed ? '!' : index + 1 }}
        </span>
        <span :class="index <= currentIndex ? '' : 'text-muted'" class="text-[15px] font-semibold">
          {{ step.label }}<span v-if="index === currentIndex && !failed" class="text-sub"> …</span>
        </span>
      </li>
    </ol>
    <p v-if="!failed" role="status" class="text-sm text-sub">보통 1~3분 걸려요. 화면을 닫아도 계속 진행돼요.</p>
    <div v-else role="alert" class="flex flex-col items-start gap-3 rounded-[14px] border-2 border-ink bg-lemon p-4 text-sm">
      <span class="font-semibold">{{ friendlyError(post.pipeline_error ?? post.plan_error ?? post.draft_error) }}</span>
      <button type="button" class="rounded-[10px] bg-ink px-3 py-2 font-bold text-cream" @click="$emit('retry')">
        {{ retryLabel ?? '다시 시도' }}
      </button>
    </div>
  </div>
</template>
