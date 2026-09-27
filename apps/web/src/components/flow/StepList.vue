<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import type { Post } from '@/lib/api'
import { STEP_NAMES, reachableStep, stepMeta, type StepNo } from '@/lib/flow'

const props = defineProps<{ post: Post | null; current: StepNo }>()

const items = computed(() =>
  STEP_NAMES.map((label, index) => {
    const n = (index + 1) as StepNo
    return {
      n,
      label,
      state: n < props.current ? 'done' : n === props.current ? 'current' : 'todo',
      meta: n === props.current ? '지금 할 일' : stepMeta(props.post, n),
      reachable: !!props.post && n <= reachableStep(props.post),
    }
  }),
)
</script>

<template>
  <nav aria-label="단계" class="flex flex-col gap-1.5">
    <component
      :is="item.reachable && item.state !== 'current' ? RouterLink : 'div'"
      v-for="item in items"
      :key="item.n"
      :to="item.reachable ? { name: 'flow', params: { id: post!.id, step: item.n } } : undefined"
      :aria-current="item.state === 'current' ? 'step' : undefined"
      :class="[
        item.state === 'current' ? 'border-2 border-ink bg-lemon' : 'border-2 border-transparent',
        item.reachable && item.state !== 'current' ? 'hover:bg-lilac-soft/60' : '',
      ]"
      class="flex items-center gap-3 rounded-[14px] p-3 text-ink no-underline hover:text-ink"
    >
      <span
        :class="
          item.state === 'done'
            ? 'bg-ink text-lemon'
            : item.state === 'current'
              ? 'bg-white text-ink'
              : 'bg-track text-[#8a7695]'
        "
        class="flex size-7 shrink-0 items-center justify-center rounded-full text-[13px] font-extrabold"
      >
        {{ item.state === 'done' ? '✓' : item.n }}
      </span>
      <span class="flex min-w-0 flex-col gap-px">
        <span :class="item.state === 'todo' ? 'text-[#8a7695]' : ''" class="text-[15px] font-bold">{{ item.label }}</span>
        <span v-if="item.meta" class="truncate text-xs text-sub">{{ item.meta }}</span>
      </span>
    </component>
  </nav>
</template>
