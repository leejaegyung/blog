<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { postApi, projectApi, referenceApi, type Platform, type Post, type Project, type Reference } from '@/lib/api'
import PlatformTabs from '@/components/PlatformTabs.vue'
import { PLATFORM_LABEL } from '@/lib/tistory'
import { validationErrors } from '@/lib/http'
import StepLayout from '@/components/flow/StepLayout.vue'
import NextButton from '@/components/flow/NextButton.vue'
import { useFlow } from './useFlow'

const props = defineProps<{ post: Post | null }>()
const flow = useFlow()
const route = useRoute()
const router = useRouter()

const keyword = ref(props.post?.keyword ?? (typeof route.query.keyword === 'string' ? route.query.keyword : ''))
// 올릴 곳을 먼저 고른다. 학습 카테고리·분석·6단계 올리기가 이 플랫폼 기준이다
// both: 네이버·티스토리에 동시에 올린다(네이버 글 + 티스토리용으로 따로 쓴 짝 글)
type Choice = Platform | 'both'
const LAST_PLATFORM = 'blog-ai.write.platform'
function lastChoice(): Choice {
  try {
    const saved = localStorage.getItem(LAST_PLATFORM)
    return saved === 'tistory' || saved === 'both' ? saved : 'naver'
  } catch {
    return 'naver'
  }
}
const queryChoice = ['naver', 'tistory', 'both'].includes(String(route.query.platform)) ? (route.query.platform as Choice) : null
const platform = ref<Choice>(props.post?.platform ?? queryChoice ?? lastChoice())
const rows = computed<Platform[]>(() => (platform.value === 'both' ? ['naver', 'tistory'] : [platform.value]))
// 관리 › 카테고리별 학습에서 학습시킨 카테고리 중 하나를 플랫폼마다 고른다(그 카테고리의 참고 글이 키워드 분석에 쓰인다)
const categories = ref<Project[]>([])
const categoryIds = ref<Record<Platform, number | null>>({ naver: null, tistory: null })
const categoriesLoaded = ref(false)
const STATUS_LABELS: Record<string, string> = { draft: '학습 전', analyzing: '학습 중', analyzed: '학습 완료', failed: '학습 실패' }
const listFor = (p: Platform) => categories.value.filter((c) => (c.platform ?? 'naver') === p)
const chosenFor = (p: Platform) => listFor(p).find((c) => c.id === categoryIds.value[p]) ?? null

async function loadCategories() {
  if (props.post) return
  categories.value = await projectApi.list('category').catch(() => [])
  const wanted = Number(route.query.category)
  for (const p of ['naver', 'tistory'] as const) {
    const list = listFor(p)
    categoryIds.value[p] = list.some((c) => c.id === wanted) ? wanted : (list[0]?.id ?? null)
  }
  categoriesLoaded.value = true
}
const urls = ref('')
const pending = ref<string[]>([])
const references = ref<Reference[]>([])
const pasteFor = ref<number | null>(null)
const pasteText = ref('')
const busy = ref(false)
const refOpen = ref(false)
const error = ref<string | null>(null)
const refMessage = ref<string | null>(null)

const STATUS: Record<string, { label: string; class: string }> = {
  parsed: { label: '완료', class: 'bg-lilac' },
  pending: { label: '분석 중', class: 'bg-lilac-soft' },
  needs_text: { label: '본문 필요', class: 'border border-ink bg-lemon' },
  duplicate: { label: '중복', class: 'bg-track' },
  failed: { label: '실패', class: 'bg-red-100 text-red-700' },
}

async function loadReferences() {
  if (props.post?.keyword_project_id) references.value = await referenceApi.list(props.post.keyword_project_id)
}

function parseUrls() {
  return urls.value.split(/\s+/).map((u) => u.trim()).filter(Boolean)
}

async function addReferences() {
  refMessage.value = null
  const list = parseUrls()
  if (!list.length) return
  if (!props.post?.keyword_project_id) {
    pending.value = [...new Set([...pending.value, ...list])]
    urls.value = ''
    refMessage.value = `${pending.value.length}개는 다음을 누르면 추가돼요.`
    return
  }
  try {
    const result = await referenceApi.addUrls(props.post.keyword_project_id, list)
    urls.value = ''
    refMessage.value = `${result.data.length}개 추가${result.skipped.length ? ` · ${result.skipped.length}개는 이미 있어요` : ''}`
    await loadReferences()
  } catch (e) {
    refMessage.value = Object.values(validationErrors(e) ?? {})[0]?.[0] ?? '추가하지 못했어요.'
  }
}

async function savePaste(reference: Reference) {
  await referenceApi.pasteText(reference.id, pasteText.value)
  pasteFor.value = null
  pasteText.value = ''
  await loadReferences()
}

async function next() {
  if (props.post) return flow.go(2)
  busy.value = true
  error.value = null
  try {
    try {
      localStorage.setItem(LAST_PLATFORM, platform.value)
    } catch {
      // 저장 못 해도 다음에 다시 고르면 된다
    }
    const main: Platform = platform.value === 'tistory' ? 'tistory' : 'naver'
    const created = await postApi.start(
      keyword.value,
      chosenFor(main)?.keyword ?? null,
      categoryIds.value[main],
      platform.value,
      platform.value === 'both' ? categoryIds.value.tistory : null,
    )
    if (pending.value.length && created.keyword_project_id) {
      await referenceApi.addUrls(created.keyword_project_id, pending.value).catch(() => {})
    }
    flow.update(created)
    flow.go(2, created.id)
  } catch (e) {
    error.value = validationErrors(e)?.keyword?.[0] ?? '시작하지 못했어요.'
  } finally {
    busy.value = false
  }
}

onMounted(() => Promise.all([loadReferences(), loadCategories()]))
</script>

<template>
  <StepLayout :step="1" :title="'어떤 키워드로\n쓸까요?'" lead="사람들이 검색할 말 그대로 적어주세요.">
    <input
      v-model="keyword"
      :readonly="!!post"
      required
      maxlength="100"
      aria-label="키워드"
      placeholder="예: 수원 인계동 파스타"
      class="rounded-2xl border-2 border-ink bg-white px-4 py-4 text-lg font-semibold outline-none placeholder:font-normal placeholder:text-muted read-only:bg-white/60 lg:rounded-[18px] lg:px-[22px] lg:py-5 lg:text-[22px]"
      @keydown.enter.prevent="next"
    />
    <p v-if="post" class="-mt-3 text-[13px] text-sub">키워드를 바꾸려면 새 글을 시작해 주세요.</p>

    <div v-if="!post" class="flex flex-col gap-2.5">
      <span class="text-[13px] font-bold lg:text-sm">올릴 곳</span>
      <PlatformTabs v-model="platform" label="올릴 곳" both />
      <p v-if="platform === 'both'" class="m-0 text-[13px] leading-normal text-sub">
        사진과 알려줄 내용은 한 번만 넣으면 돼요. 네이버 글과 별도로 티스토리용 글을 따로 써서(제목·도입·문장이 다르게) 중복 문서로 보이지 않게 해요.
      </p>
    </div>
    <p v-else class="-mt-2 m-0 text-[13px] text-sub">
      올릴 곳: <b class="text-ink">{{ PLATFORM_LABEL[post.platform ?? 'naver'] }}</b>
      <template v-if="post.twin_post_id || post.twin_of_post_id">
        · {{ PLATFORM_LABEL[post.platform === 'tistory' ? 'naver' : 'tistory'] }}용 글을
        <RouterLink :to="{ name: 'flow', params: { id: post.twin_post_id ?? post.twin_of_post_id, step: 5 } }" class="font-bold">따로 써요</RouterLink>
      </template>
    </p>

    <div v-for="row in post ? [] : rows" :key="row" class="flex flex-col gap-2.5">
      <span class="text-[13px] font-bold lg:text-sm">{{ PLATFORM_LABEL[row] }} 학습 카테고리 <span class="font-medium text-sub">(고르면 그 카테고리에서 학습한 글 구성·해시태그를 써요)</span></span>
      <div class="flex flex-wrap gap-1.5 lg:gap-2" role="radiogroup" :aria-label="`${PLATFORM_LABEL[row]} 학습 카테고리`">
        <button
          v-for="option in listFor(row)"
          :key="option.id"
          type="button"
          role="radio"
          :aria-checked="categoryIds[row] === option.id"
          :class="categoryIds[row] === option.id ? 'bg-lilac' : 'bg-white'"
          class="rounded-full border-[1.5px] border-ink px-3.5 py-2 text-sm font-semibold lg:px-4 lg:py-2.5 lg:text-[15px]"
          @click="categoryIds[row] = option.id"
        >
          {{ option.keyword }}
        </button>
        <button
          v-if="listFor(row).length"
          type="button"
          role="radio"
          :aria-checked="categoryIds[row] === null"
          :class="categoryIds[row] === null ? 'bg-lilac' : 'bg-white'"
          class="rounded-full border-[1.5px] border-ink px-3.5 py-2 text-sm font-semibold lg:px-4 lg:py-2.5 lg:text-[15px]"
          @click="categoryIds[row] = null"
        >
          선택 안 함
        </button>
        <RouterLink
          :to="{ name: 'projects', query: row === 'tistory' ? { platform: row } : {} }"
          class="rounded-full border-[1.5px] border-dashed border-ink bg-white px-3.5 py-2 text-sm font-semibold text-ink no-underline hover:text-ink lg:px-4 lg:py-2.5 lg:text-[15px]"
        >
          + 카테고리 학습시키기
        </RouterLink>
      </div>
      <p v-if="chosenFor(row)" class="m-0 text-[13px] text-sub">
        {{ chosenFor(row)!.keyword }} · 학습한 글 {{ chosenFor(row)!.reference_count ?? 0 }}개 · {{ STATUS_LABELS[chosenFor(row)!.status] ?? chosenFor(row)!.status }}
      </p>
      <p v-else-if="categoriesLoaded && !listFor(row).length" class="m-0 text-[13px] text-sub">
        아직 {{ PLATFORM_LABEL[row] }}용으로 학습한 카테고리가 없어요.
        {{ row === 'tistory' ? '관리 › 카테고리별 학습 › 티스토리에서 카테고리를 만들면 상위 글을 자동으로 가져와 학습해요.' : '관리 › 카테고리별 학습에서 잘 쓴 글로 카테고리를 학습시켜 보세요.' }}
      </p>
    </div>
    <p v-if="post?.learning_category" class="-mt-2 m-0 text-[13px] text-sub">학습 카테고리: <b class="text-ink">{{ post.learning_category.keyword }}</b></p>
    <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>

    <!-- 모바일 디자인: 접힌 점선 카드를 누르면 참고 글 입력이 열린다 -->
    <button
      v-if="!refOpen"
      type="button"
      class="flex items-center gap-3 rounded-2xl border-[1.5px] border-dashed border-lilac-mid px-4 py-3.5 text-left lg:hidden"
      @click="refOpen = true"
    >
      <span class="flex flex-1 flex-col gap-[3px]">
        <span class="text-sm font-bold">잘 쓴 글 참고하기 <span class="font-medium text-sub">(선택)</span></span>
        <span class="text-[13px] leading-snug text-sub">글 주소를 넣으면 구성·사진 배치를 분석해 참고해요.</span>
      </span>
      <span class="text-xl" aria-hidden="true">＋</span>
    </button>

    <template #aside>
      <div :class="refOpen ? 'flex' : 'hidden lg:flex'" class="flex-col gap-3.5">
      <span class="text-[13px] font-bold">잘 쓴 글 참고하기 <span class="font-medium text-sub">(선택)</span></span>
      <div class="flex flex-col gap-2.5 rounded-[14px] border-[1.5px] border-line bg-white p-3.5">
        <span class="text-[13px] leading-normal text-sub">참고할 글 주소를 한 줄에 하나씩 넣으면 구성·사진 배치를 분석해요.</span>
        <textarea
          v-model="urls"
          rows="4"
          aria-label="참고할 글 주소"
          placeholder="https://example.com/pasta-review&#10;https://blog.naver.com/…"
          class="rounded-[10px] border-[1.5px] border-line p-2.5 font-mono text-xs leading-relaxed text-sub outline-none focus:border-ink"
        />
        <button type="button" class="h-10 rounded-xl border-[1.5px] border-ink text-sm font-bold" @click="addReferences">
          추가하고 분석
        </button>
        <span v-if="refMessage" role="status" class="text-xs text-sub">{{ refMessage }}</span>
      </div>
      <div v-if="references.length || pending.length" class="flex flex-col gap-1.5">
        <div v-for="url in pending" :key="url" class="flex items-center gap-2 rounded-xl border-[1.5px] border-line bg-white px-3 py-2.5">
          <span class="rounded-md bg-track px-1.5 py-0.5 text-[11px] font-extrabold">대기</span>
          <span class="min-w-0 flex-1 truncate text-[13px]">{{ url }}</span>
        </div>
        <div v-for="reference in references" :key="reference.id" class="flex flex-col gap-2 rounded-xl border-[1.5px] border-line bg-white px-3 py-2.5">
          <div class="flex items-center gap-2">
            <span :class="STATUS[reference.parse_status]?.class" class="rounded-md px-1.5 py-0.5 text-[11px] font-extrabold whitespace-nowrap">
              {{ STATUS[reference.parse_status]?.label }}
            </span>
            <span class="min-w-0 flex-1 truncate text-[13px]">
              {{ reference.title || reference.source_url }}{{ reference.char_count ? ` · ${reference.char_count.toLocaleString('ko-KR')}자` : '' }}
            </span>
            <button
              v-if="reference.parse_status === 'needs_text'"
              type="button"
              class="text-xs font-bold underline"
              @click="(pasteFor = reference.id), (pasteText = '')"
            >
              붙여넣기
            </button>
          </div>
          <form v-if="pasteFor === reference.id" class="flex flex-col gap-2" @submit.prevent="savePaste(reference)">
            <textarea v-model="pasteText" rows="5" aria-label="글 본문" placeholder="본문을 붙여넣으세요. 사진 자리에 [사진]이라고 적으면 배치도 분석해요." class="rounded-lg border-[1.5px] border-line p-2 text-xs" />
            <button type="submit" class="self-start rounded-lg bg-ink px-3 py-1.5 text-xs font-bold text-cream">저장하고 분석</button>
          </form>
        </div>
      </div>
      <span class="text-xs leading-normal text-sub">네이버 글은 정책상 자동으로 가져오지 않아요. 본문을 붙여넣어 주세요. 원문은 저장하지 않아요.</span>
      </div>
    </template>

    <template #back>
      <button type="button" class="text-muted hover:text-ink" @click="router.push('/')">취소</button>
    </template>
    <template #next>
      <NextButton :disabled="!keyword.trim()" :busy="busy" @click="next">다음 · 사진 올리기 →</NextButton>
    </template>
  </StepLayout>
</template>
