<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import type { AnalysisProgress } from '@/lib/api'

/** 학습(분석) 진행 표시: 글 읽기 → 차례 기다리기 → AI 정리 → 가이드·해시태그. 서버가 알려 준 단계만 "진행 중"으로 보인다 */
const props = defineProps<{ progress: AnalysisProgress; learning?: boolean }>()

// AI 정리에 보통 걸리는 시간(구독 모델 기준). 막대는 이 시간에 가까워질수록 천천히 찬다(끝을 넘지 않음)
const EXPECTED_SECONDS = 45

const now = ref(Date.now())
let ticker: ReturnType<typeof setInterval> | undefined
onMounted(() => (ticker = setInterval(() => (now.value = Date.now()), 1000)))
onBeforeUnmount(() => clearInterval(ticker))

const elapsed = computed(() =>
  props.progress.started_at ? Math.max(0, Math.round((now.value - Date.parse(props.progress.started_at)) / 1000)) : 0,
)
const refs = computed(() => props.progress.references)
const reading = computed(() => refs.value.pending > 0)

type StepState = 'done' | 'current' | 'todo'
const steps = computed(() => {
  const step = props.progress.step
  const readState: StepState = reading.value ? 'current' : 'done'
  const queueState: StepState = reading.value ? 'todo' : step === 'queued' ? 'current' : 'done'
  const aiState: StepState = step === 'ai' && !reading.value ? 'current' : 'todo'
  return [
    {
      key: 'read',
      label: props.learning ? '학습할 글 읽기' : '참고 글 읽기',
      detail: reading.value ? `${refs.value.parsed}/${refs.value.total}개 읽는 중` : refs.value.total ? `${refs.value.parsed}개 읽음` : '글 없이 키워드로만',
      state: readState,
    },
    { key: 'queue', label: '차례 기다리기', detail: '앞선 작업이 끝나면 바로 시작해요', state: queueState },
    {
      key: 'ai',
      label: props.learning ? 'AI로 글 구성·사진 배치 정리' : 'AI로 검색 의도·목차 정리',
      detail: '구독 Claude·ChatGPT가 정리하고 있어요',
      state: aiState,
    },
    { key: 'guide', label: '노출 가이드·해시태그 만들기', detail: '', state: 'todo' as StepState },
  ]
})

const percent = computed(() => {
  if (reading.value) return 5 + Math.round((refs.value.parsed / Math.max(1, refs.value.total)) * 15)
  if (props.progress.step === 'queued') return 25
  // AI 정리: 25% → 최대 92%까지, 예상 시간에 가까워질수록 느려진다
  return Math.min(92, 25 + Math.round(67 * (1 - Math.exp(-elapsed.value / EXPECTED_SECONDS))))
})
</script>

<template>
  <div class="flex flex-col gap-3 rounded-[18px] border-2 border-ink bg-white p-4" role="status" aria-live="polite">
    <div class="flex items-center justify-between gap-3">
      <span class="text-sm font-bold">{{ learning ? '학습하고 있어요' : '분석하고 있어요' }}</span>
      <span class="text-xs text-sub tabular-nums">{{ elapsed }}초째 · 보통 20~60초</span>
    </div>
    <div class="h-2 overflow-hidden rounded-full bg-track" aria-hidden="true">
      <div class="progress-fill h-full rounded-full bg-ink transition-[width] duration-700" :style="{ width: `${percent}%` }" />
    </div>
    <ol class="m-0 flex list-none flex-col gap-1.5 p-0">
      <li v-for="(s, i) in steps" :key="s.key" class="flex items-center gap-3">
        <span
          :class="
            s.state === 'done'
              ? 'bg-ink text-lemon'
              : s.state === 'current'
                ? 'animate-pulse border-[1.5px] border-ink bg-lemon'
                : 'bg-track text-[#8a7695]'
          "
          class="flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-extrabold"
          aria-hidden="true"
        >
          {{ s.state === 'done' ? '✓' : i + 1 }}
        </span>
        <span class="flex min-w-0 flex-col">
          <span :class="s.state === 'todo' ? 'text-muted' : ''" class="text-sm font-semibold">
            {{ s.label }}<span v-if="s.state === 'current'" class="text-sub"> …</span>
          </span>
          <span v-if="s.state !== 'todo' && s.detail" class="text-xs text-sub">{{ s.detail }}</span>
        </span>
      </li>
    </ol>
    <p class="m-0 text-xs text-sub">화면을 닫아도 계속 진행돼요.</p>
  </div>
</template>

<style scoped>
/* 진행 중임이 보이도록 막대 위로 빛이 지나간다 */
.progress-fill {
  background-image: linear-gradient(90deg, transparent 0%, rgb(250 255 90 / 0.55) 50%, transparent 100%);
  background-size: 200% 100%;
  animation: shimmer 1.6s linear infinite;
}
@keyframes shimmer {
  from {
    background-position: 200% 0;
  }
  to {
    background-position: -200% 0;
  }
}
@media (prefers-reduced-motion: reduce) {
  .progress-fill {
    animation: none;
  }
}
</style>
