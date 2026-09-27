import type { Post, Tone } from '@/lib/api'

export const STEP_NAMES = ['키워드', '사진', '알려줄 내용', '글 계획', '초안 다듬기', '네이버에 올리기'] as const
export type StepNo = 1 | 2 | 3 | 4 | 5 | 6

export const TONE_LABELS: Record<Tone, string> = {
  natural: '자연스러운 후기',
  friendly: '친근한 말투',
  expert: '전문 정보형',
  clean: '깔끔한 정보형',
}

function factCount(post: Post) {
  return post.facts?.length ?? post.fact_count ?? 0
}

function imageCount(post: Post) {
  return post.images?.length ?? post.image_count ?? 0
}

/** 이어서 할 단계 (홈의 "이어서"와 글 주소로 들어왔을 때) */
export function resumeStep(post: Post): StepNo {
  if (post.status === 'published' || post.published_url) return 6
  if (post.content || post.status === 'generating') return 5
  if (post.plan || post.pipeline_status === 'running' || post.status === 'planning') return 4
  if (factCount(post) > 0) return 3
  return imageCount(post) > 0 ? 3 : 2
}

/** 이 단계까지는 눌러서 이동할 수 있다 */
export function reachableStep(post: Post | null): StepNo {
  if (!post) return 1
  if (post.content) return 6
  if (post.plan) return 5
  if (factCount(post) > 0) return 4
  return 3
}

export function stepMeta(post: Post | null, step: StepNo): string {
  if (!post) return ''
  switch (step) {
    case 1:
      return post.keyword ?? ''
    case 2: {
      const images = post.images ?? []
      if (!images.length) return ''
      return images.every((i) => i.vision) ? `${images.length}장 · 분석 완료` : `${images.length}장`
    }
    case 3:
      return factCount(post) ? `${factCount(post)}개 · ${TONE_LABELS[post.tone ?? 'natural']}` : ''
    case 4:
      return post.plan ? `목차 ${post.plan.outline.length}개` : ''
    case 5:
      return post.quality ? `검사 ${Math.round(post.quality.score)}점` : ''
    case 6:
      return post.published_url ? '게시 완료' : ''
  }
}

export function relativeDate(iso: string): string {
  const date = new Date(iso)
  const today = new Date()
  if (date.toDateString() === today.toDateString()) return '오늘'
  return `${date.getMonth() + 1}월 ${date.getDate()}일`
}
