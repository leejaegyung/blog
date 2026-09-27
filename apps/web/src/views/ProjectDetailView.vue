<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { AxiosError } from 'axios'
import { postApi, projectApi, type Post, type Project } from '@/lib/api'
import ProjectStatusBadge from '@/components/ProjectStatusBadge.vue'
import ReferencesPanel from '@/components/ReferencesPanel.vue'
import AnalysisPanel from '@/components/AnalysisPanel.vue'

const props = defineProps<{ id: number }>()
const router = useRouter()

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
    await router.push({ name: 'post-edit', params: { id: post.id } })
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
  <section class="space-y-6">
    <RouterLink :to="{ name: 'projects' }" class="text-sm text-stone-500 hover:underline">
      ← 프로젝트 목록
    </RouterLink>

    <p v-if="notFound" class="text-stone-500">프로젝트를 찾을 수 없습니다.</p>
    <p v-else-if="!project" class="text-stone-500">불러오는 중…</p>

    <template v-else>
      <header class="flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ project.keyword }}</h1>
        <ProjectStatusBadge :status="project.status" />
        <span v-if="project.category" class="text-stone-500">{{ project.category }}</span>
      </header>

      <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-stone-200 bg-white p-4">
          <dt class="text-sm text-stone-500">참고자료</dt>
          <dd class="text-2xl font-semibold">{{ project.reference_count ?? 0 }}</dd>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-4">
          <dt class="text-sm text-stone-500">작성한 글</dt>
          <dd class="text-2xl font-semibold">{{ project.post_count ?? 0 }}</dd>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-4">
          <dt class="text-sm text-stone-500">마지막 분석</dt>
          <dd class="text-lg font-medium">
            {{
              project.last_analyzed_at
                ? new Date(project.last_analyzed_at).toLocaleString('ko-KR')
                : '없음'
            }}
          </dd>
        </div>
      </dl>

      <ReferencesPanel
        :project-id="project.id"
        @changed="(count) => project && (project.reference_count = count)"
      />

      <AnalysisPanel
        :project-id="project.id"
        :reference-count="project.reference_count ?? 0"
        @status="(status) => project && (project.status = status)"
      />

      <section class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 class="text-lg font-semibold">글</h2>
          <button
            type="button"
            :disabled="creatingPost"
            class="rounded-md bg-stone-900 px-4 py-2 font-medium text-white disabled:opacity-50"
            @click="newPost"
          >
            새 글 작성
          </button>
        </div>
        <p v-if="createPostError" role="alert" class="text-sm text-red-600">
          글을 만들지 못했습니다.
        </p>
        <p v-if="posts.length === 0" class="text-stone-500">아직 작성한 글이 없습니다.</p>
        <ul v-else class="divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
          <li v-for="post in posts" :key="post.id">
            <RouterLink
              :to="{ name: 'post-edit', params: { id: post.id } }"
              class="flex flex-wrap items-center gap-3 px-5 py-3 hover:bg-stone-50"
            >
              <span class="font-medium">{{ post.title || '제목 없음' }}</span>
              <span class="ml-auto text-sm text-stone-500">
                사진 {{ post.image_count ?? 0 }} ·
                {{ new Date(post.updated_at).toLocaleDateString('ko-KR') }}
              </span>
            </RouterLink>
          </li>
        </ul>
      </section>

      <div class="rounded-xl border border-red-200 bg-white p-4">
        <template v-if="!confirmingDelete">
          <button type="button" class="text-sm text-red-600" @click="confirmingDelete = true">
            프로젝트 삭제
          </button>
        </template>
        <div v-else class="flex flex-wrap items-center gap-3 text-sm">
          <span>참고자료와 분석 결과가 함께 삭제됩니다. 작성한 글은 남습니다.</span>
          <button
            type="button"
            :disabled="deleting"
            class="rounded-md bg-red-600 px-3 py-1.5 font-medium text-white disabled:opacity-50"
            @click="remove"
          >
            삭제
          </button>
          <button type="button" class="text-stone-600" @click="confirmingDelete = false">
            취소
          </button>
        </div>
      </div>
    </template>
  </section>
</template>
