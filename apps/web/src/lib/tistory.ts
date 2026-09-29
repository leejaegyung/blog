import type { Platform } from './api'

/** 티스토리 글쓰기(편집기) 주소. 블로그 주소가 없으면 티스토리 첫 화면(로그인 후 내 블로그로 간다) */
export function tistoryWriteUrl(host: string | null | undefined): string {
  return host ? `https://${host}/manage/newpost` : 'https://www.tistory.com/'
}

export const PLATFORM_LABEL: Record<Platform, string> = { naver: '네이버', tistory: '티스토리' }

/** 글 주소로 어느 플랫폼 글인지(티스토리 블로그는 *.tistory.com). 모르면 null */
export function platformOf(url: string | null | undefined): Platform | null {
  try {
    const host = new URL(url ?? '').hostname.toLowerCase()
    if (host.endsWith('blog.naver.com')) return 'naver'
    if (host.endsWith('.tistory.com')) return 'tistory'
  } catch {
    // 주소가 아니면 모른다
  }
  return null
}
