<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useUiStore } from '@/stores/ui'
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

const ui = useUiStore()
ui.crumb = '관리'
ui.saveState = null

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
  <div class="grid lg:min-h-[calc(100dvh-64px)] lg:grid-cols-[minmax(0,1fr)_320px]">
    <section class="flex min-w-0 flex-col gap-6 px-5 pt-6 pb-10 lg:px-12 lg:pt-10">
      <div class="flex flex-col gap-2">
        <span class="text-[13px] font-bold text-accent">관리</span>
        <h1 class="m-0 font-display text-[32px] leading-[1.1] font-normal lg:text-[40px]">AI 연결과 사용량</h1>
        <p class="m-0 text-[15px] text-body lg:text-base">키와 시도 순서를 바꾸면 바로 적용돼요. 금액은 공급자 콘솔에서 확인해요.</p>
      </div>

      <LlmSettingsPanel />

      <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>
      <p v-else-if="loading && !usage" class="text-sub">불러오는 중…</p>

      <template v-if="usage">
        <div class="flex flex-col gap-2.5">
          <span class="text-sm font-bold">용도·모델별 AI 호출</span>
          <p v-if="!usage.by_group.length" class="rounded-[18px] border-[1.5px] border-line bg-white p-5 text-sub">이 기간에 AI 호출이 없어요.</p>
          <div v-else class="overflow-x-auto rounded-[18px] border-[1.5px] border-line bg-white">
            <table class="w-full min-w-[40rem] text-sm">
              <thead class="border-b-[1.5px] border-line text-left text-[13px] text-sub">
                <tr>
                  <th class="px-4 py-3 font-bold">용도</th>
                  <th class="px-4 py-3 font-bold">모델</th>
                  <th class="px-4 py-3 text-right font-bold">호출</th>
                  <th class="px-4 py-3 text-right font-bold">성공</th>
                  <th class="px-4 py-3 text-right font-bold">입력 토큰</th>
                  <th class="px-4 py-3 text-right font-bold">출력 토큰</th>
                  <th class="px-4 py-3 text-right font-bold">평균 시간</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-line tabular-nums">
                <tr v-for="group in usage.by_group" :key="`${group.purpose}-${group.provider}-${group.model}`">
                  <td class="px-4 py-3 font-bold">{{ PURPOSES[group.purpose] ?? group.purpose }}</td>
                  <td class="px-4 py-3">{{ group.provider }} · {{ group.model }}</td>
                  <td class="px-4 py-3 text-right">{{ num(group.calls) }}</td>
                  <td class="px-4 py-3 text-right">{{ num(group.success) }}</td>
                  <td class="px-4 py-3 text-right">{{ num(group.input_tokens) }}</td>
                  <td class="px-4 py-3 text-right">{{ num(group.output_tokens) }}</td>
                  <td class="px-4 py-3 text-right">{{ group.avg_latency_ms ? `${(group.avg_latency_ms / 1000).toFixed(1)}초` : '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="usage.daily.length" class="flex flex-col gap-2.5">
          <span class="text-sm font-bold">일별 호출</span>
          <ul class="flex flex-col gap-2 rounded-[18px] border-[1.5px] border-line bg-white p-4 text-[13px]">
            <li v-for="day in usage.daily" :key="day.day" class="grid grid-cols-[6rem_1fr_7rem] items-center gap-3">
              <span class="text-sub tabular-nums">{{ day.day }}</span>
              <div class="flex h-1.5 overflow-hidden rounded-[3px] bg-track" aria-hidden="true">
                <div class="bg-ink" :style="{ width: `${((day.calls - day.failed) / maxDaily) * 100}%` }" />
                <div class="bg-lilac" :style="{ width: `${(day.failed / maxDaily) * 100}%` }" />
              </div>
              <span class="text-right tabular-nums">{{ day.calls }}회 · 실패 {{ day.failed }}</span>
            </li>
          </ul>
        </div>
      </template>
    </section>

    <aside class="flex flex-col gap-3.5 border-line bg-panel px-5 py-7 lg:border-l-[1.5px] lg:px-[22px]">
      <div class="flex items-center justify-between gap-2">
        <span class="text-[13px] font-bold">사용량</span>
        <select v-model.number="days" aria-label="기간" class="rounded-[10px] border-[1.5px] border-line bg-white px-2.5 py-1.5 text-[13px] font-semibold">
          <option :value="7">최근 7일</option>
          <option :value="30">최근 30일</option>
          <option :value="90">최근 90일</option>
        </select>
      </div>
      <template v-if="usage">
        <div class="flex items-center gap-3.5 rounded-[18px] border-2 border-ink bg-lilac p-4">
          <span class="font-display text-[44px] leading-none">{{ num(usage.kpi.llm_calls) }}</span>
          <div class="flex flex-col gap-0.5">
            <span class="text-sm font-bold">AI 호출</span>
            <span class="text-xs">실패 {{ Math.round(usage.kpi.llm_failure_rate * 100) }}%</span>
          </div>
        </div>
        <div class="flex flex-col gap-1.5 rounded-[14px] border-[1.5px] border-line bg-white p-3.5">
          <span class="text-sm font-bold">글 / 초안 / 게시</span>
          <span class="text-lg font-bold tabular-nums">{{ usage.kpi.posts }} / {{ usage.kpi.drafted }} / {{ usage.kpi.published }}</span>
        </div>
        <div class="flex flex-col gap-1.5 rounded-[14px] border-[1.5px] border-line bg-white p-3.5">
          <span class="text-sm font-bold">토큰</span>
          <span class="text-lg font-bold tabular-nums">{{ num(usage.kpi.tokens) }}</span>
          <span class="text-xs text-sub">금액은 공급자 콘솔에서 확인</span>
        </div>
        <div class="flex flex-col gap-1.5 rounded-[14px] border-[1.5px] border-line bg-white p-3.5">
          <span class="text-sm font-bold">초안 평균 생성 시간</span>
          <span class="text-lg font-bold tabular-nums">{{ usage.kpi.avg_draft_latency_ms ? `${(usage.kpi.avg_draft_latency_ms / 1000).toFixed(1)}초` : '—' }}</span>
        </div>
      </template>

      <span class="pt-1 text-[13px] font-bold">실패한 작업 {{ failed.length }}</span>
      <p v-if="message" role="status" class="text-[13px] text-sub">{{ message }}</p>
      <p v-if="!failed.length" class="text-[13px] text-sub">실패한 작업이 없어요.</p>
      <div v-for="job in failed" :key="job.uuid" class="flex flex-col gap-2 rounded-[14px] border-2 border-ink bg-lemon px-3.5 py-3 text-[13px] leading-snug">
        <div class="flex justify-between gap-2">
          <span class="font-bold">{{ JOBS[job.job] ?? job.job }}</span>
          <span class="text-xs tabular-nums">{{ new Date(job.failed_at).toLocaleString('ko-KR') }}</span>
        </div>
        <span class="line-clamp-2 break-all" :title="job.error">{{ job.error }}</span>
        <div class="flex gap-1.5">
          <button type="button" class="rounded-lg bg-ink px-2.5 py-1.5 text-xs font-bold text-cream" @click="retry(job)">다시 실행</button>
          <button type="button" class="rounded-lg border-[1.5px] border-ink px-2.5 py-1.5 text-xs font-bold" @click="forget(job)">지우기</button>
        </div>
      </div>
      <RouterLink to="/projects" class="pt-2 text-[13px] font-bold underline">키워드·참고 글 관리 →</RouterLink>
    </aside>
  </div>
</template>
