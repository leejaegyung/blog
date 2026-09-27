import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PostEditor from '@/components/PostEditor.vue'
import { postApi, type Post, type PostImage, type PostInput, type RewriteInstruction } from '@/lib/api'

vi.mock('@/lib/api', () => ({
  postApi: {
    update: vi.fn<(id: number, input: PostInput) => Promise<Post>>(),
    rewrite: vi.fn<
      (
        id: number,
        input: { text: string; instruction: RewriteInstruction; before?: string; after?: string },
      ) => Promise<{ text: string; warnings: { code: string; message: string }[] }>
    >(),
  },
}))

const image = (id: number): PostImage => ({
  id,
  url: `/img/${id}`,
  thumb_url: `/t/${id}`,
  original_name: null,
  width: 1,
  height: 1,
  size_bytes: 1,
  taken_at: null,
  sort_order: id,
  caption: null,
})

const POST = {
  id: 9,
  content: {
    blocks: [
      { type: 'paragraph', text: '첫 문단입니다.' },
      { type: 'image', image_id: 1 },
      { type: 'paragraph', text: '런치 세트 19,000원' },
    ],
    tags: ['인계동파스타'],
  },
} as unknown as Post

type Exposed = { editor?: import('@tiptap/vue-3').Editor }

// TipTap은 create 이벤트를 setTimeout(0) 뒤에 보낸다. 고정 대기 대신 준비될 때까지 기다린다(부하에 따라 늦을 수 있음)
async function ready(wrapper: { vm: unknown }, fakeTimers = false) {
  for (let i = 0; i < 100; i++) {
    await flushPromises()
    if ((wrapper.vm as Exposed).editor?.isInitialized) return
    if (fakeTimers) await vi.advanceTimersByTimeAsync(10)
    else await new Promise((resolve) => setTimeout(resolve, 10))
  }
  throw new Error('editor not initialized')
}

function mountEditor(extraProps: Record<string, unknown> = {}) {
  return mount(PostEditor, { props: { post: POST, images: [image(1), image(2)], ...extraProps }, attachTo: document.body })
}

describe('PostEditor', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(postApi.update).mockReset().mockResolvedValue(POST)
    vi.mocked(postApi.rewrite).mockReset()
  })
  afterEach(() => {
    vi.useRealTimers()
    document.body.innerHTML = ''
  })

  it('초안 블록을 불러오고 글에 없는 사진만 따로 보여준다', async () => {
    const wrapper = mountEditor()
    await ready(wrapper)

    expect(wrapper.text()).toContain('첫 문단입니다.')
    expect(wrapper.find('[aria-label="1번 사진 넣기"]').exists()).toBe(false)
    expect(wrapper.find('[aria-label="2번 사진 넣기"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('고치면 잠시 뒤 블록으로 자동 저장한다', async () => {
    vi.useFakeTimers()
    const wrapper = mountEditor()
    await ready(wrapper, true)
    const editor = (wrapper.vm as unknown as { editor: import('@tiptap/vue-3').Editor }).editor

    editor.commands.setTextSelection(1)
    editor.commands.insertContent('수정: ')
    await wrapper.get('input').setValue('인계동파스타, #수원맛집 수원맛집')
    expect(postApi.update).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(1500)
    await flushPromises()

    expect(postApi.update).toHaveBeenCalledTimes(1)
    const [, input] = vi.mocked(postApi.update).mock.calls[0]!
    expect(input.content!.blocks[0]).toEqual({ type: 'paragraph', text: '수정: 첫 문단입니다.' })
    expect(input.content!.blocks[1]).toEqual({ type: 'image', image_id: 1 })
    expect(input.content!.tags).toEqual(['인계동파스타', '수원맛집'])
    wrapper.unmount()
  })

  it('사진을 커서 위치에 넣는다', async () => {
    const wrapper = mountEditor()
    await ready(wrapper)

    await wrapper.get('[aria-label="2번 사진 넣기"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[aria-label="2번 사진 넣기"]').exists()).toBe(false)
    expect(wrapper.findAll('figure img').map((img) => img.attributes('src'))).toContain('/img/2')
    wrapper.unmount()
  })

  it('커서가 있는 문단을 AI 결과로 바꾸고 경고를 보여준다', async () => {
    vi.mocked(postApi.rewrite).mockResolvedValue({
      text: '런치 세트는 19,000원이었고 디너는 25,000원이에요.',
      warnings: [{ code: 'unsupported_specific', message: '입력하지 않은 가격 정보: 25,000원' }],
    })
    const wrapper = mountEditor()
    await ready(wrapper)
    const editor = (wrapper.vm as unknown as { editor: import('@tiptap/vue-3').Editor }).editor
    const end = editor.state.doc.content.size - 2
    editor.commands.setTextSelection(end)

    await wrapper.findAll('button').find((b) => b.text() === '길게')!.trigger('click')
    await flushPromises()

    expect(postApi.rewrite).toHaveBeenCalledWith(9, {
      text: '런치 세트 19,000원',
      instruction: 'longer',
      before: undefined,
      after: undefined,
    })
    expect(editor.getText()).toContain('디너는 25,000원이에요.')
    expect(wrapper.text()).toContain('확인 필요 · 입력하지 않은 가격 정보: 25,000원')
    editor.commands.undo()
    expect(editor.getText()).toContain('런치 세트 19,000원')
    wrapper.unmount()
  })

  it('품질 검사 위치로 이동한다(본문 조각 우선, 없으면 블록 번호)', async () => {
    const wrapper = mountEditor()
    await ready(wrapper)
    const vm = wrapper.vm as unknown as {
      editor: import('@tiptap/vue-3').Editor
      reveal: (excerpt: string | null, index: number | null) => boolean
    }

    expect(vm.reveal('19,000원', null)).toBe(true)
    expect(vm.editor.state.selection.$from.parent.textContent).toBe('런치 세트 19,000원')

    expect(vm.reveal(null, 1)).toBe(true)
    expect(vm.editor.state.selection.constructor.name).toBe('NodeSelection')

    expect(vm.reveal('없는 문장', null)).toBe(false)
    wrapper.unmount()
  })

  it('검사가 짚은 문장을 노랗게 표시하고, 그 문장만 지운다', async () => {
    const wrapper = mountEditor({ highlights: ['19,000원'] })
    await ready(wrapper)
    const vm = wrapper.vm as unknown as {
      editor: import('@tiptap/vue-3').Editor
      removeSentence: (excerpt: string) => boolean
    }
    vm.editor.commands.setContent({
      type: 'doc',
      content: [{ type: 'paragraph', content: [{ type: 'text', text: '가격은 19,000원이에요. 맛있었어요.' }] }],
    })
    await flushPromises()

    expect(wrapper.find('.issue-mark').text()).toBe('19,000원')
    expect(vm.removeSentence('19,000원')).toBe(true)
    expect(vm.editor.getText()).toBe('맛있었어요.')
    expect(vm.removeSentence('없는 말')).toBe(false)
    wrapper.unmount()
  })

  it('소제목에 커서가 있으면 다시 쓰지 않는다', async () => {
    const wrapper = mount(PostEditor, {
      props: { post: { ...POST, content: { blocks: [{ type: 'heading', text: '메뉴' }], tags: [] } }, images: [] },
      attachTo: document.body,
    })
    await ready(wrapper)

    await wrapper.findAll('button').find((b) => b.text() === '짧게')!.trigger('click')

    expect(postApi.rewrite).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('다시 쓸 본문 문단 안에 커서를 두세요.')
    wrapper.unmount()
  })
})
