<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { postApi, type PlanSection, type Post } from '@/lib/api'
import { validationErrors } from '@/lib/http'
import StepLayout from '@/components/flow/StepLayout.vue'
import NextButton from '@/components/flow/NextButton.vue'
import PanelCard from '@/components/flow/PanelCard.vue'
import PipelineProgress from '@/components/PipelineProgress.vue'
import { useFlow } from './useFlow'

const props = defineProps<{ post: Post }>()
const flow = useFlow()

const running = computed(() => props.post.pipeline_status === 'running' || props.post.status === 'planning')
const failed = computed(() => !props.post.plan && (props.post.pipeline_status === 'failed' || props.post.status === 'failed'))
const plan = computed(() => props.post.plan)
const images = computed(() => props.post.images ?? [])
const number = computed(() => new Map(images.value.map((image, i) => [image.id, i + 1])))
const thumb = computed(() => new Map(images.value.map((image) => [image.id, image.thumb_url])))

const title = ref(props.post.title ?? '')
const outline = ref<PlanSection[]>([])
const dragging = ref<number | null>(null)
const dirty = ref(false)
const busy = ref(false)
const confirmRewrite = ref(false)
const error = ref<string | null>(null)
let timer: ReturnType<typeof setTimeout> | undefined

watch(
  plan,
  (value) => {
    outline.value = value ? value.outline.map((s) => ({ ...s })) : []
    title.value = props.post.title ?? value?.title_candidates[0] ?? ''
    dirty.value = false
  },
  { immediate: true },
)

function poll() {
  clearTimeout(timer)
  if (!running.value) return
  timer = setTimeout(async () => {
    flow.update(await postApi.get(props.post.id))
    poll()
  }, 2500)
}

// 사진 끌어 놓기(디자인: "아직 안 쓰인 사진 — 목차에 끌어다 놓기"). 한 사진은 한 섹션에만 들어간다
const dragPhoto = ref<number | null>(null)
const unplaced = computed(() => {
  const placed = new Set(outline.value.flatMap((s) => s.image_ids))
  return images.value.filter((image) => !placed.has(image.id))
})

function placePhoto(index: number | null) {
  const id = dragPhoto.value
  dragPhoto.value = null
  if (id === null) return
  if (index !== null && outline.value[index]!.image_ids.includes(id)) return
  outline.value = outline.value.map((section, i) => ({
    ...section,
    image_ids: i === index ? [...section.image_ids.filter((x) => x !== id), id] : section.image_ids.filter((x) => x !== id),
  }))
  dirty.value = true
}

function drop(index: number) {
  if (dragPhoto.value !== null) return placePhoto(index)
  const from = dragging.value
  dragging.value = null
  if (from === null || from === index) return
  const next = [...outline.value]
  const [moved] = next.splice(from, 1)
  next.splice(index, 0, moved!)
  outline.value = next
  dirty.value = true
}

async function remakePlan() {
  error.value = null
  try {
    flow.update(await postApi.autopilot(props.post.id, 'plan'))
    poll()
  } catch (e) {
    error.value = Object.values(validationErrors(e) ?? {})[0]?.[0] ?? '계획을 다시 만들지 못했어요.'
  }
}

async function writeDraft() {
  busy.value = true
  error.value = null
  try {
    if (dirty.value) flow.update(await postApi.savePlan(props.post.id, { title: title.value, outline: outline.value }))
    flow.update(await postApi.generate(props.post.id))
    flow.go(5)
  } catch (e) {
    error.value = Object.values(validationErrors(e) ?? {})[0]?.[0] ?? '초안 쓰기를 시작하지 못했어요.'
  } finally {
    busy.value = false
  }
}

async function next() {
  if (props.post.content && !dirty.value) return flow.go(5)
  if (props.post.content && !confirmRewrite.value) {
    confirmRewrite.value = true
    return
  }
  await writeDraft()
}

onMounted(poll)
watch(running, poll)
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <StepLayout v-if="running || failed" :step="4" :title="failed ? '글 계획을 만들지 못했어요' : '글 계획을 만들고 있어요'">
    <PipelineProgress :post="post" :steps="['vision', 'analysis', 'plan']" @retry="remakePlan" />
    <template #back>
      <button type="button" class="hover:text-accent" @click="flow.go(3)">← 알려줄 내용</button>
    </template>
    <template #next><span /></template>
  </StepLayout>

  <StepLayout v-else-if="plan" :step="4" :title="'제목과 목차를\n골라주세요'">
    <p v-if="post.plan_stale" role="status" class="rounded-[14px] border-2 border-ink bg-lemon px-4 py-3 text-sm">
      계획을 만든 뒤 사진이나 알려줄 내용이 바뀌었어요. 아래 "계획 다시 만들기"를 눌러 주세요.
    </p>
    <fieldset class="flex flex-col gap-1.5 lg:gap-2">
      <legend class="sr-only">제목</legend>
      <label
        v-for="candidate in plan.title_candidates"
        :key="candidate"
        :class="title === candidate ? 'border-2 border-ink' : 'border-[1.5px] border-line'"
        class="flex cursor-pointer items-center gap-3 rounded-[14px] bg-white px-3 py-[11px] lg:px-4 lg:py-[13px]"
      >
        <input v-model="title" type="radio" name="title" :value="candidate" class="sr-only" @change="dirty = true" />
        <span :class="title === candidate ? 'bg-lilac' : 'bg-white'" class="size-4 shrink-0 rounded-full border-2 border-ink lg:size-[18px]" aria-hidden="true" />
        <span :class="title === candidate ? 'font-bold' : 'font-medium'" class="text-sm leading-snug lg:text-base">{{ candidate }}</span>
      </label>
    </fieldset>

    <div class="flex flex-col gap-1.5 lg:gap-2">
      <span class="text-[13px] font-bold lg:hidden">목차 · 끌어서 순서 바꾸기</span>
      <div
        v-for="(section, index) in outline"
        :key="section.heading + index"
        draggable="true"
        :class="dragging === index ? 'opacity-40' : ''"
        class="flex items-center gap-2.5 rounded-xl border-[1.5px] border-line bg-white px-3 py-[9px] lg:gap-3.5 lg:rounded-[14px] lg:px-4 lg:py-[11px]"
        @dragstart="dragging = index"
        @dragend="dragging = null"
        @dragover.prevent
        @drop.prevent="drop(index)"
      >
        <span class="w-4 font-display text-[17px] text-lilac-mid lg:w-[22px] lg:text-xl">{{ index + 1 }}</span>
        <div class="flex min-w-0 flex-1 flex-col gap-0.5">
          <input
            v-model="section.heading"
            maxlength="100"
            :aria-label="`${index + 1}번 소제목`"
            class="min-w-0 bg-transparent text-sm font-bold outline-none focus:underline lg:text-[15px]"
            @input="dirty = true"
          />
          <span class="hidden text-[13px] text-sub lg:block">{{ section.purpose }}</span>
        </div>
        <div class="hidden gap-1 lg:flex">
          <span
            v-for="id in section.image_ids"
            :key="id"
            draggable="true"
            class="relative size-10 cursor-grab overflow-hidden rounded-lg bg-lilac-soft"
            :title="`${number.get(id)}번 사진 — 다른 목차로 끌어 옮기기`"
            @dragstart.stop="dragPhoto = id"
            @dragend="dragPhoto = null"
          >
            <img :src="thumb.get(id)" :alt="`사진 ${number.get(id)}`" class="size-full object-cover" draggable="false" />
            <span class="absolute top-0.5 left-0.5 rounded bg-ink px-1 text-[10px] leading-tight font-bold text-cream">{{ number.get(id) }}</span>
          </span>
        </div>
        <span v-if="section.image_ids.length" class="text-xs text-sub lg:hidden">사진 {{ section.image_ids.length }}</span>
        <span class="cursor-grab text-sm text-muted lg:text-lg" aria-hidden="true">⋮⋮</span>
      </div>
    </div>
    <div v-if="plan.forbidden_claims.length" class="rounded-[14px] border-2 border-ink bg-lemon px-3 py-2.5 text-[13px] leading-snug lg:hidden">
      <b>쓰지 않을 내용</b> · {{ plan.forbidden_claims.join(', ') }}
    </div>
    <p v-if="confirmRewrite" role="alert" class="rounded-[14px] border-2 border-ink bg-lemon px-4 py-3 text-sm">
      지금 초안을 이 계획으로 새로 씁니다. 다듬은 내용은 사라져요. 한 번 더 누르면 시작합니다.
    </p>
    <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>

    <template #aside>
      <span class="text-[13px] font-bold">AI가 지킬 것</span>
      <PanelCard v-if="plan.forbidden_claims.length" tone="lemon" desktop-only>
        <span class="text-sm font-bold">쓰지 않을 내용</span>
        <span v-for="claim in plan.forbidden_claims" :key="claim">{{ claim }}</span>
      </PanelCard>
      <div
        v-if="unplaced.length || dragPhoto !== null"
        :class="dragPhoto !== null ? 'border-ink border-dashed' : 'border-line'"
        class="flex flex-col gap-1.5 rounded-[14px] border-[1.5px] bg-white p-3.5 text-[13px]"
        @dragover.prevent
        @drop.prevent="placePhoto(null)"
      >
        <span class="text-sm font-bold">아직 안 쓰인 사진</span>
        <span v-if="unplaced.length" class="text-sub">{{ unplaced.map((i) => `${number.get(i.id)}번`).join(', ') }} — 목차에 끌어다 놓기</span>
        <span v-else class="text-sub">여기에 놓으면 글에서 빼요</span>
        <div v-if="unplaced.length" class="hidden flex-wrap gap-1 pt-1 lg:flex">
          <span
            v-for="image in unplaced"
            :key="image.id"
            draggable="true"
            class="relative size-10 cursor-grab overflow-hidden rounded-lg bg-lilac-soft"
            @dragstart="dragPhoto = image.id"
            @dragend="dragPhoto = null"
          >
            <img :src="image.thumb_url" :alt="`사진 ${number.get(image.id)}`" class="size-full object-cover" draggable="false" />
            <span class="absolute top-0.5 left-0.5 rounded bg-ink px-1 text-[10px] leading-tight font-bold text-cream">{{ number.get(image.id) }}</span>
          </span>
        </div>
      </div>
      <PanelCard v-if="plan.keywords.secondary.length">
        <span class="text-sm font-bold">함께 쓸 표현</span>
        <div class="flex flex-wrap gap-1">
          <span v-for="word in plan.keywords.secondary" :key="word" class="rounded-full bg-lilac-soft px-2.5 py-1 text-xs">{{ word }}</span>
        </div>
      </PanelCard>
      <PanelCard v-if="post.recommended_hashtags?.length">
        <span class="text-sm font-bold">달 해시태그 {{ post.recommended_hashtags.length }}개</span>
        <div class="flex flex-wrap gap-1">
          <span v-for="tag in post.recommended_hashtags.slice(0, 12)" :key="tag" class="rounded-full bg-lilac px-2.5 py-1 text-xs font-semibold">#{{ tag }}</span>
          <span v-if="post.recommended_hashtags.length > 12" class="px-1 py-1 text-xs text-sub">외 {{ post.recommended_hashtags.length - 12 }}개</span>
        </div>
        <span class="text-sub">
          초안을 쓰면 자동으로 달아요.
          <RouterLink v-if="post.keyword_project_id" :to="{ name: 'project', params: { id: post.keyword_project_id } }" class="font-bold">키워드에서 고치기</RouterLink>
        </span>
      </PanelCard>
      <span class="text-xs leading-normal text-sub">검색 의도 · {{ plan.search_intent }}</span>
    </template>

    <template #back>
      <button type="button" class="hover:text-accent" @click="flow.go(3)">← 알려줄 내용</button>
    </template>
    <template #next>
      <button type="button" class="h-8 text-sm text-sub hover:text-ink" @click="remakePlan">계획 다시 만들기</button>
      <NextButton :busy="busy" @click="next">
        {{ busy ? '시작하는 중…' : '이 계획으로 초안 쓰기 →' }}
      </NextButton>
    </template>
  </StepLayout>

  <StepLayout v-else :step="4" title="아직 글 계획이 없어요">
    <p class="text-sub">알려줄 내용을 적고 "글 계획 만들기"를 눌러 주세요.</p>
    <template #back><span /></template>
    <template #next><NextButton @click="flow.go(3)">← 알려줄 내용 적기</NextButton></template>
  </StepLayout>
</template>
