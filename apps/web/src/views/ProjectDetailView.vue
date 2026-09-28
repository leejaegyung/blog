<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { AxiosError } from 'axios'
import { postApi, projectApi, type Post, type Project } from '@/lib/api'
import ProjectStatusBadge from '@/components/ProjectStatusBadge.vue'
import ReferencesPanel from '@/components/ReferencesPanel.vue'
import AnalysisPanel from '@/components/AnalysisPanel.vue'
import { relativeDate } from '@/lib/flow'
import { useUiStore } from '@/stores/ui'

const props = defineProps<{ id: number }>()
const router = useRouter()
const ui = useUiStore()
ui.saveState = null

const project = ref<Project | null>(null)
const notFound = ref(false)
const confirmingDelete = ref(false)
const deleting = ref(false)
const posts = ref<Post[]>([])
const creatingPost = ref(false)
const createPostError = ref(false)

onMounted(async () => {
  try {
    project.value = await projectApi.get(props.id)
    // 학습 카테고리는 그 카테고리를 고른 키워드들로 쓴 글을 보여 준다
    posts.value = project.value.kind === 'category' ? await postApi.list(undefined, props.id) : await postApi.list(props.id)
    ui.crumb = project.value.keyword
  } catch (e) {
    if (e instanceof AxiosError && [403, 404].includes(e.response?.status ?? 0)) notFound.value = true
    else throw e
  }
})

const isCategory = computed(() => project.value?.kind === 'category')

async function newPost() {
  if (isCategory.value) {
    // 글쓰기 1단계에서 이 카테고리가 골라진 채로 시작한다
    await router.push({ name: 'write-start', query: { category: String(props.id) } })
    return
  }
  creatingPost.value = true
  createPostError.value = false
  try {
    const post = await postApi.create(props.id)
    await router.push({ name: 'flow', params: { id: post.id, step: 2 } })
  } catch {
    createPostError.value = true
  } finally {
    creatingPost.value = false
  }
}

const confirmPostId = ref<number | null>(null)
const deletingPost = ref(false)

async function removePost(post: Post) {
  deletingPost.value = true
  try {
    await postApi.remove(post.id)
    posts.value = posts.value.filter((p) => p.id !== post.id)
    if (project.value) project.value.post_count = Math.max(0, (project.value.post_count ?? 1) - 1)
    confirmPostId.value = null
  } finally {
    deletingPost.value = false
  }
}

async function remove() {
  deleting.value = true
  try {
    await projectApi.remove(props.id)
    await router.replace({ name: 'projects' })
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <p v-if="notFound" class="p-8 text-sub">찾을 수 없어요.</p>
  <p v-else-if="!project" class="p-8 text-sub">불러오는 중…</p>

  <div v-else class="grid lg:min-h-[calc(100dvh-64px)] lg:grid-cols-[minmax(0,1fr)_320px]">
    <section class="flex min-w-0 flex-col gap-6 px-5 pt-6 pb-10 lg:px-12 lg:pt-10">
      <div class="flex flex-col gap-2">
        <RouterLink :to="{ name: 'projects' }" class="text-[13px] font-bold text-accent no-underline">← {{ isCategory ? '카테고리별 학습' : '키워드' }}</RouterLink>
        <div class="flex flex-wrap items-center gap-3">
          <h1 class="m-0 font-display text-[32px] leading-[1.1] font-normal lg:text-[40px]">{{ project.keyword }}</h1>
          <ProjectStatusBadge :status="project.status" :learning="isCategory" />
        </div>
        <p class="m-0 text-[15px] text-body lg:text-base">
          <template v-if="isCategory">잘 쓴 글 URL을 넣고 “학습하기”를 누르면 글 구성·사진 배치·해시태그를 학습해요. 글쓰기 1단계에서 이 카테고리를 고르면 반영돼요.</template>
          <template v-else>{{ project.category ? `${project.category} · ` : '' }}잘 쓴 글을 모아 두면 이 키워드로 글을 쓸 때 구성·사진 배치를 참고해요.</template>
        </p>
      </div>

      <ReferencesPanel :project-id="project.id" :learning="isCategory" @changed="(count) => project && (project.reference_count = count)" />

      <AnalysisPanel
        :project-id="project.id"
        :reference-count="project.reference_count ?? 0"
        :custom-hashtags="project.hashtags"
        :learning="isCategory"
        @status="(status) => project && (project.status = status)"
        @hashtags-saved="(saved) => project && (project.hashtags = saved.hashtags)"
      />

      <section class="flex flex-col gap-2.5">
        <h2 class="m-0 text-sm font-bold">{{ isCategory ? '이 카테고리로 쓴 글' : '이 키워드로 쓴 글' }}</h2>
        <p v-if="posts.length === 0" class="rounded-[18px] border-[1.5px] border-line bg-white p-5 text-sub">아직 쓴 글이 없어요.</p>
        <div
          v-for="post in posts"
          :key="post.id"
          class="flex items-center gap-2 rounded-[18px] border-[1.5px] border-line bg-white pr-3 hover:border-ink"
        >
          <div v-if="confirmPostId === post.id" class="flex flex-1 flex-wrap items-center gap-2 px-5 py-4" role="alert">
            <span class="min-w-0 flex-1 text-sm"><b>{{ post.title || '제목 없음' }}</b> 글을 지울까요? 사진도 함께 지워져요.</span>
            <button type="button" :disabled="deletingPost" class="rounded-xl bg-ink px-3 py-2 text-[13px] font-bold text-cream disabled:opacity-50" @click="removePost(post)">지우기</button>
            <button type="button" class="rounded-xl border-[1.5px] border-ink px-3 py-2 text-[13px] font-bold" @click="confirmPostId = null">취소</button>
          </div>
          <template v-else>
            <RouterLink
              :to="{ name: 'post', params: { id: post.id } }"
              class="flex min-w-0 flex-1 items-center gap-4 py-4 pl-5 text-ink no-underline hover:text-ink"
            >
              <span class="min-w-0 flex-1 truncate font-bold">{{ post.title || '제목 없음' }}</span>
              <span class="text-[13px] whitespace-nowrap text-sub">사진 {{ post.image_count ?? 0 }} · {{ relativeDate(post.updated_at) }}</span>
              <span class="rounded-xl bg-lemon px-3 py-2 text-[13px] font-bold">이어서</span>
            </RouterLink>
            <button
              type="button"
              :aria-label="`${post.title || '제목 없음'} 글 삭제`"
              title="삭제"
              class="flex size-9 shrink-0 items-center justify-center rounded-xl text-muted hover:bg-lilac-soft hover:text-ink"
              @click="confirmPostId = post.id"
            >
              ✕
            </button>
          </template>
        </div>
      </section>
    </section>

    <aside class="flex flex-col gap-3.5 border-line bg-panel px-5 py-7 lg:border-l-[1.5px] lg:px-[22px]">
      <div class="flex items-center gap-3.5 rounded-[18px] border-2 border-ink bg-lilac p-4">
        <span class="font-display text-[44px] leading-none">{{ project.reference_count ?? 0 }}</span>
        <div class="flex flex-col gap-0.5">
          <span class="text-sm font-bold">{{ isCategory ? '학습한 글' : '참고 글' }}</span>
          <span class="text-xs">최대 50개</span>
        </div>
      </div>
      <div class="flex flex-col gap-1.5 rounded-[14px] border-[1.5px] border-line bg-white p-3.5">
        <span class="text-sm font-bold">작성한 글</span>
        <span class="text-lg font-bold tabular-nums">{{ project.post_count ?? 0 }}</span>
      </div>
      <div class="flex flex-col gap-1.5 rounded-[14px] border-[1.5px] border-line bg-white p-3.5">
        <span class="text-sm font-bold">{{ isCategory ? '마지막 학습' : '마지막 분석' }}</span>
        <span class="text-[13px] text-sub">{{ project.last_analyzed_at ? new Date(project.last_analyzed_at).toLocaleString('ko-KR') : '아직 없어요' }}</span>
      </div>
      <button
        type="button"
        :disabled="creatingPost"
        class="h-[52px] rounded-2xl bg-ink px-6 text-base font-bold text-cream disabled:opacity-50"
        @click="newPost"
      >
        + 이 {{ isCategory ? '카테고리' : '키워드' }}로 새 글 쓰기
      </button>
      <p v-if="createPostError" role="alert" class="m-0 text-sm text-red-600">글을 만들지 못했어요.</p>

      <div class="flex flex-col gap-2 text-[13px]">
        <button
          v-if="!confirmingDelete"
          type="button"
          class="h-11 rounded-2xl border-[1.5px] border-ink bg-white px-4 text-sm font-bold hover:bg-lemon"
          @click="confirmingDelete = true"
        >
          {{ isCategory ? '카테고리' : '키워드' }} 삭제
        </button>
        <div v-else class="flex flex-col gap-2 rounded-[14px] border-2 border-ink bg-lemon px-3.5 py-3">
          <span>{{ isCategory ? '학습한 글과 학습 결과' : '참고 글과 분석 결과' }}가 함께 지워져요. 작성한 글은 남아요.</span>
          <div class="flex gap-1.5">
            <button type="button" :disabled="deleting" class="rounded-lg bg-ink px-2.5 py-1.5 text-xs font-bold text-cream disabled:opacity-50" @click="remove">
              삭제
            </button>
            <button type="button" class="rounded-lg border-[1.5px] border-ink px-2.5 py-1.5 text-xs font-bold" @click="confirmingDelete = false">
              취소
            </button>
          </div>
        </div>
      </div>
    </aside>
  </div>
</template>
