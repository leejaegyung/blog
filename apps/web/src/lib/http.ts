import axios, { AxiosError, type InternalAxiosRequestConfig } from 'axios'

// Sanctum SPA 인증: 같은 origin 쿠키 + XSRF-TOKEN 헤더
export const http = axios.create({
  baseURL: '/api',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
})

export function fetchCsrfCookie() {
  return axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

type RetriableConfig = InternalAxiosRequestConfig & { _csrfRetried?: boolean }

// 세션이 만료돼 CSRF 토큰이 어긋나면(419) 쿠키를 다시 받고 한 번만 재시도한다.
http.interceptors.response.use(undefined, async (error: AxiosError) => {
  const config = error.config as RetriableConfig | undefined
  if (error.response?.status === 419 && config && !config._csrfRetried) {
    config._csrfRetried = true
    await fetchCsrfCookie()
    return http.request(config)
  }
  return Promise.reject(error)
})

export type ValidationErrors = Record<string, string[]>

export function validationErrors(error: unknown): ValidationErrors | null {
  if (error instanceof AxiosError && error.response?.status === 422) {
    return (error.response.data as { errors?: ValidationErrors }).errors ?? {}
  }
  return null
}
