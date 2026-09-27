<script setup lang="ts">
import { computed, ref } from 'vue'
import { postApi, type ExportResult, type Post, type PostImage } from '@/lib/api'
import { validationErrors } from '@/lib/http'
import { copyRich, copyText } from '@/lib/clipboard'

const props = defineProps<{ post: Post; images: PostImage[] }>()
const emit = defineEmits<{ updated: [post: Post] }>()

const result = ref<ExportResult | null>(null)
const exporting = ref(false)
const error = ref<string | null>(null)
const copied = ref<string | null>(null)
const publishedUrl = ref(props.post.published_url ?? '')
const publishing = ref(false)
const publishError = ref<string | null>(null)

const imageById = computed(() => new Map(props.images.map((image) => [image.id, image])))
const blockingCount = computed(
  () => props.post.quality?.issues.filter((issue) => issue.severity === 'error').length ?? 0,
)

async function prepare() {
  exporting.value = true
  error.value = null
  copied.value = null
  try {
    result.value = await postApi.exportPost(props.post.id)
  } catch (e) {
    const errors = validationErrors(e)
    error.value = errors ? Object.values(errors).flat().join(' ') : '내보내기를 준비하지 못했습니다.'
  } finally {
    exporting.value = false
  }
}

async function copy(kind: 'rich' | 'text') {
  if (!result.value) return
  try {
    if (kind === 'rich') await copyRich(result.value.html, result.value.text)
    else await copyText(result.value.text)
    copied.value = kind === 'rich' ? '서식 포함 본문을 복사했습니다.' : '텍스트를 복사했습니다.'
  } catch {
    copied.value = '복사하지 못했습니다. 브라우저의 클립보드 권한을 확인해 주세요.'
  }
}

async function publish() {
  publishing.value = true
  publishError.value = null
  try {
    emit('updated', await postApi.publish(props.post.id, publishedUrl.value.trim()))
  } catch (e) {
    const errors = validationErrors(e)
    publishError.value = errors?.published_url?.[0] ?? '기록하지 못했습니다.'
  } finally {
    publishing.value = false
  }
}
</script>

<template>
  <section class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 class="text-lg font-semibold">네이버에 올리기</h2>
      <button
        type="button"
        :disabled="exporting"
        class="rounded-md bg-stone-900 px-4 py-2 font-medium text-white disabled:opacity-50"
        @click="prepare"
      >
        {{ exporting ? '준비 중…' : result ? '다시 준비' : '내보내기 준비' }}
      </button>
    </div>
    <p v-if="blockingCount && !result" role="status" class="text-sm text-red-700">
      게시 전 검사에 '꼭 고치기' {{ blockingCount }}개가 남아 있습니다. 고친 뒤 내보내는 것을 권합니다.
    </p>
    <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>

    <template v-if="result">
      <ul
        v-if="result.warnings.length"
        class="space-y-1 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-900"
        aria-label="내보내기 전 확인"
      >
        <li v-for="warning in result.warnings" :key="warning">{{ warning }}</li>
      </ul>

      <ol class="list-decimal space-y-1 pl-5 text-sm text-stone-700">
        <li>네이버 블로그 글쓰기를 열고 제목을 붙여넣습니다.</li>
        <li>"서식 포함 복사"로 본문을 복사해 붙여넣습니다.</li>
        <li>본문의 [사진 1], [사진 2] … 자리에 같은 번호의 사진을 넣습니다.</li>
        <li>게시한 뒤 글 주소를 아래에 기록합니다.</li>
      </ol>

      <div class="flex flex-wrap gap-2">
        <button
          type="button"
          class="rounded-md border border-stone-300 px-3 py-2 text-sm hover:bg-stone-50"
          @click="copyText(post.title ?? '').then(() => (copied = '제목을 복사했습니다.'))"
        >
          제목 복사
        </button>
        <button
          type="button"
          class="rounded-md border border-stone-300 px-3 py-2 text-sm hover:bg-stone-50"
          @click="copy('rich')"
        >
          서식 포함 복사
        </button>
        <button
          type="button"
          class="rounded-md border border-stone-300 px-3 py-2 text-sm hover:bg-stone-50"
          @click="copy('text')"
        >
          텍스트만 복사
        </button>
        <a
          v-if="result.photos.length"
          :href="postApi.photosZipUrl(post.id)"
          download
          class="rounded-md border border-stone-300 px-3 py-2 text-sm hover:bg-stone-50"
        >
          사진 {{ result.photos.length }}장 받기 (zip)
        </a>
      </div>
      <p v-if="copied" role="status" class="text-sm text-stone-600">{{ copied }}</p>

      <ol v-if="result.photos.length" class="flex flex-wrap gap-2">
        <li v-for="photo in result.photos" :key="photo.image_id" class="relative">
          <img
            v-if="imageById.get(photo.image_id)"
            :src="imageById.get(photo.image_id)!.thumb_url"
            :alt="`사진 ${photo.number}`"
            class="size-16 rounded object-cover"
          />
          <span class="absolute top-0.5 left-0.5 rounded bg-black/60 px-1 text-[10px] text-white">
            {{ photo.number }}
          </span>
        </li>
      </ol>
    </template>

    <form
      class="flex flex-wrap items-center gap-2 border-t border-stone-200 pt-4"
      @submit.prevent="publish"
    >
      <label class="min-w-0 flex-[1_1_16rem]">
        <span class="sr-only">게시한 글 주소</span>
        <input
          v-model="publishedUrl"
          type="url"
          required
          placeholder="게시한 글 주소 (https://blog.naver.com/…)"
          class="w-full rounded-md border border-stone-300 px-3 py-2 text-sm"
        />
      </label>
      <button
        type="submit"
        :disabled="publishing"
        class="rounded-md border border-stone-300 px-3 py-2 text-sm hover:bg-stone-50 disabled:opacity-50"
      >
        {{ post.published_url ? '주소 고치기' : '게시 완료로 기록' }}
      </button>
      <p v-if="publishError" role="alert" class="w-full text-sm text-red-600">{{ publishError }}</p>
      <p v-else-if="post.status === 'published' && post.published_url" class="w-full text-sm text-emerald-700">
        게시 완료 ·
        <a :href="post.published_url" target="_blank" rel="noopener noreferrer" class="underline">
          {{ post.published_url }}
        </a>
      </p>
    </form>
  </section>
</template>
