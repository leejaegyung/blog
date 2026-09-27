<script setup lang="ts">
import { computed } from 'vue'
import { NodeViewWrapper, nodeViewProps } from '@tiptap/vue-3'
import type { PostImageOptions } from './postImage'

const props = defineProps(nodeViewProps)

const image = computed(() =>
  (props.extension.options as PostImageOptions).resolveImage(props.node.attrs.imageId as number),
)
</script>

<template>
  <NodeViewWrapper
    as="figure"
    class="group relative my-3"
    :class="selected ? 'ring-2 ring-stone-900 ring-offset-2' : ''"
  >
    <div
      data-drag-handle
      draggable="true"
      contenteditable="false"
      class="cursor-grab active:cursor-grabbing"
      title="끌어서 위치를 옮길 수 있습니다"
    >
      <img
        v-if="image"
        :src="image.url"
        :alt="image.original_name ?? '사진'"
        class="w-full rounded"
        draggable="false"
      />
      <div v-else class="rounded bg-stone-100 py-10 text-center text-sm text-stone-500">
        삭제된 사진
      </div>
    </div>
    <button
      type="button"
      contenteditable="false"
      class="absolute top-2 right-2 rounded bg-black/60 px-2 py-1 text-xs text-white opacity-0 group-hover:opacity-100 focus:opacity-100"
      @click="deleteNode"
    >
      사진 빼기
    </button>
  </NodeViewWrapper>
</template>
