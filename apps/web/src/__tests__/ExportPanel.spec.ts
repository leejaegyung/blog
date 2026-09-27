import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ExportPanel from '@/components/ExportPanel.vue'
import { postApi, type ExportResult, type Post } from '@/lib/api'
import { copyRich } from '@/lib/clipboard'

vi.mock('@/lib/api', () => ({
  postApi: {
    exportPost: vi.fn<(id: number) => Promise<ExportResult>>(),
    publish: vi.fn<(id: number, url: string) => Promise<Post>>(),
    photosZipUrl: (id: number) => `/api/posts/${id}/export/photos.zip`,
  },
}))
vi.mock('@/lib/clipboard', () => ({
  copyRich: vi.fn<(html: string, text: string) => Promise<void>>(),
  copyText: vi.fn<(text: string) => Promise<void>>(),
}))

const RESULT: ExportResult = {
  html: '<p>본문</p>',
  text: '제목\n\n본문',
  tags: ['파스타'],
  photos: [{ number: 1, image_id: 5, filename: '01.jpg', url: '/x' }],
  warnings: ["게시 전 검사에서 '꼭 고치기' 1개가 남아 있습니다."],
}

const post = (extra: Partial<Post> = {}) =>
  ({
    id: 7,
    title: '제목',
    status: 'review',
    published_url: null,
    quality: {
      issues: [{ code: 'x', severity: 'error', message: 'm', block_index: null, excerpt: null }],
    },
    ...extra,
  }) as unknown as Post

describe('ExportPanel', () => {
  beforeEach(() => {
    vi.mocked(postApi.exportPost).mockReset()
    vi.mocked(postApi.publish).mockReset()
    vi.mocked(copyRich).mockReset()
  })

  it('꼭 고치기가 남아 있으면 미리 알린다', () => {
    const wrapper = mount(ExportPanel, { props: { post: post(), images: [] } })

    expect(wrapper.text()).toContain("'꼭 고치기' 1개가 남아 있습니다")
  })

  it('내보내기를 준비하고 서식 포함으로 복사한다', async () => {
    vi.mocked(postApi.exportPost).mockResolvedValue(RESULT)
    vi.mocked(copyRich).mockResolvedValue()
    const wrapper = mount(ExportPanel, { props: { post: post(), images: [] } })

    await wrapper.findAll('button').find((b) => b.text() === '내보내기 준비')!.trigger('click')
    await flushPromises()
    await wrapper.findAll('button').find((b) => b.text() === '서식 포함 복사')!.trigger('click')
    await flushPromises()

    expect(copyRich).toHaveBeenCalledWith('<p>본문</p>', '제목\n\n본문')
    expect(wrapper.text()).toContain('서식 포함 본문을 복사했습니다.')
    expect(wrapper.get('a[download]').attributes('href')).toBe('/api/posts/7/export/photos.zip')
    expect(wrapper.get('[aria-label="내보내기 전 확인"]').text()).toContain('꼭 고치기')
  })

  it('게시한 주소를 기록한다', async () => {
    vi.mocked(postApi.publish).mockResolvedValue(post({ status: 'published', published_url: 'https://blog.naver.com/me/1' }))
    const wrapper = mount(ExportPanel, { props: { post: post(), images: [] } })

    await wrapper.get('input[type="url"]').setValue(' https://blog.naver.com/me/1 ')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(postApi.publish).toHaveBeenCalledWith(7, 'https://blog.naver.com/me/1')
    expect(wrapper.emitted('updated')).toHaveLength(1)
  })
})
