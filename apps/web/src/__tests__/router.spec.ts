import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { AxiosError, AxiosHeaders } from 'axios'
import { authApi, type User } from '@/lib/api'
import { authGuard, routes } from '@/router'

vi.mock('@/lib/api', () => ({
  authApi: { me: vi.fn<() => Promise<{ user: User; autoLogin: boolean }>>() },
}))

function unauthorized() {
  return new AxiosError('401', '401', undefined, undefined, {
    status: 401,
    statusText: '',
    headers: {},
    config: { headers: new AxiosHeaders() },
    data: {},
  })
}

function makeRouter() {
  const router = createRouter({ history: createMemoryHistory(), routes })
  router.beforeEach(authGuard)
  return router
}

describe('router guard', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(authApi.me).mockReset()
  })

  it('로그인하지 않으면 로그인 화면으로 보낸다', async () => {
    vi.mocked(authApi.me).mockRejectedValue(unauthorized())
    const router = makeRouter()

    await router.push('/projects/3')

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/projects/3')
  })

  it('로그인한 사용자는 로그인 화면 대신 홈으로 간다', async () => {
    vi.mocked(authApi.me).mockResolvedValue({
      user: { id: 1, name: 'me', username: 'admin', email: 'admin@localhost' },
      autoLogin: true,
    })
    const router = makeRouter()

    await router.push('/login')

    expect(router.currentRoute.value.name).toBe('home')
  })
})
