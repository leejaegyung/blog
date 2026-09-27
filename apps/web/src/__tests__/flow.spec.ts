import { describe, expect, it } from 'vitest'
import { reachableStep, resumeStep, stepMeta } from '@/lib/flow'
import type { Post } from '@/lib/api'

const post = (extra: Partial<Post> = {}) => ({ id: 1, status: 'draft', tone: 'natural', ...extra }) as unknown as Post

describe('흐름 단계', () => {
  it('이어서 할 단계를 글 상태로 정한다', () => {
    expect(resumeStep(post())).toBe(2)
    expect(resumeStep(post({ image_count: 3 }))).toBe(3)
    expect(resumeStep(post({ fact_count: 2 }))).toBe(3)
    expect(resumeStep(post({ pipeline_status: 'running' }))).toBe(4)
    expect(resumeStep(post({ plan: {} as Post['plan'] }))).toBe(4)
    expect(resumeStep(post({ content: { blocks: [], tags: [] } }))).toBe(5)
    expect(resumeStep(post({ status: 'published' }))).toBe(6)
  })

  it('이미 거친 단계까지만 눌러서 이동할 수 있다', () => {
    expect(reachableStep(null)).toBe(1)
    expect(reachableStep(post())).toBe(3)
    expect(reachableStep(post({ facts: [{ id: 1, fact_key: 'a', fact_value: 'b' }] }))).toBe(4)
    expect(reachableStep(post({ plan: {} as Post['plan'] }))).toBe(5)
    expect(reachableStep(post({ content: { blocks: [], tags: [] } }))).toBe(6)
  })

  it('단계 목록의 보조 설명', () => {
    const p = post({
      keyword: '인계동 파스타',
      facts: [{ id: 1, fact_key: '가격', fact_value: '19,000원' }],
      images: [{ id: 1, vision: {} }, { id: 2, vision: {} }] as unknown as Post['images'],
      quality: { score: 86.4 } as Post['quality'],
    })
    expect(stepMeta(p, 1)).toBe('인계동 파스타')
    expect(stepMeta(p, 2)).toBe('2장 · 분석 완료')
    expect(stepMeta(p, 3)).toBe('1개 · 자연스러운 후기')
    expect(stepMeta(p, 5)).toBe('검사 86점')
  })
})
