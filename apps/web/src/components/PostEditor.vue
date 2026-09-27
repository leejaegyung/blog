<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { EditorContent, useEditor, type EditorEvents } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import { AxiosError } from 'axios'
import { postApi, type DraftWarning, type Post, type PostImage, type RewriteInstruction } from '@/lib/api'
import { PostImageNode } from '@/editor/postImage'
import { IssueHighlight, issueHighlightKey } from '@/editor/issueHighlight'
import { useUiStore } from '@/stores/ui'
import { blockNodeIndexes, blocksToDoc, docToBlocks } from '@/editor/convert'

const props = defineProps<{ post: Post; images: PostImage[]; highlights?: string[] }>()
const emit = defineEmits<{ saved: [post: Post] }>()

const SAVE_DELAY_MS = 1500
const REWRITES: { instruction: RewriteInstruction; label: string }[] = [
  { instruction: 'shorter', label: '짧게' },
  { instruction: 'longer', label: '길게' },
  { instruction: 'natural', label: '자연스럽게' },
  { instruction: 'rewrite', label: '다시 쓰기' },
]

const imagesById = computed(() => new Map(props.images.map((image) => [image.id, image])))
const photoNumber = computed(() => new Map(props.images.map((image, i) => [image.id, i + 1])))

const saveState = ref<'saved' | 'dirty' | 'saving' | 'error'>('saved')
const tagsInput = ref((props.post.content?.tags ?? []).join(', '))
const placedIds = ref<number[]>([])
const rewriting = ref(false)
const rewriteMessage = ref<{ ok: boolean; text: string } | null>(null)
const rewriteWarnings = ref<DraftWarning[]>([])
let timer: ReturnType<typeof setTimeout> | undefined

// 콜백이 넘겨주는 editor를 쓴다: onCreate는 useEditor가 ref를 채우기 전에 불릴 수 있다
function collectPlaced({ editor: current }: EditorEvents['create']) {
  const ids: number[] = []
  current.state.doc.descendants((node) => {
    if (node.type.name === 'postImage' && node.attrs.imageId) ids.push(Number(node.attrs.imageId))
  })
  placedIds.value = ids
}

const editor = useEditor({
  content: blocksToDoc(props.post.content?.blocks ?? []),
  extensions: [
    // 저장 형식이 평문 블록이라 글자 서식·표·코드는 끈다
    StarterKit.configure({
      heading: { levels: [2] },
      bold: false,
      italic: false,
      strike: false,
      underline: false,
      code: false,
      codeBlock: false,
      link: false,
      orderedList: false,
      horizontalRule: false,
    }),
    PostImageNode.configure({
      resolveImage: (id) => imagesById.value.get(id),
      photoNumber: (id) => photoNumber.value.get(id),
    }),
    IssueHighlight,
  ],
  editorProps: { attributes: { class: 'post-editor min-h-[24rem] outline-none', 'aria-label': '본문' } },
  onCreate: collectPlaced,
  onUpdate: (props) => {
    collectPlaced(props)
    scheduleSave()
  },
})

// 헤더의 "자동 저장됨" 표시와 같이 쓴다
const ui = useUiStore()
watch(saveState, (state) => (ui.saveState = state), { immediate: true })

function applyHighlights(excerpts: string[]) {
  const ed = editor.value
  if (!ed) return
  ;(ed.storage as unknown as { issueHighlight: { excerpts: string[] } }).issueHighlight.excerpts = excerpts
  ed.view.dispatch(ed.state.tr.setMeta(issueHighlightKey, true))
}
watch(() => [props.highlights, editor.value] as const, ([excerpts]) => applyHighlights(excerpts ?? []), { deep: true })

/** 본문 조각이 든 문장만 지운다(검사의 "문장 지우기"). 문장이 하나뿐인 문단은 문단째 지운다. */
function removeSentence(excerpt: string): boolean {
  const ed = editor.value
  if (!ed) return false
  let done = false
  ed.state.doc.forEach((node, offset) => {
    if (done || !node.isTextblock || !node.textContent.includes(excerpt)) return
    const sentences = node.textContent.match(/[^.!?。]+[.!?。]*\s*/g) ?? [node.textContent]
    const kept = sentences.filter((sentence) => !sentence.includes(excerpt)).join('').trim()
    const from = offset + 1
    const to = offset + node.nodeSize - 1
    if (kept) ed.chain().focus().insertContentAt({ from, to }, kept).run()
    else ed.chain().focus().deleteRange({ from: offset, to: offset + node.nodeSize }).run()
    done = true
  })
  return done
}

const unplaced = computed(() => props.images.filter((image) => !placedIds.value.includes(image.id)))

function scheduleSave() {
  saveState.value = 'dirty'
  clearTimeout(timer)
  timer = setTimeout(save, SAVE_DELAY_MS)
}

function tags() {
  return [...new Set(tagsInput.value.split(/[,\s]+/).map((t) => t.replace(/^#/, '').trim()).filter(Boolean))]
}

async function save() {
  clearTimeout(timer)
  if (!editor.value) return
  saveState.value = 'saving'
  try {
    const post = await postApi.update(props.post.id, {
      content: { blocks: docToBlocks(editor.value.getJSON()), tags: tags() },
    })
    // 저장하는 동안 또 고쳤으면 dirty 상태를 유지한다
    if (saveState.value === 'saving') saveState.value = 'saved'
    emit('saved', post)
  } catch {
    saveState.value = 'error'
  }
}

function insertImage(id: number) {
  editor.value?.chain().focus().insertPostImage(id).run()
}

function topLevelText(index: number) {
  const doc = editor.value!.state.doc
  if (index < 0 || index >= doc.childCount) return undefined
  const node = doc.child(index)
  return node.type.name === 'paragraph' ? node.textContent : undefined
}

async function rewrite(instruction: RewriteInstruction) {
  const ed = editor.value
  if (!ed) return
  rewriteMessage.value = null
  rewriteWarnings.value = []
  const { $from } = ed.state.selection
  if ($from.depth !== 1 || $from.parent.type.name !== 'paragraph' || !$from.parent.textContent.trim()) {
    rewriteMessage.value = { ok: false, text: '다시 쓸 본문 문단 안에 커서를 두세요.' }
    return
  }

  const index = $from.index(0)
  const from = $from.start()
  const to = $from.end()
  const text = $from.parent.textContent
  rewriting.value = true
  try {
    const result = await postApi.rewrite(props.post.id, {
      text,
      instruction,
      before: topLevelText(index - 1),
      after: topLevelText(index + 1),
    })
    // 기다리는 동안 그 문단을 고쳤으면 덮어쓰지 않는다
    if (ed.state.doc.textBetween(from, to) !== text) {
      rewriteMessage.value = { ok: false, text: '기다리는 동안 문단이 바뀌어 적용하지 않았습니다.' }
      return
    }
    ed.chain().focus().insertContentAt({ from, to }, result.text).run()
    rewriteWarnings.value = result.warnings
    rewriteMessage.value = { ok: true, text: '문단을 바꿨습니다. 마음에 들지 않으면 되돌리기를 누르세요.' }
  } catch (error) {
    const message = error instanceof AxiosError ? (error.response?.data as { message?: string })?.message : null
    rewriteMessage.value = {
      ok: false,
      text: message?.includes('billing')
        ? 'AI 공급자 계정의 크레딧(잔액)이 부족합니다.'
        : (message ?? '문단을 다시 쓰지 못했습니다.'),
    }
  } finally {
    rewriting.value = false
  }
}

/** 품질 검사 결과의 위치로 이동한다: 본문 조각이 든 블록을 우선 찾고, 없으면 블록 번호로 찾는다. */
function reveal(excerpt: string | null, blockIndex: number | null) {
  const ed = editor.value
  if (!ed) return false
  const doc = ed.state.doc
  let target = -1
  if (excerpt) {
    doc.forEach((node, _offset, index) => {
      if (target < 0 && node.textContent.includes(excerpt)) target = index
    })
  }
  if (target < 0 && blockIndex !== null) target = blockNodeIndexes(ed.getJSON())[blockIndex] ?? -1
  if (target < 0) return false

  let pos = 0
  for (let i = 0; i < target; i++) pos += doc.child(i).nodeSize
  const node = doc.child(target)
  const chain = ed.chain().focus()
  if (node.isAtom) chain.setNodeSelection(pos)
  else chain.setTextSelection({ from: pos + 1, to: pos + node.nodeSize - 1 })
  chain.scrollIntoView().run()
  return true
}

onBeforeUnmount(() => {
  ui.saveState = null
  if (saveState.value === 'dirty') void save()
  clearTimeout(timer)
})

const root = ref<HTMLElement | null>(null)
const active = ref(false)
const TOOLS = [
  { label: '소제목', active: () => !!editor.value?.isActive('heading', { level: 2 }), run: () => editor.value?.chain().focus().toggleHeading({ level: 2 }).run() },
  { label: '목록', active: () => !!editor.value?.isActive('bulletList'), run: () => editor.value?.chain().focus().toggleBulletList().run() },
  { label: '인용', active: () => !!editor.value?.isActive('blockquote'), run: () => editor.value?.chain().focus().toggleBlockquote().run() },
]

function onFocusOut(event: FocusEvent) {
  if (!root.value?.contains(event.relatedTarget as Node | null)) active.value = false
}

defineExpose({ save, insertImage, rewrite, reveal, removeSentence, editor })
</script>

<template>
  <div ref="root" class="flex flex-col gap-3" @focusin="active = true" @focusout="onFocusOut">
    <div class="flex flex-col gap-3.5 rounded-[18px] border-[1.5px] border-line bg-white px-5 py-5 lg:px-10 lg:py-7">
      <!-- 디자인(1a)의 본문 카드에는 도구 줄이 없다: 글을 누르고 있을 때만 보인다 -->
      <div
        v-show="active || rewriting"
        class="sticky top-[env(safe-area-inset-top,0px)] z-10 -mx-2 flex flex-wrap items-center gap-1 rounded-xl bg-lilac-soft p-1 text-[13px]"
        role="toolbar"
        aria-label="편집 도구"
      >
        <button
          v-for="tool in TOOLS"
          :key="tool.label"
          type="button"
          :aria-pressed="tool.active()"
          :class="tool.active() ? 'bg-ink font-bold text-cream' : 'font-semibold'"
          class="rounded-[9px] px-2.5 py-1.5"
          @click="tool.run()"
        >
          {{ tool.label }}
        </button>
        <button type="button" :disabled="!editor?.can().undo()" class="rounded-[9px] px-2.5 py-1.5 font-semibold disabled:opacity-30" @click="editor?.chain().focus().undo().run()">되돌리기</button>
        <button type="button" :disabled="!editor?.can().redo()" class="rounded-[9px] px-2.5 py-1.5 font-semibold disabled:opacity-30" @click="editor?.chain().focus().redo().run()">다시 실행</button>
        <span class="mx-1 h-4 w-px bg-line" aria-hidden="true" />
        <span class="px-1 font-bold text-accent">AI 문단</span>
        <button
          v-for="option in REWRITES"
          :key="option.instruction"
          type="button"
          :disabled="rewriting"
          class="rounded-[9px] px-2.5 py-1.5 font-semibold disabled:opacity-40"
          @click="rewrite(option.instruction)"
        >
          {{ option.label }}
        </button>
        <span class="ml-auto px-2 text-sub" role="status" aria-live="polite">
          {{ rewriting ? 'AI가 문단을 쓰는 중…' : { saved: '저장됨', dirty: '고치는 중…', saving: '저장 중…', error: '저장 실패' }[saveState] }}
        </span>
      </div>
      <slot name="top" />
      <EditorContent :editor="editor" />
    </div>

    <p v-if="saveState === 'error'" role="alert" class="flex flex-wrap items-center gap-2 text-sm text-red-600">
      자동 저장에 실패했습니다.
      <button type="button" class="font-bold underline" @click="save">다시 저장</button>
    </p>
    <p v-if="rewriteMessage" :role="rewriteMessage.ok ? 'status' : 'alert'" :class="rewriteMessage.ok ? 'text-sub' : 'text-red-600'" class="text-sm">
      {{ rewriteMessage.text }}
    </p>
    <ul v-if="rewriteWarnings.length" class="rounded-[14px] border-2 border-ink bg-lemon px-3.5 py-3 text-[13px]">
      <li v-for="warning in rewriteWarnings" :key="warning.message"><strong>확인 필요 ·</strong> {{ warning.message }}</li>
    </ul>

    <div v-if="unplaced.length" class="flex flex-col gap-2">
      <span class="text-[13px] font-bold">아직 안 쓰인 사진 <span class="font-medium text-sub">· 넣을 곳에 커서를 두고 누르세요</span></span>
      <div class="flex flex-wrap gap-2">
        <button
          v-for="image in unplaced"
          :key="image.id"
          type="button"
          class="relative size-14 overflow-hidden rounded-[10px] bg-lilac-soft hover:ring-2 hover:ring-ink"
          :aria-label="`${photoNumber.get(image.id)}번 사진 넣기`"
          @click="insertImage(image.id)"
        >
          <img :src="image.thumb_url" alt="" class="size-full object-cover" />
          <span class="absolute top-1 left-1 rounded-[5px] bg-ink px-[5px] text-[10px] font-bold text-cream">{{ photoNumber.get(image.id) }}</span>
        </button>
      </div>
    </div>

    <label class="flex flex-col gap-1.5">
      <span class="text-[13px] font-bold">태그 <span class="font-medium text-sub">(쉼표로 구분)</span></span>
      <input v-model="tagsInput" class="rounded-xl border-[1.5px] border-line bg-white px-3.5 py-2.5 text-sm outline-none focus:border-ink" @input="scheduleSave" />
    </label>
  </div>
</template>

<style>
.post-editor {
  font-size: 16px;
  line-height: 1.8;
  color: #22002e;
}
.post-editor > * + * {
  margin-top: 0.9em;
}
.post-editor h2 {
  font-size: 17px;
  font-weight: 700;
  border-left: 4px solid #d896f4;
  padding-left: 12px;
  margin-top: 1.2em;
}
.post-editor .issue-mark {
  background: #faff5a;
  border-bottom: 2px solid #22002e;
  font-weight: 600;
}
.post-editor ul {
  list-style: disc;
  padding-left: 1.5em;
}
.post-editor blockquote {
  border-left: 4px solid #e2cfea;
  padding-left: 1em;
  color: #5c4468;
}
.post-editor .ProseMirror-selectednode img {
  outline: 2px solid #22002e;
}
.post-editor:focus {
  outline: none;
}
</style>
