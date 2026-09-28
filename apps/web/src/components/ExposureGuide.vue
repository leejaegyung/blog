<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { projectApi, type ExposureGuide, type Project } from '@/lib/api'
import { validationErrors } from '@/lib/http'

const props = defineProps<{ projectId: number; guide: ExposureGuide | null; customHashtags?: string[] | null }>()
const emit = defineEmits<{ saved: [project: Project] }>()

const MAX = 30
const suggested = computed(() => props.guide?.hashtags.map((h) => h.tag) ?? [])
const shareOf = computed(() => new Map(props.guide?.hashtags.filter((h) => h.share).map((h) => [h.tag, h.share!]) ?? []))

const tags = ref<string[]>([])
const input = ref('')
const saving = ref(false)
const message = ref<{ ok: boolean; text: string } | null>(null)

const initial = computed(() => props.customHashtags ?? suggested.value)
const dirty = computed(() => JSON.stringify(tags.value) !== JSON.stringify(initial.value))
const custom = computed(() => Array.isArray(props.customHashtags))
// 추천에 있지만 지금 목록에 없는 태그(다시 넣기 쉽게)
const removed = computed(() => suggested.value.filter((t) => !tags.value.some((x) => x.toLowerCase() === t.toLowerCase())))

watch(initial, (value) => (tags.value = [...value]), { immediate: true })

function normalize(raw: string) {
  return raw.trim().replace(/^#+/, '').replace(/[^0-9A-Za-z가-힣_]/g, '')
}

function add(raw = input.value) {
  const parts = raw.split(/[\s,#]+/).map(normalize).filter((t) => t && !/^\d+$/.test(t) && t.length <= 30)
  for (const tag of parts) {
    if (tags.value.length >= MAX) break
    if (!tags.value.some((t) => t.toLowerCase() === tag.toLowerCase())) tags.value = [...tags.value, tag]
  }
  input.value = ''
}

async function save(reset = false) {
  saving.value = true
  message.value = null
  try {
    const project = await projectApi.saveHashtags(props.projectId, reset ? null : tags.value)
    emit('saved', project)
    message.value = { ok: true, text: reset ? '분석 추천으로 되돌렸어요.' : '저장했어요. 이 키워드로 쓰는 글에 자동으로 달려요.' }
  } catch (e) {
    message.value = { ok: false, text: Object.values(validationErrors(e) ?? {})[0]?.[0] ?? '저장하지 못했어요.' }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="flex flex-col gap-3" aria-labelledby="exposure-guide">
    <div class="flex flex-col gap-1">
      <h2 id="exposure-guide" class="m-0 text-sm font-bold">상위 노출 가이드</h2>
      <p class="m-0 text-[13px] leading-normal text-sub">
        네이버는 순위 기준을 공개하지 않아요.
        {{ guide?.reference_count ? `참고 글 ${guide.reference_count}개의 통계` : '일반 기준' }}과 네이버가 밝힌 검색 원칙으로 만든 가이드예요.
      </p>
    </div>

    <p v-if="!guide" class="m-0 rounded-[14px] border-2 border-ink bg-lemon px-3.5 py-3 text-[13px]">
      다시 분석하면 목표치와 추천 해시태그가 나와요.
    </p>

    <ul v-if="guide" class="m-0 flex list-none flex-col gap-2 p-0">
      <li
        v-for="target in guide.targets"
        :key="target.key"
        class="grid gap-x-4 gap-y-0.5 rounded-[14px] border-[1.5px] border-line bg-white px-4 py-3 sm:grid-cols-[7rem_minmax(0,1fr)]"
      >
        <span class="text-[13px] font-bold text-accent">{{ target.label }}</span>
        <span class="text-[15px] font-bold">{{ target.target }}</span>
        <span class="text-xs text-sub sm:col-start-2">{{ target.basis }}</span>
      </li>
    </ul>

    <div class="flex flex-col gap-2.5 rounded-[18px] border-2 border-ink bg-white p-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <span class="text-sm font-bold">
          글에 자동으로 달 해시태그 {{ tags.length }}개
          <span class="rounded-md bg-lilac-soft px-[7px] py-[3px] text-[11px] font-extrabold">{{ custom ? '직접 고침' : '분석 추천' }}</span>
        </span>
        <span class="text-xs text-sub">최대 {{ MAX }}개</span>
      </div>
      <ul class="m-0 flex list-none flex-wrap gap-1.5 p-0" aria-label="달 해시태그">
        <li v-for="tag in tags" :key="tag" class="flex items-center gap-1 rounded-full bg-lilac px-3 py-1 text-[13px] font-semibold">
          #{{ tag }}
          <span v-if="shareOf.get(tag)" class="text-[11px] font-normal">· 참고 글 {{ Math.round(shareOf.get(tag)! * 100) }}%</span>
          <button type="button" :aria-label="`${tag} 빼기`" class="pl-0.5 text-sub hover:text-ink" @click="tags = tags.filter((t) => t !== tag)">✕</button>
        </li>
        <li v-if="!tags.length" class="text-[13px] text-sub">아직 없어요. 아래에 적어 추가하세요.</li>
      </ul>
      <form class="flex gap-2" @submit.prevent="add()">
        <input
          v-model="input"
          aria-label="해시태그 추가"
          placeholder="#태그 · 여러 개는 띄어서"
          class="min-w-0 flex-1 rounded-xl border-[1.5px] border-line px-3 py-2 text-sm outline-none focus:border-ink"
        />
        <button type="submit" :disabled="!input.trim()" class="rounded-xl border-[1.5px] border-ink px-3 text-sm font-bold disabled:opacity-40">추가</button>
      </form>
      <div v-if="removed.length" class="flex flex-wrap items-center gap-1.5 text-xs">
        <span class="text-sub">추천 다시 넣기</span>
        <button v-for="tag in removed" :key="tag" type="button" class="rounded-full border-[1.5px] border-line px-2 py-0.5 hover:border-ink" @click="add(tag)">
          + {{ tag }}
        </button>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button type="button" :disabled="!dirty || saving" class="h-10 rounded-xl bg-ink px-4 text-sm font-bold text-cream disabled:opacity-40" @click="save()">
          {{ saving ? '저장 중…' : '해시태그 저장' }}
        </button>
        <button v-if="custom && guide" type="button" :disabled="saving" class="text-[13px] text-sub underline" @click="save(true)">분석 추천으로 되돌리기</button>
        <span v-if="message" :role="message.ok ? 'status' : 'alert'" :class="message.ok ? 'text-sub' : 'text-red-600'" class="text-[13px]">
          {{ message.text }}
        </span>
      </div>
      <p class="m-0 text-xs leading-normal text-sub">
        이 키워드로 초안을 쓰면 자동으로 달려요. 글과 관련된 태그만 두세요 — 상관없는 인기 태그는 도움이 되지 않아요.
      </p>
    </div>

    <details v-if="guide?.principles.length" class="rounded-[14px] border-[1.5px] border-line bg-white px-4 py-3 text-[13px]">
      <summary class="cursor-pointer font-bold">네이버 검색 원칙 {{ guide.principles.length }}가지</summary>
      <ul class="mt-2 mb-0 flex list-disc flex-col gap-1 pl-5 leading-normal">
        <li v-for="principle in guide.principles" :key="principle">{{ principle }}</li>
      </ul>
    </details>
  </section>
</template>
