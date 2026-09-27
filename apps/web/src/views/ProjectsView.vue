<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { projectApi, type Project } from '@/lib/api'
import { validationErrors } from '@/lib/http'
import ProjectStatusBadge from '@/components/ProjectStatusBadge.vue'

const router = useRouter()

const projects = ref<Project[]>([])
const loading = ref(true)
const loadError = ref(false)

const keyword = ref('')
const category = ref('')
const creating = ref(false)
const createError = ref<string | null>(null)

async function load() {
  loading.value = true
  loadError.value = false
  try {
    projects.value = await projectApi.list()
  } catch {
    loadError.value = true
  } finally {
    loading.value = false
  }
}

async function create() {
  creating.value = true
  createError.value = null
  try {
    const project = await projectApi.create({
      keyword: keyword.value,
      category: category.value || null,
    })
    await router.push({ name: 'project', params: { id: project.id } })
  } catch (e) {
    const errors = validationErrors(e)
    createError.value = errors
      ? (errors.keyword?.[0] ?? errors.category?.[0] ?? '입력값을 확인해 주세요.')
      : '프로젝트를 만들지 못했습니다.'
  } finally {
    creating.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="space-y-8">
    <form
      class="space-y-3 rounded-xl border border-stone-200 bg-white p-5"
      @submit.prevent="create"
    >
      <h1 class="text-lg font-semibold">새 키워드 프로젝트</h1>
      <div class="flex flex-wrap gap-2">
        <input
          v-model="keyword"
          required
          maxlength="100"
          placeholder="예: 수원 인계동 파스타"
          aria-label="키워드"
          class="min-w-0 flex-[2_1_14rem] rounded-md border border-stone-300 px-3 py-2"
        />
        <input
          v-model="category"
          maxlength="50"
          placeholder="카테고리 (선택)"
          aria-label="카테고리"
          class="min-w-0 flex-[1_1_8rem] rounded-md border border-stone-300 px-3 py-2"
        />
        <button
          type="submit"
          :disabled="creating"
          class="rounded-md bg-stone-900 px-4 py-2 font-medium text-white disabled:opacity-50"
        >
          {{ creating ? '만드는 중…' : '만들기' }}
        </button>
      </div>
      <p v-if="createError" role="alert" class="text-sm text-red-600">{{ createError }}</p>
    </form>

    <div>
      <h2 class="mb-3 text-lg font-semibold">프로젝트</h2>
      <p v-if="loading" class="text-stone-500">불러오는 중…</p>
      <p v-else-if="loadError" class="text-red-600">
        목록을 불러오지 못했습니다.
        <button type="button" class="underline" @click="load">다시 시도</button>
      </p>
      <p v-else-if="projects.length === 0" class="text-stone-500">
        아직 프로젝트가 없습니다. 위에서 키워드를 입력해 시작하세요.
      </p>
      <ul v-else class="divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
        <li v-for="project in projects" :key="project.id">
          <RouterLink
            :to="{ name: 'project', params: { id: project.id } }"
            class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-4 hover:bg-stone-50"
          >
            <span class="font-medium">{{ project.keyword }}</span>
            <span v-if="project.category" class="text-sm text-stone-500">{{
              project.category
            }}</span>
            <ProjectStatusBadge :status="project.status" />
            <span class="ml-auto text-sm text-stone-500">
              참고자료 {{ project.reference_count ?? 0 }} · 글 {{ project.post_count ?? 0 }}
            </span>
          </RouterLink>
        </li>
      </ul>
    </div>
  </section>
</template>
