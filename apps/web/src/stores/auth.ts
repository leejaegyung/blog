import { ref } from 'vue'
import { defineStore } from 'pinia'
import { AxiosError } from 'axios'
import { authApi, type User } from '@/lib/api'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const loaded = ref(false)
  // 1인용 로컬 자동 로그인 모드(서버 AUTH_AUTO_LOGIN). 이때는 로그아웃해도 곧바로 다시 로그인된다
  const autoLogin = ref(false)

  async function fetchUser() {
    try {
      const result = await authApi.me()
      user.value = result.user
      autoLogin.value = result.autoLogin
    } catch (error) {
      if (!(error instanceof AxiosError && error.response?.status === 401)) throw error
      user.value = null
    } finally {
      loaded.value = true
    }
  }

  async function login(login: string, password: string, remember = false) {
    user.value = await authApi.login(login, password, remember)
    loaded.value = true
  }

  async function logout() {
    await authApi.logout()
    user.value = null
  }

  function clear() {
    user.value = null
  }

  return { user, loaded, autoLogin, fetchUser, login, logout, clear }
})
