<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { analysisApi, postApi, type Fact, type KeywordAnalysis, type Post, type Tone } from '@/lib/api'
import { validationErrors } from '@/lib/http'
import { TONE_LABELS } from '@/lib/flow'
import StepLayout from '@/components/flow/StepLayout.vue'
import NextButton from '@/components/flow/NextButton.vue'
import PanelCard from '@/components/flow/PanelCard.vue'
import { useFlow } from './useFlow'

const props = defineProps<{ post: Post }>()
const flow = useFlow()

const CHIPS = ['장소명', '가격', '방문 날짜', '주소', '주차', '좋았던 점', '아쉬웠던 점']
const PLACEHOLDERS: Record<string, string> = {
  장소명: '예: 파스타 인계',
  가격: '예: 런치 세트 19,000원',
  '방문 날짜': '예: 2026-09-20 평일 점심',
  주소: '예: 수원시 팔달구 인계동 …',
  주차: '예: 건물 지하 2시간 무료',
  메뉴: '예: 봉골레, 크림 파스타',
  '좋았던 점': '예: 봉골레 면이 탱글했어요',
  '아쉬웠던 점': '예: 웨이팅이 20분 있었어요',
}
const LENGTHS = [
  { value: 1500, label: '짧게' },
  { value: 2500, label: '보통' },
  { value: 4000, label: '길게' },
]
const SLOT_LABELS: Record<string, string> = {
  price: '가격', address: '주소', phone: '연락처', hours: '영업시간', parking: '주차',
  reservation: '예약', wait: '웨이팅', menu: '메뉴',
}

type Row = Fact & { custom?: boolean }
const rows = ref<Row[]>(
  props.post.facts?.length
    ? props.post.facts.map(({ fact_key, fact_value }) => ({ fact_key, fact_value }))
    : [{ fact_key: '장소명', fact_value: '' }, { fact_key: '좋았던 점', fact_value: '' }],
)
const tone = ref<Tone>(props.post.tone ?? 'natural')
const length = ref(props.post.target_length ?? 2500)
const analysis = ref<KeywordAnalysis | null>(null)
const busy = ref(false)
const error = ref<string | null>(null)
const list = ref<HTMLElement | null>(null)

const snapshot = () => JSON.stringify([rows.value.filter((r) => r.fact_key.trim() && r.fact_value.trim()), tone.value, length.value])
const initial = snapshot()
const dirty = computed(() => snapshot() !== initial)

const keys = computed(() => new Set(rows.value.map((r) => r.fact_key.trim())))
const visitHint = computed(() =>
  keys.value.has('방문 날짜') ? null : (props.post.images ?? []).map((i) => i.taken_at).find(Boolean)?.slice(0, 10) ?? null,
)
const missingSlots = computed(() =>
  (analysis.value?.stats?.slots ?? [])
    .filter((s) => s.share >= 0.5 && SLOT_LABELS[s.label])
    .map((s) => SLOT_LABELS[s.label]!)
    .filter((label) => ![...keys.value].some((k) => k.includes(label))),
)

async function focusLast() {
  await nextTick()
  const inputs = list.value?.querySelectorAll<HTMLInputElement>('input[data-value]')
  inputs?.[inputs.length - 1]?.focus()
}

function toggle(key: string) {
  if (keys.value.has(key)) rows.value = rows.value.filter((r) => r.fact_key !== key)
  else {
    rows.value = [...rows.value, { fact_key: key, fact_value: '' }]
    void focusLast()
  }
}

function addCustom() {
  rows.value = [...rows.value, { fact_key: '', fact_value: '', custom: true }]
  void nextTick(() => list.value?.querySelector<HTMLInputElement>('input[data-key]:last-of-type')?.focus())
}

async function next() {
  error.value = null
  const facts = rows.value
    .map((r) => ({ fact_key: r.fact_key.trim(), fact_value: r.fact_value.trim() }))
    .filter((r) => r.fact_key && r.fact_value)
  if (!facts.length) {
    error.value = '알려줄 내용을 1개 이상 적어주세요.'
    return
  }
  if (props.post.plan && !dirty.value) return flow.go(4)
  busy.value = true
  try {
    flow.update(await postApi.update(props.post.id, { facts, tone: tone.value, target_length: length.value }))
    flow.update(await postApi.autopilot(props.post.id, 'plan'))
    flow.go(4)
  } catch (e) {
    const errors = validationErrors(e)
    error.value = errors ? (Object.values(errors)[0]?.[0] ?? '입력값을 확인해 주세요.') : '글 계획을 시작하지 못했어요.'
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  if (props.post.keyword_project_id) analysis.value = (await analysisApi.get(props.post.keyword_project_id).catch(() => null))?.data ?? null
})
</script>

<template>
  <StepLayout :step="3" :title="'꼭 알려줄\n내용이 있나요?'" lead="여기 적은 것만 사실로 씁니다. 가격·주소는 직접 적어주세요." lead-mobile-only>
    <div class="flex flex-wrap gap-1.5">
      <button
        v-for="chip in CHIPS"
        :key="chip"
        type="button"
        :aria-pressed="keys.has(chip)"
        :class="keys.has(chip) ? 'bg-lilac' : 'bg-white'"
        class="rounded-full border-[1.5px] border-ink px-3 py-[7px] text-[13px] font-semibold lg:px-[13px] lg:py-2 lg:text-sm"
        @click="toggle(chip)"
      >
        {{ keys.has(chip) ? '✓' : '+' }} {{ chip }}
      </button>
      <button type="button" class="rounded-full border-[1.5px] border-dashed border-ink bg-white px-3 py-[7px] text-[13px] font-semibold lg:text-sm" @click="addCustom">
        + 직접 입력
      </button>
    </div>

    <div ref="list" class="flex flex-col gap-1.5 lg:gap-2">
      <div
        v-for="(row, index) in rows"
        :key="index"
        :class="row.fact_value.trim() ? 'border-[1.5px] border-line' : 'border-2 border-ink'"
        class="grid grid-cols-[minmax(0,1fr)_24px] items-center gap-x-2.5 gap-y-0.5 rounded-[14px] bg-white px-3.5 py-2.5 lg:grid-cols-[140px_minmax(0,1fr)_24px] lg:px-4 lg:py-3"
      >
        <input
          v-if="row.custom"
          v-model="row.fact_key"
          data-key
          maxlength="50"
          :aria-label="`${index + 1}번 항목 이름`"
          placeholder="항목 이름"
          class="col-span-1 bg-transparent text-xs font-bold text-accent outline-none lg:text-sm"
        />
        <span v-else class="text-xs font-bold text-accent lg:text-sm">{{ row.fact_key }}</span>
        <button type="button" :aria-label="`${row.fact_key || index + 1 + '번'} 항목 삭제`" class="row-span-2 self-center text-muted hover:text-ink lg:order-last lg:row-span-1" @click="rows = rows.filter((_, i) => i !== index)">✕</button>
        <input
          v-model="row.fact_value"
          data-value
          maxlength="1000"
          :aria-label="`${row.fact_key || '항목'} 내용`"
          :placeholder="PLACEHOLDERS[row.fact_key] ?? '내용을 적어주세요'"
          class="col-span-1 min-w-0 bg-transparent text-[15px] outline-none placeholder:text-muted lg:col-span-1 lg:text-base"
        />
      </div>
    </div>

    <div class="grid gap-5 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
      <fieldset class="flex flex-col gap-2">
        <legend class="pb-2 text-[13px] font-bold lg:text-sm">말투</legend>
        <div class="grid grid-cols-2 gap-1.5">
          <button
            v-for="(label, value) in TONE_LABELS"
            :key="value"
            type="button"
            :aria-pressed="tone === value"
            :class="tone === value ? 'border-ink bg-ink text-cream' : 'border-line bg-white'"
            class="rounded-xl border-[1.5px] p-2 text-center text-[13px] font-semibold lg:p-[11px] lg:text-sm"
            @click="tone = value as Tone"
          >
            {{ label }}
          </button>
        </div>
      </fieldset>
      <fieldset class="flex flex-col gap-2">
        <legend class="pb-2 text-[13px] font-bold lg:text-sm">길이</legend>
        <div class="grid grid-cols-3 rounded-xl bg-lilac-soft p-[3px] lg:flex lg:flex-col lg:gap-1.5 lg:bg-transparent lg:p-0">
          <button
            v-for="option in LENGTHS"
            :key="option.value"
            type="button"
            :aria-pressed="length === option.value"
            :class="length === option.value ? 'bg-ink font-bold text-cream' : 'lg:border-[1.5px] lg:border-line lg:bg-white'"
            class="rounded-[9px] p-2 text-center text-[13px] font-semibold lg:rounded-xl lg:px-3.5 lg:py-2.5 lg:text-left lg:text-sm lg:font-normal"
            @click="length = option.value"
          >
            {{ option.label }}<span class="hidden lg:inline"> · 약 {{ option.value.toLocaleString('ko-KR') }}자</span>
          </button>
        </div>
      </fieldset>
    </div>
    <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>

    <template #aside>
      <span class="hidden text-[13px] font-bold lg:block">이렇게 쓰여요</span>
      <PanelCard desktop-only>
        <span>여기 적은 것만 글에서 <b>사실</b>로 씁니다. 적지 않은 가격·주소·영업시간은 쓰지 않거나 "확인 필요"로 표시해요.</span>
      </PanelCard>
      <PanelCard v-if="visitHint" tone="lemon">
        <span class="text-sm font-bold">사진 촬영일 {{ visitHint }}</span>
        <span>방문 날짜로 넣을까요?</span>
        <button type="button" class="self-start rounded-[10px] bg-ink px-3 py-[7px] font-bold text-cream" @click="rows = [...rows, { fact_key: '방문 날짜', fact_value: visitHint! }]">
          방문 날짜로 추가
        </button>
      </PanelCard>
      <PanelCard v-if="missingSlots.length">
        <span class="text-sm font-bold">참고 글에서 자주 다룬 정보</span>
        <span class="text-sub">{{ missingSlots.join(' · ') }} — 알고 있다면 적어주세요.</span>
      </PanelCard>
    </template>

    <template #back>
      <button type="button" class="hover:text-accent" @click="flow.go(2)">← 사진</button>
    </template>
    <template #next>
      <NextButton :busy="busy" @click="next">{{ busy ? '시작하는 중…' : '다음 · 글 계획 만들기 →' }}</NextButton>
    </template>
  </StepLayout>
</template>
