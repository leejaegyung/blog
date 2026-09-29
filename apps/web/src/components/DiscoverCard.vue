<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import { referenceApi } from '@/lib/api'
import { validationErrors } from '@/lib/http'
import { useAuthStore } from '@/stores/auth'

/** 티스토리 카테고리: 카카오(다음) 검색 상위 티스토리 글을 찾아 학습할 글로 넣고 학습까지 예약한다 */
const props = defineProps<{ projectId: number; defaultQuery: string }>()
const emit = defineEmits<{ added: [learning: boolean] }>()
const auth = useAuthStore()

const SIZES = [5, 10, 20]
const query = ref(props.defaultQuery)
const size = ref(10)
const busy = ref(false)
const result = ref<{ added: number; skipped: number; found: number; learning: boolean } | null>(null)
const error = ref<string | null>(null)

async function discover() {
  busy.value = true
  error.value = null
  result.value = null
  try {
    const data = await referenceApi.discover(props.projectId, query.value.trim(), size.value)
    result.value = { added: data.data.length, skipped: data.skipped.length, found: data.found, learning: data.learning }
    emit('added', data.learning)
  } catch (e) {
    error.value = Object.values(validationErrors(e) ?? {})[0]?.[0] ?? '상위 글을 가져오지 못했어요. 잠시 뒤 다시 해 주세요.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="flex flex-col gap-3 rounded-[18px] border-2 border-ink bg-white p-5">
    <div class="flex items-center gap-2">
      <span class="flex size-6 items-center justify-center rounded-md bg-[#ff5a4a] text-xs font-extrabold text-white" aria-hidden="true">T</span>
      <h2 class="m-0 text-base font-bold">상위 글 자동으로 가져와 학습</h2>
    </div>
    <p class="m-0 text-[13px] leading-normal text-sub">
      다음(카카오) 검색에서 이 검색어로 위에 뜨는 티스토리 글을 찾아 한 편씩 읽고, 글 구성·길이·사진 배치 같은 특징만 학습해요(원문은 저장하지 않아요).
    </p>

    <p v-if="!auth.kakaoReady" class="m-0 rounded-xl border-[1.5px] border-ink bg-lemon px-3 py-2 text-sm">
      카카오 REST API 키가 필요해요.
      <RouterLink :to="{ name: 'admin' }" class="font-bold">관리 › 블로그 연결</RouterLink>에서 넣어 주세요.
    </p>

    <form v-else class="flex flex-col gap-2.5" @submit.prevent="discover">
      <div class="flex flex-col gap-2 rounded-2xl border-2 border-ink bg-white p-1.5 sm:flex-row sm:items-center sm:pl-4">
        <input
          v-model="query"
          required
          minlength="2"
          maxlength="100"
          aria-label="검색어"
          placeholder="검색어 · 예: 수원 맛집"
          class="min-w-0 flex-1 bg-transparent px-2.5 py-2 text-base outline-none placeholder:text-muted sm:px-0"
        />
        <button type="submit" :disabled="busy || !query.trim()" class="h-11 rounded-xl bg-ink px-5 text-sm font-bold whitespace-nowrap text-cream disabled:opacity-50">
          {{ busy ? '찾는 중…' : '가져와 학습하기' }}
        </button>
      </div>
      <div class="flex flex-wrap items-center gap-1.5 text-[13px]" role="radiogroup" aria-label="가져올 글 수">
        <span class="font-bold">몇 편</span>
        <button
          v-for="n in SIZES"
          :key="n"
          type="button"
          role="radio"
          :aria-checked="size === n"
          :class="size === n ? 'bg-lilac' : 'bg-white'"
          class="rounded-full border-[1.5px] border-ink px-3 py-1 font-semibold"
          @click="size = n"
        >
          {{ n }}
        </button>
      </div>
    </form>

    <p v-if="error" role="alert" class="m-0 rounded-xl border-2 border-ink bg-lemon px-3 py-2 text-sm font-semibold">{{ error }}</p>
    <p v-if="result" role="status" class="m-0 rounded-xl bg-lilac px-3 py-2 text-sm">
      <b>{{ result.found }}편</b>을 찾아 <b>{{ result.added }}편</b>을 새로 넣었어요<template v-if="result.skipped"> (이미 있던 {{ result.skipped }}편은 건너뜀)</template>.
      {{ result.learning ? '글을 다 읽으면 1분쯤 뒤 학습을 시작해요.' : result.added ? '' : '새 글이 없어 학습은 그대로예요.' }}
    </p>
  </section>
</template>
