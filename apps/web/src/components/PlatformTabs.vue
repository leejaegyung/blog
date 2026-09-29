<script setup lang="ts">
import { computed } from 'vue'
import type { Platform } from '@/lib/api'
import { PLATFORM_LABEL } from '@/lib/tistory'

/** 네이버 | 티스토리 (| 둘 다) 고르기 — 카테고리별 학습·글쓰기 1단계·6단계 */
type Choice = Platform | 'both'
const props = defineProps<{ label: string; both?: boolean }>()
const model = defineModel<Choice>({ required: true })
const options = computed<Choice[]>(() => (props.both ? ['naver', 'tistory', 'both'] : ['naver', 'tistory']))
const text = (option: Choice) => (option === 'both' ? '둘 다' : PLATFORM_LABEL[option])
</script>

<template>
  <div class="flex gap-1 self-start rounded-2xl border-2 border-ink bg-white p-1" role="tablist" :aria-label="label">
    <button
      v-for="option in options"
      :key="option"
      type="button"
      role="tab"
      :aria-selected="model === option"
      :class="model === option ? 'bg-ink text-cream' : 'text-ink hover:bg-lilac-soft'"
      class="flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-bold"
      @click="model = option"
    >
      <span class="flex gap-0.5" aria-hidden="true">
        <span
          v-for="mark in option === 'both' ? (['naver', 'tistory'] as const) : [option]"
          :key="mark"
          :class="mark === 'naver' ? 'bg-[#03c75a]' : 'bg-[#ff5a4a]'"
          class="flex size-4 items-center justify-center rounded-[5px] text-[10px] leading-none font-extrabold text-white"
        >
          {{ mark === 'naver' ? 'N' : 'T' }}
        </span>
      </span>
      {{ text(option) }}
    </button>
  </div>
</template>
