import { describe, expect, it } from 'vitest'
import { blocksText, blocksToDoc, docToBlocks } from '@/editor/convert'
import type { ContentBlock } from '@/lib/api'

const BLOCKS: ContentBlock[] = [
  { type: 'paragraph', text: '인계동 파스타집에 다녀왔어요.\n주차는 몰라요.' },
  { type: 'heading', text: '메뉴와 가격' },
  { type: 'image', image_id: 7 },
  { type: 'list', items: ['봉골레', '크림'] },
  { type: 'quote', text: '재방문 의사 있어요' },
]

describe('blocks ↔ TipTap 문서', () => {
  it('왕복 변환해도 같은 블록이 된다', () => {
    expect(docToBlocks(blocksToDoc(BLOCKS))).toEqual(BLOCKS)
  })

  it('줄바꿈은 hardBreak로, 소제목은 h2로 바뀐다', () => {
    const doc = blocksToDoc(BLOCKS)

    expect(doc.content![0]!.content!.map((n) => n.type)).toEqual(['text', 'hardBreak', 'text'])
    expect(doc.content![1]).toMatchObject({ type: 'heading', attrs: { level: 2 } })
    expect(doc.content![2]).toEqual({ type: 'postImage', attrs: { imageId: 7 } })
  })

  it('빈 문단·빈 목록 항목은 버린다', () => {
    const blocks = docToBlocks({
      type: 'doc',
      content: [
        { type: 'paragraph' },
        { type: 'paragraph', content: [{ type: 'text', text: '  ' }] },
        { type: 'bulletList', content: [{ type: 'listItem', content: [{ type: 'paragraph' }] }] },
        { type: 'postImage', attrs: { imageId: null } },
      ],
    })

    expect(blocks).toEqual([])
    expect(blocksToDoc([])).toEqual({ type: 'doc', content: [{ type: 'paragraph' }] })
  })

  it('텍스트 표현은 서버 규칙과 같다(목록은 "- ", 사진 제외)', () => {
    expect(blocksText(BLOCKS)).toBe(
      '인계동 파스타집에 다녀왔어요.\n주차는 몰라요.\n메뉴와 가격\n- 봉골레\n- 크림\n재방문 의사 있어요',
    )
  })
})
