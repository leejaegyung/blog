<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { placeApi, type DetectedPlace, type ParsedMapInput, type Place } from '@/lib/api'
import { validationErrors } from '@/lib/http'

/**
 * 장소 연결(여러 곳): 네이버·구글·카카오 지도 링크나 가게 이름으로 찾거나, 알려줄 내용 속 장소 이름을 찾아 카카오 로컬(공식 API)로 확인한다.
 * 고른 장소의 이름·주소·연락처·업종은 "알려줄 내용"에 채워져 사용자가 확인·수정한다(사실로만 쓴다).
 */
const model = defineModel<Place[]>({ required: true })
const props = defineProps<{ texts: string[] }>()
const emit = defineEmits<{ chosen: [place: Place, index: number] }>()

const MAX = 10
const SOURCE_LABEL: Record<string, string> = { naver: '네이버 지도', google: '구글 지도', kakao: '카카오맵', other: '링크', text: '이름' }
const input = ref('')
const busy = ref(false)
const error = ref<string | null>(null)
const candidates = ref<Place[] | null>(null)
const parsed = ref<ParsedMapInput | null>(null)
const adding = ref(model.value.length === 0)
const detecting = ref(false)
const detected = ref<DetectedPlace[] | null>(null)
const detectMessage = ref<string | null>(null)

const linkedIds = computed(() => model.value.map((p) => p.kakao_id).filter((id): id is string => !!id))
const anchor = computed(() => model.value.find((p) => p.lat !== null && p.lng !== null) ?? null)

async function lookup() {
  busy.value = true
  error.value = null
  candidates.value = null
  try {
    const result = await placeApi.lookup(input.value.trim())
    candidates.value = result.data
    parsed.value = result.parsed
    if (!result.data.length) error.value = '찾은 장소가 없어요. 가게 이름을 조금 다르게(지역과 함께) 적어 보세요.'
  } catch (e) {
    error.value = Object.values(validationErrors(e) ?? {})[0]?.[0] ?? '장소를 찾지 못했어요.'
  } finally {
    busy.value = false
  }
}

function add(place: Place) {
  if (place.kakao_id && linkedIds.value.includes(place.kakao_id)) return
  // 새 값은 부모를 거쳐 돌아오므로 순서는 넣기 전에 정한다
  const index = model.value.length
  model.value = [...model.value, place]
  emit('chosen', place, index)
}

function choose(place: Place) {
  add({ ...place, map_url: parsed.value?.url ?? null, source: parsed.value?.source ?? 'text' })
  candidates.value = null
  input.value = ''
  adding.value = false
}

function remove(index: number) {
  model.value = model.value.filter((_, i) => i !== index)
  if (!model.value.length) adding.value = true
}

/** 알려줄 내용에 적은 장소 이름(예: 카시오 도산점, 서울숲)을 찾아 후보로 보여 준다 */
async function detect() {
  detecting.value = true
  detectMessage.value = null
  detected.value = null
  try {
    const anchorPlace = anchor.value
    const result = await placeApi.detect(
      props.texts.filter((t) => t.trim()),
      anchorPlace ? { lat: anchorPlace.lat!, lng: anchorPlace.lng! } : null,
      linkedIds.value,
    )
    detected.value = result.data
    if (!result.data.length) detectMessage.value = '알려줄 내용에서 새로 찾은 장소가 없어요. 위 칸에 지도 링크나 가게 이름을 넣어 보세요.'
  } catch (e) {
    detectMessage.value = Object.values(validationErrors(e) ?? {})[0]?.[0] ?? '장소를 찾지 못했어요.'
  } finally {
    detecting.value = false
  }
}

function addDetected(place: DetectedPlace) {
  const rest: Place & { query?: string } = { ...place }
  delete rest.query
  add({ ...rest, source: 'text', map_url: null })
  detected.value = detected.value?.filter((p) => p.kakao_id !== place.kakao_id) ?? null
}

const distance = (m: number) => (m < 1000 ? `${m}m` : `${(m / 1000).toFixed(1)}km`)
</script>

<template>
  <section class="flex flex-col gap-2.5 rounded-[18px] border-[1.5px] border-line bg-white p-4">
    <span class="text-sm font-bold">장소 연결 <span class="font-medium text-sub">(선택 · 여러 곳 가능 · 정확한 이름·주소를 채워요)</span></span>

    <ul v-if="model.length" class="m-0 flex list-none flex-col gap-1.5 p-0" aria-label="연결한 장소">
      <li v-for="(place, i) in model" :key="place.kakao_id ?? i" class="flex items-start gap-3 rounded-[14px] bg-lilac-soft px-3.5 py-2.5 text-sm">
        <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-ink text-[11px] font-bold text-cream">{{ i + 1 }}</span>
        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
          <b class="truncate">{{ place.name ?? '고른 위치' }}</b>
          <span class="truncate text-xs text-sub">{{ [place.category, place.road_address ?? place.address, place.phone].filter(Boolean).join(' · ') }}</span>
          <a v-if="place.map_url || place.kakao_url" :href="(place.map_url ?? place.kakao_url)!" target="_blank" rel="noopener noreferrer" class="self-start text-xs font-bold">
            {{ place.map_url ? SOURCE_LABEL[place.source ?? 'other'] : '카카오맵' }} 열기 ↗
          </a>
        </span>
        <button type="button" :aria-label="`${place.name ?? '장소'} 연결 해제`" class="text-xs text-sub underline" @click="remove(i)">빼기</button>
      </li>
    </ul>

    <form v-if="adding" class="flex flex-col gap-2" @submit.prevent="lookup">
      <div class="flex flex-col gap-2 rounded-[14px] border-[1.5px] border-ink p-1.5 sm:flex-row sm:items-center sm:pl-3.5">
        <!-- 지도 앱 "공유 → 복사"는 여러 줄(이름·주소·링크)이라 줄바꿈을 살리는 칸을 쓴다. Enter로 찾기, Shift+Enter 줄바꿈 -->
        <textarea
          v-model="input"
          rows="1"
          aria-label="지도 링크나 가게 이름"
          placeholder="네이버 지도 공유 글 통째로 · 구글 지도 링크 · 가게 이름"
          class="min-w-0 flex-1 resize-none bg-transparent px-2 py-2 text-sm outline-none placeholder:text-muted sm:px-0"
          @keydown.enter.exact.prevent="input.trim() && lookup()"
        />
        <button type="submit" :disabled="busy || !input.trim()" class="h-10 rounded-[10px] bg-ink px-4 text-sm font-bold whitespace-nowrap text-cream disabled:opacity-50">
          {{ busy ? '찾는 중…' : '장소 찾기' }}
        </button>
      </div>
      <span class="text-xs leading-normal text-sub">링크는 열어 보지 않고, 링크·글에 적힌 이름과 위치로 카카오 장소 검색에서 확인해요.</span>
      <details class="text-xs leading-normal text-sub">
        <summary class="cursor-pointer font-bold text-ink">네이버 지도에서 가져오는 법</summary>
        <ol class="mt-1.5 mb-0 flex flex-col gap-1 pl-4">
          <li><b class="text-ink">휴대폰 앱</b>: 가게를 열고 <b class="text-ink">공유</b> → <b class="text-ink">복사</b>(또는 링크 복사) → 여기에 그대로 붙여넣기. 가게 이름·주소·링크가 함께 오면 가장 정확해요.</li>
          <li><b class="text-ink">PC</b>(map.naver.com): 가게를 열고 <b class="text-ink">공유</b> → <b class="text-ink">URL 복사</b> → 붙여넣고 한 칸 띄운 뒤 가게 이름 적기.</li>
          <li>링크만 붙였는데 못 찾으면 <code>https://naver.me/… 가게이름</code>처럼 이름을 같이 적어 주세요(naver.me 단축 링크에는 가게 정보가 글자로 없어요).</li>
          <li>체인점은 지점까지 적으면 정확해요(예: 파스타인계 인계점).</li>
        </ol>
      </details>
      <button v-if="model.length" type="button" class="self-start text-xs text-sub underline" @click="adding = false">취소</button>
    </form>

    <div class="flex flex-wrap gap-2">
      <button
        v-if="!adding && model.length < MAX"
        type="button"
        class="rounded-[10px] border-[1.5px] border-ink bg-white px-3 py-1.5 text-[13px] font-bold"
        @click="adding = true"
      >
        + 장소 추가
      </button>
      <button
        type="button"
        :disabled="detecting || !texts.some((t) => t.trim())"
        class="rounded-[10px] border-[1.5px] border-ink bg-white px-3 py-1.5 text-[13px] font-bold disabled:opacity-50"
        @click="detect"
      >
        {{ detecting ? '찾는 중…' : '알려줄 내용에서 장소 찾기' }}
      </button>
    </div>

    <p v-if="error" role="alert" class="m-0 text-[13px] text-red-700">
      {{ error }}
      <RouterLink v-if="error.includes('API 키가 없어요')" :to="{ name: 'admin' }" class="font-bold">관리로 가기</RouterLink>
    </p>

    <ul v-if="candidates?.length" class="m-0 flex list-none flex-col gap-1.5 p-0" aria-label="장소 후보">
      <li v-for="place in candidates" :key="place.kakao_id ?? `${place.lat},${place.lng}`">
        <button
          type="button"
          class="flex w-full items-center gap-3 rounded-[14px] border-[1.5px] border-line bg-white px-3.5 py-2.5 text-left hover:border-ink"
          @click="choose(place)"
        >
          <span class="flex min-w-0 flex-1 flex-col gap-0.5">
            <b class="truncate text-sm">{{ place.name ?? '이 위치' }}</b>
            <span class="truncate text-xs text-sub">{{ [place.category, place.road_address ?? place.address].filter(Boolean).join(' · ') }}</span>
          </span>
          <span v-if="place.distance_m !== null && place.distance_m !== undefined" class="text-xs whitespace-nowrap text-sub">링크에서 {{ distance(place.distance_m) }}</span>
          <span class="rounded-lg bg-lemon px-2.5 py-1 text-xs font-bold whitespace-nowrap">이 장소로</span>
        </button>
      </li>
    </ul>

    <p v-if="detectMessage" role="status" class="m-0 text-[13px] text-sub">{{ detectMessage }}</p>
    <ul v-if="detected?.length" class="m-0 flex list-none flex-col gap-1.5 p-0" aria-label="알려줄 내용에서 찾은 장소">
      <li v-for="place in detected" :key="place.kakao_id ?? place.query">
        <button
          type="button"
          class="flex w-full items-center gap-3 rounded-[14px] border-[1.5px] border-dashed border-ink bg-white px-3.5 py-2.5 text-left"
          @click="addDetected(place)"
        >
          <span class="flex min-w-0 flex-1 flex-col gap-0.5">
            <span class="text-xs text-sub">“{{ place.query }}” →</span>
            <b class="truncate text-sm">{{ place.name }}</b>
            <span class="truncate text-xs text-sub">{{ [place.category, place.road_address ?? place.address].filter(Boolean).join(' · ') }}</span>
          </span>
          <span class="rounded-lg bg-lemon px-2.5 py-1 text-xs font-bold whitespace-nowrap">추가</span>
        </button>
      </li>
    </ul>
  </section>
</template>
