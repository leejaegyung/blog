<script setup lang="ts">
defineProps<{ label: string; share: number }>()
</script>

<template>
  <div class="grid grid-cols-[minmax(0,8rem)_1fr_3rem] items-center gap-2 text-sm">
    <span class="truncate text-stone-700">{{ label }}</span>
    <div class="h-2 rounded-full bg-stone-100" aria-hidden="true">
      <div class="h-2 rounded-full bg-stone-800" :style="{ width: `${Math.round(share * 100)}%` }" />
    </div>
    <span class="text-right text-stone-500 tabular-nums">{{ Math.round(share * 100) }}%</span>
  </div>
</template>
