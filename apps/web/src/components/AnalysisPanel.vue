<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { analysisApi, type AnalysisProgress, type KeywordAnalysis, type Project, type ProjectStatus } from '@/lib/api'
import ShareBar from '@/components/ShareBar.vue'
import ExposureGuide from '@/components/ExposureGuide.vue'
import LearningProgress from '@/components/LearningProgress.vue'

// learning: 관리 › 카테고리별 학습 화면(분석 대신 "학습"이라고 부른다)
const props = defineProps<{ projectId: number; referenceCount: number; customHashtags?: string[] | null; learning?: boolean }>()
const emit = defineEmits<{ status: [status: ProjectStatus]; hashtagsSaved: [project: Project] }>()

const POLL_MS = 2000

const SLOT_LABELS: Record<string, string> = {
  price: '가격',
  address: '위치·주소',
  phone: '연락처',
  hours: '영업시간',
  parking: '주차',
  reservation: '예약',
  wait: '웨이팅',
  menu: '메뉴',
  pros: '좋았던 점',
  cons: '아쉬운 점',
  recommend: '추천',
}
const POSITION_LABELS: Record<string, string> = {
  start: '앞',
  middle: '중간',
  end: '끝',
  none: '없음',
}
const INTRO_LABELS: Record<string, string> = {
  greeting: '인사로 시작',
  question: '질문으로 시작',
  summary: '요약으로 시작',
  story: '경험 이야기로 시작',
  none: '문단 없음',
}

const analysis = ref<KeywordAnalysis | null>(null)
const status = ref<ProjectStatus>('draft')
const progress = ref<AnalysisProgress | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
let timer: ReturnType<typeof setTimeout> | undefined

const stats = computed(() => analysis.value?.stats ?? null)
const analyzing = computed(() => status.value === 'analyzing')

function pct(share: number) {
  return `${Math.round(share * 100)}%`
}

function num(value: number) {
  return Math.round(value).toLocaleString('ko-KR')
}

async function load() {
  const result = await analysisApi.get(props.projectId)
  analysis.value = result.data
  status.value = result.status
  progress.value = result.progress ?? null
  loading.value = false
  emit('status', result.status)
  clearTimeout(timer)
  if (result.status === 'analyzing') timer = setTimeout(load, POLL_MS)
}

defineExpose({ load })

async function analyze() {
  error.value = null
  try {
    // 참고자료가 그대로여도 사용자가 다시 누르면 새로 분석한다
    const result = await analysisApi.analyze(props.projectId, analysis.value !== null && !analysis.value.stale)
    if (result.data) analysis.value = result.data
    status.value = result.status
    progress.value = result.progress ?? null
    emit('status', result.status)
    if (result.status === 'analyzing') timer = setTimeout(load, POLL_MS)
  } catch {
    error.value = '분석을 시작하지 못했습니다.'
  }
}

onMounted(load)
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <section class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 class="m-0 text-sm font-bold">{{ learning ? '학습 결과' : '키워드 분석' }}</h2>
      <button
        type="button"
        :disabled="analyzing || loading"
        class="h-11 rounded-xl bg-ink px-5 font-bold text-cream disabled:opacity-50"
        @click="analyze"
      >
        {{ learning ? (analyzing ? '학습 중…' : analysis ? '다시 학습' : '학습하기') : analyzing ? '분석 중…' : analysis ? '다시 분석' : '분석하기' }}
      </button>
    </div>

    <LearningProgress v-if="analyzing && progress" :progress="progress" :learning="learning" />
    <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
    <p v-if="status === 'failed'" role="alert" class="text-sm text-red-600">
      분석에 실패했습니다. 잠시 뒤 다시 시도해 주세요.
    </p>

    <p v-if="loading" class="text-sub">불러오는 중…</p>
    <p v-else-if="!analysis && !analyzing" class="text-sub">
      {{
        referenceCount > 0
          ? learning
            ? `학습할 글 ${referenceCount}개로 글 구성·사진 배치·해시태그를 학습해요.`
            : `참고자료 ${referenceCount}개로 검색 의도와 글 구성을 분석합니다.`
          : learning
            ? '위에 잘 쓴 글 URL을 넣고 “학습하기”를 누르세요. 글 구성·사진 배치·해시태그를 학습해요.'
            : '참고자료 없이도 키워드만으로 분석할 수 있지만, 참고자료를 추가하면 구성·사진 배치 통계가 함께 나옵니다.'
      }}
    </p>

    <template v-if="analysis">
      <p class="text-sm text-sub">
        {{
          stats
            ? `등록한 참고자료 ${stats.reference_count}개의 분포입니다.`
            : '참고자료 없이 키워드만으로 만든 분석입니다.'
        }}
        네이버 검색 순위 예측이 아닙니다.
      </p>
      <p
        v-if="analysis.stale"
        class="rounded-[14px] border-2 border-ink bg-lemon px-3.5 py-3 text-[13px]"
        role="status"
      >
        {{ learning ? '학습 뒤에 글이 바뀌었어요. 다시 학습해 주세요.' : '분석 뒤에 참고자료나 키워드가 바뀌었습니다. 다시 분석해 주세요.' }}
      </p>
      <p
        v-if="analysis.insight_error"
        class="rounded-[14px] border-[1.5px] border-line bg-white px-3.5 py-3 text-[13px] text-sub"
        role="status"
      >
        AI 해석 없이 통계만 표시합니다.
        {{
          analysis.insight_error.includes('billing')
            ? 'AI 공급자 계정의 크레딧(잔액)이 부족합니다. 충전한 뒤 다시 분석해 주세요.'
            : 'API 키를 설정한 뒤 다시 분석하면 검색 의도와 추천 목차가 나옵니다.'
        }}
      </p>

      <ExposureGuide
        :project-id="projectId"
        :guide="analysis.guide"
        :custom-hashtags="customHashtags"
        @saved="(project) => emit('hashtagsSaved', project)"
      />

      <div v-if="analysis.primary_intent" class="grid gap-4 md:grid-cols-2">
        <div class="space-y-3 rounded-[18px] border-[1.5px] border-line bg-white p-4">
          <h3 class="font-medium">검색 의도 · {{ analysis.primary_intent }}</h3>
          <ShareBar
            v-for="intent in analysis.intent_distribution"
            :key="intent.label"
            :label="intent.label"
            :share="intent.share"
          />
        </div>
        <div class="space-y-2 rounded-[18px] border-[1.5px] border-line bg-white p-4">
          <h3 class="font-medium">글에서 꼭 답할 질문</h3>
          <ul class="list-disc space-y-1 pl-5 text-sm">
            <li v-for="question in analysis.must_answer" :key="question">{{ question }}</li>
          </ul>
        </div>
        <div class="space-y-2 rounded-[18px] border-[1.5px] border-line bg-white p-4 md:col-span-2">
          <h3 class="font-medium">추천 글 구성</h3>
          <ol class="space-y-2 text-sm">
            <li
              v-for="(section, index) in analysis.recommended_outline"
              :key="index"
              class="grid grid-cols-[1.5rem_1fr] gap-x-2"
            >
              <span class="text-muted tabular-nums">{{ index + 1 }}</span>
              <div>
                <p class="font-medium">{{ section.heading }}</p>
                <p class="text-sub">{{ section.purpose }}</p>
                <p class="text-sub">사진: {{ section.photo_hint }}</p>
              </div>
            </li>
          </ol>
        </div>
        <div class="space-y-2 rounded-[18px] border-[1.5px] border-line bg-white p-4">
          <h3 class="font-medium">제목 가이드</h3>
          <ul class="list-disc space-y-1 pl-5 text-sm">
            <li v-for="guide in analysis.title_guidelines" :key="guide">{{ guide }}</li>
          </ul>
          <h3 class="pt-2 font-medium">작성 팁</h3>
          <ul class="list-disc space-y-1 pl-5 text-sm">
            <li v-for="tip in analysis.writing_tips" :key="tip">{{ tip }}</li>
          </ul>
        </div>
        <div class="space-y-2 rounded-[18px] border-[1.5px] border-line bg-white p-4">
          <h3 class="font-medium">함께 쓰면 좋은 표현</h3>
          <div class="flex flex-wrap gap-1.5">
            <span
              v-for="word in analysis.related_keywords"
              :key="word"
              class="rounded-full bg-lilac-soft px-2.5 py-1 text-xs"
            >
              {{ word }}
            </span>
          </div>
        </div>
      </div>

      <div v-if="stats" class="space-y-4">
        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <div class="rounded-[18px] border-[1.5px] border-line bg-white p-4">
            <dt class="text-sm text-sub">글 길이 (중앙값)</dt>
            <dd class="font-display text-[28px] leading-none tabular-nums">{{ num(stats.char_count.median) }}자</dd>
            <dd class="text-xs text-sub tabular-nums">
              {{ num(stats.char_count.p25) }}~{{ num(stats.char_count.p75) }}자
            </dd>
          </div>
          <div class="rounded-[18px] border-[1.5px] border-line bg-white p-4">
            <dt class="text-sm text-sub">사진</dt>
            <dd class="font-display text-[28px] leading-none tabular-nums">{{ num(stats.photos.count.median) }}장</dd>
            <dd class="text-xs text-sub tabular-nums">
              {{ num(stats.photos.count.p25) }}~{{ num(stats.photos.count.p75) }}장
            </dd>
          </div>
          <div class="rounded-[18px] border-[1.5px] border-line bg-white p-4">
            <dt class="text-sm text-sub">소제목</dt>
            <dd class="font-display text-[28px] leading-none tabular-nums">{{ num(stats.heading_count.median) }}개</dd>
          </div>
          <div class="rounded-[18px] border-[1.5px] border-line bg-white p-4">
            <dt class="text-sm text-sub">문단</dt>
            <dd class="font-display text-[28px] leading-none tabular-nums">
              {{ num(stats.paragraph_count.median) }}개
            </dd>
          </div>
        </dl>

        <div class="grid gap-4 md:grid-cols-2">
          <div class="space-y-2 rounded-[18px] border-[1.5px] border-line bg-white p-4">
            <h3 class="font-medium">사진 배치</h3>
            <ul class="space-y-1 text-sm text-ink">
              <li>{{ pct(stats.photos.starts_with_photo_share) }}가 사진으로 글을 시작</li>
              <li>도입부 사진 {{ num(stats.photos.intro_images.median) }}장 (중앙값)</li>
              <li>한 번에 최대 {{ num(stats.photos.max_group_size.median) }}장씩 묶어 배치</li>
              <li v-if="stats.photos.paragraphs_between_groups">
                사진 묶음 사이 문단 {{ stats.photos.paragraphs_between_groups.median }}개
              </li>
            </ul>
            <h3 class="pt-2 font-medium">시작 구성</h3>
            <ShareBar
              v-for="pattern in stats.opening_patterns"
              :key="pattern.label"
              :label="pattern.label"
              :share="pattern.share"
            />
          </div>
          <div class="space-y-2 rounded-[18px] border-[1.5px] border-line bg-white p-4">
            <h3 class="font-medium">자주 다룬 정보</h3>
            <ShareBar
              v-for="slot in stats.slots.filter((s) => s.share > 0)"
              :key="slot.label"
              :label="SLOT_LABELS[slot.label] ?? slot.label"
              :share="slot.share"
            />
          </div>
          <div class="space-y-2 rounded-[18px] border-[1.5px] border-line bg-white p-4">
            <h3 class="font-medium">자주 나온 소제목 주제</h3>
            <div class="flex flex-wrap gap-1.5">
              <span
                v-for="topic in stats.topics"
                :key="topic.term"
                class="rounded-full bg-lilac-soft px-2.5 py-1 text-xs"
              >
                {{ topic.term }}
                <span class="text-sub">{{ topic.documents }}/{{ stats.reference_count }}</span>
              </span>
            </div>
            <h3 class="pt-2 font-medium">자주 나온 단어</h3>
            <div class="flex flex-wrap gap-1.5">
              <span
                v-for="term in stats.terms"
                :key="term.term"
                class="rounded-full bg-lilac-soft px-2.5 py-1 text-xs"
              >
                {{ term.term }}
              </span>
            </div>
          </div>
          <div v-if="stats.title" class="space-y-2 rounded-[18px] border-[1.5px] border-line bg-white p-4">
            <h3 class="font-medium">제목</h3>
            <p class="text-sm text-ink">
              길이 {{ num(stats.title.length.median) }}자 · 괄호 사용
              {{ pct(stats.title.with_brackets_share) }} · 숫자 포함
              {{ pct(stats.title.with_number_share) }}
            </p>
            <ShareBar
              v-for="position in stats.title.keyword_position"
              :key="position.label"
              :label="`키워드 위치: ${POSITION_LABELS[position.label] ?? position.label}`"
              :share="position.share"
            />
            <h3 class="pt-2 font-medium">도입과 마무리</h3>
            <ShareBar
              v-for="intro in stats.intro_types"
              :key="intro.label"
              :label="INTRO_LABELS[intro.label] ?? intro.label"
              :share="intro.share"
            />
            <ShareBar label="총평으로 마무리" :share="stats.ending_summary_share" />
            <ShareBar label="공감·댓글 요청" :share="stats.ending_engagement_share" />
          </div>
        </div>
      </div>
    </template>
  </section>
</template>
