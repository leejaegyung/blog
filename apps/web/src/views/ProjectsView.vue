<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { projectApi, type Project } from '@/lib/api'
import { validationErrors } from '@/lib/http'
import ProjectStatusBadge from '@/components/ProjectStatusBadge.vue'
import { useUiStore } from '@/stores/ui'
import SendButtonCard from '@/components/SendButtonCard.vue'

const router = useRouter()
const ui = useUiStore()
ui.crumb = '카테고리별 학습'
ui.saveState = null

const projects = ref<Project[]>([])
const loading = ref(true)
const loadError = ref(false)

const keyword = ref('')
const creating = ref(false)
const createError = ref<string | null>(null)

async function load() {
  loading.value = true
  loadError.value = false
  try {
    projects.value = await projectApi.list('category')
  } catch {
    loadError.value = true
  } finally {
    loading.value = false
  }
}

const confirmId = ref<number | null>(null)
const deleting = ref(false)
const deleteError = ref<string | null>(null)

async function remove(project: Project) {
  deleting.value = true
  deleteError.value = null
  try {
    await projectApi.remove(project.id)
    projects.value = projects.value.filter((p) => p.id !== project.id)
    confirmId.value = null
  } catch {
    deleteError.value = '카테고리를 지우지 못했어요.'
  } finally {
    deleting.value = false
  }
}

async function create() {
  creating.value = true
  createError.value = null
  try {
    const project = await projectApi.create({ keyword: keyword.value, kind: 'category' })
    await router.push({ name: 'project', params: { id: project.id } })
  } catch (e) {
    const errors = validationErrors(e)
    createError.value = errors
      ? (errors.keyword?.[0] ?? '입력값을 확인해 주세요.')
      : '카테고리를 만들지 못했어요.'
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
      <span class="text-[13px] font-bold">카테고리별 학습</span>
      <h1 class="m-0 font-display text-[32px] leading-[1.1] font-normal lg:text-[44px] lg:leading-[1.05]">
        어떤 카테고리를<br />학습시킬까요?
      </h1>
      <p class="m-0 max-w-[420px] text-sm leading-normal lg:text-base">
        카테고리를 만들고 잘 쓴 글 URL을 넣어 학습시키면, 글쓰기에서 그 카테고리를 골랐을 때 글 구성·사진 배치·해시태그에 반영해요.
      </p>
      <div class="flex flex-col gap-2 rounded-[20px] border-2 border-ink bg-white p-2 sm:flex-row sm:items-center sm:pl-5">
        <input
          v-model="keyword"
          required
          maxlength="100"
          placeholder="카테고리 이름 · 예: 맛집, 카페, 제품 리뷰"
          aria-label="카테고리 이름"
          class="min-w-0 flex-1 bg-transparent px-3 py-2 text-base outline-none placeholder:text-muted sm:px-0"
        />
        <button
          type="submit"
          :disabled="creating"
          class="h-12 rounded-[14px] bg-ink px-6 text-base font-bold whitespace-nowrap text-cream disabled:opacity-50"
        >
          {{ creating ? '만드는 중…' : '카테고리 만들기 →' }}
        </button>
      </div>
      <p v-if="createError" role="alert" class="m-0 text-sm font-semibold text-red-700">{{ createError }}</p>
    </form>

    <div class="flex flex-col gap-1">
      <SendButtonCard class="mb-5" />
      <h2 class="m-0 pb-1.5 text-[13px] font-bold lg:pb-2.5 lg:text-sm">학습 카테고리</h2>
      <p v-if="loading" class="text-sub">불러오는 중…</p>
      <p v-else-if="loadError" class="text-red-600">
        목록을 불러오지 못했어요.
        <button type="button" class="font-bold underline" @click="load">다시 시도</button>
      </p>
      <p v-else-if="projects.length === 0" class="rounded-[18px] border-[1.5px] border-line bg-white p-5 text-sub">
        아직 카테고리가 없어요. 왼쪽에서 카테고리를 만들어 시작하세요.
      </p>
      <div
        v-for="project in projects"
        :key="project.id"
        class="flex items-center gap-2 border-b border-[#eadbee] lg:mb-1.5 lg:rounded-[18px] lg:border-[1.5px] lg:border-line lg:bg-white lg:pr-3 lg:hover:border-ink"
      >
        <!-- 지우기 전에 카드 안에서 한 번 더 확인한다(브라우저 확인 창은 쓰지 않는다) -->
        <div v-if="confirmId === project.id" class="flex flex-1 flex-wrap items-center gap-2 py-3.5 lg:px-5 lg:py-[18px]" role="alert">
          <span class="min-w-0 flex-1 text-sm">
            <b>{{ project.keyword }}</b> 카테고리를 지울까요? 학습한 글과 결과가 함께 지워져요. 쓴 글은 남아요.
          </span>
          <button type="button" :disabled="deleting" class="rounded-xl bg-ink px-3 py-2 text-[13px] font-bold text-cream disabled:opacity-50" @click="remove(project)">
            {{ deleting ? '지우는 중…' : '지우기' }}
          </button>
          <button type="button" class="rounded-xl border-[1.5px] border-ink px-3 py-2 text-[13px] font-bold" @click="confirmId = null">취소</button>
        </div>
        <template v-else>
          <RouterLink
            :to="{ name: 'project', params: { id: project.id } }"
            class="flex min-w-0 flex-1 items-center gap-3 py-3.5 text-ink no-underline hover:text-ink lg:gap-4 lg:py-[18px] lg:pl-5"
          >
            <div class="flex min-w-0 flex-1 flex-col gap-2">
              <span class="flex min-w-0 items-center gap-2">
                <span class="truncate text-[15px] font-bold lg:text-[17px]">{{ project.keyword }}</span>
                <ProjectStatusBadge :status="project.status" learning />
              </span>
              <span class="text-xs text-sub lg:text-[13px]">
                학습한 글 {{ project.reference_count ?? 0 }} · 쓴 글 {{ project.post_count ?? 0 }}
              </span>
            </div>
            <span class="rounded-xl bg-lilac-soft px-3 py-2 text-[13px] font-bold whitespace-nowrap lg:px-4 lg:py-2.5 lg:text-sm">열기</span>
          </RouterLink>
          <button
            type="button"
            :aria-label="`${project.keyword} 카테고리 삭제`"
            title="삭제"
            class="flex size-9 shrink-0 items-center justify-center rounded-xl text-muted hover:bg-lilac-soft hover:text-ink"
            @click="confirmId = project.id"
          >
            ✕
          </button>
        </template>
      </div>
      <p v-if="deleteError" role="alert" class="text-sm text-red-600">{{ deleteError }}</p>
    </div>
  </section>
</template>
