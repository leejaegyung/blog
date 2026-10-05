<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { postApi, type ExportResult, type Platform, type Post } from '@/lib/api'
import { PLATFORM_LABEL, platformOf, tistoryWriteUrl } from '@/lib/tistory'
import PlatformTabs from '@/components/PlatformTabs.vue'
import { validationErrors } from '@/lib/http'
import { canUseClipboardApi, copyRich, copyText } from '@/lib/clipboard'
import { buildPasteHtml, splitPieces } from '@/lib/naverExport'
import PieceHelper from '@/components/PieceHelper.vue'
import { naverWriteUrl } from '@/lib/naver'
import { useAuthStore } from '@/stores/auth'
import StepLayout from '@/components/flow/StepLayout.vue'
import NextButton from '@/components/flow/NextButton.vue'
import PanelCard from '@/components/flow/PanelCard.vue'
import { useFlow } from './useFlow'
import { RouterLink } from 'vue-router'

const props = defineProps<{ post: Post }>()
const flow = useFlow()

// 네이버에 로그인되어 있으면 내 블로그 글쓰기 화면으로 이동한다(공식 글쓰기 API는 2020년 종료).
// 관리 화면에서 블로그 아이디를 넣으면 https://blog.naver.com/{아이디}?Redirect=Write& 로 연다
const auth = useAuthStore()
// 올릴 곳 탭. 기본은 글을 시작할 때 고른 곳이고, 같은 글을 다른 곳에도 올릴 수 있다
// 티스토리는 공식 글쓰기 API가 끝나(2024) 내 블로그 글쓰기(/manage/newpost)를 열고 붙여넣는다
const target = ref<Platform>(props.post.platform ?? 'naver')
const label = computed(() => PLATFORM_LABEL[target.value])
const writeUrl = computed(() => (target.value === 'tistory' ? tistoryWriteUrl(auth.tistoryHost) : naverWriteUrl(auth.naverBlogId)))

// 동시에 올리기: 다른 플랫폼 탭은 짝 글(같은 경험을 그 플랫폼용으로 따로 쓴 글)을 올린다.
// 같은 글을 그대로 두 곳에 올리면 검색엔진이 중복 문서로 보고 둘 다 밀어낼 수 있다
const partner = ref<Post | null>(null)
const overlap = ref<number | null>(null)
const writingTwin = ref(false)
const twinError = ref<string | null>(null)
let twinTimer: ReturnType<typeof setTimeout> | undefined
const otherTab = computed(() => target.value !== (props.post.platform ?? 'naver'))
const active = computed<Post>(() => (otherTab.value && partner.value ? partner.value : props.post))
const sameCopy = computed(() => otherTab.value && !partner.value)
const partnerBusy = computed(() => otherTab.value && partner.value?.pipeline_status === 'running')
const partnerFailed = computed(() => otherTab.value && partner.value?.pipeline_status === 'failed' && !partner.value.content)
const publishedUrl = computed(() => (target.value === 'tistory' ? (active.value.tistory_url ?? null) : active.value.published_url))

async function loadPartner() {
  clearTimeout(twinTimer)
  if (!props.post.twin_post_id && !props.post.twin_of_post_id && !partner.value) return
  try {
    const result = await postApi.twin(props.post.id)
    partner.value = result.data
    overlap.value = result.overlap
  } catch {
    // 다음에 다시 읽는다
  }
  if (partner.value?.pipeline_status === 'running') twinTimer = setTimeout(loadPartner, 3000)
}

/** 다른 플랫폼용 짝 글 쓰기(있으면 다시 쓰기): 사실·사진은 이 글에서 가져간다 */
async function writeTwin() {
  writingTwin.value = true
  twinError.value = null
  try {
    partner.value = await postApi.writeTwin(props.post.twin_of_post_id ?? props.post.id)
    partner.value = { ...partner.value, pipeline_status: 'running' }
    twinTimer = setTimeout(loadPartner, 3000)
  } catch (e) {
    twinError.value = Object.values(validationErrors(e) ?? {})[0]?.[0] ?? '짝 글을 시작하지 못했어요.'
  } finally {
    writingTwin.value = false
  }
}

type Prepared = { html: string; text: string; embedded: number; result: ExportResult; signature: string }
const prepared = ref<Prepared | null>(null)
const prepareError = ref<string | null>(null)
const status = ref<string | null>(null)
const opened = ref(false)
const url = ref(publishedUrl.value ?? '')
const urlInput = ref<HTMLInputElement | null>(null)
const urlError = ref<string | null>(null)
const recording = ref(false)
let timer: ReturnType<typeof setTimeout> | undefined

const signature = computed(() => `${active.value.id}|${active.value.updated_at}|${active.value.title}|${target.value}`)
const ready = computed(() => prepared.value?.signature === signature.value)
const quality = computed(() => active.value.quality)
const blocking = computed(() => quality.value?.issues.filter((i) => i.severity === 'error').length ?? 0)
const warningCount = computed(() => quality.value?.issues.filter((i) => i.severity === 'warning').length ?? 0)
const thumbs = computed(() => new Map((active.value.images ?? []).map((i) => [i.id, i.thumb_url])))
const photoCount = computed(() => prepared.value?.result.photos.length ?? 0)
const scoreTitle = computed(() => (blocking.value ? `꼭 고치기 ${blocking.value}개 남음` : '게시 전 검사 통과'))
const scoreSub = computed(() =>
  blocking.value ? '초안 다듬기에서 확인해 주세요' : warningCount.value ? `확인 권장 ${warningCount.value}개 남음` : '확인할 곳이 없어요',
)

/** 사진을 받아 본문 안에 넣어 두는 데 시간이 걸려 미리 준비한다(복사·새 창은 누른 순간에 해야 브라우저가 막지 않는다) */
async function prepare() {
  const wanted = signature.value
  prepareError.value = null
  // 짝 글을 쓰는 중이면 끝난 뒤 준비한다
  if (partnerBusy.value || partnerFailed.value) return
  try {
    const result = await postApi.exportPost(active.value.id, false, target.value)
    const { html, embedded } = await buildPasteHtml(result)
    if (wanted === signature.value) prepared.value = { html, text: result.text, embedded, result, signature: wanted }
  } catch (e) {
    prepareError.value = Object.values(validationErrors(e) ?? {}).flat().join(' ') || '올릴 본문을 준비하지 못했어요.'
  }
}

/** 글쓰기 창 열기. force가 아니면 이 화면에서 이미 연 적이 있을 때는 다시 열지 않는다(본문·조각 단계) */
function openEditor(force = false) {
  if (opened.value && !force) return
  window.open(writeUrl.value, '_blank', 'noopener')
  opened.value = true
}

/**
 * 1. 제목 복사 — 누를 때마다 글쓰기 창도 연다(새 창은 누른 순간에만 열 수 있다).
 * 열었던 창을 닫았거나 멈춰서 새로 열어야 할 때도 이 버튼으로 다시 시작한다
 */
async function copyTitle() {
  // 복사가 막혀도 글쓰기 창은 연다
  let copied = true
  try {
    await copyText(active.value.title ?? '')
  } catch {
    copied = false
  }
  openEditor(true)
  if (!copied) {
    status.value = `${label.value} 글쓰기를 열었지만 제목을 복사하지 못했어요. 제목을 직접 선택해 복사해 주세요: ${active.value.title ?? ''}`
    return
  }
  status.value = `제목을 복사하고 ${label.value} 글쓰기를 새 탭으로 열었어요. 제목 칸에 붙여넣으세요.${target.value === 'tistory' && !auth.tistoryHost ? ' (관리 › 내 티스토리 블로그에 주소를 넣으면 내 블로그 글쓰기가 바로 열려요)' : ''}`
}

// 본문 복사의 평문에는 제목을 넣지 않는다. 편집기는 열리면 제목 칸에 커서가 있어서,
// 거기 붙여넣으면 평문(제목+본문)이 한 줄로 제목 칸에 들어가고 사진은 빠진다
const bodyText = computed(() => {
  const text = prepared.value?.text ?? ''
  const title = active.value.title ?? ''
  return title && text.startsWith(title) ? text.slice(title.length).replace(/^\n+/, '') : text
})
// 본문을 복사한 뒤 "제목 칸 말고 본문 칸" 안내를 크게 보여 준다
const bodyCopied = ref(false)

// 네이버 편집기는 붙여넣은 글 속 사진(data URI)을 버리고, 티스토리 편집기는 사진이 든 본문을 붙이면 멈춘다(2026-10-02 사용자 확인).
// 그래서 사진이 있으면 두 곳 모두 글 묶음·사진을 한 조각씩 붙여넣는 도우미를 쓴다. 사진이 없으면 한 번에 붙여넣기
const pieces = computed(() => (prepared.value && ready.value ? splitPieces(prepared.value.result) : []))
const pieceImageIds = computed(() => new Map((prepared.value?.result.photos ?? []).map((p) => [p.number, p.image_id])))
const usePieces = computed(() => photoCount.value > 0)
const showPieces = ref(false)
const helper = ref<HTMLElement | null>(null)
function openPieces() {
  showPieces.value = true
  bodyCopied.value = false
  status.value = `${label.value} 글쓰기에서 제목 아래 본문 칸을 클릭해 두고, 아래 도우미로 한 조각씩 붙여넣으세요.`
  openEditor()
  void Promise.resolve().then(() => helper.value?.scrollIntoView?.({ behavior: 'smooth', block: 'start' }))
}

/** 2. 본문 복사 — 사진까지 본문 안에 넣어 한 번에 붙여넣어진다 */
async function copyBody() {
  if (!prepared.value) return
  try {
    await copyRich(prepared.value.html, bodyText.value)
  } catch {
    status.value = '클립보드에 복사하지 못했어요. 이 화면을 한 번 클릭한 뒤 다시 눌러 주세요.'
    return
  }
  openEditor()
  bodyCopied.value = true
  status.value = `본문과 사진 ${prepared.value.embedded}장을 복사했어요. ${label.value} 글쓰기에서 제목 아래 본문 칸을 한 번 클릭한 뒤 붙여넣으세요.`
  void postApi.exportPost(active.value.id, true, target.value).catch(() => {})
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
  window.location.href = postApi.photosZipUrl(active.value.id)
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
    const saved = await postApi.publish(active.value.id, url.value.trim(), platformOf(url.value.trim()) ?? target.value)
    if (saved.id === props.post.id) flow.update(saved)
    else partner.value = saved
    status.value = '게시 완료로 기록했어요. 내 글 목록에 "게시 완료"로 보여요.'
  } catch (e) {
    urlError.value = validationErrors(e)?.published_url?.[0] ?? '기록하지 못했어요.'
  } finally {
    recording.value = false
  }
}

// 탭을 바꾸면 그 플랫폼 기준으로 다시 준비하고, 글쓰기 창도 새로 연다
watch(target, () => {
  opened.value = false
  bodyCopied.value = false
  showPieces.value = false
  status.value = null
  url.value = publishedUrl.value ?? ''
})
watch(signature, () => {
  clearTimeout(timer)
  timer = setTimeout(prepare, 1200)
})
onMounted(() => {
  void prepare()
  void loadPartner()
})
onBeforeUnmount(() => {
  clearTimeout(timer)
  clearTimeout(twinTimer)
})

const tagList = computed(() => prepared.value?.result.tags ?? active.value.content?.tags ?? [])

async function copyTags() {
  if (target.value === 'tistory') {
    // 티스토리 태그 칸은 쉼표로 나눈다(# 없이)
    await copyText(tagList.value.join(','))
    status.value = `태그 ${tagList.value.length}개를 복사했어요. 티스토리 글쓰기 아래 태그 칸에 붙여넣으세요.`
    return
  }
  await copyText(tagList.value.map((t) => `#${t}`).join(' '))
  status.value = `해시태그 ${tagList.value.length}개를 복사했어요. 네이버 발행 창의 태그 칸에 붙여넣으세요.`
}

// http 주소(Tailscale 기기 이름 등)에서는 브라우저가 사진 복사와 자동 복사를 막는다. https 주소나 이 Mac의 localhost로 열면 된다
const secure = canUseClipboardApi()
const secureUrl = computed(() => (typeof location !== 'undefined' ? `http://localhost:8080${location.pathname}` : ''))

/** 단계 버튼: 복사가 막혀도 조용히 끝나지 않게 알려 준다 */
async function runStep(step: { run: () => unknown }) {
  try {
    await step.run()
  } catch {
    status.value = '복사하지 못했어요. 이 화면을 한 번 클릭한 뒤 다시 눌러 주세요.'
  }
}

type UploadAction = { text: string; action: string; disabled: boolean; run: () => unknown }
const steps = computed(() => {
  const list: UploadAction[] = [
    { text: `제목을 복사하고 ${label.value} 글쓰기를 새 탭으로 열어 제목 칸에 붙여넣기`, action: '제목 복사', disabled: false, run: copyTitle },
    usePieces.value
      ? {
          text: `제목 아래 본문 칸을 클릭하고, 글과 사진 ${photoCount.value}장을 한 조각씩 붙여넣기` + (active.value.places?.length ? ' · 끝에 📍 위치 포함' : ''),
          action: ready.value ? '조각 붙여넣기' : '준비 중…',
          disabled: !ready.value,
          run: openPieces,
        }
      : {
          text:
            (photoCount.value
              ? `제목 아래 본문 칸을 클릭하고 붙여넣기 — 사진 ${photoCount.value}장도 함께 들어가요`
              : '제목 아래 본문 칸을 클릭하고 붙여넣기') + (active.value.places?.length ? ' · 끝에 📍 위치·지도 링크 포함' : ''),
          action: ready.value ? '본문 복사' : '준비 중…',
          disabled: !ready.value,
          run: copyBody,
        },
  ]
  if (tagList.value.length) {
    list.push({
      text:
        target.value === 'tistory'
          ? `태그 ${tagList.value.length}개를 글쓰기 아래 태그 칸에 붙여넣기 (본문에는 넣지 않았어요)`
          : `해시태그 ${tagList.value.length}개는 본문 끝에 들어가요 — 발행 창 태그 칸에도 붙여넣기`,
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
  <StepLayout :step="6" :title="`${label}에\n올릴 차례예요`">
    <div v-if="!secure && photoCount" class="flex flex-col gap-1 rounded-[14px] border-2 border-ink bg-lemon px-3.5 py-3 text-[13px] leading-normal" role="note">
      <b class="text-sm">이 주소(http)에서는 브라우저가 사진 복사를 막아요</b>
      <span>제목·글·태그는 복사돼요. 사진까지 한 조각씩 붙여넣으려면 https 주소로 열어 주세요. 이 Mac에서는 <a :href="secureUrl" class="font-bold">localhost:8080으로 열기</a>만 해도 돼요.</span>
    </div>
    <div class="flex flex-wrap items-center gap-3">
      <PlatformTabs v-model="target" label="올릴 곳" />
      <!-- 창이 막혔거나 닫혔을 때 바로 여는 링크(브라우저가 새 창을 막아도 링크는 열린다) -->
      <a :href="writeUrl" target="_blank" rel="noopener noreferrer" class="text-[13px] font-bold" @click="opened = true">{{ label }} 글쓰기 열기 ↗</a>
    </div>

    <!-- 동시에 올리기: 다른 플랫폼 탭은 그 플랫폼용으로 따로 쓴 짝 글을 올린다 -->
    <div v-if="sameCopy" class="flex flex-col gap-2.5 rounded-[18px] border-2 border-ink bg-lemon p-4 text-sm leading-normal" role="note">
      <b>{{ label }}에도 올린다면 {{ label }}용으로 따로 쓰는 걸 권해요.</b>
      <span>같은 글을 두 곳에 그대로 올리면 검색엔진이 중복 문서로 보고 두 글 모두 노출이 떨어질 수 있어요. 사실·사진은 그대로 가져가고 제목·도입·문장만 {{ label }}에 맞게 새로 써요(AI를 한 번 더 써요).</span>
      <button type="button" :disabled="writingTwin" class="self-start rounded-xl bg-ink px-4 py-2.5 text-sm font-bold text-cream disabled:opacity-50" @click="writeTwin">
        {{ writingTwin ? '시작하는 중…' : `${label}용으로 따로 쓰기` }}
      </button>
      <span class="text-xs text-sub">그냥 같은 글을 올리려면 아래 순서대로 하면 돼요.</span>
    </div>
    <div v-else-if="otherTab && partner" class="flex flex-col gap-2 rounded-[18px] border-[1.5px] border-line bg-white p-4 text-sm" role="status">
      <template v-if="partnerBusy">
        <b>{{ label }}용 글을 쓰고 있어요…</b>
        <span class="text-sub">보통 1~3분 걸려요. 이 화면을 닫아도 계속 써요.</span>
      </template>
      <template v-else-if="partnerFailed">
        <b>{{ label }}용 글을 쓰지 못했어요.</b>
        <span class="text-sub">{{ partner.pipeline_error }}</span>
      </template>
      <template v-else>
        <span>
          <b>{{ label }}용으로 따로 쓴 글</b>을 올려요.
          <template v-if="overlap !== null">
            두 글 문장 겹침 <b :class="overlap >= 0.3 ? 'text-red-700' : ''">{{ Math.round(overlap * 100) }}%</b>
            {{ overlap >= 0.3 ? '— 겹치는 문장이 많아요. 다듬거나 다시 쓰세요.' : '— 서로 다른 글로 보여요.' }}
          </template>
        </span>
        <RouterLink :to="{ name: 'flow', params: { id: partner.id, step: 5 } }" class="self-start text-[13px] font-bold">{{ label }} 글 다듬기 →</RouterLink>
      </template>
      <button
        v-if="!post.twin_of_post_id && !partnerBusy"
        type="button"
        :disabled="writingTwin"
        class="self-start text-[13px] text-sub underline disabled:opacity-50"
        @click="writeTwin"
      >
        {{ writingTwin ? '시작하는 중…' : '처음부터 다시 쓰기' }}
      </button>
    </div>
    <p v-if="twinError" role="alert" class="text-sm text-red-600">{{ twinError }}</p>

    <!-- 모바일은 제목 아래에 검사 점수(디자인 M6) -->
    <div class="flex items-center gap-4 rounded-[20px] border-2 border-ink bg-lilac px-[18px] py-3.5 lg:hidden">
      <span class="font-display text-5xl leading-none">{{ quality ? Math.round(quality.score) : '–' }}</span>
      <div class="flex flex-col gap-[3px]">
        <span class="text-sm font-bold">{{ scoreTitle }}</span>
        <span class="text-[13px]">{{ scoreSub }}</span>
      </div>
    </div>

    <div v-if="!partnerBusy && !partnerFailed" class="flex flex-col gap-2 lg:gap-2.5">
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
          @click="runStep(s)"
        >
          {{ s.action }}
        </button>
      </div>
    </div>
    <div v-if="usePieces && showPieces && pieces.length && !partnerBusy && !partnerFailed" ref="helper">
      <PieceHelper :pieces="pieces" :thumbs="thumbs" :image-ids="pieceImageIds" :editor-label="`${label} 편집기`" />
    </div>
    <p v-if="status" role="status" class="text-sm font-semibold">{{ status }}</p>
    <div v-if="bodyCopied" class="flex flex-col gap-1 rounded-[14px] border-2 border-ink bg-lemon px-3.5 py-3 text-[13px] leading-normal" role="note">
      <b class="text-sm">⚠️ 제목 칸이 아니라 본문 칸에 붙여넣으세요</b>
      <span>글쓰기 화면은 처음에 제목 칸에 커서가 있어요. 제목 아래 “{{ target === 'naver' ? '글감과 함께 나의 일상을 기록해보세요!' : '내용을 입력하세요' }}” 같은 회색 글씨가 있는 본문 칸을 한 번 클릭한 뒤 ⌘V(윈도우는 Ctrl+V)를 누르세요.</span>
      <span>제목 칸에 붙여넣으면 사진이 빠지고 글자만 한 줄로 들어가요. 그랬다면 제목 칸을 지우고(⌘Z) 본문 칸에 다시 붙여넣으세요.</span>
    </div>
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
        <label for="published-url" class="text-sm font-bold">{{ label }}에 게시한 글 주소</label>
        <input id="published-url" ref="urlInput" v-model="url" type="url" required :placeholder="target === 'tistory' ? `https://${auth.tistoryHost ?? '내블로그.tistory.com'}/…` : 'https://blog.naver.com/…'" class="rounded-[10px] border-[1.5px] border-line px-3 py-2.5 text-[13px] outline-none placeholder:text-muted focus:border-ink" />
        <span class="text-xs leading-normal text-sub">기록해 두면 내 글 목록에 "게시 완료"로 표시돼요.</span>
        <span v-if="urlError" role="alert" class="text-xs text-red-600">{{ urlError }}</span>
        <a v-if="publishedUrl" :href="publishedUrl" target="_blank" rel="noopener noreferrer" class="text-xs font-bold">게시한 글 보기 ↗</a>
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
      <NextButton :disabled="!url.trim()" :busy="recording" class="w-full lg:w-auto" @click="record">{{ publishedUrl ? '주소 고치기' : '게시 완료로 기록' }}</NextButton>
    </template>
  </StepLayout>
</template>
