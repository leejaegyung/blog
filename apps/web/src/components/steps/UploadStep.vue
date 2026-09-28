<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { postApi, type ExportResult, type Post } from '@/lib/api'
import { validationErrors } from '@/lib/http'
import { copyRich, copyText } from '@/lib/clipboard'
import { buildPasteHtml } from '@/lib/naverExport'
import StepLayout from '@/components/flow/StepLayout.vue'
import NextButton from '@/components/flow/NextButton.vue'
import PanelCard from '@/components/flow/PanelCard.vue'
import { useFlow } from './useFlow'

const props = defineProps<{ post: Post }>()
const flow = useFlow()

// 네이버에 로그인되어 있으면 내 블로그 글쓰기 화면으로 이동한다(공식 글쓰기 API는 2020년 종료)
const NAVER_WRITE_URL = 'https://blog.naver.com/GoBlogWrite.naver'

type Prepared = { html: string; text: string; embedded: number; result: ExportResult; signature: string }
const prepared = ref<Prepared | null>(null)
const prepareError = ref<string | null>(null)
const status = ref<string | null>(null)
const opened = ref(false)
const url = ref(props.post.published_url ?? '')
const urlInput = ref<HTMLInputElement | null>(null)
const urlError = ref<string | null>(null)
const recording = ref(false)
let timer: ReturnType<typeof setTimeout> | undefined

const signature = computed(() => `${props.post.updated_at}|${props.post.title}`)
const ready = computed(() => prepared.value?.signature === signature.value)
const quality = computed(() => props.post.quality)
const blocking = computed(() => quality.value?.issues.filter((i) => i.severity === 'error').length ?? 0)
const warningCount = computed(() => quality.value?.issues.filter((i) => i.severity === 'warning').length ?? 0)
const thumbs = computed(() => new Map((props.post.images ?? []).map((i) => [i.id, i.thumb_url])))
const photoCount = computed(() => prepared.value?.result.photos.length ?? 0)
const scoreTitle = computed(() => (blocking.value ? `꼭 고치기 ${blocking.value}개 남음` : '게시 전 검사 통과'))
const scoreSub = computed(() =>
  blocking.value ? '초안 다듬기에서 확인해 주세요' : warningCount.value ? `확인 권장 ${warningCount.value}개 남음` : '확인할 곳이 없어요',
)

/** 사진을 받아 본문 안에 넣어 두는 데 시간이 걸려 미리 준비한다(복사·새 창은 누른 순간에 해야 브라우저가 막지 않는다) */
async function prepare() {
  const target = signature.value
  prepareError.value = null
  try {
    const result = await postApi.exportPost(props.post.id, false)
    const { html, embedded } = await buildPasteHtml(result)
    if (target === signature.value) prepared.value = { html, text: result.text, embedded, result, signature: target }
  } catch (e) {
    prepareError.value = Object.values(validationErrors(e) ?? {}).flat().join(' ') || '올릴 본문을 준비하지 못했어요.'
  }
}

function openNaver() {
  if (opened.value) return
  window.open(NAVER_WRITE_URL, '_blank', 'noopener')
  opened.value = true
}

/** 1. 제목 복사 — 처음 누르면 네이버 글쓰기도 연다(새 창은 누른 순간에만 열 수 있다) */
async function copyTitle() {
  await copyText(props.post.title ?? '')
  const first = !opened.value
  openNaver()
  status.value = first
    ? '제목을 복사하고 네이버 글쓰기를 열었어요. 제목 칸에 붙여넣으세요.'
    : '제목을 복사했어요. 네이버 제목 칸에 붙여넣으세요.'
}

/** 2. 본문 복사 — 사진까지 본문 안에 넣어 한 번에 붙여넣어진다 */
async function copyBody() {
  if (!prepared.value) return
  try {
    await copyRich(prepared.value.html, prepared.value.text)
  } catch {
    status.value = '클립보드에 복사하지 못했어요. 브라우저의 클립보드 권한을 확인해 주세요.'
    return
  }
  openNaver()
  status.value = `본문과 사진 ${prepared.value.embedded}장을 복사했어요. 네이버 본문 칸에 붙여넣으세요.`
  void postApi.exportPost(props.post.id, true).catch(() => {})
}

/** 4. 게시한 글 주소 붙여넣기 — 클립보드를 읽을 수 없으면 입력 칸으로 보낸다 */
async function pasteUrl() {
  try {
    const text = (await navigator.clipboard.readText()).trim()
    if (/^https?:\/\//.test(text)) {
      url.value = text
      status.value = '주소를 붙여넣었어요. "게시 완료로 기록"을 누르세요.'
      return
    }
  } catch {
    // 권한이 없으면 직접 붙여넣게 한다
  }
  urlInput.value?.focus()
  status.value = '게시한 글 주소를 오른쪽 칸에 붙여넣어 주세요.'
}

function downloadPhotos() {
  window.location.href = postApi.photosZipUrl(props.post.id)
}

async function copyPlain() {
  if (!prepared.value) return
  await copyText(prepared.value.text)
  status.value = '서식 없이 텍스트만 복사했어요.'
}

async function record() {
  urlError.value = null
  recording.value = true
  try {
    flow.update(await postApi.publish(props.post.id, url.value.trim()))
    status.value = '게시 완료로 기록했어요. 내 글 목록에 "게시 완료"로 보여요.'
  } catch (e) {
    urlError.value = validationErrors(e)?.published_url?.[0] ?? '기록하지 못했어요.'
  } finally {
    recording.value = false
  }
}

watch(signature, () => {
  clearTimeout(timer)
  timer = setTimeout(prepare, 1200)
})
onMounted(prepare)
onBeforeUnmount(() => clearTimeout(timer))

const tagList = computed(() => prepared.value?.result.tags ?? props.post.content?.tags ?? [])

async function copyTags() {
  await copyText(tagList.value.map((t) => `#${t}`).join(' '))
  status.value = `해시태그 ${tagList.value.length}개를 복사했어요. 네이버 발행 창의 태그 칸에 붙여넣으세요.`
}

type UploadAction = { text: string; action: string; disabled: boolean; run: () => unknown }
const steps = computed(() => {
  const list: UploadAction[] = [
    { text: '제목을 복사해 네이버 글쓰기에 붙여넣기', action: '제목 복사', disabled: false, run: copyTitle },
    {
      text: photoCount.value
        ? `본문을 서식째 복사해 붙여넣기 — 사진 ${photoCount.value}장도 함께 들어가요`
        : '본문을 서식째 복사해 붙여넣기',
      action: ready.value ? '본문 복사' : '준비 중…',
      disabled: !ready.value,
      run: copyBody,
    },
  ]
  if (tagList.value.length) {
    list.push({
      text: `해시태그 ${tagList.value.length}개는 본문 끝에 들어가요 — 발행 창 태그 칸에도 붙여넣기`,
      action: '태그 복사',
      disabled: false,
      run: copyTags,
    })
  }
  if (photoCount.value) {
    list.push({ text: '사진이 빠졌다면 [사진 1] 자리에 같은 번호 사진 넣기', action: `사진 ${photoCount.value}장 받기`, disabled: false, run: downloadPhotos })
  }
  list.push({ text: '게시한 글 주소 붙여넣기', action: '붙여넣기', disabled: false, run: pasteUrl })
  return list.map((step, index) => ({ ...step, n: index + 1 }))
})
</script>

<template>
  <StepLayout :step="6" :title="'네이버에\n올릴 차례예요'">
    <!-- 모바일은 제목 아래에 검사 점수(디자인 M6) -->
    <div class="flex items-center gap-4 rounded-[20px] border-2 border-ink bg-lilac px-[18px] py-3.5 lg:hidden">
      <span class="font-display text-5xl leading-none">{{ quality ? Math.round(quality.score) : '–' }}</span>
      <div class="flex flex-col gap-[3px]">
        <span class="text-sm font-bold">{{ scoreTitle }}</span>
        <span class="text-[13px]">{{ scoreSub }}</span>
      </div>
    </div>

    <div class="flex flex-col gap-2 lg:gap-2.5">
      <div
        v-for="s in steps"
        :key="s.n"
        class="flex items-center gap-3 rounded-2xl border-[1.5px] border-line bg-white px-3.5 py-3 lg:gap-4 lg:rounded-[18px] lg:px-5 lg:py-4"
      >
        <span class="flex size-7 shrink-0 items-center justify-center rounded-full border-[1.5px] border-ink bg-lemon text-[13px] font-extrabold lg:size-[34px] lg:text-[15px]">{{ s.n }}</span>
        <span class="flex-1 text-sm leading-snug lg:text-base">{{ s.text }}</span>
        <button
          type="button"
          :disabled="s.disabled"
          class="rounded-[10px] bg-ink px-3 py-2 text-[13px] font-bold whitespace-nowrap text-cream disabled:opacity-50 lg:rounded-xl lg:px-[18px] lg:py-[11px] lg:text-sm"
          @click="s.run"
        >
          {{ s.action }}
        </button>
      </div>
    </div>
    <p v-if="status" role="status" class="text-sm font-semibold">{{ status }}</p>
    <p v-if="prepareError" role="alert" class="text-sm text-red-600">{{ prepareError }}</p>

    <ol v-if="prepared?.result.photos.length" class="m-0 hidden list-none flex-wrap gap-2 p-0 lg:flex" aria-label="본문에 들어가는 사진 순서">
      <li v-for="photo in prepared.result.photos" :key="photo.image_id" class="relative size-14 overflow-hidden rounded-[10px] bg-lilac-soft">
        <img v-if="thumbs.get(photo.image_id)" :src="thumbs.get(photo.image_id)" :alt="`사진 ${photo.number}`" class="size-full object-cover" />
        <span class="absolute top-1 left-1 rounded-[5px] bg-ink px-[5px] text-[10px] font-bold text-cream">{{ photo.number }}</span>
      </li>
    </ol>

    <template #aside>
      <div class="hidden items-center gap-3.5 rounded-[18px] border-2 border-ink bg-lilac p-4 lg:flex">
        <span class="font-display text-[44px] leading-none">{{ quality ? Math.round(quality.score) : '–' }}</span>
        <div class="flex flex-col gap-0.5">
          <span class="text-sm font-bold">{{ scoreTitle }}</span>
          <span class="text-xs">{{ scoreSub }}</span>
        </div>
      </div>
      <form class="flex flex-col gap-2.5 rounded-[14px] border-[1.5px] border-line bg-white p-3.5" @submit.prevent="record">
        <label for="published-url" class="text-sm font-bold">게시한 글 주소</label>
        <input id="published-url" ref="urlInput" v-model="url" type="url" required placeholder="https://blog.naver.com/…" class="rounded-[10px] border-[1.5px] border-line px-3 py-2.5 text-[13px] outline-none placeholder:text-muted focus:border-ink" />
        <span class="text-xs leading-normal text-sub">기록해 두면 내 글 목록에 "게시 완료"로 표시돼요.</span>
        <span v-if="urlError" role="alert" class="text-xs text-red-600">{{ urlError }}</span>
        <a v-if="post.published_url" :href="post.published_url" target="_blank" rel="noopener noreferrer" class="text-xs font-bold">게시한 글 보기 ↗</a>
      </form>
      <PanelCard>
        <span class="text-sm font-bold">텍스트만 필요하면</span>
        <button type="button" :disabled="!ready" class="self-start text-[13px] underline disabled:opacity-50" @click="copyPlain">서식 없이 복사</button>
      </PanelCard>
    </template>

    <template #back>
      <button type="button" class="hover:text-accent" @click="flow.go(5)">← 초안 다듬기</button>
    </template>
    <template #next>
      <NextButton :disabled="!url.trim()" :busy="recording" class="w-full lg:w-auto" @click="record">{{ post.published_url ? '주소 고치기' : '게시 완료로 기록' }}</NextButton>
    </template>
  </StepLayout>
</template>
