<script setup lang="ts">
import { ref } from 'vue'
import { settingsApi } from '@/lib/api'
import { validationErrors } from '@/lib/http'
import { tistoryWriteUrl } from '@/lib/tistory'
import { useAuthStore } from '@/stores/auth'

/** 관리 › 티스토리 연결: 내 블로그 주소(6단계 글쓰기 열기)와 카카오 REST API 키(상위 글 찾기) */
const auth = useAuthStore()
const hostInput = ref(auth.tistoryHost ?? '')
const keyInput = ref('')
const message = ref<{ ok: boolean; text: string } | null>(null)
const testing = ref(false)

async function save(input: { tistory_host?: string; kakao_key?: string }, done: string) {
  message.value = null
  try {
    const saved = await settingsApi.saveBlogs(input)
    auth.tistoryHost = saved.tistory_host
    auth.kakaoReady = saved.kakao_ready
    hostInput.value = saved.tistory_host ?? ''
    keyInput.value = ''
    message.value = { ok: true, text: done }
  } catch (e) {
    message.value = { ok: false, text: Object.values(validationErrors(e) ?? {})[0]?.[0] ?? '저장하지 못했어요.' }
  }
}

function saveHost() {
  return save({ tistory_host: hostInput.value }, hostInput.value.trim() ? '저장했어요. 6단계 티스토리 탭에서 이 블로그 글쓰기가 열려요.' : '지웠어요.')
}

function saveKey() {
  return save({ kakao_key: keyInput.value.trim() }, keyInput.value.trim() ? '키를 저장했어요. “연결 확인”으로 검색이 되는지 볼 수 있어요.' : '키를 지웠어요.')
}

async function test() {
  testing.value = true
  message.value = null
  try {
    const result = await settingsApi.testKakao()
    message.value = result.ok
      ? { ok: true, text: `연결됐어요. 시험 검색에서 티스토리 글 ${result.found}편을 찾았어요.` }
      : { ok: false, text: result.error ?? '연결하지 못했어요.' }
  } catch {
    message.value = { ok: false, text: '확인하지 못했어요. 잠시 뒤 다시 해 주세요.' }
  } finally {
    testing.value = false
  }
}
</script>

<template>
  <section class="flex flex-col gap-3 rounded-[18px] border-[1.5px] border-line bg-white p-5">
    <h2 class="m-0 flex items-center gap-2 text-[17px] font-bold">
      <span class="flex size-5 items-center justify-center rounded-[5px] bg-[#ff5a4a] text-[11px] font-extrabold text-white" aria-hidden="true">T</span>
      내 티스토리 블로그
    </h2>
    <form class="flex flex-col gap-2" @submit.prevent="saveHost">
      <p class="m-0 text-[13px] text-sub">6단계 티스토리 탭에서 이 블로그의 글쓰기를 바로 열어요.</p>
      <div class="flex flex-wrap gap-2">
        <input
          v-model="hostInput"
          aria-label="티스토리 블로그 주소"
          placeholder="예: myblog 또는 https://myblog.tistory.com"
          class="min-w-0 flex-[1_1_14rem] rounded-xl border-[1.5px] border-line px-3 py-2.5 text-sm outline-none focus:border-ink"
        />
        <button type="submit" class="h-10 rounded-xl bg-ink px-4 text-sm font-bold text-cream">저장</button>
      </div>
      <div v-if="auth.tistoryHost" class="flex flex-wrap items-center gap-3 text-[13px]">
        <a :href="tistoryWriteUrl(auth.tistoryHost)" target="_blank" rel="noopener noreferrer" class="font-bold">글쓰기 열어 보기 ↗</a>
        <span class="text-sub">{{ tistoryWriteUrl(auth.tistoryHost) }}</span>
      </div>
    </form>

    <form class="flex flex-col gap-2 border-t-[1.5px] border-line pt-3" @submit.prevent="saveKey">
      <span class="flex items-center gap-2 text-sm font-bold">
        카카오 REST API 키
        <span :class="auth.kakaoReady ? 'bg-lilac' : 'bg-track'" class="rounded-full px-2 py-0.5 text-[11px] font-extrabold">
          {{ auth.kakaoReady ? '넣음' : '없음' }}
        </span>
      </span>
      <p class="m-0 text-[13px] leading-normal text-sub">
        티스토리 카테고리에서 다음 검색 상위 글을 자동으로 찾을 때 써요. developers.kakao.com › 내 애플리케이션 › 앱 키의 <b class="text-ink">REST API 키</b>를 넣으세요.
        키는 암호화해 저장하고 다시 보여 주지 않아요.
      </p>
      <div class="flex flex-wrap gap-2">
        <input
          v-model="keyInput"
          type="password"
          autocomplete="off"
          aria-label="카카오 REST API 키"
          :placeholder="auth.kakaoReady ? '바꾸려면 새 키를 넣으세요' : 'REST API 키'"
          class="min-w-0 flex-[1_1_14rem] rounded-xl border-[1.5px] border-line px-3 py-2.5 text-sm outline-none focus:border-ink"
        />
        <button type="submit" :disabled="!keyInput.trim()" class="h-10 rounded-xl bg-ink px-4 text-sm font-bold text-cream disabled:opacity-50">저장</button>
        <button
          v-if="auth.kakaoReady"
          type="button"
          :disabled="testing"
          class="h-10 rounded-xl border-[1.5px] border-ink px-4 text-sm font-bold disabled:opacity-50"
          @click="test"
        >
          {{ testing ? '확인 중…' : '연결 확인' }}
        </button>
        <button v-if="auth.kakaoReady" type="button" class="h-10 px-2 text-[13px] text-sub underline" @click="save({ kakao_key: '' }, '키를 지웠어요.')">지우기</button>
      </div>
    </form>

    <span v-if="message" :role="message.ok ? 'status' : 'alert'" :class="message.ok ? 'text-sub' : 'text-red-600'" class="text-[13px]">{{ message.text }}</span>
  </section>
</template>
