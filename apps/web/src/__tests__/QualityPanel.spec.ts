import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import QualityPanel from '@/components/QualityPanel.vue'
import { postApi, type Post, type QualityReport } from '@/lib/api'

vi.mock('@/lib/api', () => ({ postApi: { qualityCheck: vi.fn<(id: number) => Promise<Post>>() } }))

const REPORT: QualityReport = {
  version: 'quality-1',
  score: 72.4,
  parts: [
    { key: 'facts', label: '사용자 사실 반영', score: 13.3, max: 20 },
    { key: 'risk', label: '중복·위험 표현', score: 5, max: 10 },
  ],
  issues: [
    { code: 'unsupported_specific', severity: 'error', message: '입력하지 않은 시간 정보가 있습니다: 오후 3시', block_index: 7, excerpt: '오후 3시' },
    { code: 'title_keyword', severity: 'warning', message: '제목에 키워드가 없습니다.', block_index: null, excerpt: null },
    { code: 'length', severity: 'info', message: '글자 수가 목표와 차이가 큽니다.', block_index: null, excerpt: null },
  ],
  metrics: {},
}

const post = (extra: Partial<Post> = {}) => ({ id: 3, quality: REPORT, quality_stale: false, ...extra }) as Post

describe('QualityPanel', () => {
  beforeEach(() => vi.mocked(postApi.qualityCheck).mockReset())

  it('점수·항목·심각도별 문제를 보여주고 순위 보장이 아님을 알린다', () => {
    const wrapper = mount(QualityPanel, { props: { post: post() } })
    const text = wrapper.text()

    expect(text).toContain('72 / 100')
    expect(text).toContain('네이버 순위나 노출을 보장하지 않습니다')
    expect(text).toContain('13.3/20')
    expect(wrapper.findAll('h3').map((h) => h.text())).toEqual(['꼭 고치기 1', '확인 권장 1', '참고 1'])
  })

  it('위치가 있는 문제만 위치 보기를 제공한다', async () => {
    const wrapper = mount(QualityPanel, { props: { post: post() } })
    const buttons = wrapper.findAll('li button')

    expect(buttons).toHaveLength(1)
    await buttons[0]!.trigger('click')
    expect(wrapper.emitted('locate')![0]![0]).toMatchObject({ excerpt: '오후 3시', block_index: 7 })
  })

  it('검사 후 결과를 올려보내고, 본문이 바뀌었으면 안내한다', async () => {
    vi.mocked(postApi.qualityCheck).mockResolvedValue(post({ quality_stale: false }))
    const wrapper = mount(QualityPanel, { props: { post: post({ quality_stale: true }) } })

    expect(wrapper.text()).toContain('다시 검사해 주세요')
    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(postApi.qualityCheck).toHaveBeenCalledWith(3)
    expect(wrapper.emitted('updated')).toHaveLength(1)
  })
})
