import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ReferencesPanel from '@/components/ReferencesPanel.vue'
import { referenceApi, type Reference } from '@/lib/api'

vi.mock('@/lib/api', () => ({
  referenceApi: {
    list: vi.fn<(projectId: number) => Promise<Reference[]>>(),
    addUrls: vi.fn<(projectId: number, urls: string[]) => Promise<unknown>>(),
    addText: vi.fn<(projectId: number, text: string, title?: string) => Promise<Reference>>(),
    pasteText: vi.fn<(id: number, text: string, title?: string) => Promise<Reference>>(),
    reparse: vi.fn<(id: number) => Promise<Reference>>(),
    remove: vi.fn<(id: number) => Promise<void>>(),
  },
}))

function reference(id: number, parse_status: Reference['parse_status'], extra: Partial<Reference> = {}): Reference {
  return {
    id,
    source_type: 'user_url',
    source_url: `https://ex.com/${id}`,
    title: null,
    author: null,
    published_at: null,
    parse_status,
    error_message: null,
    char_count: null,
    paragraph_count: null,
    image_count: null,
    heading_count: null,
    collected_at: null,
    created_at: '',
    ...extra,
  }
}

describe('ReferencesPanel', () => {
  beforeEach(() => {
    Object.values(referenceApi).forEach((fn) => vi.mocked(fn).mockReset())
  })
  afterEach(() => vi.useRealTimers())

  it('줄마다 URL을 나눠 보내고 결과를 요약한다', async () => {
    vi.mocked(referenceApi.list).mockResolvedValue([])
    vi.mocked(referenceApi.addUrls).mockResolvedValue({
      data: [reference(1, 'pending'), reference(2, 'needs_text')],
      skipped: [{ url: 'https://ex.com/9', reason: '이미 등록된 주소입니다.' }],
    })
    const wrapper = mount(ReferencesPanel, { props: { projectId: 3 } })
    await flushPromises()

    await wrapper
      .get('textarea[aria-label="참고할 글 URL"]')
      .setValue('  https://ex.com/1\n\nhttps://blog.naver.com/a/2 \nhttps://ex.com/9')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(referenceApi.addUrls).toHaveBeenCalledWith(3, [
      'https://ex.com/1',
      'https://blog.naver.com/a/2',
      'https://ex.com/9',
    ])
    expect(wrapper.get('[role="status"]').text()).toBe(
      '2개 추가 · 1개는 이미 있어 건너뜀 · 네이버 글 1개는 본문을 붙여넣어 주세요',
    )
  })

  it('본문 필요 항목에 붙여넣기 폼을 연다', async () => {
    vi.mocked(referenceApi.list).mockResolvedValue([reference(5, 'needs_text')])
    vi.mocked(referenceApi.pasteText).mockResolvedValue(reference(5, 'pending'))
    const wrapper = mount(ReferencesPanel, { props: { projectId: 3 } })
    await flushPromises()

    await wrapper.get('li button').trigger('click')
    await wrapper.get('textarea[aria-label="이 글의 본문"]').setValue('본문 내용')
    await wrapper.get('li form').trigger('submit')
    await flushPromises()

    expect(referenceApi.pasteText).toHaveBeenCalledWith(5, '본문 내용', '')
  })

  it('분석 중인 항목이 있으면 끝날 때까지 다시 불러온다', async () => {
    vi.useFakeTimers()
    vi.mocked(referenceApi.list)
      .mockResolvedValueOnce([reference(1, 'pending')])
      .mockResolvedValueOnce([reference(1, 'parsed', { char_count: 1500, image_count: 4, heading_count: 2 })])
    const wrapper = mount(ReferencesPanel, { props: { projectId: 3 } })
    await flushPromises()
    expect(wrapper.text()).toContain('분석 중')

    await vi.advanceTimersByTimeAsync(2000)
    await flushPromises()

    expect(wrapper.text()).toContain('1,500자 · 소제목 2 · 사진 4')
    await vi.advanceTimersByTimeAsync(5000)
    expect(referenceApi.list).toHaveBeenCalledTimes(2)
  })

  it('다시 가져올 수 없는 실패 항목은 붙여넣기를 안내한다', async () => {
    vi.mocked(referenceApi.list).mockResolvedValue([
      reference(8, 'failed', { source_url: 'https://m.blog.naver.com/a/1' }),
      reference(9, 'failed', { source_type: 'user_text', source_url: null }),
    ])
    const wrapper = mount(ReferencesPanel, { props: { projectId: 3 } })
    await flushPromises()

    const buttons = wrapper.findAll('li button').map((b) => b.text())
    expect(buttons.filter((t) => t === '본문 붙여넣기')).toHaveLength(2)
    expect(buttons).not.toContain('다시 시도')
  })

  it('실패한 항목은 다시 시도할 수 있다', async () => {
    vi.mocked(referenceApi.list).mockResolvedValue([
      reference(8, 'failed', { error_message: '페이지를 가져오지 못했습니다 (404).' }),
    ])
    vi.mocked(referenceApi.reparse).mockResolvedValue(reference(8, 'pending'))
    const wrapper = mount(ReferencesPanel, { props: { projectId: 3 } })
    await flushPromises()

    expect(wrapper.text()).toContain('페이지를 가져오지 못했습니다 (404).')
    await wrapper.findAll('li button').find((b) => b.text() === '다시 시도')!.trigger('click')

    expect(referenceApi.reparse).toHaveBeenCalledWith(8)
  })
})
