<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { postApi, type Post, type QualityIssue } from '@/lib/api'
import { validationErrors } from '@/lib/http'
import StepLayout from '@/components/flow/StepLayout.vue'
import NextButton from '@/components/flow/NextButton.vue'
import PipelineProgress from '@/components/PipelineProgress.vue'
import PostEditor from '@/components/PostEditor.vue'
import DraftPreview from '@/components/DraftPreview.vue'
import DraftDiff from '@/components/DraftDiff.vue'
import { useFlow } from './useFlow'

const props = defineProps<{ post: Post }>()
const flow = useFlow()

const generating = computed(() => props.post.status === 'generating')
const failed = computed(() => !props.post.content && props.post.status === 'failed')
const quality = computed(() => props.post.quality)
const errors = computed(() => quality.value?.issues.filter((i) => i.severity === 'error') ?? [])
const warnings = computed(() => quality.value?.issues.filter((i) => i.severity === 'warning') ?? [])
const infos = computed(() => quality.value?.issues.filter((i) => i.severity === 'info') ?? [])
const highlights = computed(() =>
  [...errors.value, ...warnings.value].map((i) => i.excerpt).filter((e): e is string => !!e),
)

const TABS = [
  { id: 'edit', label: '편집' },
  { id: 'preview', label: '미리보기' },
  { id: 'diff', label: 'AI 초안과 비교' },
] as const
const tab = ref<'edit' | 'preview' | 'diff'>('edit')
// 모바일 "다음 확인": 확인할 곳을 차례로 보여준다
const pending = computed(() => [...errors.value, ...warnings.value].filter((i) => i.excerpt || i.block_index !== null))
let cursor = 0
function nextIssue() {
  const list = pending.value
  if (!list.length) return
  void locate(list[cursor % list.length]!)
  cursor++
}
const editorKey = ref(0)
const editor = ref<InstanceType<typeof PostEditor> | null>(null)
const title = ref(props.post.title ?? '')
const checking = ref(false)
const working = ref<string | null>(null)
const confirmRedo = ref(false)
const message = ref<string | null>(null)
let timer: ReturnType<typeof setTimeout> | undefined

function poll() {
  clearTimeout(timer)
  if (!generating.value) return
  timer = setTimeout(async () => {
    const next = await postApi.get(props.post.id)
    flow.update(next)
    if (next.status !== 'generating') {
      editorKey.value++
      title.value = next.title ?? ''
    }
    poll()
  }, 2500)
}

async function recheck() {
  checking.value = true
  try {
    flow.update(await postApi.qualityCheck(props.post.id))
  } finally {
    checking.value = false
  }
}

async function removeSentence(issue: QualityIssue) {
  if (!issue.excerpt) return
  working.value = issue.excerpt
  tab.value = 'edit'
  if (editor.value?.removeSentence(issue.excerpt)) {
    await editor.value.save()
    await recheck()
    message.value = '문장을 지웠어요. 되돌리려면 편집 도구의 "되돌리기"를 누르세요.'
  }
  working.value = null
}

async function addAsFact(issue: QualityIssue) {
  if (!issue.excerpt || !issue.suggested_fact_key) return
  working.value = issue.excerpt
  const facts = [
    ...(props.post.facts ?? []).map(({ fact_key, fact_value }) => ({ fact_key, fact_value })),
    { fact_key: issue.suggested_fact_key, fact_value: issue.excerpt },
  ]
  flow.update(await postApi.update(props.post.id, { facts }))
  await recheck()
  message.value = `"${issue.suggested_fact_key}: ${issue.excerpt}"을 알려줄 내용에 추가했어요.`
  working.value = null
}

async function locate(issue: QualityIssue) {
  tab.value = 'edit'
  for (let i = 0; i < 20 && !editor.value?.editor; i++) await new Promise((r) => setTimeout(r, 50))
  editor.value?.reveal(issue.excerpt, issue.block_index)
}

async function saveTitle() {
  if (title.value !== props.post.title) flow.update(await postApi.update(props.post.id, { title: title.value }))
}

async function redo() {
  if (!confirmRedo.value) {
    confirmRedo.value = true
    return
  }
  confirmRedo.value = false
  try {
    flow.update(await postApi.generate(props.post.id))
    poll()
  } catch (e) {
    message.value = Object.values(validationErrors(e) ?? {})[0]?.[0] ?? '초안을 다시 쓰지 못했어요.'
  }
}

onMounted(poll)
watch(generating, poll)
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <StepLayout v-if="generating || failed" :step="5" :title="failed ? '초안을 쓰지 못했어요' : '초안을 쓰고 있어요'">
    <PipelineProgress
      :post="{ ...post, pipeline_status: failed ? 'failed' : 'running', pipeline_step: 'draft' }"
      :steps="['draft', 'quality']"
      @retry="redo"
    />
    <template #back>
      <button type="button" class="hover:text-accent" @click="flow.go(4)">← 글 계획</button>
    </template>
    <template #next><span /></template>
  </StepLayout>

  <StepLayout v-else-if="post.content" :step="5" :title="'노란 곳만\n확인해주세요'">
    <template #title-side>
      <div role="tablist" class="hidden gap-1 self-start rounded-xl bg-lilac-soft p-1 text-[13px] lg:flex lg:self-auto">
        <button
          v-for="t in TABS"
          :key="t.id"
          type="button"
          role="tab"
          :aria-selected="tab === t.id"
          :class="tab === t.id ? 'bg-ink font-bold text-cream' : 'font-semibold'"
          class="rounded-[9px] px-3.5 py-2 whitespace-nowrap"
          @click="tab = t.id"
        >
          {{ t.label }}
        </button>
      </div>
    </template>
    <!-- 모바일: 제목 아래 요약 칩 -->
    <div class="-mt-2 flex flex-wrap gap-1.5 lg:hidden">
      <span class="rounded-full bg-ink px-2.5 py-[5px] text-xs font-extrabold text-lemon">꼭 고치기 {{ errors.length }}</span>
      <span class="rounded-full bg-lilac-soft px-2.5 py-[5px] text-xs font-bold">확인 권장 {{ warnings.length }}</span>
      <span v-if="quality" class="rounded-full border-[1.5px] border-line bg-white px-2.5 py-[5px] text-xs font-bold">점수 {{ Math.round(quality.score) }}</span>
    </div>

    <PostEditor v-if="tab === 'edit'" ref="editor" :key="editorKey" :post="post" :images="post.images ?? []" :highlights="highlights" @saved="flow.update">
      <template #top>
        <input v-model="title" maxlength="200" aria-label="제목" class="text-xl leading-snug font-extrabold outline-none sm:text-2xl" @change="saveTitle" />
      </template>
    </PostEditor>
    <DraftPreview v-else-if="tab === 'preview'" :post="post" :images="post.images ?? []" />
    <DraftDiff v-else-if="post.content_original" :original="post.content_original.blocks" :current="post.content.blocks" />
    <p v-if="message" role="status" class="text-sm text-sub">{{ message }}</p>

    <template #aside>
      <div class="flex items-center gap-3.5">
        <span class="font-display text-[46px] leading-none">{{ quality ? Math.round(quality.score) : '–' }}</span>
        <div class="flex flex-col gap-0.5">
          <span class="text-sm font-bold">게시 전 검사</span>
          <span class="text-xs text-sub">네이버 순위를 보장하지 않아요</span>
        </div>
      </div>
      <button
        v-if="!quality || post.quality_stale"
        type="button"
        :disabled="checking"
        class="self-start rounded-[10px] border-[1.5px] border-ink px-3 py-1.5 text-[13px] font-bold disabled:opacity-50"
        @click="recheck"
      >
        {{ checking ? '검사 중…' : quality ? '본문이 바뀌었어요 · 다시 검사' : '검사하기' }}
      </button>

      <template v-if="errors.length">
        <span class="pt-1 text-[13px] font-bold">꼭 고치기 {{ errors.length }}</span>
        <div v-for="(issue, index) in errors" :key="'e' + index" class="flex flex-col gap-2 rounded-[14px] border-2 border-ink bg-lemon px-3.5 py-3 text-[13px] leading-snug">
          <span>{{ issue.message }}</span>
          <div class="flex flex-wrap gap-1.5">
            <button v-if="issue.excerpt" type="button" :disabled="working === issue.excerpt" class="rounded-lg bg-ink px-2.5 py-1.5 text-xs font-bold text-cream disabled:opacity-50" @click="removeSentence(issue)">문장 지우기</button>
            <button v-if="issue.excerpt && issue.suggested_fact_key" type="button" :disabled="working === issue.excerpt" class="rounded-lg border-[1.5px] border-ink px-2.5 py-1.5 text-xs font-bold disabled:opacity-50" @click="addAsFact(issue)">사실로 추가</button>
            <button v-if="issue.excerpt || issue.block_index !== null" type="button" class="px-1 text-xs font-bold underline" @click="locate(issue)">위치</button>
          </div>
        </div>
      </template>

      <template v-if="warnings.length">
        <span class="pt-1 text-[13px] font-bold">확인 권장 {{ warnings.length }}</span>
        <div v-for="(issue, index) in warnings" :key="'w' + index" class="flex flex-col gap-2 rounded-[14px] border-[1.5px] border-line bg-white px-3.5 py-3 text-[13px] leading-snug">
          <div class="flex justify-between gap-2.5">
            <span>{{ issue.message }}</span>
            <button v-if="issue.excerpt || issue.block_index !== null" type="button" class="font-bold whitespace-nowrap underline" @click="locate(issue)">위치</button>
          </div>
          <div v-if="issue.suggested_fact_key && issue.excerpt" class="flex gap-1.5">
            <button type="button" class="rounded-lg bg-ink px-2.5 py-1.5 text-xs font-bold text-cream" @click="removeSentence(issue)">문장 지우기</button>
            <button type="button" class="rounded-lg border-[1.5px] border-ink px-2.5 py-1.5 text-xs font-bold" @click="addAsFact(issue)">사실로 추가</button>
          </div>
        </div>
      </template>

      <details v-if="infos.length" class="text-[13px]">
        <summary class="cursor-pointer font-bold">참고 {{ infos.length }}</summary>
        <ul class="mt-2 flex flex-col gap-1 text-sub">
          <li v-for="(issue, index) in infos" :key="'i' + index">{{ issue.message }}</li>
        </ul>
      </details>
      <p v-if="quality && !errors.length && !warnings.length" class="text-sm font-semibold">확인할 곳이 없어요 👍</p>
    </template>

    <template #back>
      <button type="button" class="hover:text-accent" @click="flow.go(4)">← 글 계획</button>
    </template>
    <template #next>
      <button type="button" class="hidden h-8 text-sm text-sub hover:text-ink lg:inline" @click="redo">
        {{ confirmRedo ? '다듬은 내용이 사라져요 · 한 번 더 누르면 다시 써요' : '초안 다시 쓰기' }}
      </button>
      <div class="flex gap-2 lg:contents">
        <button
          type="button"
          class="flex h-[58px] items-center rounded-[18px] border-[1.5px] border-ink px-4 text-sm font-bold lg:hidden"
          @click="tab = tab === 'preview' ? 'edit' : 'preview'"
        >
          {{ tab === 'preview' ? '편집' : '미리보기' }}
        </button>
        <NextButton v-if="pending.length" class="flex-1 lg:hidden" @click="nextIssue">다음 확인 →</NextButton>
        <NextButton :class="pending.length ? 'hidden lg:block' : 'flex-1 lg:flex-none'" @click="flow.go(6)">다음 · 네이버에 올리기 →</NextButton>
      </div>
    </template>
  </StepLayout>

  <StepLayout v-else :step="5" title="아직 초안이 없어요">
    <p class="text-sub">글 계획에서 "이 계획으로 초안 쓰기"를 눌러 주세요.</p>
    <template #back><span /></template>
    <template #next><NextButton @click="flow.go(4)">← 글 계획으로</NextButton></template>
  </StepLayout>
</template>
