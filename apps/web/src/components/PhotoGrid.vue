<script setup lang="ts">
import { ref } from 'vue'
import type { PostImage } from '@/lib/api'

const props = defineProps<{ images: PostImage[] }>()
const emit = defineEmits<{ reorder: [ids: number[]]; remove: [image: PostImage] }>()

const draggingId = ref<number | null>(null)

const TYPE_LABELS: Record<string, string> = {
  food: '음식',
  drink: '음료',
  exterior: '외관',
  interior: '내부',
  menu_board: '메뉴판',
  product: '제품',
  package: '포장',
  view: '풍경',
  person: '인물',
  receipt: '영수증',
  other: '기타',
}

function move(from: number, to: number) {
  if (to < 0 || to >= props.images.length || from === to) return
  const ids = props.images.map((image) => image.id)
  const [moved] = ids.splice(from, 1)
  ids.splice(to, 0, moved!)
  emit('reorder', ids)
}

function onDrop(targetIndex: number) {
  const from = props.images.findIndex((image) => image.id === draggingId.value)
  draggingId.value = null
  if (from !== -1) move(from, targetIndex)
}
</script>

<template>
  <ol class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-5">
    <li
      v-for="(image, index) in images"
      :key="image.id"
      draggable="true"
      :class="draggingId === image.id ? 'opacity-40' : ''"
      class="group relative overflow-hidden rounded-lg border border-stone-200 bg-white"
      @dragstart="draggingId = image.id"
      @dragend="draggingId = null"
      @dragover.prevent
      @drop.prevent="onDrop(index)"
    >
      <img
        :src="image.thumb_url"
        :alt="image.original_name ?? `사진 ${index + 1}`"
        loading="lazy"
        class="aspect-square w-full object-cover"
      />
      <span
        class="absolute top-1.5 left-1.5 rounded bg-black/60 px-1.5 text-xs font-medium text-white"
      >
        {{ index + 1 }}
      </span>
      <div class="space-y-1 px-1.5 pt-1 text-xs">
        <p v-if="image.vision_status === 'pending'" class="text-stone-500">분석 중…</p>
        <p v-else-if="image.vision_status === 'failed'" class="text-red-600">분석 실패</p>
        <template v-else-if="image.vision">
          <div class="flex flex-wrap gap-1">
            <span class="rounded bg-stone-100 px-1.5 py-0.5">
              {{ TYPE_LABELS[image.vision.type] ?? image.vision.type }}
            </span>
            <span v-if="!image.vision.usable" class="rounded bg-stone-200 px-1.5 py-0.5 text-stone-600">
              사용 비추천
            </span>
            <span
              v-if="image.vision.privacy_flags.length"
              class="rounded bg-red-100 px-1.5 py-0.5 text-red-700"
              :title="image.vision.privacy_flags.join(', ')"
            >
              개인정보 확인
            </span>
          </div>
          <p class="line-clamp-2 text-stone-600" :title="image.vision.description">
            {{ image.vision.description }}
          </p>
        </template>
      </div>
      <div class="flex items-center justify-between gap-1 px-1.5 py-1 text-sm">
        <button
          type="button"
          :disabled="index === 0"
          :aria-label="`${index + 1}번 사진 앞으로`"
          class="rounded px-1.5 hover:bg-stone-100 disabled:opacity-30"
          @click="move(index, index - 1)"
        >
          ←
        </button>
        <button
          type="button"
          :aria-label="`${index + 1}번 사진 삭제`"
          class="rounded px-1.5 text-red-600 hover:bg-red-50"
          @click="emit('remove', image)"
        >
          삭제
        </button>
        <button
          type="button"
          :disabled="index === images.length - 1"
          :aria-label="`${index + 1}번 사진 뒤로`"
          class="rounded px-1.5 hover:bg-stone-100 disabled:opacity-30"
          @click="move(index, index + 1)"
        >
          →
        </button>
      </div>
    </li>
  </ol>
</template>
