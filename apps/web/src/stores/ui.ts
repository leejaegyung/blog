import { ref } from 'vue'
import { defineStore } from 'pinia'

export type SaveState = 'saved' | 'dirty' | 'saving' | 'error'

/** 헤더에 보이는 현재 글 이름(브레드크럼)과 자동 저장 상태 */
export const useUiStore = defineStore('ui', () => {
  const crumb = ref<string | null>(null)
  const saveState = ref<SaveState | null>(null)
  return { crumb, saveState }
})
