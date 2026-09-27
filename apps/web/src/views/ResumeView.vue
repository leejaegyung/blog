<script setup lang="ts">
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { postApi } from '@/lib/api'
import { resumeStep } from '@/lib/flow'

const props = defineProps<{ id: number }>()
const router = useRouter()

onMounted(async () => {
  try {
    const post = await postApi.get(props.id)
    router.replace({ name: 'flow', params: { id: post.id, step: resumeStep(post) } })
  } catch {
    router.replace({ name: 'home' })
  }
})
</script>

<template>
  <p class="text-sub">불러오는 중…</p>
</template>
