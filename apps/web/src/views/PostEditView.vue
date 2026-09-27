<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { AxiosError } from 'axios'
import { imageApi, postApi, type Fact, type Post, type PostImage, type Tone } from '@/lib/api'
import { validationErrors } from '@/lib/http'
import FactsEditor from '@/components/FactsEditor.vue'
import PhotoGrid from '@/components/PhotoGrid.vue'
import PhotoUploader from '@/components/PhotoUploader.vue'
import PlanPanel from '@/components/PlanPanel.vue'
import DraftPreview from '@/components/DraftPreview.vue'
import PostEditor from '@/components/PostEditor.vue'
import DraftDiff from '@/components/DraftDiff.vue'
import QualityPanel from '@/components/QualityPanel.vue'
import ExportPanel from '@/components/ExportPanel.vue'
import type { QualityIssue } from '@/lib/api'

const props = defineProps<{ id: number }>()

const TONES: { value: Tone; label: string }[] = [
  { value: 'natural', label: '자연스러운 후기' },
  { value: 'expert', label: '전문 정보형' },
  { value: 'friendly', label: '친근한 말투' },
  { value: 'clean', label: '깔끔한 정보형' },
]
const LENGTHS = [
  { value: 1500, label: '짧게 (약 1,500자)' },
  { value: 2500, label: '보통 (약 2,500자)' },
  { value: 4000, label: '길게 (약 4,000자)' },
]
const MAX_IMAGES = 30

const post = ref<Post | null>(null)
const notFound = ref(false)
const images = ref<PostImage[]>([])
const facts = ref<Fact[]>([])
const tone = ref<Tone>('natural')
const targetLength = ref(2500)

const PLAN_POLL_MS = 3000
const planning = computed(() => post.value?.status === 'planning')
const generating = computed(() => post.value?.status === 'generating')
const draftError = ref<string | null>(null)
const draftTab = ref<'edit' | 'preview' | 'diff'>('edit')
// 새 초안이 나오면 편집기를 새 내용으로 다시 만든다
const editorKey = ref(0)
const editorRef = ref<InstanceType<typeof PostEditor> | null>(null)

async function locateIssue(issue: QualityIssue) {
  draftTab.value = 'edit'
  // 편집 탭으로 바꾼 뒤 편집기가 만들어질 때까지 기다린다
  for (let i = 0; i < 20 && !editorRef.value?.editor; i++) await new Promise((r) => setTimeout(r, 50))
  editorRef.value?.reveal(issue.excerpt, issue.block_index)
}
const canDraft = computed(
  () => !!post.value?.plan && !post.value.plan_stale && !planning.value && !generating.value,
)

function friendlyError(message: string) {
  return message.includes('billing')
    ? 'AI 공급자 계정의 크레딧(잔액)이 부족합니다. 충전한 뒤 다시 시도해 주세요.'
    : message
}
const planError = ref<string | null>(null)
let planTimer: ReturnType<typeof setTimeout> | undefined

const saving = ref(false)
const saveMessage = ref<{ ok: boolean; text: string } | null>(null)
const imageError = ref<string | null>(null)
const analyzingPhotos = computed(() => images.value.some((image) => image.vision_status === 'pending'))
const unanalyzedCount = computed(
  () => images.value.filter((image) => !image.vision && image.vision_status !== 'pending').length,
)
const privacyCount = computed(
  () => images.value.filter((image) => image.vision?.privacy_flags.length).length,
)
let visionTimer: ReturnType<typeof setTimeout> | undefined

async function analyzePhotos() {
  imageError.value = null
  try {
    const result = await imageApi.analyze(props.id)
    images.value = result.data
    pollVision()
  } catch {
    imageError.value = '사진 분석을 시작하지 못했습니다.'
  }
}

function pollVision() {
  clearTimeout(visionTimer)
  if (!analyzingPhotos.value) return
  visionTimer = setTimeout(async () => {
    images.value = (await postApi.get(props.id)).images ?? images.value
    pollVision()
  }, 3000)
}

const visitDateHint = computed(() => {
  if (facts.value.some((f) => f.fact_key.trim() === '방문 날짜')) return null
  const taken = images.value.map((i) => i.taken_at).find(Boolean)
  return taken ? taken.slice(0, 10) : null
})

onMounted(async () => {
  try {
    const loaded = await postApi.get(props.id)
    post.value = loaded
    images.value = loaded.images ?? []
    facts.value = (loaded.facts ?? []).map(({ fact_key, fact_value }) => ({ fact_key, fact_value }))
    if (facts.value.length === 0) facts.value = [{ fact_key: '장소명', fact_value: '' }]
    tone.value = loaded.tone ?? 'natural'
    targetLength.value = loaded.target_length ?? 2500
    pollPlan()
    pollVision()
  } catch (e) {
    if (e instanceof AxiosError && [403, 404].includes(e.response?.status ?? 0)) notFound.value = true
    else throw e
  }
})

async function save(): Promise<boolean> {
  saving.value = true
  saveMessage.value = null
  try {
    const filled = facts.value.filter((f) => f.fact_key.trim() && f.fact_value.trim())
    post.value = await postApi.update(props.id, {
      tone: tone.value,
      target_length: targetLength.value,
      facts: filled,
    })
    facts.value = filled.length ? filled : [{ fact_key: '', fact_value: '' }]
    saveMessage.value = { ok: true, text: '저장했습니다.' }
    return true
  } catch (e) {
    const errors = validationErrors(e)
    saveMessage.value = {
      ok: false,
      text: errors ? (Object.values(errors)[0]?.[0] ?? '입력값을 확인해 주세요.') : '저장하지 못했습니다.',
    }
    return false
  } finally {
    saving.value = false
  }
}

// 입력한 내용을 먼저 저장해야 계획에 반영된다
async function makePlan() {
  planError.value = null
  if (!(await save())) return
  try {
    post.value = await postApi.plan(props.id)
    pollPlan()
  } catch (e) {
    const errors = validationErrors(e)
    planError.value = errors
      ? (Object.values(errors)[0]?.[0] ?? '계획을 만들 수 없습니다.')
      : '계획을 시작하지 못했습니다.'
  }
}

async function makeDraft() {
  draftError.value = null
  try {
    post.value = await postApi.generate(props.id)
    pollPlan()
  } catch (e) {
    const errors = validationErrors(e)
    draftError.value = errors
      ? (Object.values(errors)[0]?.[0] ?? '초안을 만들 수 없습니다.')
      : '초안 쓰기를 시작하지 못했습니다.'
  }
}

// 계획·초안 작업이 끝날 때까지 상태를 확인한다
function pollPlan() {
  clearTimeout(planTimer)
  if (post.value?.status !== 'planning' && post.value?.status !== 'generating') return
  planTimer = setTimeout(async () => {
    const wasGenerating = post.value?.status === 'generating'
    post.value = await postApi.get(props.id)
    if (wasGenerating && post.value.status !== 'generating') editorKey.value++
    pollPlan()
  }, PLAN_POLL_MS)
}

onBeforeUnmount(() => {
  clearTimeout(planTimer)
  clearTimeout(visionTimer)
})

function addVisitDate() {
  if (!visitDateHint.value) return
  facts.value = [...facts.value, { fact_key: '방문 날짜', fact_value: visitDateHint.value }]
}

function onUploaded(image: PostImage) {
  images.value = [...images.value, image].sort((a, b) => a.sort_order - b.sort_order)
}

async function onReorder(ids: number[]) {
  const previous = images.value
  images.value = ids.map((id) => previous.find((image) => image.id === id)!)
  imageError.value = null
  try {
    images.value = await imageApi.reorder(props.id, ids)
  } catch {
    images.value = previous
    imageError.value = '순서를 저장하지 못했습니다.'
  }
}

async function onRemove(image: PostImage) {
  const previous = images.value
  images.value = previous.filter((i) => i.id !== image.id)
  imageError.value = null
  try {
    await imageApi.remove(props.id, image.id)
  } catch {
    images.value = previous
    imageError.value = '사진을 삭제하지 못했습니다.'
  }
}
</script>

<template>
  <section class="space-y-8">
    <RouterLink
      v-if="post?.keyword_project_id"
      :to="{ name: 'project', params: { id: post.keyword_project_id } }"
      class="text-sm text-stone-500 hover:underline"
    >
      ← 프로젝트
    </RouterLink>

    <p v-if="notFound" class="text-stone-500">글을 찾을 수 없습니다.</p>
    <p v-else-if="!post" class="text-stone-500">불러오는 중…</p>

    <template v-else>
      <h1 class="text-2xl font-semibold">{{ post.title || '새 글' }}</h1>

      <section class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 class="text-lg font-semibold">사진 {{ images.length }}/{{ MAX_IMAGES }}</h2>
          <button
            v-if="images.length"
            type="button"
            :disabled="analyzingPhotos || unanalyzedCount === 0"
            class="rounded-md border border-stone-300 px-3 py-1.5 text-sm hover:bg-stone-50 disabled:opacity-50"
            @click="analyzePhotos"
          >
            {{
              analyzingPhotos
                ? '사진 분석 중…'
                : unanalyzedCount
                  ? `사진 분석 (${unanalyzedCount}장)`
                  : '모두 분석됨'
            }}
          </button>
        </div>
        <p class="text-sm text-stone-500">
          사진 분석은 사진 종류와 설명을 만들어 목차·본문의 사진 배치에 씁니다. 사진만 보고 메뉴명이나
          가격을 확정하지 않습니다.
        </p>
        <p
          v-if="privacyCount"
          role="status"
          class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-800"
        >
          얼굴·번호판·영수증 등 개인정보가 보이는 사진이 {{ privacyCount }}장 있습니다. 올리기 전에 확인해
          주세요.
        </p>
        <p v-if="imageError" role="alert" class="text-sm text-red-600">{{ imageError }}</p>
        <PhotoGrid v-if="images.length" :images="images" @reorder="onReorder" @remove="onRemove" />
        <PhotoUploader
          :post-id="post.id"
          :remaining="MAX_IMAGES - images.length"
          @uploaded="onUploaded"
        />
      </section>

      <form class="space-y-6" @submit.prevent="save">
        <section class="space-y-3">
          <h2 class="text-lg font-semibold">알려주고 싶은 내용</h2>
          <p class="text-sm text-stone-500">
            여기 적은 내용만 글에서 사실로 씁니다. 가격·주소·메뉴는 직접 적어 주세요.
          </p>
          <button
            v-if="visitDateHint"
            type="button"
            class="rounded-md bg-amber-50 px-3 py-1.5 text-sm text-amber-900 hover:bg-amber-100"
            @click="addVisitDate"
          >
            사진 촬영일 {{ visitDateHint }}을 방문 날짜로 추가
          </button>
          <FactsEditor v-model="facts" />
        </section>

        <section class="flex flex-wrap gap-6">
          <label class="space-y-1">
            <span class="block text-sm text-stone-600">말투</span>
            <select v-model="tone" class="rounded-md border border-stone-300 px-3 py-2">
              <option v-for="t in TONES" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
          </label>
          <label class="space-y-1">
            <span class="block text-sm text-stone-600">길이</span>
            <select v-model.number="targetLength" class="rounded-md border border-stone-300 px-3 py-2">
              <option v-for="l in LENGTHS" :key="l.value" :value="l.value">{{ l.label }}</option>
            </select>
          </label>
        </section>

        <div class="flex items-center gap-3">
          <button
            type="submit"
            :disabled="saving"
            class="rounded-md bg-stone-900 px-4 py-2 font-medium text-white disabled:opacity-50"
          >
            {{ saving ? '저장 중…' : '저장' }}
          </button>
          <span
            v-if="saveMessage"
            :role="saveMessage.ok ? 'status' : 'alert'"
            :class="saveMessage.ok ? 'text-emerald-700' : 'text-red-600'"
            class="text-sm"
          >
            {{ saveMessage.text }}
          </span>
        </div>
      </form>

      <section class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 class="text-lg font-semibold">글 계획</h2>
          <button
            type="button"
            :disabled="planning || saving"
            class="rounded-md border border-stone-300 px-4 py-2 font-medium hover:bg-stone-50 disabled:opacity-50"
            @click="makePlan"
          >
            {{ planning ? '계획 만드는 중…' : post.plan ? '계획 다시 만들기' : '글 계획 만들기' }}
          </button>
        </div>
        <p class="text-sm text-stone-500">
          입력한 사실·사진·키워드 분석으로 제목 후보와 목차, 사진 배치를 만듭니다. 입력하지 않은
          정보는 글에 단정해서 쓰지 않도록 따로 표시합니다.
        </p>
        <p v-if="planError" role="alert" class="text-sm text-red-600">{{ planError }}</p>
        <p v-else-if="post.status === 'failed' && post.plan_error" role="alert" class="text-sm text-red-600">
          {{ friendlyError(post.plan_error) }}
        </p>
        <PlanPanel :post="post" :images="images" @updated="(updated) => (post = updated)" />
      </section>

      <section v-if="post.plan" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 class="text-lg font-semibold">초안</h2>
          <button
            type="button"
            :disabled="!canDraft"
            class="rounded-md bg-stone-900 px-4 py-2 font-medium text-white disabled:opacity-50"
            @click="makeDraft"
          >
            {{ generating ? '초안 쓰는 중…' : post.content ? '초안 다시 쓰기' : '초안 쓰기' }}
          </button>
        </div>
        <p class="text-sm text-stone-500">
          저장한 계획대로 본문을 씁니다. 입력하지 않은 가격·시간·연락처·주소가 들어가면 확인
          필요로 표시합니다.
        </p>
        <p v-if="draftError" role="alert" class="text-sm text-red-600">{{ draftError }}</p>
        <p
          v-else-if="post.status === 'failed' && post.draft_error"
          role="alert"
          class="text-sm text-red-600"
        >
          {{ friendlyError(post.draft_error) }}
        </p>
        <template v-if="post.content">
          <div role="tablist" class="flex gap-1 text-sm">
            <button
              v-for="tab in [
                { id: 'edit', label: '편집' },
                { id: 'preview', label: '미리보기' },
                { id: 'diff', label: 'AI 초안과 비교' },
              ] as const"
              :key="tab.id"
              type="button"
              role="tab"
              :aria-selected="draftTab === tab.id"
              :class="draftTab === tab.id ? 'bg-stone-900 text-white' : 'text-stone-600 hover:bg-stone-100'"
              class="rounded-md px-3 py-1.5"
              @click="draftTab = tab.id"
            >
              {{ tab.label }}
            </button>
          </div>
          <PostEditor
            v-if="draftTab === 'edit' && !generating"
            ref="editorRef"
            :key="editorKey"
            :post="post"
            :images="images"
            @saved="(saved) => (post = saved)"
          />
          <DraftPreview v-else-if="draftTab === 'preview'" :post="post" :images="images" />
          <DraftDiff
            v-else-if="draftTab === 'diff' && post.content_original"
            :original="post.content_original.blocks"
            :current="post.content.blocks"
          />
        </template>
      </section>

      <QualityPanel
        v-if="post.content"
        :post="post"
        @updated="(updated) => (post = updated)"
        @locate="locateIssue"
      />

      <ExportPanel
        v-if="post.content"
        :post="post"
        :images="images"
        @updated="(updated) => (post = updated)"
      />
    </template>
  </section>
</template>
