<script setup lang="ts">
import { computed } from 'vue'
import { diffWords } from 'diff'
import type { ContentBlock } from '@/lib/api'
import { blocksText } from '@/editor/convert'

const props = defineProps<{ original: ContentBlock[]; current: ContentBlock[] }>()

const parts = computed(() => diffWords(blocksText(props.original), blocksText(props.current)))

// 사용자 수정률(기획서 29장 User Edit Distance): 바뀐 글자 수 / AI 초안 글자 수
const editRatio = computed(() => {
  const originalLength = blocksText(props.original).length || 1
  const changed = parts.value
    .filter((part) => part.added || part.removed)
    .reduce((sum, part) => sum + part.value.length, 0)
  return Math.min(1, changed / originalLength)
})
</script>

<template>
  <div class="space-y-3">
    <p class="text-sm text-sub">
      AI 초안 대비 수정 {{ Math.round(editRatio * 100) }}% ·
      <span class="rounded bg-lilac-soft px-1">추가</span>
      <span class="ml-1 rounded bg-track px-1 text-sub line-through">삭제</span>
    </p>
    <div
      class="mx-auto max-w-[693px] rounded-[18px] border-[1.5px] border-line bg-white px-6 py-6 text-[15px] leading-[1.8] whitespace-pre-wrap sm:px-10"
    >
      <template v-for="(part, index) in parts" :key="index">
        <ins v-if="part.added" class="bg-lilac-soft no-underline">{{
          part.value
        }}</ins>
        <del v-else-if="part.removed" class="bg-track text-sub">{{ part.value }}</del>
        <span v-else>{{ part.value }}</span>
      </template>
    </div>
  </div>
</template>
