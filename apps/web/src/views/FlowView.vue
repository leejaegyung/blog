<script setup lang="ts">
import { computed, provide, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { AxiosError } from 'axios'
import { postApi, type Post } from '@/lib/api'
import { STEP_NAMES, type StepNo } from '@/lib/flow'
import { useUiStore } from '@/stores/ui'
import StepList from '@/components/flow/StepList.vue'
import KeywordStep from '@/components/steps/KeywordStep.vue'
import PhotoStep from '@/components/steps/PhotoStep.vue'
import FactsStep from '@/components/steps/FactsStep.vue'
import PlanStep from '@/components/steps/PlanStep.vue'
import DraftStep from '@/components/steps/DraftStep.vue'
import UploadStep from '@/components/steps/UploadStep.vue'

const props = defineProps<{ id?: number; step: StepNo }>()
const router = useRouter()
const ui = useUiStore()

const post = ref<Post | null>(null)
const notFound = ref(false)
const STEPS = { 1: KeywordStep, 2: PhotoStep, 3: FactsStep, 4: PlanStep, 5: DraftStep, 6: UploadStep } as const

async function load() {
  notFound.value = false
  if (!props.id) {
    post.value = null
    ui.crumb = '새 글'
    return
  }
  if (post.value?.id === props.id) return
  try {
    post.value = await postApi.get(props.id)
    ui.crumb = post.value.keyword ?? null
  } catch (e) {
    if (e instanceof AxiosError && [403, 404].includes(e.response?.status ?? 0)) notFound.value = true
    else throw e
  }
}

function update(next: Post) {
  post.value = next
  ui.crumb = next.keyword ?? ui.crumb
}

function go(step: StepNo, id = post.value?.id) {
  router.push({ name: 'flow', params: { id, step } })
}

provide('flow', { update, go })
watch(() => props.id, load, { immediate: true })
watch(() => props.step, () => (ui.saveState = null))

const backTarget = computed(() =>
  props.step > 1 && post.value ? { name: 'flow', params: { id: post.value.id, step: props.step - 1 } } : '/',
)
</script>

<template>
  <div class="grid lg:min-h-[calc(100dvh-64px)] lg:grid-cols-[260px_minmax(0,1fr)]">
    <aside class="hidden border-r-[1.5px] border-line px-5 py-7 lg:block">
      <StepList :post="post" :current="step" />
    </aside>

    <div class="flex min-w-0 flex-col">
      <!-- 모바일: 뒤로 · 글 이름 · N / 6, 6칸 진행 막대 -->
      <div class="px-5 pt-4 lg:hidden">
        <div class="flex items-center justify-between">
          <RouterLink :to="backTarget" class="text-[22px] text-ink no-underline" :aria-label="step > 1 ? '이전 단계' : '닫기'">
            {{ step > 1 ? '←' : '✕' }}
          </RouterLink>
          <span class="truncate px-3 text-sm font-semibold text-sub">{{ post?.keyword ?? '새 글' }}</span>
          <span class="text-[13px] font-bold">{{ step }} / 6</span>
        </div>
        <div class="grid grid-cols-6 gap-[5px] pt-3.5" aria-hidden="true">
          <div v-for="n in 6" :key="n" :class="n <= step ? 'bg-ink' : 'bg-track'" class="h-1.5 rounded-[3px]" />
        </div>
        <p class="sr-only">{{ step }}단계 · {{ STEP_NAMES[step - 1] }}</p>
      </div>

      <p v-if="notFound" class="p-8 text-sub">글을 찾을 수 없습니다.</p>
      <p v-else-if="id && !post" class="p-8 text-sub">불러오는 중…</p>
      <component :is="STEPS[step]" v-else :key="step" :post="post" class="flex-1" />
    </div>
  </div>
</template>
