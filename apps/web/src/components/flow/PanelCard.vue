<script setup lang="ts">
// desktopOnly: 모바일 디자인에는 없고 웹 오른쪽 패널에만 있는 카드
defineProps<{ tone?: 'plain' | 'lemon' | 'lilac'; desktopOnly?: boolean }>()
</script>

<template>
  <div
    :class="[
      desktopOnly ? 'hidden lg:flex' : 'flex',
      tone === 'lemon'
        ? 'border-2 border-ink bg-lemon'
        : tone === 'lilac'
          ? 'border-2 border-ink bg-lilac'
          : 'border-[1.5px] border-line bg-white',
    ]"
    class="flex-col gap-1.5 rounded-[14px] p-3.5 text-[13px] leading-normal"
  >
    <slot />
  </div>
</template>
