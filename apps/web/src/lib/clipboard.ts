/** 서식(HTML)과 평문을 함께 클립보드에 넣는다. 붙여넣는 곳이 HTML을 받으면 서식이 유지된다. */
export async function copyRich(html: string, text: string) {
  if (typeof ClipboardItem !== 'undefined' && navigator.clipboard?.write) {
    await navigator.clipboard.write([
      new ClipboardItem({
        'text/html': new Blob([html], { type: 'text/html' }),
        'text/plain': new Blob([text], { type: 'text/plain' }),
      }),
    ])
    return
  }
  await navigator.clipboard.writeText(text)
}

export async function copyText(text: string) {
  await navigator.clipboard.writeText(text)
}
