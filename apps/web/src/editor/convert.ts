import type { JSONContent } from '@tiptap/vue-3'
import type { ContentBlock } from '@/lib/api'

// content_json 블록 ↔ TipTap 문서. 서식(굵게 등)은 쓰지 않는다: 저장 형식이 평문 블록이다.

function textNodes(text: string): JSONContent[] {
  const nodes: JSONContent[] = []
  text.split('\n').forEach((line, index) => {
    if (index > 0) nodes.push({ type: 'hardBreak' })
    if (line) nodes.push({ type: 'text', text: line })
  })
  return nodes
}

function paragraph(text: string): JSONContent {
  const content = textNodes(text)
  return content.length ? { type: 'paragraph', content } : { type: 'paragraph' }
}

export function blocksToDoc(blocks: ContentBlock[]): JSONContent {
  const content = blocks.map((block): JSONContent => {
    switch (block.type) {
      case 'heading':
        return { type: 'heading', attrs: { level: 2 }, content: textNodes(block.text ?? '') }
      case 'image':
        return { type: 'postImage', attrs: { imageId: block.image_id } }
      case 'list':
        return {
          type: 'bulletList',
          content: (block.items ?? []).map((item) => ({ type: 'listItem', content: [paragraph(item)] })),
        }
      case 'quote':
        return { type: 'blockquote', content: [paragraph(block.text ?? '')] }
      default:
        return paragraph(block.text ?? '')
    }
  })
  return { type: 'doc', content: content.length ? content : [{ type: 'paragraph' }] }
}

function plain(node: JSONContent): string {
  if (node.type === 'text') return node.text ?? ''
  if (node.type === 'hardBreak') return '\n'
  const children = node.content ?? []
  // 블록 자식(문단 여러 개)은 줄바꿈으로 잇는다
  const separator = children.some((child) => child.type === 'paragraph') ? '\n' : ''
  return children.map(plain).join(separator)
}

export function docToBlocks(doc: JSONContent): ContentBlock[] {
  const blocks: ContentBlock[] = []
  for (const node of doc.content ?? []) {
    switch (node.type) {
      case 'heading': {
        const text = plain(node).trim()
        if (text) blocks.push({ type: 'heading', text })
        break
      }
      case 'postImage':
        if (node.attrs?.imageId) blocks.push({ type: 'image', image_id: Number(node.attrs.imageId) })
        break
      case 'bulletList':
      case 'orderedList': {
        const items = (node.content ?? []).map((item) => plain(item).trim()).filter(Boolean)
        if (items.length) blocks.push({ type: 'list', items })
        break
      }
      case 'blockquote': {
        const text = plain(node).trim()
        if (text) blocks.push({ type: 'quote', text })
        break
      }
      default: {
        const text = plain(node).trim()
        if (text) blocks.push({ type: 'paragraph', text })
      }
    }
  }
  return blocks
}

export function blocksText(blocks: ContentBlock[]): string {
  return blocks
    .flatMap((block) => {
      if (block.type === 'image') return []
      if (block.type === 'list') return (block.items ?? []).map((item) => `- ${item}`)
      return [block.text ?? '']
    })
    .join('\n')
}

/** content_json 블록 순서 → 문서 최상위 노드 번호. 빈 노드는 블록이 되지 않으므로 건너뛴다. */
export function blockNodeIndexes(doc: JSONContent): number[] {
  const indexes: number[] = []
  ;(doc.content ?? []).forEach((node, index) => {
    if (docToBlocks({ type: 'doc', content: [node] }).length) indexes.push(index)
  })
  return indexes
}
