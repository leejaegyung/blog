import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { AxiosError, AxiosHeaders } from 'axios'
import ProjectsView from '@/views/ProjectsView.vue'
import { projectApi, type Project, type ProjectInput } from '@/lib/api'

vi.mock('@/lib/api', () => ({
  projectApi: {
    list: vi.fn<() => Promise<Project[]>>(),
    create: vi.fn<(input: ProjectInput) => Promise<Project>>(),
    remove: vi.fn<(id: number) => Promise<void>>(),
  },
}))

const project: Project = {
  id: 7,
  keyword: '수원 인계동 파스타',
  category: '맛집',
  kind: 'category',
  status: 'draft',
  last_analyzed_at: null,
  analysis_version: null,
  reference_count: 2,
  post_count: 1,
  created_at: '',
  updated_at: '',
}

function mountView() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'projects', component: ProjectsView },
      { path: '/projects/:id', name: 'project', component: { template: '<div />' } },
    ],
  })
  return { router, wrapper: mount(ProjectsView, { global: { plugins: [router] } }) }
}

describe('ProjectsView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(projectApi.list).mockReset()
    vi.mocked(projectApi.create).mockReset()
  })

  it('학습 카테고리 목록을 표시한다', async () => {
    vi.mocked(projectApi.list).mockResolvedValue([project])
    const { wrapper } = mountView()
    await flushPromises()

    expect(wrapper.text()).toContain('수원 인계동 파스타')
    expect(wrapper.text()).toContain('학습한 글 2 · 쓴 글 1')
    expect(projectApi.list).toHaveBeenCalledWith('category')
  })

  it('카테고리를 지우기 전에 카드 안에서 한 번 더 묻고, 지우면 목록에서 뺀다', async () => {
    vi.mocked(projectApi.list).mockResolvedValue([project, { ...project, id: 8, keyword: '테스트' }])
    vi.mocked(projectApi.remove).mockResolvedValue()
    const { wrapper } = mountView()
    await flushPromises()

    await wrapper.get('button[aria-label="테스트 카테고리 삭제"]').trigger('click')
    expect(wrapper.get('[role="alert"]').text()).toContain('카테고리를 지울까요?')
    expect(projectApi.remove).not.toHaveBeenCalled()
    await wrapper.findAll('button').find((b) => b.text() === '지우기')!.trigger('click')
    await flushPromises()

    expect(projectApi.remove).toHaveBeenCalledWith(8)
    expect(wrapper.text()).not.toContain('테스트')
    expect(wrapper.text()).toContain('수원 인계동 파스타')
  })

  it('만들기에 성공하면 상세 화면으로 이동한다', async () => {
    vi.mocked(projectApi.list).mockResolvedValue([])
    vi.mocked(projectApi.create).mockResolvedValue(project)
    const { wrapper, router } = mountView()
    await flushPromises()

    await wrapper.get('input[aria-label="카테고리 이름"]').setValue('맛집')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(projectApi.create).toHaveBeenCalledWith({ keyword: '맛집', kind: 'category' })
    expect(router.currentRoute.value.fullPath).toBe('/projects/7')
  })

  it('같은 이름 카테고리 오류를 보여준다', async () => {
    vi.mocked(projectApi.list).mockResolvedValue([])
    vi.mocked(projectApi.create).mockRejectedValue(
      new AxiosError('422', '422', undefined, undefined, {
        status: 422,
        statusText: '',
        headers: {},
        config: { headers: new AxiosHeaders() },
        data: { errors: { keyword: ['이미 같은 이름의 카테고리가 있어요.'] } },
      }),
    )
    const { wrapper } = mountView()
    await flushPromises()

    await wrapper.get('input[aria-label="카테고리 이름"]').setValue('중복')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe('이미 같은 이름의 카테고리가 있어요.')
  })
})
