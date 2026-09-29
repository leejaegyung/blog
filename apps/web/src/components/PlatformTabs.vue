<script setup lang="ts">
import type { Platform } from '@/lib/api'
import { PLATFORM_LABEL } from '@/lib/tistory'

/** 네이버 | 티스토리 고르기(카테고리별 학습·글쓰기 1단계·6단계) */
defineProps<{ label: string }>()
const model = defineModel<Platform>({ required: true })
const OPTIONS: Platform[] = ['naver', 'tistory']
</script>

<template>
  <div class="flex gap-1 self-start rounded-2xl border-2 border-ink bg-white p-1" role="tablist" :aria-label="label">
    <button
      v-for="option in OPTIONS"
      :key="option"
      type="button"
      role="tab"
      :aria-selected="model === option"
      :class="model === option ? 'bg-ink text-cream' : 'text-ink hover:bg-lilac-soft'"
      class="flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-bold"
      @click="model = option"
    >
      <span
        :class="option === 'naver' ? 'bg-[#03c75a]' : 'bg-[#ff5a4a]'"
        class="flex size-4 items-center justify-center rounded-[5px] text-[10px] leading-none font-extrabold text-white"
        aria-hidden="true"
      >
        {{ option === 'naver' ? 'N' : 'T' }}
      </span>
      {{ PLATFORM_LABEL[option] }}
    </button>
  </div>
</template>
