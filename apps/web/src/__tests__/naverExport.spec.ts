import { describe, expect, it } from 'vitest'
import { buildPasteHtml, splitPieces } from '@/lib/naverExport'
import type { ExportResult } from '@/lib/api'

const RESULT: ExportResult = {
  html: '<p>도입</p>\n<p data-photo="1"><strong>[사진 1]</strong></p>\n<h2>메뉴</h2>\n<p data-photo="2"><strong>[사진 2]</strong></p>',
  text: '',
  tags: [],
  photos: [
    { number: 1, image_id: 10, filename: '01.jpg', url: '/p/10' },
    { number: 2, image_id: 11, filename: '02.jpg', url: '/p/11' },
  ],
  warnings: [],
}

describe('buildPasteHtml', () => {
  it('사진 자리를 실제 사진(data URI)으로 바꿔 한 번에 붙여넣게 한다', async () => {
    const { html, embedded } = await buildPasteHtml(RESULT, async (url) => `data:image/jpeg;base64,${url.slice(-2)}`)

    expect(embedded).toBe(2)
    expect(html).toBe(
      '<p>도입</p>\n<p><img src="data:image/jpeg;base64,10" alt="사진 1" style="max-width:100%"></p>\n<h2>메뉴</h2>\n<p><img src="data:image/jpeg;base64,11" alt="사진 2" style="max-width:100%"></p>',
    )
  })

  it('받지 못한 사진은 [사진 N] 표시를 남긴다', async () => {
    const { html, embedded } = await buildPasteHtml(RESULT, async (url) => {
      if (url.endsWith('11')) throw new Error('404')
      return 'data:x'
    })

    expect(embedded).toBe(1)
    expect(html).toContain('<strong>[사진 2]</strong>')
    expect(html).not.toContain('[사진 1]')
  })
})

describe('한 조각씩 나누기', () => {
  it('사진 사이 글은 한 묶음, 사진은 한 장씩, 빈 줄만 있는 조각은 만들지 않는다', () => {
    const pieces = splitPieces({
      html: ['<p>첫 글</p>', '<p><br></p>', '<p data-photo="1"><strong>[사진 1]</strong></p>', '<p><br></p>', '<p data-photo="2"><strong>[사진 2]</strong></p>', '<p><br></p>', '<p><span style="font-size:19px;"><b>소제목</b></span></p>'].join('\n'),
      text: '', tags: [], warnings: [],
      photos: [{ number: 1, image_id: 5, filename: '01.jpg', url: '/p/5' }, { number: 2, image_id: 6, filename: '02.jpg', url: '/p/6' }],
    })

    expect(pieces.map((p) => p.kind)).toEqual(['text', 'photo', 'photo', 'text'])
    expect(pieces[3]).toMatchObject({ kind: 'text', html: '<p><br></p>\n<p><span style="font-size:19px;"><b>소제목</b></span></p>' })
  })
})
