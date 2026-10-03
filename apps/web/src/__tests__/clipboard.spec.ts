import { afterEach, describe, expect, it, vi } from 'vitest'
import { canUseClipboardApi, ClipboardBlockedError, copyImage, copyRich, copyText } from '@/lib/clipboard'

/** http 주소(Tailscale 기기 이름)처럼 새 클립보드 API가 막힌 곳에서도 글·서식은 복사 명령으로 복사된다 */
describe('클립보드(http 주소)', () => {
  afterEach(() => vi.restoreAllMocks())

  function fakeExecCommand() {
    const written: Record<string, string> = {}
    document.execCommand = vi.fn<(command: string) => boolean>(() => {
      const event = new Event('copy') as ClipboardEvent
      Object.defineProperty(event, 'clipboardData', { value: { setData: (type: string, value: string) => (written[type] = value) } })
      document.dispatchEvent(event)
      return true
    })
    return written
  }

  it('새 API가 없으면 복사 명령으로 평문과 서식을 넣는다', async () => {
    expect(canUseClipboardApi()).toBe(false)  // 테스트 환경은 https가 아니다
    const written = fakeExecCommand()

    await copyText('제목')
    expect(written['text/plain']).toBe('제목')

    await copyRich('<p>본문</p>', '본문')
    expect(written).toMatchObject({ 'text/plain': '본문', 'text/html': '<p>본문</p>' })
    expect(document.querySelector('textarea')).toBeNull()  // 잠깐 쓴 칸은 지운다
  })

  it('사진은 복사 명령으로 넣을 수 없어 막혔다고 알린다', async () => {
    await expect(copyImage(new Blob())).rejects.toBeInstanceOf(ClipboardBlockedError)
  })
})
