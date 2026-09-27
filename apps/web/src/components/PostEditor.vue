<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { EditorContent, useEditor, type EditorEvents } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import { AxiosError } from 'axios'
import { postApi, type DraftWarning, type Post, type PostImage, type RewriteInstruction } from '@/lib/api'
import { PostImageNode } from '@/editor/postImage'
import { blockNodeIndexes, blocksToDoc, docToBlocks } from '@/editor/convert'

const props = defineProps<{ post: Post; images: PostImage[] }>()
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
    PostImageNode.configure({ resolveImage: (id) => imagesById.value.get(id) }),
  ],
  editorProps: { attributes: { class: 'post-editor min-h-[24rem] outline-none', 'aria-label': '본문' } },
  onCreate: collectPlaced,
  onUpdate: (props) => {
    collectPlaced(props)
    scheduleSave()
  },
})

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
  if (saveState.value === 'dirty') void save()
  clearTimeout(timer)
})

defineExpose({ save, insertImage, rewrite, reveal, editor })
</script>

<template>
  <div class="space-y-3">
    <div
      class="sticky top-[env(safe-area-inset-top,0px)] z-10 flex flex-wrap items-center gap-1 rounded-lg border border-stone-200 bg-white p-1.5 text-sm"
      role="toolbar"
      aria-label="편집 도구"
    >
      <button
        type="button"
        :aria-pressed="editor?.isActive('heading', { level: 2 })"
        :class="editor?.isActive('heading', { level: 2 }) ? 'bg-stone-900 text-white' : 'hover:bg-stone-100'"
        class="rounded px-2 py-1"
        @click="editor?.chain().focus().toggleHeading({ level: 2 }).run()"
      >
        소제목
      </button>
      <button
        type="button"
        :aria-pressed="editor?.isActive('bulletList')"
        :class="editor?.isActive('bulletList') ? 'bg-stone-900 text-white' : 'hover:bg-stone-100'"
        class="rounded px-2 py-1"
        @click="editor?.chain().focus().toggleBulletList().run()"
      >
        목록
      </button>
      <button
        type="button"
        :aria-pressed="editor?.isActive('blockquote')"
        :class="editor?.isActive('blockquote') ? 'bg-stone-900 text-white' : 'hover:bg-stone-100'"
        class="rounded px-2 py-1"
        @click="editor?.chain().focus().toggleBlockquote().run()"
      >
        인용
      </button>
      <span class="mx-1 h-5 w-px bg-stone-200" aria-hidden="true" />
      <button
        type="button"
        :disabled="!editor?.can().undo()"
        class="rounded px-2 py-1 hover:bg-stone-100 disabled:opacity-30"
        @click="editor?.chain().focus().undo().run()"
      >
        되돌리기
      </button>
      <button
        type="button"
        :disabled="!editor?.can().redo()"
        class="rounded px-2 py-1 hover:bg-stone-100 disabled:opacity-30"
        @click="editor?.chain().focus().redo().run()"
      >
        다시 실행
      </button>
      <span class="mx-1 h-5 w-px bg-stone-200" aria-hidden="true" />
      <span class="px-1 text-stone-500">AI 문단</span>
      <button
        v-for="option in REWRITES"
        :key="option.instruction"
        type="button"
        :disabled="rewriting"
        class="rounded px-2 py-1 hover:bg-stone-100 disabled:opacity-40"
        @click="rewrite(option.instruction)"
      >
        {{ option.label }}
      </button>
      <span class="ml-auto px-2 text-stone-500" role="status" aria-live="polite">
        {{
          rewriting
            ? 'AI가 문단을 쓰는 중…'
            : { saved: '저장됨', dirty: '고치는 중…', saving: '저장 중…', error: '저장 실패' }[saveState]
        }}
      </span>
    </div>

    <p
      v-if="saveState === 'error'"
      role="alert"
      class="flex flex-wrap items-center gap-2 text-sm text-red-600"
    >
      자동 저장에 실패했습니다.
      <button type="button" class="underline" @click="save">다시 저장</button>
    </p>
    <p
      v-if="rewriteMessage"
      :role="rewriteMessage.ok ? 'status' : 'alert'"
      :class="rewriteMessage.ok ? 'text-stone-600' : 'text-red-600'"
      class="text-sm"
    >
      {{ rewriteMessage.text }}
    </p>
    <ul v-if="rewriteWarnings.length" class="rounded-md bg-amber-50 px-4 py-2 text-sm text-amber-900">
      <li v-for="warning in rewriteWarnings" :key="warning.message">
        <strong>확인 필요 ·</strong> {{ warning.message }}
      </li>
    </ul>

    <div class="mx-auto max-w-[693px] rounded-xl border border-stone-200 bg-white px-6 py-6 sm:px-10">
      <EditorContent :editor="editor" />
    </div>

    <div v-if="unplaced.length" class="space-y-2">
      <p class="text-sm text-stone-600">
        글에 없는 사진 — 넣을 위치에 커서를 두고 사진을 누르세요.
      </p>
      <div class="flex flex-wrap gap-2">
        <button
          v-for="image in unplaced"
          :key="image.id"
          type="button"
          class="relative overflow-hidden rounded border border-stone-200 hover:ring-2 hover:ring-stone-900"
          :aria-label="`${photoNumber.get(image.id)}번 사진 넣기`"
          @click="insertImage(image.id)"
        >
          <img :src="image.thumb_url" alt="" class="size-16 object-cover" />
          <span class="absolute top-0.5 left-0.5 rounded bg-black/60 px-1 text-[10px] text-white">
            {{ photoNumber.get(image.id) }}
          </span>
        </button>
      </div>
    </div>

    <label class="block space-y-1">
      <span class="text-sm text-stone-600">태그 (쉼표로 구분)</span>
      <input
        v-model="tagsInput"
        class="w-full rounded-md border border-stone-300 px-3 py-2 text-sm"
        @input="scheduleSave"
      />
    </label>
  </div>
</template>

<style>
.post-editor {
  font-size: 16px;
  line-height: 1.8;
  color: #292524;
}
.post-editor > * + * {
  margin-top: 0.9em;
}
.post-editor h2 {
  font-size: 20px;
  font-weight: 700;
  padding-top: 0.75em;
}
.post-editor ul {
  list-style: disc;
  padding-left: 1.5em;
}
.post-editor blockquote {
  border-left: 4px solid #d6d3d1;
  padding-left: 1em;
  color: #57534e;
}
.post-editor .ProseMirror-selectednode img {
  outline: 2px solid #1c1917;
}
</style>
