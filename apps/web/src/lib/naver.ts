/** 네이버 블로그 글쓰기(편집기) 주소. 아이디가 있으면 내 블로그 편집기를 바로 연다 */
export function naverWriteUrl(blogId: string | null | undefined): string {
  return blogId
    ? `https://blog.naver.com/${encodeURIComponent(blogId)}?Redirect=Write&`
    : 'https://blog.naver.com/GoBlogWrite.naver'
}
