<script setup lang="ts">
import { ref } from 'vue'
import { AxiosError } from 'axios'
import { imageApi, type PostImage } from '@/lib/api'
import { validationErrors } from '@/lib/http'
import { runWithConcurrency } from '@/lib/concurrency'

const props = defineProps<{ postId: number; remaining: number }>()
const emit = defineEmits<{ uploaded: [image: PostImage] }>()

const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif']
const MAX_BYTES = 20 * 1024 * 1024
const CONCURRENCY = 3

type Item = {
  key: number
  name: string
  progress: number
  state: 'queued' | 'uploading' | 'processing' | 'failed'
  error?: string
}

const stripExif = ref(true)
const dragging = ref(false)
const items = ref<Item[]>([])
const input = ref<HTMLInputElement | null>(null)
let seq = 0

function isAccepted(file: File) {
  // iOS는 HEIC 파일의 type을 비워 보내는 경우가 있어 확장자도 본다.
  return ACCEPTED.includes(file.type) || /\.(heic|heif)$/i.test(file.name)
}

function errorMessage(error: unknown) {
  const errors = validationErrors(error)
  if (errors) return errors.image?.[0] ?? '올릴 수 없는 사진입니다.'
  if (error instanceof AxiosError && error.response?.status === 413) return '파일이 너무 큽니다.'
  if (error instanceof AxiosError && error.response?.status === 503)
    return '사진 처리 서비스에 연결할 수 없습니다.'
  return '업로드에 실패했습니다.'
}

async function addFiles(files: FileList | File[]) {
  const list = Array.from(files)
  const accepted: { file: File; item: Item }[] = []
  let room = props.remaining

  for (const file of list) {
    const item: Item = { key: ++seq, name: file.name, progress: 0, state: 'queued' }
    if (!isAccepted(file)) Object.assign(item, { state: 'failed', error: '지원하지 않는 형식입니다.' })
    else if (file.size > MAX_BYTES) Object.assign(item, { state: 'failed', error: '20MB를 넘습니다.' })
    else if (room <= 0) Object.assign(item, { state: 'failed', error: '최대 30장까지 올릴 수 있습니다.' })
    else {
      room--
      accepted.push({ file, item })
    }
    items.value.push(item)
  }

  await runWithConcurrency(accepted, CONCURRENCY, async ({ file, item }) => {
    const tracked = items.value.find((i) => i.key === item.key)!
    tracked.state = 'uploading'
    try {
      const image = await imageApi.upload(props.postId, file, {
        stripExif: stripExif.value,
        onProgress: (ratio) => {
          tracked.progress = ratio
          if (ratio >= 1) tracked.state = 'processing'
        },
      })
      items.value = items.value.filter((i) => i.key !== item.key)
      emit('uploaded', image)
    } catch (error) {
      tracked.state = 'failed'
      tracked.error = errorMessage(error)
    }
  })
}

function onDrop(event: DragEvent) {
  dragging.value = false
  if (event.dataTransfer?.files.length) addFiles(event.dataTransfer.files)
}

function onPick(event: Event) {
  const target = event.target as HTMLInputElement
  if (target.files?.length) addFiles(target.files)
  target.value = ''
}

function dismiss(key: number) {
  items.value = items.value.filter((i) => i.key !== key)
}

defineExpose({ addFiles })
</script>

<template>
  <div class="space-y-3">
    <div
      :class="dragging ? 'border-stone-900 bg-stone-100' : 'border-stone-300 bg-white'"
      class="flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-4 py-8 text-center"
      @dragover.prevent="dragging = true"
      @dragleave.prevent="dragging = false"
      @drop.prevent="onDrop"
    >
      <p class="font-medium">사진을 여기에 끌어다 놓으세요</p>
      <p class="text-sm text-stone-500">JPG · PNG · WEBP · HEIC, 한 장 20MB 이하, 최대 30장</p>
      <button
        type="button"
        class="mt-1 rounded-md border border-stone-300 px-3 py-1.5 text-sm hover:bg-stone-50"
        @click="input?.click()"
      >
        파일 선택
      </button>
      <input
        ref="input"
        type="file"
        multiple
        accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif"
        class="sr-only"
        aria-label="사진 파일 선택"
        @change="onPick"
      />
    </div>

    <label class="flex items-center gap-2 text-sm text-stone-600">
      <input v-model="stripExif" type="checkbox" />
      촬영 정보(EXIF) 제거 — 해제해도 위치 정보는 항상 지웁니다
    </label>

    <ul v-if="items.length" class="space-y-1 text-sm">
      <li v-for="item in items" :key="item.key" class="flex items-center gap-3">
        <span class="min-w-0 flex-1 truncate">{{ item.name }}</span>
        <template v-if="item.state === 'failed'">
          <span role="alert" class="text-red-600">{{ item.error }}</span>
          <button
            type="button"
            class="text-stone-500"
            :aria-label="`${item.name} 오류 닫기`"
            @click="dismiss(item.key)"
          >
            ✕
          </button>
        </template>
        <span v-else-if="item.state === 'processing'" class="text-stone-500">처리 중…</span>
        <span v-else-if="item.state === 'queued'" class="text-stone-500">대기</span>
        <progress v-else :value="item.progress" max="1" class="w-24" />
      </li>
    </ul>
  </div>
</template>
