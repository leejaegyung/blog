import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { AxiosError } from 'axios'

import App from './App.vue'
import router from './router'
import { http } from './lib/http'
import { useAuthStore } from './stores/auth'

const app = createApp(App)

app.use(createPinia())
app.use(router)

// 세션이 끊기면 로그인 화면으로 보낸다.
http.interceptors.response.use(undefined, (error) => {
  if (error instanceof AxiosError && error.response?.status === 401) {
    useAuthStore().clear()
    const current = router.currentRoute.value
    if (!current.meta.guest) router.replace({ name: 'login', query: { redirect: current.fullPath } })
  }
  return Promise.reject(error)
})

app.mount('#app')
