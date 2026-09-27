<script setup lang="ts">
import { computed } from 'vue'
import { NodeViewWrapper, nodeViewProps } from '@tiptap/vue-3'
import type { PostImageOptions } from './postImage'

const props = defineProps(nodeViewProps)

const options = computed(() => props.extension.options as PostImageOptions)
const image = computed(() => options.value.resolveImage(props.node.attrs.imageId as number))
const number = computed(() => options.value.photoNumber?.(props.node.attrs.imageId as number))
</script>

<template>
  <NodeViewWrapper
    as="figure"
    class="group relative my-2"
    :class="selected ? 'rounded-xl ring-2 ring-ink ring-offset-2' : ''"
  >
    <div
      data-drag-handle
      draggable="true"
      contenteditable="false"
      class="cursor-grab active:cursor-grabbing"
      title="끌어서 위치를 옮길 수 있습니다"
    >
      <!-- 디자인(1a): 낮은 사진 칸 + 왼쪽 위 "사진 N" -->
      <img
        v-if="image"
        :src="image.url"
        :alt="image.original_name ?? '사진'"
        class="h-[120px] w-full rounded-xl object-cover lg:h-[200px]"
        draggable="false"
      />
      <span v-if="image && number" class="absolute top-2 left-2 rounded-md bg-ink px-1.5 py-0.5 text-[11px] font-bold text-cream">사진 {{ number }}</span>
      <div v-else-if="!image" class="photo-stripes rounded-xl py-10 text-center text-sm text-sub">
        삭제된 사진
      </div>
    </div>
    <button
      type="button"
      contenteditable="false"
      class="absolute top-2 right-2 rounded-lg bg-ink px-2 py-1 text-xs font-bold text-cream opacity-0 group-hover:opacity-100 focus:opacity-100"
      @click="deleteNode"
    >
      사진 빼기
    </button>
  </NodeViewWrapper>
</template>
