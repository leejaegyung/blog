<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { postApi, type Post } from '@/lib/api'
import { STEP_NAMES, relativeDate, resumeStep } from '@/lib/flow'
import { useUiStore } from '@/stores/ui'

const router = useRouter()
const ui = useUiStore()
ui.crumb = null
ui.saveState = null

const posts = ref<Post[]>([])
const loading = ref(true)
const keyword = ref('')

type Row = { post: Post; label: string; pct: number; done: boolean; step: number }

function row(post: Post): Row {
  const done = post.status === 'published' || !!post.published_url || !!post.tistory_url
  const step = resumeStep(post)
  return {
    post,
    step,
    done,
    label: done ? '게시 완료' : `${step}/6 ${STEP_NAMES[step - 1]}`,
    pct: done ? 100 : Math.round((step / 6) * 100),
  }
}

const confirmId = ref<number | null>(null)
const deleting = ref(false)
const deleteError = ref<string | null>(null)

async function remove(post: Post) {
  deleting.value = true
  deleteError.value = null
  try {
    await postApi.remove(post.id)
    posts.value = posts.value.filter((p) => p.id !== post.id)
    confirmId.value = null
  } catch {
    deleteError.value = '글을 지우지 못했어요.'
  } finally {
    deleting.value = false
  }
}

function start() {
  router.push({ name: 'write-start', query: keyword.value.trim() ? { keyword: keyword.value.trim() } : {} })
}

onMounted(async () => {
  posts.value = await postApi.list()
  loading.value = false
})
</script>

<template>
  <section class="grid gap-8 px-5 py-6 lg:min-h-[calc(100dvh-64px)] lg:grid-cols-2 lg:gap-10 lg:px-16 lg:py-12">
    <div class="relative flex flex-col gap-[18px] overflow-hidden rounded-[26px] border-2 border-ink bg-lilac p-6 lg:rounded-[28px] lg:p-11">
      <h1 class="m-0 font-display text-[32px] leading-[1.1] font-normal lg:text-[52px] lg:leading-[1.05]">
        오늘은 어떤<br />글을 쓸까요?
      </h1>
      <p class="m-0 max-w-[420px] text-sm leading-normal lg:text-[17px]">
        키워드, 사진, 알려줄 내용만 있으면 6단계로 네이버·티스토리 블로그 초안을 만들어 드려요.
      </p>
      <!-- "시작하기"와 같은 모양의 바로가기: 관리 › 카테고리별 학습 -->
      <RouterLink
        :to="{ name: 'projects' }"
        class="flex h-[52px] items-center gap-2 self-start rounded-[14px] bg-ink px-6 text-base font-bold text-cream no-underline hover:text-cream"
      >
        <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
          <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" />
          <path d="M9 7h7M9 11h5" />
        </svg>
        키워드·카테고리 학습 →
      </RouterLink>
      <RouterLink
        :to="{ name: 'write-start' }"
        class="flex h-14 items-center justify-center rounded-[18px] bg-ink text-[17px] font-bold text-cream no-underline hover:text-cream lg:hidden"
      >
        + 새 글 쓰기
      </RouterLink>
      <form
        class="mt-auto hidden items-center gap-2 rounded-[20px] border-2 border-ink bg-white py-2 pr-2 pl-5 lg:flex"
        @submit.prevent="start"
      >
        <input
          v-model="keyword"
          aria-label="키워드"
          placeholder="키워드 · 예: 수원 인계동 파스타"
          class="min-w-0 flex-1 bg-transparent text-[17px] outline-none placeholder:text-muted"
        />
        <button type="submit" class="h-[52px] rounded-[14px] bg-ink px-6 text-base font-bold text-cream">
          시작하기 →
        </button>
      </form>
      <div
        class="pointer-events-none absolute top-[-4px] right-[-110px] hidden rotate-[22deg] border-y-2 border-ink bg-lemon px-[90px] py-1.5 font-display text-base whitespace-nowrap lg:block"
        aria-hidden="true"
      >
        6단계면 끝
      </div>
    </div>

    <div class="flex flex-col gap-1">
      <h2 class="m-0 pb-1.5 text-[13px] font-bold lg:pb-2.5 lg:text-sm">쓰던 글</h2>
      <p v-if="loading" class="text-sub">불러오는 중…</p>
      <p v-else-if="!posts.length" class="rounded-[18px] border-[1.5px] border-line bg-white p-5 text-sub">
        아직 쓴 글이 없어요. 왼쪽에서 첫 글을 시작해 보세요.
      </p>
      <div
        v-for="r in posts.map(row)"
        :key="r.post.id"
        class="flex items-center gap-2 border-b border-[#eadbee] lg:mb-1.5 lg:rounded-[18px] lg:border-[1.5px] lg:border-line lg:bg-white lg:pr-3 lg:hover:border-ink"
      >
        <!-- 지우기 전에 카드 안에서 한 번 더 확인한다(브라우저 확인 창은 쓰지 않는다) -->
        <div v-if="confirmId === r.post.id" class="flex flex-1 flex-wrap items-center gap-2 py-3.5 lg:px-5 lg:py-[18px]" role="alert">
          <span class="min-w-0 flex-1 text-sm">
            <b>{{ r.post.keyword || r.post.title || '제목 없음' }}</b> 글을 지울까요? 사진도 함께 지워지고 되돌릴 수 없어요.
          </span>
          <button type="button" :disabled="deleting" class="rounded-xl bg-ink px-3 py-2 text-[13px] font-bold text-cream disabled:opacity-50" @click="remove(r.post)">
            {{ deleting ? '지우는 중…' : '지우기' }}
          </button>
          <button type="button" class="rounded-xl border-[1.5px] border-ink px-3 py-2 text-[13px] font-bold" @click="confirmId = null">취소</button>
        </div>
        <template v-else>
          <RouterLink
            :to="{ name: 'flow', params: { id: r.post.id, step: r.step } }"
            class="flex min-w-0 flex-1 items-center gap-3 py-3.5 text-ink no-underline hover:text-ink lg:gap-4 lg:py-[18px] lg:pl-5"
          >
            <div class="flex min-w-0 flex-1 flex-col gap-2">
              <span class="flex min-w-0 items-center gap-2">
                <span class="truncate text-[15px] font-bold lg:text-[17px]">{{ r.post.keyword || r.post.title || '제목 없음' }}</span>
                <span v-if="r.post.platform === 'tistory'" class="shrink-0 rounded-full bg-[#ff5a4a] px-2 py-0.5 text-[11px] font-bold text-white">티스토리</span>
              </span>
              <div class="flex items-center gap-2.5">
                <div class="h-[5px] w-[84px] shrink-0 overflow-hidden rounded-[3px] bg-track lg:h-1.5 lg:w-[140px]" aria-hidden="true">
                  <div class="h-full bg-ink" :style="{ width: `${r.pct}%` }" />
                </div>
                <span class="text-xs text-sub lg:text-[13px]">{{ r.label }}<span class="hidden lg:inline"> · {{ relativeDate(r.post.updated_at) }}</span></span>
              </div>
            </div>
            <span
              :class="r.done ? 'bg-lilac-soft' : 'bg-lemon'"
              class="rounded-xl px-3 py-2 text-[13px] font-bold whitespace-nowrap lg:px-4 lg:py-2.5 lg:text-sm"
            >
              {{ r.done ? '보기' : '이어서' }}
            </span>
          </RouterLink>
          <button
            type="button"
            :aria-label="`${r.post.keyword || r.post.title || '제목 없음'} 글 삭제`"
            title="삭제"
            class="flex size-9 shrink-0 items-center justify-center rounded-xl text-muted hover:bg-lilac-soft hover:text-ink"
            @click="confirmId = r.post.id"
          >
            ✕
          </button>
        </template>
      </div>
      <p v-if="deleteError" role="alert" class="text-sm text-red-600">{{ deleteError }}</p>
    </div>
  </section>
</template>
