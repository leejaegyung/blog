<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { adminApi, type FailedJob, type Usage } from '@/lib/api'
import LlmSettingsPanel from '@/components/LlmSettingsPanel.vue'

const PURPOSES: Record<string, string> = {
  keyword_analysis: '키워드 분석',
  plan: '글 계획',
  draft: '초안',
  rewrite: '문단 다시 쓰기',
  vision: '사진 분석',
  ping: '연결 확인',
}
const JOBS: Record<string, string> = {
  'App\\Jobs\\ParseReferenceJob': '참고자료 분석',
  'App\\Jobs\\AnalyzeKeywordJob': '키워드 분석',
  'App\\Jobs\\GeneratePlanJob': '글 계획',
  'App\\Jobs\\GenerateDraftJob': '초안',
  'App\\Jobs\\AnalyzeImagesJob': '사진 분석',
}

const days = ref(30)
const usage = ref<Usage | null>(null)
const failed = ref<FailedJob[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const message = ref<string | null>(null)

const num = (n: number) => Math.round(n).toLocaleString('ko-KR')
const maxDaily = computed(() => Math.max(1, ...(usage.value?.daily.map((d) => d.calls) ?? [])))

async function load() {
  loading.value = true
  error.value = null
  try {
    ;[usage.value, failed.value] = await Promise.all([adminApi.usage(days.value), adminApi.failedJobs()])
  } catch {
    error.value = '관리 정보를 불러오지 못했습니다.'
  } finally {
    loading.value = false
  }
}

async function retry(job: FailedJob) {
  await adminApi.retry(job.uuid)
  message.value = `${JOBS[job.job] ?? job.job} 작업을 다시 실행하도록 넣었습니다.`
  failed.value = failed.value.filter((j) => j.uuid !== job.uuid)
}

async function forget(job: FailedJob) {
  await adminApi.forget(job.uuid)
  failed.value = failed.value.filter((j) => j.uuid !== job.uuid)
}

watch(days, load)
onMounted(load)
</script>

<template>
  <section class="space-y-8">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-2xl font-semibold">관리</h1>
      <label class="flex items-center gap-2 text-sm">
        기간
        <select v-model.number="days" class="rounded-md border border-stone-300 px-2 py-1">
          <option :value="7">최근 7일</option>
          <option :value="30">최근 30일</option>
          <option :value="90">최근 90일</option>
        </select>
      </label>
    </div>

    <LlmSettingsPanel />

    <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>
    <p v-else-if="loading && !usage" class="text-stone-500">불러오는 중…</p>

    <template v-if="usage">
      <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-xl border border-stone-200 bg-white p-4">
          <dt class="text-sm text-stone-500">글 / 초안 / 게시</dt>
          <dd class="text-xl font-semibold tabular-nums">
            {{ usage.kpi.posts }} / {{ usage.kpi.drafted }} / {{ usage.kpi.published }}
          </dd>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-4">
          <dt class="text-sm text-stone-500">AI 호출</dt>
          <dd class="text-xl font-semibold tabular-nums">{{ num(usage.kpi.llm_calls) }}회</dd>
          <dd class="text-xs text-stone-500">실패 {{ Math.round(usage.kpi.llm_failure_rate * 100) }}%</dd>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-4">
          <dt class="text-sm text-stone-500">토큰</dt>
          <dd class="text-xl font-semibold tabular-nums">{{ num(usage.kpi.tokens) }}</dd>
          <dd class="text-xs text-stone-500">금액은 공급자 콘솔에서 확인</dd>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-4">
          <dt class="text-sm text-stone-500">초안 평균 생성 시간</dt>
          <dd class="text-xl font-semibold tabular-nums">
            {{ usage.kpi.avg_draft_latency_ms ? `${(usage.kpi.avg_draft_latency_ms / 1000).toFixed(1)}초` : '—' }}
          </dd>
        </div>
      </dl>

      <div class="space-y-2">
        <h2 class="text-lg font-semibold">용도·모델별 AI 호출</h2>
        <p v-if="!usage.by_group.length" class="text-stone-500">이 기간에 AI 호출이 없습니다.</p>
        <div v-else class="overflow-x-auto rounded-xl border border-stone-200 bg-white">
          <table class="w-full min-w-[40rem] text-sm">
            <thead class="border-b border-stone-200 text-left text-stone-500">
              <tr>
                <th class="px-4 py-2 font-medium">용도</th>
                <th class="px-4 py-2 font-medium">모델</th>
                <th class="px-4 py-2 text-right font-medium">호출</th>
                <th class="px-4 py-2 text-right font-medium">성공</th>
                <th class="px-4 py-2 text-right font-medium">입력 토큰</th>
                <th class="px-4 py-2 text-right font-medium">출력 토큰</th>
                <th class="px-4 py-2 text-right font-medium">평균 시간</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-stone-100 tabular-nums">
              <tr v-for="group in usage.by_group" :key="`${group.purpose}-${group.provider}-${group.model}`">
                <td class="px-4 py-2">{{ PURPOSES[group.purpose] ?? group.purpose }}</td>
                <td class="px-4 py-2">{{ group.provider }} · {{ group.model }}</td>
                <td class="px-4 py-2 text-right">{{ num(group.calls) }}</td>
                <td class="px-4 py-2 text-right">{{ num(group.success) }}</td>
                <td class="px-4 py-2 text-right">{{ num(group.input_tokens) }}</td>
                <td class="px-4 py-2 text-right">{{ num(group.output_tokens) }}</td>
                <td class="px-4 py-2 text-right">
                  {{ group.avg_latency_ms ? `${(group.avg_latency_ms / 1000).toFixed(1)}초` : '—' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="usage.daily.length" class="space-y-2">
        <h2 class="text-lg font-semibold">일별 호출</h2>
        <ul class="space-y-1 text-sm">
          <li
            v-for="day in usage.daily"
            :key="day.day"
            class="grid grid-cols-[6rem_1fr_6rem] items-center gap-2"
          >
            <span class="text-stone-500 tabular-nums">{{ day.day }}</span>
            <div class="flex h-2 overflow-hidden rounded-full bg-stone-100" aria-hidden="true">
              <div class="bg-stone-800" :style="{ width: `${((day.calls - day.failed) / maxDaily) * 100}%` }" />
              <div class="bg-red-400" :style="{ width: `${(day.failed / maxDaily) * 100}%` }" />
            </div>
            <span class="text-right tabular-nums">{{ day.calls }}회 · 실패 {{ day.failed }}</span>
          </li>
        </ul>
      </div>
    </template>

    <div class="space-y-2">
      <h2 class="text-lg font-semibold">실패한 작업</h2>
      <p v-if="message" role="status" class="text-sm text-stone-600">{{ message }}</p>
      <p v-if="!failed.length" class="text-stone-500">실패한 작업이 없습니다.</p>
      <ul v-else class="divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
        <li v-for="job in failed" :key="job.uuid" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-sm">
          <span class="font-medium">{{ JOBS[job.job] ?? job.job }}</span>
          <span class="text-stone-500 tabular-nums">{{ new Date(job.failed_at).toLocaleString('ko-KR') }}</span>
          <span class="w-full truncate text-stone-600" :title="job.error">{{ job.error }}</span>
          <div class="ml-auto flex gap-1">
            <button type="button" class="rounded px-2 py-1 hover:bg-stone-100" @click="retry(job)">다시 실행</button>
            <button type="button" class="rounded px-2 py-1 text-red-600 hover:bg-red-50" @click="forget(job)">지우기</button>
          </div>
        </li>
      </ul>
    </div>
  </section>
</template>
