<script setup lang="ts">
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

async function logout() {
  await auth.logout()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <div class="min-h-screen bg-stone-50 text-stone-900">
    <header
      class="flex items-center justify-between gap-4 border-b border-stone-200 bg-white px-4 py-3 sm:px-6"
    >
      <RouterLink to="/" class="text-lg font-semibold">Blog AI</RouterLink>
      <div v-if="auth.user" class="flex items-center gap-3 text-sm">
        <RouterLink to="/admin" class="rounded-md px-2 py-1 text-stone-600 hover:bg-stone-100">
          관리
        </RouterLink>
        <span class="text-stone-500">{{ auth.user.name }}</span>
        <button
          v-if="!auth.autoLogin"
          type="button"
          class="rounded-md px-2 py-1 text-stone-600 hover:bg-stone-100"
          @click="logout"
        >
          로그아웃
        </button>
      </div>
    </header>
    <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
      <RouterView />
    </main>
  </div>
</template>
