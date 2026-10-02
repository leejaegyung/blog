<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import { placeApi, type ParsedMapInput, type Place } from '@/lib/api'
import { validationErrors } from '@/lib/http'

/**
 * 장소 연결: 네이버·구글·카카오 지도 링크나 가게 이름을 넣으면 카카오 로컬(공식 API)로 장소를 찾아 고르게 한다.
 * 고른 장소의 이름·주소·연락처·업종은 "알려줄 내용"에 채워져 사용자가 확인·수정한다(사실로만 쓴다).
 */
const model = defineModel<Place | null>({ required: true })
const emit = defineEmits<{ chosen: [place: Place] }>()

const SOURCE_LABEL: Record<string, string> = { naver: '네이버 지도', google: '구글 지도', kakao: '카카오맵', other: '링크', text: '이름' }
const input = ref('')
const busy = ref(false)
const error = ref<string | null>(null)
const candidates = ref<Place[] | null>(null)
const parsed = ref<ParsedMapInput | null>(null)
const editing = ref(model.value === null)

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

function choose(place: Place) {
  const chosen = { ...place, map_url: parsed.value?.url ?? null, source: parsed.value?.source ?? 'text' }
  model.value = chosen
  emit('chosen', chosen)
  candidates.value = null
  input.value = ''
  editing.value = false
}

function clear() {
  model.value = null
  editing.value = true
}
</script>

<template>
  <section class="flex flex-col gap-2.5 rounded-[18px] border-[1.5px] border-line bg-white p-4">
    <div class="flex items-center justify-between gap-2">
      <span class="text-sm font-bold">장소 연결 <span class="font-medium text-sub">(선택 · 지도 링크로 정확한 이름·주소를 채워요)</span></span>
    </div>

    <div v-if="model && !editing" class="flex flex-col gap-1 rounded-[14px] bg-lilac-soft px-3.5 py-3 text-sm">
      <b>{{ model.name ?? '고른 위치' }}</b>
      <span v-if="model.category" class="text-xs text-sub">{{ model.category }}</span>
      <span>{{ model.road_address ?? model.address }}</span>
      <span v-if="model.phone" class="text-xs">{{ model.phone }}</span>
      <div class="mt-1 flex flex-wrap gap-3 text-xs">
        <a v-if="model.map_url" :href="model.map_url" target="_blank" rel="noopener noreferrer" class="font-bold">
          {{ SOURCE_LABEL[model.source ?? 'other'] }} 열기 ↗
        </a>
        <a v-else-if="model.kakao_url" :href="model.kakao_url" target="_blank" rel="noopener noreferrer" class="font-bold">카카오맵 열기 ↗</a>
        <button type="button" class="underline" @click="editing = true">바꾸기</button>
        <button type="button" class="text-sub underline" @click="clear">연결 해제</button>
      </div>
    </div>

    <form v-else class="flex flex-col gap-2" @submit.prevent="lookup">
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
      <span class="text-xs leading-normal text-sub">
        링크는 열어 보지 않고, 링크·글에 적힌 이름과 위치로 카카오 장소 검색에서 확인해요.
      </span>
      <details class="text-xs leading-normal text-sub">
        <summary class="cursor-pointer font-bold text-ink">네이버 지도에서 가져오는 법</summary>
        <ol class="mt-1.5 mb-0 flex flex-col gap-1 pl-4">
          <li><b class="text-ink">휴대폰 앱</b>: 가게를 열고 <b class="text-ink">공유</b> → <b class="text-ink">복사</b>(또는 링크 복사) → 여기에 그대로 붙여넣기. 가게 이름·주소·링크가 함께 오면 가장 정확해요.</li>
          <li><b class="text-ink">PC</b>(map.naver.com): 가게를 열고 <b class="text-ink">공유</b> → <b class="text-ink">URL 복사</b> → 붙여넣고 한 칸 띄운 뒤 가게 이름 적기.</li>
          <li>링크만 붙였는데 못 찾으면 <code>https://naver.me/… 가게이름</code>처럼 이름을 같이 적어 주세요(naver.me 단축 링크에는 가게 정보가 글자로 없어요).</li>
          <li>체인점은 지점까지 적으면 정확해요(예: 파스타인계 인계점).</li>
        </ol>
      </details>
      <button v-if="model" type="button" class="self-start text-xs text-sub underline" @click="editing = false">취소</button>
    </form>

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
          <span v-if="place.distance_m !== null && place.distance_m !== undefined" class="text-xs whitespace-nowrap text-sub">링크에서 {{ place.distance_m < 1000 ? `${place.distance_m}m` : `${(place.distance_m / 1000).toFixed(1)}km` }}</span>
          <span class="rounded-lg bg-lemon px-2.5 py-1 text-xs font-bold whitespace-nowrap">이 장소로</span>
        </button>
      </li>
    </ul>
  </section>
</template>
