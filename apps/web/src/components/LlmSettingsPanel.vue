<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { llmApi, type LlmProvider, type LlmState, type LlmTarget, type LlmTestResult } from '@/lib/api'
import { validationErrors } from '@/lib/http'

const SOURCE = {
  admin: { label: '관리 화면에서 설정', color: 'bg-emerald-100 text-emerald-800' },
  env: { label: '.env에서 설정', color: 'bg-sky-100 text-sky-800' },
  none: { label: '키 없음', color: 'bg-stone-100 text-stone-600' },
} as const

const ERROR_KINDS: Record<string, string> = {
  billing: '크레딧(잔액) 부족',
  auth: '키가 올바르지 않거나 권한 없음',
  not_configured: '키가 설정되지 않음',
  rate_limited: '요청 한도 초과',
  unavailable: '공급자 서버 오류·연결 실패',
  bad_request: '요청 오류(모델 이름 확인)',
  refused: '응답 거절',
  truncated: '응답이 잘림',
  invalid_output: '응답 형식 오류',
}

const state = ref<LlmState | null>(null)
const route = ref<LlmTarget[]>([])
const keyInputs = reactive<Record<string, string>>({})
const messages = reactive<Record<string, { ok: boolean; text: string } | null>>({})
const tests = reactive<Record<string, LlmTestResult | 'running' | null>>({})
const routeMessage = ref<{ ok: boolean; text: string } | null>(null)
const loadError = ref(false)

function apply(next: LlmState) {
  state.value = next
  route.value = next.route.map((t) => ({ ...t }))
}

async function load() {
  try {
    apply(await llmApi.get())
  } catch {
    loadError.value = true
  }
}

function errorText(error: unknown, fallback: string) {
  const errors = validationErrors(error)
  return errors ? (Object.values(errors)[0]?.[0] ?? fallback) : fallback
}

async function saveKey(provider: LlmProvider) {
  messages[provider.provider] = null
  try {
    apply(await llmApi.saveKey(provider.provider, keyInputs[provider.provider] ?? ''))
    keyInputs[provider.provider] = ''
    messages[provider.provider] = { ok: true, text: '저장했습니다. 바로 적용됩니다.' }
  } catch (e) {
    messages[provider.provider] = { ok: false, text: errorText(e, '저장하지 못했습니다.') }
  }
}

async function deleteKey(provider: LlmProvider) {
  apply(await llmApi.deleteKey(provider.provider))
  messages[provider.provider] = {
    ok: true,
    text: provider.source === 'admin' ? '관리 화면 키를 지웠습니다. .env 키가 있으면 그 키를 씁니다.' : '지웠습니다.',
  }
}

async function test(provider: LlmProvider) {
  const model = route.value.find((t) => t.provider === provider.provider)?.model ?? provider.models[0]
  tests[provider.provider] = 'running'
  try {
    tests[provider.provider] = await llmApi.test(`${provider.provider}:${model}`)
  } catch {
    tests[provider.provider] = { ok: false, reply: null, attempts: [] }
  }
}

function move(index: number, to: number) {
  if (to < 0 || to >= route.value.length) return
  const next = [...route.value]
  const [item] = next.splice(index, 1)
  next.splice(to, 0, item!)
  route.value = next
}

function addTarget() {
  route.value = [...route.value, { provider: 'openai', model: 'gpt-5.5' }]
}

async function saveRoute() {
  routeMessage.value = null
  try {
    apply(await llmApi.saveRoute(route.value))
    routeMessage.value = { ok: true, text: '순서를 저장했습니다.' }
  } catch (e) {
    routeMessage.value = { ok: false, text: errorText(e, '저장하지 못했습니다.') }
  }
}

async function resetRoute() {
  apply(await llmApi.resetRoute())
  routeMessage.value = { ok: true, text: '.env의 기본 순서로 되돌렸습니다.' }
}

function modelsFor(provider: string) {
  return state.value?.providers.find((p) => p.provider === provider)?.models ?? []
}

onMounted(load)
defineExpose({ load })
</script>

<template>
  <section class="space-y-4">
    <h2 class="text-lg font-semibold">AI 공급자</h2>
    <p class="text-sm text-stone-500">
      관리 화면에서 넣은 키는 암호화해 저장하고 .env 키보다 먼저 씁니다. 저장하면 재시작 없이 바로
      적용됩니다. 키 원문은 다시 보여주지 않습니다.
    </p>
    <p v-if="loadError" role="alert" class="text-red-600">AI 공급자 설정을 불러오지 못했습니다.</p>

    <div v-if="state" class="grid gap-4 md:grid-cols-2">
      <div
        v-for="provider in state.providers"
        :key="provider.provider"
        class="space-y-3 rounded-xl border border-stone-200 bg-white p-4"
      >
        <div class="flex flex-wrap items-center gap-2">
          <h3 class="font-medium">{{ provider.label }}</h3>
          <span :class="SOURCE[provider.source].color" class="rounded-full px-2 py-0.5 text-xs">
            {{ SOURCE[provider.source].label }}
          </span>
        </div>
        <p class="font-mono text-sm text-stone-600">{{ provider.masked_key ?? '—' }}</p>

        <form class="flex flex-wrap gap-2" @submit.prevent="saveKey(provider)">
          <input
            v-model="keyInputs[provider.provider]"
            type="password"
            autocomplete="off"
            spellcheck="false"
            :placeholder="`${provider.key_prefix}… 새 키`"
            :aria-label="`${provider.label} API 키`"
            class="min-w-0 flex-[1_1_12rem] rounded-md border border-stone-300 px-3 py-2 font-mono text-sm"
          />
          <button
            type="submit"
            :disabled="!keyInputs[provider.provider]"
            class="rounded-md bg-stone-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-40"
          >
            키 저장
          </button>
        </form>

        <div class="flex flex-wrap items-center gap-2 text-sm">
          <button
            type="button"
            :disabled="provider.source === 'none' || tests[provider.provider] === 'running'"
            class="rounded-md border border-stone-300 px-3 py-1.5 hover:bg-stone-50 disabled:opacity-40"
            @click="test(provider)"
          >
            {{ tests[provider.provider] === 'running' ? '확인 중…' : '연결 테스트' }}
          </button>
          <button
            v-if="provider.source === 'admin'"
            type="button"
            class="rounded-md px-3 py-1.5 text-red-600 hover:bg-red-50"
            @click="deleteKey(provider)"
          >
            관리 화면 키 지우기
          </button>
          <a
            :href="provider.console"
            target="_blank"
            rel="noopener noreferrer"
            class="ml-auto text-stone-500 underline"
          >
            키 발급·크레딧 확인
          </a>
        </div>

        <p
          v-if="messages[provider.provider]"
          :role="messages[provider.provider]!.ok ? 'status' : 'alert'"
          :class="messages[provider.provider]!.ok ? 'text-stone-600' : 'text-red-600'"
          class="text-sm"
        >
          {{ messages[provider.provider]!.text }}
        </p>
        <template v-if="tests[provider.provider] && tests[provider.provider] !== 'running'">
          <p
            v-for="attempt in (tests[provider.provider] as LlmTestResult).attempts"
            :key="attempt.model"
            role="status"
            :class="attempt.status === 'success' ? 'text-emerald-700' : 'text-red-600'"
            class="text-sm"
          >
            {{ attempt.model }}:
            {{
              attempt.status === 'success'
                ? `연결됨 (${(attempt.latency_ms / 1000).toFixed(1)}초)`
                : (ERROR_KINDS[attempt.error_kind ?? ''] ?? attempt.error_kind ?? '실패')
            }}
          </p>
          <p
            v-if="!(tests[provider.provider] as LlmTestResult).attempts.length"
            role="alert"
            class="text-sm text-red-600"
          >
            AI Worker에 연결하지 못했습니다.
          </p>
        </template>
      </div>
    </div>

    <div v-if="state" class="space-y-3 rounded-xl border border-stone-200 bg-white p-4">
      <div class="flex flex-wrap items-center gap-2">
        <h3 class="font-medium">시도 순서</h3>
        <span class="text-xs text-stone-500">
          {{ state.route_source === 'admin' ? '관리 화면에서 설정' : '.env 기본값' }}
        </span>
      </div>
      <p class="text-sm text-stone-500">
        위에서부터 시도하고, 실패(크레딧 부족·장애·거절 등)하면 다음 모델로 넘어갑니다.
      </p>
      <ol class="space-y-2">
        <li v-for="(target, index) in route" :key="index" class="flex flex-wrap items-center gap-2">
          <span class="w-5 text-sm text-stone-400 tabular-nums">{{ index + 1 }}</span>
          <select
            v-model="target.provider"
            :aria-label="`${index + 1}번 공급자`"
            class="rounded-md border border-stone-300 px-2 py-1.5 text-sm"
            @change="target.model = modelsFor(target.provider)[0] ?? ''"
          >
            <option v-for="p in state.providers" :key="p.provider" :value="p.provider">{{ p.label }}</option>
          </select>
          <input
            v-model="target.model"
            :list="`models-${target.provider}`"
            :aria-label="`${index + 1}번 모델`"
            class="min-w-0 flex-[1_1_10rem] rounded-md border border-stone-300 px-2 py-1.5 font-mono text-sm"
          />
          <button type="button" :disabled="index === 0" :aria-label="`${index + 1}번 위로`" class="rounded px-1.5 hover:bg-stone-100 disabled:opacity-30" @click="move(index, index - 1)">↑</button>
          <button type="button" :disabled="index === route.length - 1" :aria-label="`${index + 1}번 아래로`" class="rounded px-1.5 hover:bg-stone-100 disabled:opacity-30" @click="move(index, index + 1)">↓</button>
          <button type="button" :disabled="route.length === 1" :aria-label="`${index + 1}번 삭제`" class="rounded px-1.5 text-red-600 hover:bg-red-50 disabled:opacity-30" @click="route = route.filter((_, i) => i !== index)">✕</button>
        </li>
      </ol>
      <datalist v-for="p in state.providers" :id="`models-${p.provider}`" :key="p.provider">
        <option v-for="m in p.models" :key="m" :value="m" />
      </datalist>
      <div class="flex flex-wrap items-center gap-2">
        <button type="button" :disabled="route.length >= 6" class="rounded-md border border-stone-300 px-3 py-1.5 text-sm hover:bg-stone-50 disabled:opacity-40" @click="addTarget">+ 모델 추가</button>
        <button type="button" class="rounded-md bg-stone-900 px-3 py-1.5 text-sm font-medium text-white" @click="saveRoute">순서 저장</button>
        <button v-if="state.route_source === 'admin'" type="button" class="rounded-md px-3 py-1.5 text-sm text-stone-600 hover:bg-stone-100" @click="resetRoute">기본값으로</button>
        <span
          v-if="routeMessage"
          :role="routeMessage.ok ? 'status' : 'alert'"
          :class="routeMessage.ok ? 'text-stone-600' : 'text-red-600'"
          class="text-sm"
        >
          {{ routeMessage.text }}
        </span>
      </div>
    </div>
  </section>
</template>
