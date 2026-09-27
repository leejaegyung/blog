import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue(), vueDevTools(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    // 로컬 개발(npm run dev) 시 API는 Docker의 NGINX(:8080)로 넘긴다.
    proxy: {
      '/api': 'http://localhost:8080',
      '/sanctum': 'http://localhost:8080',
    },
  },
})
