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
  }
}

export const routes: RouteRecordRaw[] = [
  { path: '/', redirect: { name: 'projects' } },
  {
    path: '/login',
    name: 'login',
    component: () => import('../views/LoginView.vue'),
    meta: { guest: true },
  },
  {
    path: '/projects',
    name: 'projects',
    component: () => import('../views/ProjectsView.vue'),
  },
  {
    path: '/projects/:id(\\d+)',
    name: 'project',
    component: () => import('../views/ProjectDetailView.vue'),
    props: (route) => ({ id: Number(route.params.id) }),
  },
  {
    path: '/posts/:id(\\d+)/edit',
    name: 'post-edit',
    component: () => import('../views/PostEditView.vue'),
    props: (route) => ({ id: Number(route.params.id) }),
  },
  {
    path: '/admin',
    name: 'admin',
    component: () => import('../views/AdminView.vue'),
  },
  { path: '/:pathMatch(.*)*', redirect: { name: 'projects' } },
]

// guest 표시가 없는 화면은 모두 로그인이 필요하다.
export const authGuard: NavigationGuardWithThis<undefined> = async (to) => {
  const auth = useAuthStore()
  if (!auth.loaded) await auth.fetchUser()

  if (to.meta.guest) return auth.user ? { name: 'projects' } : true
  if (!auth.user) return { name: 'login', query: { redirect: to.fullPath } }
  return true
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})

router.beforeEach(authGuard)

export default router
