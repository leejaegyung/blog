<script setup lang="ts">
import { computed, ref } from 'vue'
import { AxiosError } from 'axios'
import { postApi, type Post, type QualityIssue } from '@/lib/api'
import { validationErrors } from '@/lib/http'

const props = defineProps<{ post: Post }>()
const emit = defineEmits<{ updated: [post: Post]; locate: [issue: QualityIssue] }>()

const checking = ref(false)
const error = ref<string | null>(null)
const report = computed(() => props.post.quality)

const SEVERITY = {
  error: { label: '꼭 고치기', color: 'border-red-200 bg-red-50 text-red-900' },
  warning: { label: '확인 권장', color: 'border-amber-200 bg-amber-50 text-amber-900' },
  info: { label: '참고', color: 'border-stone-200 bg-stone-50 text-stone-700' },
} as const

const groups = computed(() =>
  (['error', 'warning', 'info'] as const)
    .map((severity) => ({
      severity,
      issues: report.value?.issues.filter((issue) => issue.severity === severity) ?? [],
    }))
    .filter((group) => group.issues.length),
)

async function run() {
  checking.value = true
  error.value = null
  try {
    emit('updated', await postApi.qualityCheck(props.post.id))
  } catch (e) {
    const errors = validationErrors(e)
    error.value = errors
      ? (Object.values(errors)[0]?.[0] ?? '검사할 수 없습니다.')
      : e instanceof AxiosError && e.response?.status === 503
        ? '품질 검사 서비스에 연결하지 못했습니다.'
        : '품질 검사를 하지 못했습니다.'
  } finally {
    checking.value = false
  }
}

defineExpose({ run })
</script>

<template>
  <section class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 class="text-lg font-semibold">게시 전 검사</h2>
      <button
        type="button"
        :disabled="checking"
        class="rounded-md border border-stone-300 px-4 py-2 font-medium hover:bg-stone-50 disabled:opacity-50"
        @click="run"
      >
        {{ checking ? '검사 중…' : report ? '다시 검사' : '품질 검사' }}
      </button>
    </div>
    <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
    <p
      v-if="report && post.quality_stale"
      role="status"
      class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900"
    >
      검사한 뒤 본문이나 사실 정보가 바뀌었습니다. 다시 검사해 주세요.
    </p>

    <template v-if="report">
      <div class="flex flex-wrap items-end gap-x-6 gap-y-3">
        <p>
          <span class="text-4xl font-bold tabular-nums">{{ Math.round(report.score) }}</span>
          <span class="text-stone-500"> / 100</span>
        </p>
        <p class="max-w-md text-sm text-stone-500">
          서비스 내부 점수입니다. 네이버 순위나 노출을 보장하지 않습니다.
        </p>
      </div>
      <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
        <div
          v-for="part in report.parts"
          :key="part.key"
          class="grid grid-cols-[minmax(0,9rem)_1fr_3.5rem] items-center gap-2 text-sm"
        >
          <dt class="truncate text-stone-700">{{ part.label }}</dt>
          <dd class="h-2 rounded-full bg-stone-100" aria-hidden="true">
            <div
              class="h-2 rounded-full bg-stone-800"
              :style="{ width: `${Math.round((part.score / part.max) * 100)}%` }"
            />
          </dd>
          <dd class="text-right text-stone-500 tabular-nums">{{ part.score }}/{{ part.max }}</dd>
        </div>
      </dl>

      <p v-if="!groups.length" class="text-sm text-emerald-700">발견된 문제가 없습니다.</p>
      <div v-for="group in groups" :key="group.severity" class="space-y-2">
        <h3 class="font-medium">
          {{ SEVERITY[group.severity].label }} {{ group.issues.length }}
        </h3>
        <ul class="space-y-1.5">
          <li
            v-for="(issue, index) in group.issues"
            :key="index"
            :class="SEVERITY[group.severity].color"
            class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border px-3 py-2 text-sm"
          >
            <span class="min-w-0 flex-1">{{ issue.message }}</span>
            <button
              v-if="issue.excerpt || issue.block_index !== null"
              type="button"
              class="shrink-0 underline"
              @click="emit('locate', issue)"
            >
              위치 보기
            </button>
          </li>
        </ul>
      </div>
    </template>
  </section>
</template>
