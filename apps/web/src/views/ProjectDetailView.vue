<script setup lang="ts">
import { onMounted, ref } from 'vue'
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
    ;[project.value, posts.value] = await Promise.all([
      projectApi.get(props.id),
      postApi.list(props.id),
    ])
    ui.crumb = project.value.keyword
  } catch (e) {
    if (e instanceof AxiosError && [403, 404].includes(e.response?.status ?? 0)) notFound.value = true
    else throw e
  }
})

async function newPost() {
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
  <p v-if="notFound" class="p-8 text-sub">키워드를 찾을 수 없어요.</p>
  <p v-else-if="!project" class="p-8 text-sub">불러오는 중…</p>

  <div v-else class="grid lg:min-h-[calc(100dvh-64px)] lg:grid-cols-[minmax(0,1fr)_320px]">
    <section class="flex min-w-0 flex-col gap-6 px-5 pt-6 pb-10 lg:px-12 lg:pt-10">
      <div class="flex flex-col gap-2">
        <RouterLink :to="{ name: 'projects' }" class="text-[13px] font-bold text-accent no-underline">← 키워드·참고 글</RouterLink>
        <div class="flex flex-wrap items-center gap-3">
          <h1 class="m-0 font-display text-[32px] leading-[1.1] font-normal lg:text-[40px]">{{ project.keyword }}</h1>
          <ProjectStatusBadge :status="project.status" />
        </div>
        <p class="m-0 text-[15px] text-body lg:text-base">
          {{ project.category ? `${project.category} · ` : '' }}잘 쓴 글을 모아 두면 이 키워드로 글을 쓸 때 구성·사진 배치를 참고해요.
        </p>
      </div>

      <ReferencesPanel :project-id="project.id" @changed="(count) => project && (project.reference_count = count)" />

      <AnalysisPanel
        :project-id="project.id"
        :reference-count="project.reference_count ?? 0"
        :custom-hashtags="project.hashtags"
        @status="(status) => project && (project.status = status)"
        @hashtags-saved="(saved) => project && (project.hashtags = saved.hashtags)"
      />

      <section class="flex flex-col gap-2.5">
        <h2 class="m-0 text-sm font-bold">이 키워드로 쓴 글</h2>
        <p v-if="posts.length === 0" class="rounded-[18px] border-[1.5px] border-line bg-white p-5 text-sub">아직 쓴 글이 없어요.</p>
        <RouterLink
          v-for="post in posts"
          :key="post.id"
          :to="{ name: 'post', params: { id: post.id } }"
          class="flex items-center gap-4 rounded-[18px] border-[1.5px] border-line bg-white px-5 py-4 text-ink no-underline hover:border-ink hover:text-ink"
        >
          <span class="min-w-0 flex-1 truncate font-bold">{{ post.title || '제목 없음' }}</span>
          <span class="text-[13px] whitespace-nowrap text-sub">사진 {{ post.image_count ?? 0 }} · {{ relativeDate(post.updated_at) }}</span>
          <span class="rounded-xl bg-lemon px-3 py-2 text-[13px] font-bold">이어서</span>
        </RouterLink>
      </section>
    </section>

    <aside class="flex flex-col gap-3.5 border-line bg-panel px-5 py-7 lg:border-l-[1.5px] lg:px-[22px]">
      <div class="flex items-center gap-3.5 rounded-[18px] border-2 border-ink bg-lilac p-4">
        <span class="font-display text-[44px] leading-none">{{ project.reference_count ?? 0 }}</span>
        <div class="flex flex-col gap-0.5">
          <span class="text-sm font-bold">참고 글</span>
          <span class="text-xs">최대 50개</span>
        </div>
      </div>
      <div class="flex flex-col gap-1.5 rounded-[14px] border-[1.5px] border-line bg-white p-3.5">
        <span class="text-sm font-bold">작성한 글</span>
        <span class="text-lg font-bold tabular-nums">{{ project.post_count ?? 0 }}</span>
      </div>
      <div class="flex flex-col gap-1.5 rounded-[14px] border-[1.5px] border-line bg-white p-3.5">
        <span class="text-sm font-bold">마지막 분석</span>
        <span class="text-[13px] text-sub">{{ project.last_analyzed_at ? new Date(project.last_analyzed_at).toLocaleString('ko-KR') : '아직 없어요' }}</span>
      </div>
      <button
        type="button"
        :disabled="creatingPost"
        class="h-[52px] rounded-2xl bg-ink px-6 text-base font-bold text-cream disabled:opacity-50"
        @click="newPost"
      >
        + 이 키워드로 새 글 쓰기
      </button>
      <p v-if="createPostError" role="alert" class="m-0 text-sm text-red-600">글을 만들지 못했어요.</p>

      <div class="mt-auto flex flex-col gap-2 pt-6 text-[13px]">
        <button v-if="!confirmingDelete" type="button" class="self-start text-sub underline" @click="confirmingDelete = true">
          키워드 삭제
        </button>
        <div v-else class="flex flex-col gap-2 rounded-[14px] border-2 border-ink bg-lemon px-3.5 py-3">
          <span>참고 글과 분석 결과가 함께 지워져요. 작성한 글은 남아요.</span>
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
