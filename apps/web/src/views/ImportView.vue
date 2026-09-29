<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { analysisApi, projectApi, referenceApi, type Project } from '@/lib/api'
import { parseImportMessage, summarize, type ImportedPost } from '@/lib/bookmarklet'
import { PLATFORM_LABEL, platformOf } from '@/lib/tistory'
import { validationErrors } from '@/lib/http'
import { useUiStore } from '@/stores/ui'

/** 북마크 버튼 "Blog AI로 보내기"가 여는 받기 화면: 받은 글을 학습 카테고리에 넣고 바로 학습한다 */
const ui = useUiStore()
ui.crumb = '글 받기'
ui.saveState = null

const LAST_CATEGORY = 'blog-ai.import.category'
// 이 시간 안에 더 보낸 글은 같은 학습에 함께 들어간다
const LEARN_DELAY_SECONDS = 45

const post = ref<ImportedPost | null>(null)
const categories = ref<Project[]>([])
const categoryId = ref<number | null>(null)
const learnNow = ref(true)
const waitingTooLong = ref(false)
const state = ref<'idle' | 'sending' | 'learning' | 'done' | 'skipped'>('idle')
const error = ref<string | null>(null)
let waitTimer: ReturnType<typeof setTimeout> | undefined

const summary = computed(() => (post.value ? summarize(post.value.text) : null))
const preview = computed(() => post.value?.text.split('\n').filter(Boolean).slice(0, 8) ?? [])
// 보낸 글이 네이버 글이면 네이버용, 티스토리 글이면 티스토리용 카테고리만 보인다(모르는 곳이면 모두)
const postPlatform = computed(() => platformOf(post.value?.url))
const shown = computed(() => (postPlatform.value ? categories.value.filter((c) => c.platform === postPlatform.value) : categories.value))
const chosen = computed(() => shown.value.find((c) => c.id === categoryId.value) ?? null)

function onMessage(event: MessageEvent) {
  const received = parseImportMessage(event.data)
  if (!received) return
  // 보낸 쪽이 같은 글을 계속 보내지 않게 받았다고 알린다
  ;(event.source as Window | null)?.postMessage({ type: 'blog-ai-received' }, { targetOrigin: event.origin })
  if (post.value && state.value !== 'idle') return
  post.value = received
  // 이전에 고른 카테고리가 이 글의 플랫폼용이 아니면 그 플랫폼의 첫 카테고리로
  if (!shown.value.some((c) => c.id === categoryId.value)) categoryId.value = shown.value[0]?.id ?? null
  state.value = 'idle'
  error.value = null
  clearTimeout(waitTimer)
}

function readLast(): number | null {
  try {
    return Number(localStorage.getItem(LAST_CATEGORY)) || null
  } catch {
    return null
  }
}

async function send() {
  if (!post.value || !categoryId.value) return
  error.value = null
  state.value = 'sending'
  try {
    localStorage.setItem(LAST_CATEGORY, String(categoryId.value))
  } catch {
    // 저장 못 해도 괜찮다(다음에 다시 고르면 된다)
  }
  try {
    const result = await referenceApi.send(categoryId.value, {
      text: post.value.text,
      title: post.value.title,
      sourceUrl: post.value.url,
    })
    if (!result.data.length) {
      state.value = 'skipped'
      return
    }
    if (!learnNow.value) {
      state.value = 'done'
      return
    }
    // 여러 글을 이어서 보내도 학습은 한 번만: 마지막으로 보낸 뒤 잠시 모았다가 학습한다(이미 대기 중이면 서버가 건너뛴다)
    state.value = 'learning'
    await analysisApi.analyze(categoryId.value, true, LEARN_DELAY_SECONDS)
    state.value = 'done'
  } catch (e) {
    state.value = 'idle'
    error.value = Object.values(validationErrors(e) ?? {})[0]?.[0] ?? '추가하지 못했어요.'
  }
}

onMounted(async () => {
  window.addEventListener('message', onMessage)
  waitTimer = setTimeout(() => (waitingTooLong.value = !post.value), 5000)
  categories.value = await projectApi.list('category').catch(() => [])
  const last = readLast()
  categoryId.value = shown.value.some((c) => c.id === last) ? last : (shown.value[0]?.id ?? null)
})
onBeforeUnmount(() => {
  window.removeEventListener('message', onMessage)
  clearTimeout(waitTimer)
})
</script>

<template>
  <section class="mx-auto flex w-full max-w-2xl flex-col gap-5 px-5 py-6 lg:py-12">
    <div class="flex flex-col gap-2">
      <span class="text-[13px] font-bold text-accent">카테고리별 학습 · 글 받기</span>
      <h1 class="m-0 font-display text-[32px] leading-[1.1] font-normal lg:text-[40px]">
        {{ post ? '이 글을 학습시킬까요?' : '글을 기다리고 있어요' }}
      </h1>
    </div>

    <div v-if="!post" class="flex flex-col gap-2 rounded-[18px] border-[1.5px] border-line bg-white p-5 text-sm leading-normal text-sub">
      <span>학습시킬 글 화면에서 즐겨찾기의 <b class="text-ink">Blog AI로 보내기</b>를 누르면 여기로 글이 와요.</span>
      <span v-if="waitingTooLong">
        버튼이 아직 없다면
        <RouterLink :to="{ name: 'projects' }" class="font-bold">카테고리별 학습</RouterLink>에서 버튼을 즐겨찾기바로 끌어다 놓으세요.
      </span>
    </div>

    <template v-else>
      <div class="flex flex-col gap-3 rounded-[18px] border-2 border-ink bg-white p-5">
        <span class="text-lg leading-snug font-bold">{{ post.title || '제목 없음' }}</span>
        <a v-if="post.url" :href="post.url" target="_blank" rel="noopener noreferrer" class="truncate text-xs text-sub">{{ post.url }}</a>
        <div v-if="summary" class="flex flex-wrap gap-1.5 text-xs font-bold">
          <span class="rounded-full bg-lilac-soft px-2.5 py-1">{{ summary.chars.toLocaleString('ko-KR') }}자</span>
          <span class="rounded-full bg-lilac-soft px-2.5 py-1">소제목 {{ summary.headings }}</span>
          <span class="rounded-full bg-lilac-soft px-2.5 py-1">사진 {{ summary.photos }}</span>
          <span class="rounded-full bg-lilac-soft px-2.5 py-1">해시태그 {{ summary.hashtags }}</span>
        </div>
        <details class="text-[13px]">
          <summary class="cursor-pointer font-bold">읽은 내용 미리보기</summary>
          <ul class="mt-2 mb-0 flex list-none flex-col gap-1 p-0 text-sub">
            <li v-for="(line, i) in preview" :key="i" class="truncate">{{ line }}</li>
          </ul>
        </details>
      </div>

      <div class="flex flex-col gap-2.5">
        <span class="text-sm font-bold">{{ postPlatform ? `${PLATFORM_LABEL[postPlatform]} ` : '' }}학습 카테고리</span>
        <div class="flex flex-wrap gap-1.5" role="radiogroup" aria-label="학습 카테고리">
          <button
            v-for="option in shown"
            :key="option.id"
            type="button"
            role="radio"
            :aria-checked="categoryId === option.id"
            :class="categoryId === option.id ? 'bg-lilac' : 'bg-white'"
            class="rounded-full border-[1.5px] border-ink px-3.5 py-2 text-sm font-semibold"
            @click="categoryId = option.id"
          >
            {{ option.keyword }}
          </button>
          <RouterLink
            :to="{ name: 'projects', query: postPlatform === 'tistory' ? { platform: 'tistory' } : {} }"
            class="rounded-full border-[1.5px] border-dashed border-ink bg-white px-3.5 py-2 text-sm font-semibold text-ink no-underline hover:text-ink"
          >
            + 카테고리 만들기
          </RouterLink>
        </div>
        <label class="flex items-center gap-2 text-sm font-semibold">
          <input v-model="learnNow" type="checkbox" class="size-4 accent-ink" />
          추가하고 바로 학습하기
        </label>
      </div>

      <p v-if="error" role="alert" class="m-0 rounded-xl border-2 border-ink bg-lemon px-3 py-2 text-sm font-semibold">{{ error }}</p>
      <p v-if="state === 'skipped'" role="status" class="m-0 rounded-xl border-2 border-ink bg-lemon px-3 py-2 text-sm font-semibold">
        이미 {{ chosen?.keyword }}에 있는 글이에요.
      </p>
      <div v-if="state === 'done' && chosen" role="status" class="flex flex-col gap-2 rounded-[18px] border-2 border-ink bg-lilac p-4 text-sm">
        <b>{{ chosen.keyword }}에 추가했어요{{ learnNow ? ` · 곧 학습해요` : '' }}.</b>
        <span v-if="learnNow">다른 글도 이어서 보내면 모아서 한 번만 학습해요(마지막으로 보낸 뒤 약 {{ LEARN_DELAY_SECONDS }}초).</span>
        <span>이 창은 닫아도 돼요. 다른 글도 같은 버튼으로 보내면 이어서 추가돼요.</span>
        <RouterLink :to="{ name: 'project', params: { id: chosen.id } }" class="self-start font-bold">학습 진행 보기 →</RouterLink>
      </div>

      <button
        v-if="state !== 'done'"
        type="button"
        :disabled="!categoryId || !['idle', 'skipped'].includes(state)"
        class="h-14 rounded-[18px] bg-ink text-[17px] font-bold text-cream disabled:opacity-50"
        @click="send"
      >
        {{
          state === 'sending'
            ? '추가하는 중…'
            : state === 'learning'
              ? '학습 예약하는 중…'
              : chosen
                ? `${chosen.keyword}에 추가${learnNow ? '하고 학습' : ''}`
                : '카테고리를 먼저 만들어 주세요'
        }}
      </button>
    </template>
  </section>
</template>
