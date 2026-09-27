import { Node, mergeAttributes, VueNodeViewRenderer } from '@tiptap/vue-3'
import type { PostImage } from '@/lib/api'
import PostImageView from './PostImageView.vue'

export type PostImageOptions = {
  resolveImage: (id: number) => PostImage | undefined
  // 올린 순서 번호(디자인의 "사진 N" 표시)
  photoNumber?: (id: number) => number | undefined
}

declare module '@tiptap/vue-3' {
  interface Commands<ReturnType> {
    postImage: {
      insertPostImage: (imageId: number) => ReturnType
    }
  }
}

/** 글에 올린 사진을 id로 가리키는 블록. 파일 URL은 저장하지 않고 화면에서만 찾는다. */
export const PostImageNode = Node.create<PostImageOptions>({
  name: 'postImage',
  group: 'block',
  atom: true,
  draggable: true,

  addOptions() {
    return { resolveImage: () => undefined, photoNumber: () => undefined }
  },

  addAttributes() {
    return {
      imageId: {
        default: null,
        parseHTML: (element) => Number(element.getAttribute('data-image-id')) || null,
        renderHTML: (attributes) => ({ 'data-image-id': attributes.imageId }),
      },
    }
  },

  parseHTML() {
    return [{ tag: 'figure[data-post-image]' }]
  },

  renderHTML({ HTMLAttributes }) {
    return ['figure', mergeAttributes(HTMLAttributes, { 'data-post-image': '' })]
  },

  addCommands() {
    return {
      insertPostImage:
        (imageId) =>
        ({ commands }) =>
          commands.insertContent({ type: this.name, attrs: { imageId } }),
    }
  },

  addNodeView() {
    return VueNodeViewRenderer(PostImageView)
  },
})
