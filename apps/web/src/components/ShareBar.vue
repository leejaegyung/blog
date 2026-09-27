<script setup lang="ts">
defineProps<{ label: string; share: number }>()
</script>

<template>
  <div class="grid grid-cols-[minmax(0,8rem)_1fr_3rem] items-center gap-2 text-sm">
    <span class="truncate text-ink">{{ label }}</span>
    <div class="h-1.5 rounded-[3px] bg-track" aria-hidden="true">
      <div class="h-1.5 rounded-[3px] bg-ink" :style="{ width: `${Math.round(share * 100)}%` }" />
    </div>
    <span class="text-right text-sub tabular-nums">{{ Math.round(share * 100) }}%</span>
  </div>
</template>
