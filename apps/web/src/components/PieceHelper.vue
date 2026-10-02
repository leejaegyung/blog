<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { copyImage, copyRich } from '@/lib/clipboard'
import { photoPngBlob, type PastePiece } from '@/lib/naverExport'

/**
 * 한 조각씩 붙여넣기 도우미. 네이버 편집기는 붙여넣은 글 속 사진을 버려서, 글 묶음과 사진을 한 조각씩 복사해 순서대로 붙인다.
 * "돌아오면 다음 조각 자동 복사"를 켜 두면 ⌘`(같은 앱 창 전환)로 이 창에 왔다가 돌아가 ⌘V만 누르면 된다.
 */
const props = defineProps<{ pieces: PastePiece[]; thumbs: Map<number, string | null | undefined>; imageIds: Map<number, number>; editorLabel: string }>()
const emit = defineEmits<{ done: [] }>()

const index = ref(0) // 다음에 복사할 조각
const copied = ref<number | null>(null) // 마지막으로 복사한 조각
const auto = ref(true)
const error = ref<string | null>(null)
const busy = ref(false)
const total = computed(() => props.pieces.length)
const photoCount = computed(() => props.pieces.filter((p) => p.kind === 'photo').length)
const finished = computed(() => copied.value === total.value - 1 && index.value >= total.value)
let leftAt = 0

async function copyPiece(at: number) {
  const piece = props.pieces[at]
  if (!piece || busy.value) return
  busy.value = true
  error.value = null
  try {
    if (piece.kind === 'text') await copyRich(piece.html, piece.text)
    else await copyImage(photoPngBlob(piece.url))
    copied.value = at
    index.value = at + 1
    if (at === total.value - 1) emit('done')
  } catch {
    error.value = '복사하지 못했어요. 이 창을 한 번 클릭한 뒤 다시 눌러 주세요.'
  } finally {
    busy.value = false
  }
}

function next() {
  void copyPiece(index.value)
}

function back() {
  const at = Math.max(0, (copied.value ?? 0) - 1)
  void copyPiece(at)
}

// 다른 창(네이버)에 다녀오면 다음 조각을 자동으로 복사한다(잠깐 스쳐 지나간 건 무시)
function onBlur() {
  leftAt = Date.now()
}
function onFocus() {
  if (!auto.value || !leftAt || Date.now() - leftAt < 400 || index.value >= total.value || copied.value === null) return
  leftAt = 0
  next()
}

watch(
  () => props.pieces,
  () => {
    index.value = 0
    copied.value = null
  },
)
onMounted(() => {
  window.addEventListener('blur', onBlur)
  window.addEventListener('focus', onFocus)
})
onBeforeUnmount(() => {
  window.removeEventListener('blur', onBlur)
  window.removeEventListener('focus', onFocus)
})
</script>

<template>
  <section class="flex flex-col gap-3 rounded-[18px] border-2 border-ink bg-white p-4" aria-label="한 조각씩 붙여넣기">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <b class="text-sm">한 조각씩 붙여넣기 · 글 {{ total - photoCount }}묶음 + 사진 {{ photoCount }}장</b>
      <span class="text-xs text-sub tabular-nums">{{ Math.min(index, total) }} / {{ total }}</span>
    </div>
    <p class="m-0 text-[13px] leading-normal text-sub">
      {{ editorLabel }}는 글 속에 붙인 사진을 받지 않아서, 글과 사진을 한 조각씩 순서대로 붙여넣어요.
      <b class="text-ink">본문 칸 맨 끝을 클릭해 둔 뒤</b> ⌘V → 이 창으로 돌아오기 → ⌘V를 반복하세요.
    </p>

    <button
      type="button"
      :disabled="busy || index >= total"
      class="h-12 rounded-[14px] bg-ink px-5 text-base font-bold text-cream disabled:opacity-50"
      @click="next"
    >
      {{
        index >= total
          ? '모두 복사했어요'
          : busy
            ? '복사하는 중…'
            : `${index + 1}번째 조각 복사 — ${pieces[index]?.kind === 'photo' ? `사진 ${(pieces[index] as { number: number }).number}` : '글'}`
      }}
    </button>
    <label class="flex items-center gap-2 text-[13px] font-semibold">
      <input v-model="auto" type="checkbox" class="size-4 accent-ink" />
      이 창으로 돌아오면 다음 조각 자동 복사 <span class="font-normal text-sub">(창 전환 ⌘` · 윈도우 Alt+Tab)</span>
    </label>
    <p v-if="copied !== null && !finished" role="status" class="m-0 rounded-xl bg-lilac px-3 py-2 text-[13px] font-semibold">
      {{ copied + 1 }}번째 조각({{ pieces[copied]?.kind === 'photo' ? '사진' : '글' }})을 복사했어요 → {{ editorLabel }} 본문에 ⌘V
    </p>
    <p v-if="finished" role="status" class="m-0 rounded-xl bg-lilac px-3 py-2 text-[13px] font-semibold">마지막 조각까지 복사했어요. 붙여넣고 사진이 모두 들어갔는지 확인하세요.</p>
    <p v-if="error" role="alert" class="m-0 text-[13px] text-red-700">{{ error }}</p>

    <ol class="m-0 flex list-none flex-wrap gap-1.5 p-0" aria-label="붙여넣을 순서">
      <li v-for="(piece, i) in pieces" :key="i">
        <button
          type="button"
          :title="piece.kind === 'text' ? piece.preview : `사진 ${piece.number}`"
          :aria-label="`${i + 1}번째 조각 다시 복사`"
          :class="[
            i === copied ? 'ring-2 ring-ink' : '',
            i < index ? 'opacity-45' : '',
          ]"
          class="relative flex h-12 items-center justify-center overflow-hidden rounded-lg border-[1.5px] border-line bg-white text-[11px] font-bold"
          :style="{ width: piece.kind === 'photo' ? '48px' : '72px' }"
          @click="copyPiece(i)"
        >
          <img
            v-if="piece.kind === 'photo' && thumbs.get(imageIds.get(piece.number) ?? -1)"
            :src="thumbs.get(imageIds.get(piece.number) ?? -1) ?? undefined"
            alt=""
            class="size-full object-cover"
          />
          <span v-else-if="piece.kind === 'photo'">사진 {{ piece.number }}</span>
          <span v-else class="px-1 leading-tight text-sub">글 · {{ piece.preview.slice(0, 8) }}…</span>
          <span class="absolute top-0.5 left-0.5 rounded bg-ink px-1 text-[9px] text-cream">{{ i + 1 }}</span>
        </button>
      </li>
    </ol>
    <button v-if="copied !== null && copied > 0" type="button" class="self-start text-xs text-sub underline" @click="back">하나 앞 조각 다시 복사</button>
  </section>
</template>
