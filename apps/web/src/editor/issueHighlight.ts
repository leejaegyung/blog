import { Extension } from '@tiptap/vue-3'
import { Plugin, PluginKey } from '@tiptap/pm/state'
import { Decoration, DecorationSet } from '@tiptap/pm/view'

export const issueHighlightKey = new PluginKey('issueHighlight')

/** 게시 전 검사가 짚은 문장(본문 조각)을 본문에서 노랗게 표시한다. 문서 내용은 바꾸지 않는다. */
export const IssueHighlight = Extension.create<Record<string, never>, { excerpts: string[] }>({
  name: 'issueHighlight',

  addStorage() {
    return { excerpts: [] }
  },

  addProseMirrorPlugins() {
    const storage = this.storage
    return [
      new Plugin({
        key: issueHighlightKey,
        props: {
          decorations(state) {
            const excerpts = storage.excerpts.filter((e) => e.length >= 2)
            if (!excerpts.length) return null
            const decorations: Decoration[] = []
            state.doc.descendants((node, pos) => {
              if (!node.isText || !node.text) return
              for (const excerpt of excerpts) {
                let at = node.text.indexOf(excerpt)
                while (at >= 0) {
                  decorations.push(Decoration.inline(pos + at, pos + at + excerpt.length, { class: 'issue-mark' }))
                  at = node.text.indexOf(excerpt, at + excerpt.length)
                }
              }
            })
            return DecorationSet.create(state.doc, decorations)
          },
        },
      }),
    ]
  },
})
