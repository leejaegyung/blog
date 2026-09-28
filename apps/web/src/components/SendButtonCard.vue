<script setup lang="ts">
import { ref } from 'vue'
import { bookmarkletHref } from '@/lib/bookmarklet'
import { copyText } from '@/lib/clipboard'

/** 북마크 버튼 "Blog AI로 보내기" 설치 안내. 네이버 글은 서버가 가져오지 않으므로 내 브라우저에서 보고 있는 글을 버튼으로 보낸다 */
// 지금 열어 둔 Blog AI 주소(localhost·Tailscale)로 보낸다
const bookmarklet = bookmarkletHref(window.location.origin)
const copied = ref(false)
const clickedHere = ref(false)
async function copyBookmarklet() {
  await copyText(bookmarklet)
  copied.value = true
}
</script>

<template>
  <div class="flex flex-col gap-2.5 rounded-[18px] border-2 border-ink bg-lemon p-4 text-[13px] leading-normal">
    <span class="text-sm font-bold">네이버 글, 붙여넣기 없이 버튼 한 번으로 학습시키기</span>
    <ol class="m-0 flex flex-col gap-1 pl-5">
      <li>
        아래 버튼을 브라우저 <b>즐겨찾기바로 끌어다 놓아요</b>(처음 한 번만).
        즐겨찾기바가 안 보이면 <b>⌘+Shift+B</b>(윈도우는 Ctrl+Shift+B).
      </li>
      <li>학습시킬 네이버 글을 열고 즐겨찾기의 <b>Blog AI로 보내기</b>를 눌러요.</li>
      <li>열린 창에서 카테고리를 고르고 “추가하고 학습”을 눌러요.</li>
    </ol>
    <div class="flex flex-wrap items-center gap-2">
      <a
        :href="bookmarklet"
        class="rounded-xl bg-ink px-4 py-2.5 text-sm font-bold text-cream no-underline hover:text-cream"
        title="즐겨찾기바로 끌어다 놓으세요"
        @click.prevent="clickedHere = true"
      >
        ★ Blog AI로 보내기
      </a>
      <button type="button" class="text-[13px] font-bold underline" @click="copyBookmarklet">
        {{ copied ? '복사했어요 · 즐겨찾기 주소에 붙여넣으세요' : '끌어다 놓기가 안 되면 주소 복사' }}
      </button>
    </div>
    <p v-if="clickedHere" role="status" class="m-0 rounded-xl border-2 border-ink bg-white px-3 py-2 text-[13px] font-semibold">
      이 버튼은 여기서 누르는 게 아니라, 마우스로 잡아서 위쪽 즐겨찾기바에 끌어다 놓는 거예요. 그다음 네이버 글에서 눌러요.
    </p>
    <span class="text-xs text-sub">내 브라우저가 보고 있는 글을 옮기는 것이라 붙여넣기와 같아요. 서버는 네이버에 접속하지 않고, 원문은 분석 뒤 버려요.</span>
  </div>
</template>
