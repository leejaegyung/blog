import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { AxiosError, AxiosHeaders } from 'axios'
import ProjectsView from '@/views/ProjectsView.vue'
import { projectApi, type Project, type ProjectInput } from '@/lib/api'

vi.mock('@/lib/api', () => ({
  projectApi: {
    list: vi.fn<() => Promise<Project[]>>(),
    create: vi.fn<(input: ProjectInput) => Promise<Project>>(),
  },
}))

const project: Project = {
  id: 7,
  keyword: '수원 인계동 파스타',
  category: '맛집',
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
    vi.mocked(projectApi.list).mockReset()
    vi.mocked(projectApi.create).mockReset()
  })

  it('프로젝트 목록을 표시한다', async () => {
    vi.mocked(projectApi.list).mockResolvedValue([project])
    const { wrapper } = mountView()
    await flushPromises()

    expect(wrapper.text()).toContain('수원 인계동 파스타')
    expect(wrapper.text()).toContain('참고자료 2 · 글 1')
  })

  it('만들기에 성공하면 상세 화면으로 이동한다', async () => {
    vi.mocked(projectApi.list).mockResolvedValue([])
    vi.mocked(projectApi.create).mockResolvedValue(project)
    const { wrapper, router } = mountView()
    await flushPromises()

    await wrapper.get('input[aria-label="키워드"]').setValue('수원 인계동 파스타')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(projectApi.create).toHaveBeenCalledWith({ keyword: '수원 인계동 파스타', category: null })
    expect(router.currentRoute.value.fullPath).toBe('/projects/7')
  })

  it('중복 키워드 오류를 보여준다', async () => {
    vi.mocked(projectApi.list).mockResolvedValue([])
    vi.mocked(projectApi.create).mockRejectedValue(
      new AxiosError('422', '422', undefined, undefined, {
        status: 422,
        statusText: '',
        headers: {},
        config: { headers: new AxiosHeaders() },
        data: { errors: { keyword: ['이미 같은 키워드의 프로젝트가 있습니다.'] } },
      }),
    )
    const { wrapper } = mountView()
    await flushPromises()

    await wrapper.get('input[aria-label="키워드"]').setValue('중복')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe('이미 같은 키워드의 프로젝트가 있습니다.')
  })
})
