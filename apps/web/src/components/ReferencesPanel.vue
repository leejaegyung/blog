<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { referenceApi, type ParseStatus, type Reference } from '@/lib/api'
import { validationErrors } from '@/lib/http'

// learning: 카테고리별 학습 화면("학습할 글")
const props = defineProps<{ projectId: number; learning?: boolean }>()
const emit = defineEmits<{ changed: [count: number] }>()

const MAX = 50
const POLL_MS = 2000
const PASTE_HINT =
  '글 본문을 복사해 붙여넣으세요.\n사진이 있던 자리에 [사진] 이라고 한 줄 적으면 사진 배치도 분석합니다.\n소제목은 # 소제목 처럼 적을 수 있습니다.'

const STATUS: Record<ParseStatus, { label: string; color: string }> = {
  pending: { label: '분석 중', color: 'bg-lilac-soft' },
  needs_text: { label: '본문 필요', color: 'border border-ink bg-lemon' },
  parsed: { label: '완료', color: 'bg-lilac' },
  duplicate: { label: '중복', color: 'bg-track' },
  failed: { label: '실패', color: 'border border-ink bg-lemon' },
}

const references = ref<Reference[]>([])
const loading = ref(true)
const mode = ref<'urls' | 'text'>('urls')
const urlsInput = ref('')
const pasteTitle = ref('')
const pasteBody = ref('')
const submitting = ref(false)
const message = ref<{ ok: boolean; text: string } | null>(null)

const openPasteId = ref<number | null>(null)
const inlineTitle = ref('')
const inlineBody = ref('')
const inlineError = ref<string | null>(null)

const remaining = computed(() => MAX - references.value.length)
const hasPending = computed(() => references.value.some((r) => r.parse_status === 'pending'))

let timer: ReturnType<typeof setTimeout> | undefined

async function load() {
  references.value = await referenceApi.list(props.projectId)
  loading.value = false
  emit('changed', references.value.length)
  schedulePoll()
}

function schedulePoll() {
  clearTimeout(timer)
  if (hasPending.value) timer = setTimeout(load, POLL_MS)
}

function firstError(error: unknown, fallback: string) {
  const errors = validationErrors(error)
  return errors ? (Object.values(errors)[0]?.[0] ?? fallback) : fallback
}

async function submit() {
  submitting.value = true
  message.value = null
  try {
    if (mode.value === 'urls') {
      const urls = urlsInput.value
        .split(/\s+/)
        .map((u) => u.trim())
        .filter(Boolean)
      if (urls.length === 0) return
      const result = await referenceApi.addUrls(props.projectId, urls)
      urlsInput.value = ''
      const naver = result.data.filter((r) => r.parse_status === 'needs_text').length
      const parts = [`${result.data.length}개 추가`]
      if (result.skipped.length) parts.push(`${result.skipped.length}개는 이미 있어 건너뜀`)
      if (naver) parts.push(`네이버 글 ${naver}개는 본문을 붙여넣어 주세요`)
      message.value = { ok: true, text: parts.join(' · ') }
    } else {
      await referenceApi.addText(props.projectId, pasteBody.value, pasteTitle.value)
      pasteBody.value = ''
      pasteTitle.value = ''
      message.value = { ok: true, text: '본문을 추가했습니다.' }
    }
    await load()
  } catch (error) {
    message.value = { ok: false, text: firstError(error, '추가하지 못했습니다.') }
  } finally {
    submitting.value = false
  }
}

function openPaste(reference: Reference) {
  openPasteId.value = reference.id
  inlineTitle.value = reference.title ?? ''
  inlineBody.value = ''
  inlineError.value = null
}

async function submitPaste(reference: Reference) {
  inlineError.value = null
  try {
    await referenceApi.pasteText(reference.id, inlineBody.value, inlineTitle.value)
    openPasteId.value = null
    await load()
  } catch (error) {
    inlineError.value = firstError(error, '저장하지 못했습니다.')
  }
}

async function retry(reference: Reference) {
  await referenceApi.reparse(reference.id)
  await load()
}

async function remove(reference: Reference) {
  await referenceApi.remove(reference.id)
  await load()
}

// 워커 BLOCKED_HOST_SUFFIXES와 같게 유지
const NAVER_HOST = /(^|\.)naver\.(com|me|net)$/i

/** 본문은 분석 후 지우므로, 서버가 다시 가져올 수 있는 글만 "다시 시도"할 수 있다. */
function canRefetch(reference: Reference) {
  if (!reference.source_url) return false
  try {
    return !NAVER_HOST.test(new URL(reference.source_url).hostname)
  } catch {
    return false
  }
}

function needsPaste(reference: Reference) {
  return (
    reference.parse_status === 'needs_text' ||
    (reference.parse_status === 'failed' && !canRefetch(reference))
  )
}

function label(reference: Reference) {
  return reference.title || reference.source_url || '붙여넣은 글'
}

onMounted(load)
onBeforeUnmount(() => clearTimeout(timer))

defineExpose({ load })
</script>

<template>
  <section class="space-y-4">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
      <h2 class="m-0 text-sm font-bold">{{ learning ? '학습할 글' : '참고자료' }}</h2>
      <span class="text-sm text-sub">{{ references.length }}/{{ MAX }}</span>
    </div>

    <form
      class="space-y-3 rounded-[18px] border-[1.5px] border-line bg-white p-4"
      @submit.prevent="submit"
    >
      <div role="tablist" class="flex gap-1 rounded-xl bg-lilac-soft p-1 text-[13px]">
        <button
          type="button"
          role="tab"
          :aria-selected="mode === 'urls'"
          :class="mode === 'urls' ? 'bg-ink font-bold text-cream' : 'font-semibold'"
          class="rounded-[9px] px-3 py-1.5"
          @click="mode = 'urls'"
        >
          URL 추가
        </button>
        <button
          type="button"
          role="tab"
          :aria-selected="mode === 'text'"
          :class="mode === 'text' ? 'bg-ink font-bold text-cream' : 'font-semibold'"
          class="rounded-[9px] px-3 py-1.5"
          @click="mode = 'text'"
        >
          본문 붙여넣기
        </button>
      </div>

      <template v-if="mode === 'urls'">
        <textarea
          v-model="urlsInput"
          rows="4"
          aria-label="참고할 글 URL"
          placeholder="참고할 글 주소를 한 줄에 하나씩 넣으세요."
          class="w-full rounded-xl border-[1.5px] border-line bg-white px-3.5 py-2.5 outline-none focus:border-ink font-mono text-sm"
        />
        <p class="text-sm text-sub">
          네이버 블로그 글은 네이버 정책상 자동으로 가져오지 않습니다. 추가한 뒤 본문을 붙여넣어
          주세요.
        </p>
      </template>
      <template v-else>
        <input
          v-model="pasteTitle"
          maxlength="200"
          aria-label="제목"
          placeholder="제목 (선택)"
          class="w-full rounded-xl border-[1.5px] border-line bg-white px-3.5 py-2.5 outline-none focus:border-ink"
        />
        <textarea
          v-model="pasteBody"
          rows="8"
          aria-label="붙여넣을 본문"
          :placeholder="PASTE_HINT"
          class="w-full rounded-xl border-[1.5px] border-line bg-white px-3.5 py-2.5 outline-none focus:border-ink text-sm"
        />
      </template>

      <div class="flex flex-wrap items-center gap-3">
        <button
          type="submit"
          :disabled="submitting || remaining <= 0"
          class="h-11 rounded-xl bg-ink px-5 font-bold text-cream disabled:opacity-50"
        >
          추가
        </button>
        <span
          v-if="message"
          :role="message.ok ? 'status' : 'alert'"
          :class="message.ok ? 'text-sub' : 'text-red-600'"
          class="text-sm"
        >
          {{ message.text }}
        </span>
      </div>
    </form>

    <p class="text-sm text-sub">
      참고 글의 본문은 구성·사진 배치·자주 쓰는 단어 같은 통계만 뽑은 뒤 저장하지 않습니다.
    </p>
    <p v-if="loading" class="text-sub">불러오는 중…</p>
    <p v-else-if="references.length === 0" class="text-sub">
      {{
        learning
          ? '아직 학습할 글이 없어요. 위 “Blog AI로 보내기” 버튼으로 보내거나 URL을 넣어 주세요.'
          : '아직 참고자료가 없습니다. 이 키워드로 잘 쓰인 글을 추가하면 구성과 사진 배치를 분석합니다.'
      }}
    </p>
    <ul v-else class="divide-y divide-line rounded-[18px] border-[1.5px] border-line bg-white">
      <li v-for="reference in references" :key="reference.id" class="space-y-2 px-4 py-3">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
          <span
            :class="STATUS[reference.parse_status].color"
            class="rounded-md px-[7px] py-[3px] text-[11px] font-extrabold whitespace-nowrap"
          >
            {{ STATUS[reference.parse_status].label }}
          </span>
          <a
            v-if="reference.source_url"
            :href="reference.source_url"
            target="_blank"
            rel="noopener noreferrer"
            class="min-w-0 flex-1 truncate hover:underline"
          >
            {{ label(reference) }}
          </a>
          <span v-else class="min-w-0 flex-1 truncate">{{ label(reference) }}</span>
          <span v-if="reference.char_count !== null" class="text-sm text-sub">
            {{ reference.char_count.toLocaleString('ko-KR') }}자 · 소제목
            {{ reference.heading_count ?? 0 }} · 사진 {{ reference.image_count ?? 0 }}
          </span>
          <div class="flex gap-1 rounded-xl bg-lilac-soft p-1 text-[13px]">
            <button
              v-if="needsPaste(reference)"
              type="button"
              class="rounded-lg px-2 py-1 font-bold underline"
              @click="openPaste(reference)"
            >
              본문 붙여넣기
            </button>
            <button
              v-if="reference.parse_status === 'failed' && canRefetch(reference)"
              type="button"
              class="rounded-lg px-2 py-1 font-bold hover:bg-lilac-soft"
              @click="retry(reference)"
            >
              다시 시도
            </button>
            <button
              type="button"
              :aria-label="`${label(reference)} 삭제`"
              class="rounded-lg px-2 py-1 text-sub hover:text-ink"
              @click="remove(reference)"
            >
              삭제
            </button>
          </div>
        </div>
        <p v-if="reference.error_message" class="text-sm text-red-600">
          {{ reference.error_message }}
        </p>
        <form
          v-if="openPasteId === reference.id"
          class="space-y-2"
          @submit.prevent="submitPaste(reference)"
        >
          <input
            v-model="inlineTitle"
            maxlength="200"
            aria-label="제목"
            placeholder="제목 (선택)"
            class="w-full rounded-xl border-[1.5px] border-line bg-white px-3.5 py-2.5 outline-none focus:border-ink"
          />
          <textarea
            v-model="inlineBody"
            rows="8"
            aria-label="이 글의 본문"
            :placeholder="PASTE_HINT"
            class="w-full rounded-xl border-[1.5px] border-line bg-white px-3.5 py-2.5 outline-none focus:border-ink text-sm"
          />
          <p v-if="inlineError" role="alert" class="text-sm text-red-600">{{ inlineError }}</p>
          <div class="flex gap-2">
            <button
              type="submit"
              class="rounded-[10px] bg-ink px-3 py-1.5 text-sm font-bold text-cream"
            >
              저장하고 분석
            </button>
            <button type="button" class="text-sm text-sub" @click="openPasteId = null">
              취소
            </button>
          </div>
        </form>
      </li>
    </ul>
  </section>
</template>
