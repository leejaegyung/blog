<script setup lang="ts">
defineProps<{ disabled?: boolean; busy?: boolean }>()
defineEmits<{ click: [] }>()
</script>

<template>
  <button
    type="button"
    :disabled="disabled || busy"
    class="h-[58px] rounded-[18px] bg-ink px-7 text-[17px] font-bold text-cream disabled:opacity-50 lg:h-[52px] lg:rounded-2xl lg:text-base"
    @click="$emit('click')"
  >
    <slot />
  </button>
</template>
