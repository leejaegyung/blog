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
        <!-- 관리: 테마(잉크 테두리·레몬 강조)에 맞춘 톱니바퀴 아이콘 버튼 -->
        <RouterLink
          to="/admin"
          aria-label="관리"
          title="관리"
          :class="route.name === 'admin' ? 'bg-lemon' : 'bg-white hover:bg-lemon'"
          class="flex size-10 items-center justify-center rounded-xl border-[1.5px] border-ink text-ink no-underline transition-colors hover:text-ink"
        >
          <svg viewBox="0 0 24 24" class="size-[22px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="3" />
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
          </svg>
        </RouterLink>
      </div>
    </header>
    <main :class="wide ? '' : 'mx-auto max-w-5xl px-4 py-8 sm:px-6'">
      <RouterView />
    </main>
  </div>
</template>
