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

/** 한 조각씩 붙여넣기: 글 묶음(서식 HTML) 또는 사진 한 장 */
export type PastePiece =
  | { kind: 'text'; html: string; text: string; preview: string }
  | { kind: 'photo'; number: number; url: string }

/**
 * 내보내기 HTML을 붙여넣을 순서대로 나눈다. 사진 사이의 글은 한 묶음, 사진은 한 장씩.
 * 네이버 편집기는 붙여넣은 글 속 사진은 버리지만, 사진 한 장(이미지)을 붙여넣으면 직접 올린 것처럼 받는다.
 */
export function splitPieces(result: ExportResult): PastePiece[] {
  const urls = new Map(result.photos.map((p) => [p.number, p.url]))
  const doc = new DOMParser().parseFromString(`<body>${result.html}</body>`, 'text/html')
  const pieces: PastePiece[] = []
  let buffer: Element[] = []
  const flush = () => {
    if (!buffer.length) return
    const text = buffer.map((el) => (el as HTMLElement).innerText ?? el.textContent ?? '').join('\n\n').trim()
    pieces.push({
      kind: 'text',
      html: buffer.map((el) => el.outerHTML).join('\n'),
      text: text || buffer.map((el) => el.textContent ?? '').join('\n\n'),
      preview: (buffer.map((el) => el.textContent ?? '').join(' ').replace(/\s+/g, ' ').trim()).slice(0, 40),
    })
    buffer = []
  }
  for (const el of Array.from(doc.body.children)) {
    const number = Number(el.getAttribute('data-photo'))
    const url = urls.get(number)
    if (number && url) {
      flush()
      pieces.push({ kind: 'photo', number, url })
    } else {
      buffer.push(el)
    }
  }
  flush()
  return pieces
}

/** 사진을 클립보드에 넣을 수 있는 PNG로(브라우저 클립보드는 이미지로 PNG만 받는다). 네이버 권장 폭으로 줄인다 */
export async function photoPngBlob(url: string): Promise<Blob> {
  const response = await fetch(url, { credentials: 'same-origin' })
  if (!response.ok) throw new Error(`photo ${response.status}`)
  const bitmap = await createImageBitmap(await response.blob())
  const scale = Math.min(1, MAX_WIDTH / bitmap.width)
  const canvas = document.createElement('canvas')
  canvas.width = Math.round(bitmap.width * scale)
  canvas.height = Math.round(bitmap.height * scale)
  canvas.getContext('2d')!.drawImage(bitmap, 0, 0, canvas.width, canvas.height)
  bitmap.close()
  return new Promise((resolve, reject) => canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('png'))), 'image/png'))
}
