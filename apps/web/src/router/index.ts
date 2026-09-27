import {
  createRouter,
  createWebHistory,
  type NavigationGuardWithThis,
  type RouteRecordRaw,
} from 'vue-router'
import { useAuthStore } from '@/stores/auth'

declare module 'vue-router' {
  interface RouteMeta {
    guest?: boolean
    // 단계 화면처럼 화면 전체 폭을 쓰는 페이지
    wide?: boolean
    // 단계 화면: 모바일에서는 위쪽 앱 머리줄 없이 "← 글 이름 N/6"으로 시작한다(디자인 1a)
    flow?: boolean
  }
}

export const routes: RouteRecordRaw[] = [
  { path: '/', name: 'home', component: () => import('../views/HomeView.vue'), meta: { wide: true } },
  {
    path: '/login',
    name: 'login',
    component: () => import('../views/LoginView.vue'),
    meta: { guest: true },
  },
  {
    path: '/write',
    name: 'write-start',
    component: () => import('../views/FlowView.vue'),
    props: { step: 1 },
    meta: { wide: true, flow: true },
  },
  {
    path: '/posts/:id(\\d+)/:step([1-6])',
    name: 'flow',
    component: () => import('../views/FlowView.vue'),
    props: (route) => ({ id: Number(route.params.id), step: Number(route.params.step) }),
    meta: { wide: true, flow: true },
  },
  {
    // 글 주소만으로 들어오면 이어서 할 단계로 보낸다
    path: '/posts/:id(\\d+)',
    name: 'post',
    component: () => import('../views/ResumeView.vue'),
    props: (route) => ({ id: Number(route.params.id) }),
  },
  { path: '/posts/:id(\\d+)/edit', redirect: (to) => ({ name: 'post', params: to.params }) },
  { path: '/write/:id(\\d+)', redirect: (to) => ({ name: 'post', params: to.params }) },
  {
    path: '/projects',
    name: 'projects',
    component: () => import('../views/ProjectsView.vue'),
    meta: { wide: true },
  },
  {
    path: '/projects/:id(\\d+)',
    name: 'project',
    component: () => import('../views/ProjectDetailView.vue'),
    props: (route) => ({ id: Number(route.params.id) }),
    meta: { wide: true },
  },
  {
    path: '/admin',
    name: 'admin',
    component: () => import('../views/AdminView.vue'),
    meta: { wide: true },
  },
  { path: '/:pathMatch(.*)*', redirect: { name: 'home' } },
]

// guest 표시가 없는 화면은 모두 로그인이 필요하다.
export const authGuard: NavigationGuardWithThis<undefined> = async (to) => {
  const auth = useAuthStore()
  if (!auth.loaded) await auth.fetchUser()

  if (to.meta.guest) return auth.user ? { name: 'home' } : true
  if (!auth.user) return { name: 'login', query: { redirect: to.fullPath } }
  return true
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})

router.beforeEach(authGuard)

export default router
