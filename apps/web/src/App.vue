<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

const auth = useAuthStore()
const ui = useUiStore()
const route = useRoute()
const wide = computed(() => route.meta.wide === true)
const flow = computed(() => route.meta.flow === true)

const SAVE_LABELS = { saved: '자동 저장됨', dirty: '고치는 중…', saving: '저장 중…', error: '저장 실패' } as const
</script>

<template>
  <div class="min-h-dvh bg-cream text-ink">
    <header
      :class="flow ? 'hidden lg:flex' : 'flex'"
      class="sticky top-[env(safe-area-inset-top,0px)] z-20 h-16 items-center justify-between gap-4 border-b-[1.5px] border-line bg-cream/95 px-4 backdrop-blur sm:px-7"
    >
      <div class="flex min-w-0 items-center gap-[18px]">
        <RouterLink to="/" class="font-display text-[26px] leading-none no-underline hover:text-ink">Blog AI</RouterLink>
        <span v-if="ui.crumb" class="hidden truncate text-sm text-sub sm:inline">
          <RouterLink to="/" class="text-sub no-underline">내 글</RouterLink> / <b class="text-ink">{{ ui.crumb }}</b>
        </span>
      </div>
      <div v-if="auth.user" class="flex items-center gap-4 text-sm text-sub">
        <span v-if="ui.saveState" :class="ui.saveState === 'error' ? 'text-red-600' : ''" role="status">
          {{ SAVE_LABELS[ui.saveState] }}
        </span>
        <RouterLink to="/admin" class="text-sub no-underline hover:text-accent">관리</RouterLink>
        <span class="size-8 rounded-full bg-lilac" :title="auth.user.name" aria-hidden="true" />
      </div>
    </header>
    <main :class="wide ? '' : 'mx-auto max-w-5xl px-4 py-8 sm:px-6'">
      <RouterView />
    </main>
  </div>
</template>
