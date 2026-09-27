import type { ExportResult } from '@/lib/api'

const MAX_WIDTH = 1280
const JPEG_QUALITY = 0.85

/** 로그인해야 받을 수 있는 사진을 받아 붙여넣기용 data URI로 만든다(네이버 권장 폭에 맞게 줄임). */
export async function photoDataUrl(url: string): Promise<string> {
  const response = await fetch(url, { credentials: 'same-origin' })
  if (!response.ok) throw new Error(`photo ${response.status}`)
  const bitmap = await createImageBitmap(await response.blob())
  const scale = Math.min(1, MAX_WIDTH / bitmap.width)
  const canvas = document.createElement('canvas')
  canvas.width = Math.round(bitmap.width * scale)
  canvas.height = Math.round(bitmap.height * scale)
  canvas.getContext('2d')!.drawImage(bitmap, 0, 0, canvas.width, canvas.height)
  bitmap.close()
  return canvas.toDataURL('image/jpeg', JPEG_QUALITY)
}

/**
 * 내보내기 HTML의 [사진 N] 자리(data-photo="N")를 실제 사진으로 바꾼다.
 * 이렇게 하면 본문을 한 번 붙여넣을 때 사진도 제자리에 함께 들어간다.
 * 받지 못한 사진은 [사진 N] 표시를 그대로 둔다.
 */
export async function buildPasteHtml(
  result: ExportResult,
  load: (url: string) => Promise<string> = photoDataUrl,
): Promise<{ html: string; embedded: number }> {
  const sources = new Map<number, string>()
  await Promise.all(
    result.photos.map(async (photo) => {
      try {
        sources.set(photo.number, await load(photo.url))
      } catch {
        // 표시를 남겨 두면 사용자가 zip으로 넣을 수 있다
      }
    }),
  )
  const html = result.html.replace(
    /<p data-photo="(\d+)">[\s\S]*?<\/p>/g,
    (block, number: string) => {
      const src = sources.get(Number(number))
      return src ? `<p><img src="${src}" alt="사진 ${number}" style="max-width:100%"></p>` : block
    },
  )
  return { html, embedded: sources.size }
}
