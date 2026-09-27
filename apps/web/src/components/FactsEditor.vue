<script setup lang="ts">
import type { Fact } from '@/lib/api'

const facts = defineModel<Fact[]>({ required: true })

// 기획서 2.2 최소 입력 정보
const SUGGESTED_KEYS = [
  '장소명',
  '제품명',
  '방문 날짜',
  '가격',
  '주소',
  '주차',
  '좋았던 점',
  '아쉬웠던 점',
  '꼭 넣을 내용',
]

function add(key = '') {
  facts.value = [...facts.value, { fact_key: key, fact_value: '' }]
}

function remove(index: number) {
  facts.value = facts.value.filter((_, i) => i !== index)
}

defineExpose({ add })
</script>

<template>
  <div class="space-y-2">
    <datalist id="fact-keys">
      <option v-for="key in SUGGESTED_KEYS" :key="key" :value="key" />
    </datalist>
    <div v-for="(fact, index) in facts" :key="index" class="flex flex-wrap gap-2">
      <input
        v-model="fact.fact_key"
        list="fact-keys"
        maxlength="50"
        placeholder="항목 (예: 가격)"
        :aria-label="`${index + 1}번 항목 이름`"
        class="min-w-0 flex-[1_1_8rem] rounded-md border border-stone-300 px-3 py-2"
      />
      <input
        v-model="fact.fact_value"
        maxlength="1000"
        placeholder="내용 (예: 런치 세트 19,000원)"
        :aria-label="`${index + 1}번 항목 내용`"
        class="min-w-0 flex-[3_1_14rem] rounded-md border border-stone-300 px-3 py-2"
      />
      <button
        type="button"
        :aria-label="`${index + 1}번 항목 삭제`"
        class="rounded-md px-2 text-stone-500 hover:bg-stone-100"
        @click="remove(index)"
      >
        ✕
      </button>
    </div>
    <button type="button" class="text-sm text-stone-600 hover:underline" @click="add()">
      + 항목 추가
    </button>
  </div>
</template>
