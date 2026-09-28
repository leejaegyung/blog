import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import AnalysisPanel from '@/components/AnalysisPanel.vue'
import { analysisApi, type KeywordAnalysis, type ProjectStatus } from '@/lib/api'

vi.mock('@/lib/api', () => ({
  analysisApi: {
    get: vi.fn<(id: number) => Promise<{ data: KeywordAnalysis | null; status: ProjectStatus }>>(),
    analyze: vi.fn<
      (
        id: number,
        force?: boolean,
      ) => Promise<{ data?: KeywordAnalysis; status: ProjectStatus; cached: boolean }>
    >(),
  },
}))

const spread = (median: number) => ({ median, p25: median - 1, p75: median + 1 })

const ANALYSIS: KeywordAnalysis = {
  id: 1,
  primary_intent: '맛집 방문 후기',
  intent_distribution: [{ label: '맛집 방문 후기', share: 0.7 }],
  must_answer: ['주차가 되나요?'],
  guide: null,
  recommended_outline: [{ heading: '위치와 주차', purpose: '찾아가는 법', photo_hint: '외관 1장' }],
  title_guidelines: ['키워드를 앞에'],
  related_keywords: ['주차'],
  writing_tips: ['사진은 2~3장씩'],
  stats: {
    reference_count: 12,
    char_count: { median: 2480, p25: 1900, p75: 3100 },
    heading_count: spread(4),
    paragraph_count: spread(15),
    photos: {
      count: spread(14),
      starts_with_photo_share: 0.75,
      intro_images: spread(2),
      max_group_size: spread(3),
      paragraphs_between_groups: spread(1.5),
      per_1000_chars: spread(5),
    },
    title: {
      length: spread(24),
      keyword_position: [{ label: 'start', share: 0.8 }],
      with_brackets_share: 0.5,
      with_number_share: 0.25,
      with_question_share: 0,
    },
    keyword_in_first_paragraph_share: 0.9,
    slots: [
      { label: 'parking', share: 0.83 },
      { label: 'phone', share: 0 },
    ],
    topics: [{ term: '주차', documents: 10, share: 0.83 }],
    terms: [{ term: '봉골레', documents: 6, share: 0.5 }],
    opening_patterns: [{ label: '사진→문단→소제목', share: 0.6 }],
    intro_types: [{ label: 'greeting', share: 0.5 }],
    ending_summary_share: 0.4,
    ending_recommendation_share: 0.6,
    ending_engagement_share: 0.3,
  },
  insight_error: null,
  prompt_version: 'keyword-analysis-v1',
  stale: false,
  expires_at: null,
  created_at: '',
}

describe('AnalysisPanel', () => {
  beforeEach(() => {
    vi.mocked(analysisApi.get).mockReset()
    vi.mocked(analysisApi.analyze).mockReset()
  })
  afterEach(() => vi.useRealTimers())

  it('AI 해석과 통계를 함께 보여주고 순위 예측이 아님을 알린다', async () => {
    vi.mocked(analysisApi.get).mockResolvedValue({ data: ANALYSIS, status: 'analyzed' })
    const wrapper = mount(AnalysisPanel, { props: { projectId: 1, referenceCount: 12 } })
    await flushPromises()

    const text = wrapper.text()
    expect(text).toContain('등록한 참고자료 12개의 분포입니다. 네이버 검색 순위 예측이 아닙니다.')
    expect(text).toContain('검색 의도 · 맛집 방문 후기')
    expect(text).toContain('주차가 되나요?')
    expect(text).toContain('2,480자')
    expect(text).toContain('75%가 사진으로 글을 시작')
    expect(text).toContain('주차')
    expect(text).not.toContain('연락처') // 0%인 정보 항목은 숨긴다
  })

  it('AI 해석이 없으면 통계만 보여주고 안내한다', async () => {
    vi.mocked(analysisApi.get).mockResolvedValue({
      data: { ...ANALYSIS, primary_intent: null, insight_error: 'not_configured' },
      status: 'analyzed',
    })
    const wrapper = mount(AnalysisPanel, { props: { projectId: 1, referenceCount: 12 } })
    await flushPromises()

    expect(wrapper.text()).toContain('AI 해석 없이 통계만 표시합니다')
    expect(wrapper.text()).not.toContain('검색 의도 ·')
    expect(wrapper.text()).toContain('사진 배치')
  })

  it('분석을 시작하면 끝날 때까지 상태를 확인한다', async () => {
    vi.useFakeTimers()
    vi.mocked(analysisApi.get)
      .mockResolvedValueOnce({ data: null, status: 'draft' })
      .mockResolvedValueOnce({ data: ANALYSIS, status: 'analyzed' })
    vi.mocked(analysisApi.analyze).mockResolvedValue({ status: 'analyzing', cached: false })
    const wrapper = mount(AnalysisPanel, { props: { projectId: 1, referenceCount: 3 } })
    await flushPromises()

    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(wrapper.get('button').text()).toBe('분석 중…')
    expect(analysisApi.analyze).toHaveBeenCalledWith(1, false)

    await vi.advanceTimersByTimeAsync(3000)
    await flushPromises()

    expect(wrapper.get('button').text()).toBe('다시 분석')
    const statuses = wrapper.emitted('status')!
    expect(statuses[statuses.length - 1]).toEqual(['analyzed'])
  })

  it('참고자료가 바뀐 분석은 안내하고 강제 재분석 없이 다시 요청한다', async () => {
    vi.mocked(analysisApi.get).mockResolvedValue({ data: { ...ANALYSIS, stale: true }, status: 'analyzed' })
    vi.mocked(analysisApi.analyze).mockResolvedValue({ status: 'analyzing', cached: false })
    const wrapper = mount(AnalysisPanel, { props: { projectId: 1, referenceCount: 13 } })
    await flushPromises()

    expect(wrapper.text()).toContain('다시 분석해 주세요')
    await wrapper.get('button').trigger('click')

    expect(analysisApi.analyze).toHaveBeenCalledWith(1, false)
  })
})
