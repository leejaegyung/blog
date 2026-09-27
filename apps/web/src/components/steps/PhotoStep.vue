<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { AxiosError } from 'axios'
import { imageApi, postApi, type Post, type PostImage } from '@/lib/api'
import { validationErrors } from '@/lib/http'
import { runWithConcurrency } from '@/lib/concurrency'
import StepLayout from '@/components/flow/StepLayout.vue'
import NextButton from '@/components/flow/NextButton.vue'
import PanelCard from '@/components/flow/PanelCard.vue'
import { useFlow } from './useFlow'

const props = defineProps<{ post: Post }>()
const flow = useFlow()

const MAX = 30
const MAX_BYTES = 20 * 1024 * 1024
const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif']

const images = ref<PostImage[]>(props.post.images ?? [])
const stripExif = ref(true)
const uploads = ref<{ key: number; name: string; progress: number; error?: string }[]>([])
const dragging = ref<number | null>(null)
const dropActive = ref(false)
const error = ref<string | null>(null)
const input = ref<HTMLInputElement | null>(null)
let seq = 0

const flagged = computed(() =>
  images.value.map((image, i) => ({ image, n: i + 1 })).filter(({ image }) => image.vision?.privacy_flags.length),
)
// 디자인 문구: "얼굴이 보이는 사진 N장". 얼굴만 걸렸으면 그렇게, 아니면 무엇이 보이는지 적는다
const flaggedLabel = computed(() => {
  const kinds = new Set(flagged.value.flatMap(({ image }) => image.vision!.privacy_flags))
  if (kinds.size === 1 && kinds.has('사람 얼굴')) return '얼굴이'
  return `${[...kinds].join('·')}이(가)`
})
const analyzed = computed(() => images.value.length > 0 && images.value.every((i) => i.vision))
const pendingCount = computed(() => images.value.filter((i) => i.vision_status === 'pending').length)
const doneCount = computed(() => images.value.filter((i) => i.vision).length)
const failedCount = computed(() => images.value.filter((i) => !i.vision && i.vision_status === 'failed').length)
const analyzeError = ref<string | null>(null)
let pollTimer: ReturnType<typeof setTimeout> | undefined

/** 디자인(1a) 2단계: 올린 사진을 바로 분석해 "N장 모두 분석됨"을 보여준다. 글 계획 흐름은 끝난 사진을 건너뛴다 */
async function analyze(retryFailed = false) {
  const waiting = images.value.some((i) => !i.vision && (retryFailed ? i.vision_status !== 'pending' : !i.vision_status))
  if (!waiting) return
  analyzeError.value = null
  try {
    const result = await imageApi.analyze(props.post.id)
    images.value = [...result.data].sort((a, b) => a.sort_order - b.sort_order)
    sync()
    poll()
  } catch {
    analyzeError.value = '사진 분석을 시작하지 못했어요. 글 계획을 만들 때 다시 시도해요.'
  }
}

function poll() {
  clearTimeout(pollTimer)
  if (!pendingCount.value) return
  pollTimer = setTimeout(async () => {
    const fresh = await postApi.get(props.post.id).catch(() => null)
    // 기다리는 동안 올리거나 지운 사진이 있으면 목록은 그대로 두고 분석 결과만 반영한다
    if (fresh?.images) {
      const byId = new Map(fresh.images.map((i) => [i.id, i]))
      images.value = images.value.map((i) => byId.get(i.id) ?? i)
      sync()
    }
    poll()
  }, 2500)
}

onMounted(() => (pendingCount.value ? poll() : analyze()))
onBeforeUnmount(() => clearTimeout(pollTimer))

function sync() {
  flow.update({ ...props.post, images: images.value })
}

function uploadError(e: unknown) {
  const errors = validationErrors(e)
  if (errors) return errors.image?.[0] ?? '올릴 수 없는 사진이에요.'
  if (e instanceof AxiosError && e.response?.status === 503) return '사진 처리 서비스에 연결하지 못했어요.'
  return '올리지 못했어요.'
}

async function addFiles(list: FileList | File[]) {
  const files = Array.from(list)
  let room = MAX - images.value.length
  const accepted: { file: File; key: number }[] = []
  for (const file of files) {
    const key = ++seq
    const ok = ACCEPTED.includes(file.type) || /\.(heic|heif)$/i.test(file.name)
    const item = { key, name: file.name, progress: 0, error: undefined as string | undefined }
    if (!ok) item.error = '지원하지 않는 형식이에요.'
    else if (file.size > MAX_BYTES) item.error = '20MB를 넘어요.'
    else if (room-- <= 0) item.error = `최대 ${MAX}장까지 올릴 수 있어요.`
    else accepted.push({ file, key })
    uploads.value.push(item)
  }
  await runWithConcurrency(accepted, 3, async ({ file, key }) => {
    const item = uploads.value.find((u) => u.key === key)!
    try {
      const image = await imageApi.upload(props.post.id, file, {
        stripExif: stripExif.value,
        onProgress: (ratio) => (item.progress = ratio),
      })
      images.value = [...images.value, image].sort((a, b) => a.sort_order - b.sort_order)
      uploads.value = uploads.value.filter((u) => u.key !== key)
      sync()
    } catch (e) {
      item.error = uploadError(e)
    }
  })
  if (accepted.length) void analyze()
}

function onPick(event: Event) {
  const target = event.target as HTMLInputElement
  if (target.files?.length) void addFiles(target.files)
  target.value = ''
}

function onDropFiles(event: DragEvent) {
  dropActive.value = false
  if (event.dataTransfer?.files.length) void addFiles(event.dataTransfer.files)
}

async function reorder(ids: number[]) {
  const previous = images.value
  images.value = ids.map((id) => previous.find((i) => i.id === id)!)
  try {
    images.value = await imageApi.reorder(props.post.id, ids)
    sync()
  } catch {
    images.value = previous
    error.value = '순서를 저장하지 못했어요.'
  }
}

function dropOn(index: number) {
  const from = images.value.findIndex((i) => i.id === dragging.value)
  dragging.value = null
  if (from < 0 || from === index) return
  const ids = images.value.map((i) => i.id)
  const [moved] = ids.splice(from, 1)
  ids.splice(index, 0, moved!)
  void reorder(ids)
}

function moveLeft(index: number) {
  const ids = images.value.map((i) => i.id)
  ;[ids[index - 1], ids[index]] = [ids[index]!, ids[index - 1]!]
  void reorder(ids)
}

async function remove(image: PostImage) {
  const previous = images.value
  images.value = previous.filter((i) => i.id !== image.id)
  try {
    await imageApi.remove(props.post.id, image.id)
    sync()
  } catch {
    images.value = previous
    error.value = '사진을 지우지 못했어요.'
  }
}
</script>

<template>
  <StepLayout :step="2" :title="'찍은 사진을\n올려주세요'" lead="올린 순서대로 글에 배치돼요. 끌어서 순서를 바꿀 수 있어요.">
    <ol
      class="grid grid-cols-3 gap-2 lg:grid-cols-5 lg:gap-2.5"
      @dragover.prevent="dropActive = dragging === null"
      @dragleave.self="dropActive = false"
      @drop.prevent="dragging === null && onDropFiles($event)"
    >
      <li
        v-for="(image, index) in images"
        :key="image.id"
        draggable="true"
        :class="dragging === image.id ? 'opacity-40' : ''"
        class="group relative aspect-square overflow-hidden rounded-[14px] bg-lilac-soft"
        @dragstart="dragging = image.id"
        @dragend="dragging = null"
        @drop.prevent.stop="dropOn(index)"
      >
        <img :src="image.thumb_url" :alt="`사진 ${index + 1}`" class="size-full object-cover" draggable="false" />
        <span class="absolute top-2 left-2 rounded-md bg-ink px-[7px] py-0.5 text-[11px] font-bold text-cream">{{ index + 1 }}</span>
        <span v-if="image.vision?.privacy_flags.length" class="absolute bottom-2 left-2 rounded-md border border-ink bg-lemon px-1.5 text-[11px] font-bold">확인</span>
        <div class="absolute top-1.5 right-1.5 flex gap-1 opacity-100 lg:opacity-0 lg:group-hover:opacity-100 lg:group-focus-within:opacity-100">
          <button type="button" :disabled="index === 0" :aria-label="`${index + 1}번 사진 앞으로`" class="rounded-md bg-ink/70 px-1.5 text-xs text-cream disabled:hidden" @click="moveLeft(index)">←</button>
          <button type="button" :aria-label="`${index + 1}번 사진 삭제`" class="rounded-md bg-ink/70 px-1.5 text-xs text-cream" @click="remove(image)">✕</button>
        </div>
      </li>
      <li>
        <button
          type="button"
          :class="dropActive ? 'bg-lemon' : ''"
          class="flex aspect-square w-full flex-col items-center justify-center gap-1 rounded-[14px] border-2 border-dashed border-ink p-2 text-center font-bold"
          @click="input?.click()"
        >
          <span class="text-[26px] leading-none">+</span>
          <span class="text-xs leading-tight"><span class="hidden lg:inline">끌어다 놓거나<br />눌러서 </span>추가</span>
        </button>
        <input ref="input" type="file" multiple accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif" class="sr-only" aria-label="사진 파일 선택" @change="onPick" />
      </li>
    </ol>

    <ul v-if="uploads.length" class="flex flex-col gap-1 text-sm">
      <li v-for="item in uploads" :key="item.key" class="flex items-center gap-3">
        <span class="min-w-0 flex-1 truncate">{{ item.name }}</span>
        <span v-if="item.error" role="alert" class="text-red-600">{{ item.error }}</span>
        <progress v-else :value="item.progress" max="1" class="w-24 accent-ink" />
        <button v-if="item.error" type="button" class="text-sub" :aria-label="`${item.name} 닫기`" @click="uploads = uploads.filter((u) => u.key !== item.key)">✕</button>
      </li>
    </ul>
    <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
    <div class="flex flex-col gap-2 lg:hidden">
      <div v-if="flagged.length" class="flex items-center justify-between gap-2.5 rounded-[14px] border-2 border-ink bg-lemon px-3.5 py-3">
        <span class="text-sm leading-snug font-semibold">{{ flaggedLabel }} 보이는 사진 {{ flagged.length }}장이 있어요</span>
        <span class="text-[13px] font-bold whitespace-nowrap">{{ flagged.map((f) => `${f.n}번`).join(', ') }}</span>
      </div>
      <span class="text-[13px] text-sub">✓ 위치 정보는 항상 지워요 · {{ images.length }} / {{ MAX }}장</span>
    </div>

    <template #aside>
      <span class="hidden text-[13px] font-bold lg:block">사진 확인</span>
      <PanelCard v-if="flagged.length" tone="lemon" desktop-only>
        <span class="text-sm font-bold">{{ flaggedLabel }} 보이는 사진 {{ flagged.length }}장</span>
        <span>{{ flagged.map((f) => `${f.n}번`).join(', ') }} 사진 — 올리기 전에 확인해 주세요.</span>
      </PanelCard>
      <PanelCard v-if="failedCount && !pendingCount" tone="lemon">
        <span class="text-sm font-bold">분석하지 못한 사진 {{ failedCount }}장</span>
        <span>글 계획을 만들 때 다시 시도해요.</span>
        <button type="button" class="self-start rounded-[10px] bg-ink px-3 py-[7px] font-bold text-cream" @click="analyze(true)">지금 다시 분석</button>
      </PanelCard>
      <PanelCard desktop-only>
        <span class="text-sm font-bold" role="status">
          {{
            !images.length
              ? '올리면 바로 분석해요'
              : analyzed
                ? `✓ ${images.length}장 모두 분석됨`
                : pendingCount
                  ? `사진 분석 중 · ${doneCount} / ${images.length}장`
                  : `${doneCount} / ${images.length}장 분석됨`
          }}
        </span>
        <span class="text-sub">사진 종류를 파악해 목차에 배치해요. 사진만 보고 메뉴·가격을 확정하지 않아요.</span>
        <span v-if="analyzeError" role="alert" class="text-red-600">{{ analyzeError }}</span>
      </PanelCard>
      <label class="flex cursor-pointer items-center gap-2.5 rounded-[14px] border-[1.5px] border-line bg-white p-3.5">
        <input v-model="stripExif" type="checkbox" class="peer sr-only" />
        <span class="relative h-5 w-9 shrink-0 rounded-full bg-track transition peer-checked:bg-ink peer-focus-visible:ring-2 peer-focus-visible:ring-accent" aria-hidden="true">
          <span :class="stripExif ? 'right-0.5 bg-lemon' : 'left-0.5 bg-white'" class="absolute top-0.5 size-4 rounded-full" />
        </span>
        <span class="text-[13px] leading-snug">촬영 정보(EXIF) 지우기 · 위치 정보는 항상 지워요</span>
      </label>
      <span class="hidden text-xs text-sub lg:inline">JPG · PNG · WEBP · HEIC · 한 장 20MB · {{ images.length }} / {{ MAX }}장</span>
    </template>

    <template #back>
      <button type="button" class="hover:text-accent" @click="flow.go(1)">← 키워드</button>
    </template>
    <template #next>
      <button v-if="!images.length" type="button" class="h-8 text-sm text-sub hover:text-ink" @click="flow.go(3)">사진 없이 건너뛰기</button>
      <NextButton :disabled="uploads.some((u) => !u.error)" @click="flow.go(3)">다음 · 알려줄 내용 적기 →</NextButton>
    </template>
  </StepLayout>
</template>
