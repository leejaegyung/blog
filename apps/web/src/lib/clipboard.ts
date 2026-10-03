/**
 * 클립보드 복사. 브라우저의 새 클립보드 API는 https·localhost에서만 열린다.
 * Tailscale 주소(http://기기이름:8080)처럼 그 밖의 주소에서는 예전 방식(복사 명령)으로 글·서식을 복사한다.
 * 사진(이미지)은 예전 방식으로 복사할 수 없어 https·localhost가 필요하다.
 */

export class ClipboardBlockedError extends Error {}

/** 새 클립보드 API를 쓸 수 있는 주소인지(https 또는 localhost) */
export const canUseClipboardApi = () => typeof window !== 'undefined' && !!window.isSecureContext && !!navigator.clipboard

/** 복사 명령으로 text/html·text/plain을 함께 넣는다(버튼을 누른 순간에만 동작) */
function legacyCopy(html: string | null, text: string): void {
  const onCopy = (event: ClipboardEvent) => {
    event.clipboardData?.setData('text/plain', text)
    if (html !== null) event.clipboardData?.setData('text/html', html)
    event.preventDefault()
  }
  // 선택된 내용이 있어야 복사 명령이 실행되므로 잠깐 보이지 않는 칸을 선택한다
  const holder = document.createElement('textarea')
  holder.value = text || ' '
  holder.setAttribute('readonly', '')
  holder.style.cssText = 'position:fixed;top:0;left:-9999px;opacity:0'
  document.body.appendChild(holder)
  holder.select()
  document.addEventListener('copy', onCopy)
  let ok = false
  try {
    ok = document.execCommand('copy')
  } finally {
    document.removeEventListener('copy', onCopy)
    holder.remove()
  }
  if (!ok) throw new ClipboardBlockedError('copy command failed')
}

/** 서식(HTML)과 평문을 함께 클립보드에 넣는다. 붙여넣는 곳이 HTML을 받으면 서식이 유지된다. */
export async function copyRich(html: string, text: string) {
  if (canUseClipboardApi() && typeof ClipboardItem !== 'undefined' && navigator.clipboard.write) {
    try {
      await navigator.clipboard.write([
        new ClipboardItem({
          'text/html': new Blob([html], { type: 'text/html' }),
          'text/plain': new Blob([text], { type: 'text/plain' }),
        }),
      ])
      return
    } catch {
      // 창에 초점이 없거나 막히면 예전 방식으로
    }
  }
  legacyCopy(html, text)
}

export async function copyText(text: string) {
  if (canUseClipboardApi()) {
    try {
      await navigator.clipboard.writeText(text)
      return
    } catch {
      // 예전 방식으로
    }
  }
  legacyCopy(null, text)
}

/** 사진 한 장을 이미지로 복사한다. Promise를 넘겨 누른 순간에 복사를 시작하고(브라우저가 막지 않게) 사진은 이어서 만든다 */
export async function copyImage(png: Promise<Blob> | Blob) {
  if (!canUseClipboardApi() || typeof ClipboardItem === 'undefined') throw new ClipboardBlockedError('image copy needs https or localhost')
  await navigator.clipboard.write([new ClipboardItem({ 'image/png': png })])
}
