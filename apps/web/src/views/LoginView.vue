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
    class="mx-auto mt-6 flex max-w-sm flex-col gap-4 rounded-[26px] border-2 border-ink bg-lilac p-6 lg:mt-12 lg:rounded-[28px] lg:p-8"
    @submit.prevent="submit"
  >
    <h1 class="m-0 font-display text-[32px] leading-[1.1] font-normal">다시 오셨네요</h1>
    <label class="flex flex-col gap-1.5">
      <span class="text-[13px] font-bold">아이디 또는 이메일</span>
      <input
        v-model="login"
        type="text"
        autocomplete="username"
        autocapitalize="none"
        spellcheck="false"
        required
        class="w-full rounded-2xl border-2 border-ink bg-white px-4 py-3 text-base outline-none"
      />
    </label>
    <label class="flex flex-col gap-1.5">
      <span class="text-[13px] font-bold">비밀번호</span>
      <input
        v-model="password"
        type="password"
        autocomplete="current-password"
        required
        class="w-full rounded-2xl border-2 border-ink bg-white px-4 py-3 text-base outline-none"
      />
    </label>
    <label class="flex items-center gap-2 text-sm font-semibold">
      <input v-model="remember" type="checkbox" class="size-4 accent-ink" />
      로그인 유지
    </label>
    <p v-if="error" role="alert" class="m-0 rounded-xl border-2 border-ink bg-lemon px-3 py-2 text-sm font-semibold">{{ error }}</p>
    <button
      type="submit"
      :disabled="submitting"
      class="h-14 w-full rounded-[18px] bg-ink px-5 text-[17px] font-bold text-cream disabled:opacity-50"
    >
      {{ submitting ? '로그인 중…' : '로그인' }}
    </button>
  </form>
</template>
