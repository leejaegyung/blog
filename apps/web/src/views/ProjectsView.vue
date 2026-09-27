<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { projectApi, type Project } from '@/lib/api'
import { validationErrors } from '@/lib/http'
import ProjectStatusBadge from '@/components/ProjectStatusBadge.vue'
import { useUiStore } from '@/stores/ui'

const router = useRouter()
const ui = useUiStore()
ui.crumb = '키워드·참고 글'
ui.saveState = null

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
      : '키워드를 만들지 못했어요.'
  } finally {
    creating.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="grid gap-8 px-5 py-6 lg:min-h-[calc(100dvh-64px)] lg:grid-cols-2 lg:gap-10 lg:px-16 lg:py-12">
    <form
      class="relative flex flex-col gap-[18px] overflow-hidden rounded-[26px] border-2 border-ink bg-lilac p-6 lg:self-start lg:rounded-[28px] lg:p-11"
      @submit.prevent="create"
    >
      <span class="text-[13px] font-bold">키워드·참고 글 관리</span>
      <h1 class="m-0 font-display text-[32px] leading-[1.1] font-normal lg:text-[44px] lg:leading-[1.05]">
        어떤 키워드를<br />모아 볼까요?
      </h1>
      <p class="m-0 max-w-[420px] text-sm leading-normal lg:text-base">
        키워드마다 잘 쓴 글을 모아 구성·사진 배치를 분석해 두면, 그 키워드로 글을 쓸 때 참고해요.
      </p>
      <div class="flex flex-col gap-2 rounded-[20px] border-2 border-ink bg-white p-2 sm:flex-row sm:items-center sm:pl-5">
        <input
          v-model="keyword"
          required
          maxlength="100"
          placeholder="키워드 · 예: 수원 인계동 파스타"
          aria-label="키워드"
          class="min-w-0 bg-transparent px-3 py-2 text-base outline-none placeholder:text-muted sm:flex-[2_1_10rem] sm:px-0"
        />
        <input
          v-model="category"
          maxlength="50"
          placeholder="카테고리 (선택)"
          aria-label="카테고리"
          class="min-w-0 border-t-[1.5px] border-line bg-transparent px-3 py-2 text-base outline-none placeholder:text-muted sm:flex-[1_1_6rem] sm:border-t-0 sm:border-l-[1.5px]"
        />
        <button
          type="submit"
          :disabled="creating"
          class="h-12 rounded-[14px] bg-ink px-6 text-base font-bold whitespace-nowrap text-cream disabled:opacity-50"
        >
          {{ creating ? '만드는 중…' : '만들기 →' }}
        </button>
      </div>
      <p v-if="createError" role="alert" class="m-0 text-sm font-semibold text-red-700">{{ createError }}</p>
    </form>

    <div class="flex flex-col gap-1">
      <h2 class="m-0 pb-1.5 text-[13px] font-bold lg:pb-2.5 lg:text-sm">키워드</h2>
      <p v-if="loading" class="text-sub">불러오는 중…</p>
      <p v-else-if="loadError" class="text-red-600">
        목록을 불러오지 못했어요.
        <button type="button" class="font-bold underline" @click="load">다시 시도</button>
      </p>
      <p v-else-if="projects.length === 0" class="rounded-[18px] border-[1.5px] border-line bg-white p-5 text-sub">
        아직 키워드가 없어요. 왼쪽에서 키워드를 넣어 시작하세요.
      </p>
      <RouterLink
        v-for="project in projects"
        :key="project.id"
        :to="{ name: 'project', params: { id: project.id } }"
        class="flex items-center gap-3 border-b border-[#eadbee] py-3.5 text-ink no-underline hover:text-ink lg:mb-1.5 lg:gap-4 lg:rounded-[18px] lg:border-[1.5px] lg:border-line lg:bg-white lg:px-5 lg:py-[18px] lg:hover:border-ink"
      >
        <div class="flex min-w-0 flex-1 flex-col gap-2">
          <span class="flex min-w-0 items-center gap-2">
            <span class="truncate text-[15px] font-bold lg:text-[17px]">{{ project.keyword }}</span>
            <ProjectStatusBadge :status="project.status" />
          </span>
          <span class="text-xs text-sub lg:text-[13px]">
            {{ project.category ? `${project.category} · ` : '' }}참고 글 {{ project.reference_count ?? 0 }} · 글 {{ project.post_count ?? 0 }}
          </span>
        </div>
        <span class="rounded-xl bg-lilac-soft px-3 py-2 text-[13px] font-bold whitespace-nowrap lg:px-4 lg:py-2.5 lg:text-sm">열기</span>
      </RouterLink>
    </div>
  </section>
</template>
