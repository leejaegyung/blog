<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { validationErrors } from '@/lib/http'
import { AxiosError } from 'axios'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const login = ref('')
const password = ref('')
const remember = ref(true)
const submitting = ref(false)
const error = ref<string | null>(null)

async function submit() {
  submitting.value = true
  error.value = null
  try {
    await auth.login(login.value, password.value, remember.value)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/projects'
    await router.replace(redirect)
  } catch (e) {
    const errors = validationErrors(e)
    if (errors) error.value = errors.login?.[0] ?? '입력값을 확인해 주세요.'
    else if (e instanceof AxiosError && e.response?.status === 429)
      error.value = '로그인 시도가 너무 많습니다. 1분 뒤 다시 시도해 주세요.'
    else error.value = '로그인하지 못했습니다. 잠시 뒤 다시 시도해 주세요.'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <form
    class="mx-auto mt-12 max-w-sm space-y-4 rounded-xl border border-stone-200 bg-white p-6"
    @submit.prevent="submit"
  >
    <h1 class="text-xl font-semibold">로그인</h1>
    <label class="block space-y-1">
      <span class="text-sm text-stone-600">아이디 또는 이메일</span>
      <input
        v-model="login"
        type="text"
        autocomplete="username"
        autocapitalize="none"
        spellcheck="false"
        required
        class="w-full rounded-md border border-stone-300 px-3 py-2"
      />
    </label>
    <label class="block space-y-1">
      <span class="text-sm text-stone-600">비밀번호</span>
      <input
        v-model="password"
        type="password"
        autocomplete="current-password"
        required
        class="w-full rounded-md border border-stone-300 px-3 py-2"
      />
    </label>
    <label class="flex items-center gap-2 text-sm text-stone-600">
      <input v-model="remember" type="checkbox" />
      로그인 유지
    </label>
    <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
    <button
      type="submit"
      :disabled="submitting"
      class="w-full rounded-md bg-stone-900 px-4 py-2 font-medium text-white disabled:opacity-50"
    >
      {{ submitting ? '로그인 중…' : '로그인' }}
    </button>
  </form>
</template>
