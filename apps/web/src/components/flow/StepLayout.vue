<script setup lang="ts">
import { STEP_NAMES, type StepNo } from '@/lib/flow'

// leadMobileOnly: 디자인에서 웹 화면에는 설명 문장이 없는 단계(3단계)
defineProps<{ step: StepNo; title: string; lead?: string; leadMobileOnly?: boolean }>()
</script>

<template>
  <div class="grid min-h-0 lg:grid-cols-[minmax(0,1fr)_320px]">
    <div class="flex min-w-0 flex-col gap-5 px-5 pt-6 lg:gap-6 lg:px-12 lg:pt-10">
      <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div class="flex min-w-0 flex-col gap-2">
          <span class="hidden text-[13px] font-bold text-accent lg:block">{{ step }}단계 · {{ STEP_NAMES[step - 1] }}</span>
          <!-- 제목의 줄바꿈(\n)은 모바일에서만 지킨다(디자인 1a) -->
          <h1 class="m-0 font-display text-[32px] leading-[1.1] font-normal lg:text-[40px]">
            <template v-for="(line, i) in title.split('\n')" :key="i"><br v-if="i" class="lg:hidden" /><span v-if="i" class="hidden lg:inline">{{ ' ' }}</span>{{ line }}</template>
          </h1>
          <p v-if="lead" :class="leadMobileOnly ? 'lg:hidden' : ''" class="m-0 text-[15px] leading-normal text-body lg:text-base">{{ lead }}</p>
        </div>
        <slot name="title-side" />
      </div>
      <slot />
      <!-- 모바일은 도움말을 본문 아래에 둔다 -->
      <div v-if="$slots.aside" class="flex flex-col gap-3.5 lg:hidden">
        <slot name="aside" />
      </div>
      <div
        class="sticky bottom-0 z-10 -mx-5 mt-auto flex flex-col-reverse gap-2 border-t border-line bg-cream px-5 pt-4 pb-[calc(env(safe-area-inset-bottom,0px)+24px)] sm:flex-row sm:items-center sm:justify-between lg:static lg:mx-0 lg:border-t-[1.5px] lg:px-0 lg:pt-[18px] lg:pb-6"
      >
        <!-- 모바일은 위쪽 ← 가 이전 단계라 여기서는 숨긴다 -->
        <div class="hidden text-[15px] font-semibold sm:flex"><slot name="back" /></div>
        <div class="flex flex-col-reverse items-stretch gap-2 sm:flex-row sm:items-center sm:gap-4"><slot name="next" /></div>
      </div>
    </div>
    <aside
      v-if="$slots.aside"
      class="hidden flex-col gap-3.5 border-l-[1.5px] border-line bg-panel px-[22px] py-7 lg:flex"
    >
      <slot name="aside" />
    </aside>
  </div>
</template>
