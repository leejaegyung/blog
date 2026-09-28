import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ImportView from '@/views/ImportView.vue'
import { analysisApi, projectApi, referenceApi, type Project, type Reference } from '@/lib/api'
import { bookmarkletHref, parseImportMessage, summarize } from '@/lib/bookmarklet'

vi.mock('@/lib/api', () => ({
  projectApi: { list: vi.fn<(kind?: string) => Promise<Project[]>>() },
  referenceApi: {
    send: vi.fn<(id: number, input: object) => Promise<{ data: Reference[]; skipped: { url: string; reason: string }[] }>>(),
    list: vi.fn<(id: number) => Promise<Reference[]>>(),
  },
  analysisApi: { analyze: vi.fn<(id: number, force?: boolean) => Promise<{ status: string; cached: boolean }>>() },
}))

const TEXT = '[사진]\n인계동 파스타 다녀왔어요. 맛있었어요.\n\n# 메뉴\n봉골레 18,000원\n\n#인계동맛집 #수원파스타'

function mountView() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: ImportView },
      { path: '/projects', name: 'projects', component: { render: () => null } },
      { path: '/projects/:id', name: 'project', component: { render: () => null } },
    ],
  })
  return mount(ImportView, { global: { plugins: [router] } })
}

describe('북마크 버튼 도우미', () => {
  it('설치 주소에 Blog AI 주소가 들어가고, 받은 메시지를 검사한다', () => {
    const href = bookmarkletHref('http://leejk-macbookpro:8080')
    expect(href.startsWith('javascript:')).toBe(true)
    expect(decodeURIComponent(href)).toContain("var APP='http://leejk-macbookpro:8080'")

    expect(parseImportMessage({ type: 'other', text: TEXT })).toBeNull()
    expect(parseImportMessage({ type: 'blog-ai-import', text: '짧음' })).toBeNull()
    expect(parseImportMessage({ type: 'blog-ai-import', title: '제목', text: TEXT, url: 'javascript:alert(1)' })).toEqual({ title: '제목', text: TEXT, url: '' })
    expect(summarize(TEXT)).toMatchObject({ headings: 1, photos: 1, hashtags: 2 })
  })
})

describe('ImportView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(projectApi.list).mockResolvedValue([{ id: 5, keyword: '맛집', kind: 'category' }] as Project[])
    vi.mocked(referenceApi.send).mockReset().mockResolvedValue({ data: [{ id: 9 } as Reference], skipped: [] })
    vi.mocked(referenceApi.list).mockReset().mockResolvedValue([{ id: 9, parse_status: 'parsed' } as Reference])
    vi.mocked(analysisApi.analyze).mockReset().mockResolvedValue({ status: 'analyzing', cached: false })
  })

  it('보낸 글을 받아 요약을 보여주고, 카테고리에 추가한 뒤 학습을 시작한다', async () => {
    const wrapper = mountView()
    await flushPromises()
    expect(wrapper.text()).toContain('글을 기다리고 있어요')

    window.dispatchEvent(new MessageEvent('message', { data: { type: 'blog-ai-import', title: '인계동 파스타 후기', text: TEXT, url: 'https://blog.naver.com/me/1' } }))
    await flushPromises()
    expect(wrapper.text()).toContain('인계동 파스타 후기')
    expect(wrapper.text()).toContain('사진 1')

    await wrapper.findAll('button').find((b) => b.text() === '맛집에 추가하고 학습')!.trigger('click')
    await flushPromises()

    expect(referenceApi.send).toHaveBeenCalledWith(5, { text: TEXT, title: '인계동 파스타 후기', sourceUrl: 'https://blog.naver.com/me/1' })
    expect(analysisApi.analyze).toHaveBeenCalledWith(5, true)
    expect(wrapper.text()).toContain('맛집에 추가했어요 · 학습을 시작했어요')
  })

  it('이미 있는 글이면 알려준다', async () => {
    vi.mocked(referenceApi.send).mockResolvedValue({ data: [], skipped: [{ url: 'x', reason: '이미 등록된 글입니다.' }] })
    const wrapper = mountView()
    await flushPromises()
    window.dispatchEvent(new MessageEvent('message', { data: { type: 'blog-ai-import', title: 't', text: TEXT, url: '' } }))
    await flushPromises()

    await wrapper.findAll('button').find((b) => b.text() === '맛집에 추가하고 학습')!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('이미 맛집에 있는 글이에요')
    expect(analysisApi.analyze).not.toHaveBeenCalled()
  })
})
