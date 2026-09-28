import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import HomeView from '@/views/HomeView.vue'
import { postApi, type Post } from '@/lib/api'

vi.mock('@/lib/api', () => ({
  postApi: { list: vi.fn<() => Promise<Post[]>>(), remove: vi.fn<(id: number) => Promise<void>>() },
}))

describe('HomeView', () => {
  function mountHome() {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', component: HomeView },
        { path: '/write', name: 'write-start', component: { render: () => null } },
        { path: '/posts/:id/:step', name: 'flow', component: { render: () => null } },
        { path: '/projects', component: { render: () => null } },
      ],
    })
    return { router, wrapper: mount(HomeView, { global: { plugins: [router, createPinia()] } }) }
  }

  it('글을 지우기 전에 카드 안에서 한 번 더 묻고, 지우면 목록에서 뺀다', async () => {
    vi.mocked(postApi.list).mockResolvedValue([
      { id: 1, keyword: '테스트', status: 'draft', updated_at: new Date().toISOString() },
      { id: 2, keyword: '테스트', status: 'draft', updated_at: new Date().toISOString() },
    ] as unknown as Post[])
    vi.mocked(postApi.remove).mockResolvedValue()
    const { wrapper } = mountHome()
    await flushPromises()

    await wrapper.findAll('button[aria-label="테스트 글 삭제"]')[0]!.trigger('click')
    expect(wrapper.get('[role="alert"]').text()).toContain('글을 지울까요?')
    expect(postApi.remove).not.toHaveBeenCalled()
    await wrapper.findAll('button').find((b) => b.text() === '지우기')!.trigger('click')
    await flushPromises()

    expect(postApi.remove).toHaveBeenCalledWith(1)
    expect(wrapper.findAll('a[href^="/posts/"]').map((a) => a.attributes('href'))).toEqual(['/posts/2/2'])
  })

  it('쓰던 글을 단계·진행률과 함께 보여주고 이어서 할 단계로 연결한다', async () => {
    vi.mocked(postApi.list).mockResolvedValue([
      { id: 1, keyword: '수원 인계동 파스타', status: 'planned', plan: {}, updated_at: new Date().toISOString() },
      { id: 2, keyword: '광교 브런치 카페', status: 'published', published_url: 'https://x', updated_at: '2026-09-22T00:00:00Z' },
    ] as unknown as Post[])
    const { router, wrapper } = mountHome()
    await flushPromises()

    const links = wrapper.findAll('a[href^="/posts/"]')
    expect(links.map((a) => a.attributes('href'))).toEqual(['/posts/1/4', '/posts/2/6'])
    expect(links[0]!.text()).toContain('4/6 글 계획 · 오늘')
    expect(links[0]!.text()).toContain('이어서')
    expect(links[1]!.text()).toContain('게시 완료 · 9월 22일')
    expect(links[1]!.text()).toContain('보기')

    await wrapper.get('input[aria-label="키워드"]').setValue('행궁동 디저트')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/write?keyword=%ED%96%89%EA%B6%81%EB%8F%99+%EB%94%94%EC%A0%80%ED%8A%B8')
  })
})
