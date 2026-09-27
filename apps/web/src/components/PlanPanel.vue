<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { postApi, type PlanSection, type Post, type PostImage } from '@/lib/api'
import { validationErrors } from '@/lib/http'

const props = defineProps<{ post: Post; images: PostImage[] }>()
const emit = defineEmits<{ updated: [post: Post] }>()

const plan = computed(() => props.post.plan)
const outline = ref<PlanSection[]>([])
const title = ref<string | null>(null)
const dirty = ref(false)
const saving = ref(false)
const message = ref<{ ok: boolean; text: string } | null>(null)

const imageById = computed(() => new Map(props.images.map((image) => [image.id, image])))
const photoNumber = computed(
  () => new Map(props.images.map((image, index) => [image.id, index + 1])),
)

watch(
  () => props.post.plan,
  (value) => {
    outline.value = value ? value.outline.map((s) => ({ ...s, key_points: [...s.key_points] })) : []
    title.value = props.post.title
    dirty.value = false
  },
  { immediate: true },
)

function markDirty() {
  dirty.value = true
  message.value = null
}

function move(index: number, to: number) {
  if (to < 0 || to >= outline.value.length) return
  const next = [...outline.value]
  const [section] = next.splice(index, 1)
  next.splice(to, 0, section!)
  outline.value = next
  markDirty()
}

function remove(index: number) {
  outline.value = outline.value.filter((_, i) => i !== index)
  markDirty()
}

function chooseTitle(candidate: string) {
  title.value = candidate
  markDirty()
}

async function save() {
  saving.value = true
  message.value = null
  try {
    const post = await postApi.savePlan(props.post.id, { title: title.value, outline: outline.value })
    emit('updated', post)
    message.value = { ok: true, text: '계획을 저장했습니다.' }
  } catch (error) {
    const errors = validationErrors(error)
    message.value = {
      ok: false,
      text: errors ? (Object.values(errors)[0]?.[0] ?? '입력값을 확인해 주세요.') : '저장하지 못했습니다.',
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div v-if="plan" class="space-y-5">
    <p
      v-if="post.plan_stale"
      role="status"
      class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900"
    >
      계획을 만든 뒤 사실 정보나 사진이 바뀌었습니다. 계획을 다시 만들어 주세요.
    </p>

    <fieldset class="space-y-2">
      <legend class="font-medium">제목 후보</legend>
      <label
        v-for="candidate in plan.title_candidates"
        :key="candidate"
        class="flex items-start gap-2 rounded-md px-2 py-1.5 hover:bg-stone-50"
      >
        <input
          type="radio"
          name="title"
          class="mt-1"
          :checked="title === candidate"
          @change="chooseTitle(candidate)"
        />
        <span>{{ candidate }}</span>
      </label>
    </fieldset>

    <p class="text-sm text-stone-600">검색 의도: {{ plan.search_intent }}</p>

    <div class="space-y-3">
      <h3 class="font-medium">목차</h3>
      <ol class="space-y-3">
        <li
          v-for="(section, index) in outline"
          :key="index"
          class="space-y-2 rounded-xl border border-stone-200 bg-white p-4"
        >
          <div class="flex items-center gap-2">
            <span class="text-sm text-stone-400 tabular-nums">{{ index + 1 }}</span>
            <input
              v-model="section.heading"
              maxlength="100"
              :aria-label="`${index + 1}번 섹션 소제목`"
              class="min-w-0 flex-1 rounded-md border border-stone-300 px-2 py-1 font-medium"
              @input="markDirty"
            />
            <button
              type="button"
              :disabled="index === 0"
              :aria-label="`${index + 1}번 섹션 위로`"
              class="rounded px-1.5 hover:bg-stone-100 disabled:opacity-30"
              @click="move(index, index - 1)"
            >
              ↑
            </button>
            <button
              type="button"
              :disabled="index === outline.length - 1"
              :aria-label="`${index + 1}번 섹션 아래로`"
              class="rounded px-1.5 hover:bg-stone-100 disabled:opacity-30"
              @click="move(index, index + 1)"
            >
              ↓
            </button>
            <button
              type="button"
              :aria-label="`${index + 1}번 섹션 삭제`"
              class="rounded px-1.5 text-red-600 hover:bg-red-50"
              @click="remove(index)"
            >
              ✕
            </button>
          </div>
          <textarea
            v-model="section.purpose"
            rows="2"
            maxlength="500"
            :aria-label="`${index + 1}번 섹션 내용`"
            class="w-full rounded-md border border-stone-300 px-2 py-1 text-sm"
            @input="markDirty"
          />
          <ul v-if="section.key_points.length" class="list-disc pl-5 text-sm text-stone-600">
            <li v-for="point in section.key_points" :key="point">{{ point }}</li>
          </ul>
          <div v-if="section.fact_keys.length" class="flex flex-wrap gap-1.5">
            <span
              v-for="key in section.fact_keys"
              :key="key"
              class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-800"
            >
              {{ key }}
            </span>
          </div>
          <div v-if="section.image_ids.length" class="flex flex-wrap gap-1.5">
            <figure v-for="id in section.image_ids" :key="id" class="relative">
              <img
                v-if="imageById.get(id)"
                :src="imageById.get(id)!.thumb_url"
                :alt="`사진 ${photoNumber.get(id)}`"
                class="size-14 rounded object-cover"
              />
              <figcaption
                class="absolute top-0.5 left-0.5 rounded bg-black/60 px-1 text-[10px] text-white"
              >
                {{ photoNumber.get(id) }}
              </figcaption>
            </figure>
          </div>
        </li>
      </ol>
    </div>

    <p v-if="plan.unplaced_image_ids.length" class="text-sm text-stone-600">
      아직 배치되지 않은 사진:
      {{ plan.unplaced_image_ids.map((id) => `${photoNumber.get(id) ?? '?'}번`).join(', ') }}
    </p>
    <p v-if="plan.unused_fact_keys.length" class="text-sm text-stone-600">
      어느 섹션에도 쓰이지 않은 사실: {{ plan.unused_fact_keys.join(', ') }}
    </p>

    <div v-if="plan.forbidden_claims.length" class="space-y-1">
      <h3 class="font-medium">쓰지 않을 내용</h3>
      <ul class="list-disc pl-5 text-sm text-stone-700">
        <li v-for="claim in plan.forbidden_claims" :key="claim">{{ claim }}</li>
      </ul>
    </div>

    <div class="flex flex-wrap gap-1.5">
      <span
        v-for="word in [...plan.keywords.primary, ...plan.keywords.secondary]"
        :key="word"
        class="rounded-full bg-stone-100 px-2.5 py-1 text-sm"
      >
        {{ word }}
      </span>
    </div>

    <div class="flex flex-wrap items-center gap-3">
      <button
        type="button"
        :disabled="!dirty || saving || outline.length === 0"
        class="rounded-md bg-stone-900 px-4 py-2 font-medium text-white disabled:opacity-50"
        @click="save"
      >
        {{ saving ? '저장 중…' : '계획 저장' }}
      </button>
      <span
        v-if="message"
        :role="message.ok ? 'status' : 'alert'"
        :class="message.ok ? 'text-emerald-700' : 'text-red-600'"
        class="text-sm"
      >
        {{ message.text }}
      </span>
    </div>
  </div>
</template>
