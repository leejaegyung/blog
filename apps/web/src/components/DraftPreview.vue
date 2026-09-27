<script setup lang="ts">
import { computed, ref } from 'vue'
import type { Post, PostImage } from '@/lib/api'

const props = defineProps<{ post: Post; images: PostImage[] }>()

const mobile = ref(false)
const content = computed(() => props.post.content)
const meta = computed(() => props.post.draft_meta)
const imageById = computed(() => new Map(props.images.map((image) => [image.id, image])))

// 입력에 없는 구체 정보(환각 의심)를 먼저, 그다음 사실 누락·길이·사진 순으로 보여준다
const ORDER = ['unsupported_specific', 'fact_missing', 'length', 'image_unplaced', 'image_invalid']
const warnings = computed(() =>
  [...(meta.value?.warnings ?? [])].sort((a, b) => ORDER.indexOf(a.code) - ORDER.indexOf(b.code)),
)
</script>

<template>
  <div v-if="content" class="space-y-4">
    <div v-if="meta" class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-sub">
      <span class="tabular-nums">
        {{ meta.char_count.toLocaleString('ko-KR') }}자 / 목표
        {{ meta.target_length.toLocaleString('ko-KR') }}자
      </span>
      <span>키워드 {{ meta.keyword_count }}회</span>
    </div>

    <ul
      v-if="warnings.length"
      class="space-y-1 rounded-[14px] border-2 border-ink bg-lemon px-3.5 py-3 text-[13px]"
      aria-label="초안 확인 필요"
    >
      <li v-for="warning in warnings" :key="warning.message">
        <strong v-if="warning.code === 'unsupported_specific'">확인 필요 ·</strong>
        {{ warning.message }}
      </li>
    </ul>

    <div class="flex gap-1 rounded-xl bg-lilac-soft p-1 text-[13px]" role="group" aria-label="미리보기 폭">
      <button
        type="button"
        :aria-pressed="!mobile"
        :class="!mobile ? 'bg-ink font-bold text-cream' : 'font-semibold'"
        class="rounded-[9px] px-3 py-1.5"
        @click="mobile = false"
      >
        PC
      </button>
      <button
        type="button"
        :aria-pressed="mobile"
        :class="mobile ? 'bg-ink font-bold text-cream' : 'font-semibold'"
        class="rounded-[9px] px-3 py-1.5"
        @click="mobile = true"
      >
        모바일
      </button>
    </div>

    <article
      :class="mobile ? 'max-w-[390px] px-4' : 'max-w-[693px] px-6 sm:px-10'"
      class="mx-auto space-y-5 rounded-[18px] border-[1.5px] border-line bg-white py-8 text-[16px] leading-[1.8] text-ink"
    >
      <h1 class="border-b-[1.5px] border-line pb-5 text-[26px] leading-snug font-bold">
        {{ post.title }}
      </h1>
      <template v-for="(block, index) in content.blocks" :key="index">
        <h2 v-if="block.type === 'heading'" class="pt-4 text-[20px] font-bold">
          {{ block.text }}
        </h2>
        <p v-else-if="block.type === 'paragraph'" class="whitespace-pre-line">{{ block.text }}</p>
        <figure v-else-if="block.type === 'image' && block.image_id">
          <img
            v-if="imageById.get(block.image_id)"
            :src="imageById.get(block.image_id)!.url"
            :alt="imageById.get(block.image_id)!.original_name ?? '사진'"
            loading="lazy"
            class="w-full rounded"
          />
          <div v-else class="photo-stripes rounded-xl py-10 text-center text-sm text-sub">
            삭제된 사진
          </div>
        </figure>
        <ul v-else-if="block.type === 'list'" class="list-disc space-y-1 pl-6">
          <li v-for="item in block.items ?? []" :key="item">{{ item }}</li>
        </ul>
        <blockquote
          v-else-if="block.type === 'quote'"
          class="border-l-4 border-line py-1 pl-4 text-sub"
        >
          {{ block.text }}
        </blockquote>
      </template>
      <p v-if="content.tags.length" class="pt-4 text-sm text-sub">
        {{ content.tags.map((tag) => `#${tag}`).join(' ') }}
      </p>
    </article>
  </div>
</template>
