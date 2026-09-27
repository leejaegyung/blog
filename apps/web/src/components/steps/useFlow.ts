import { inject } from 'vue'
import type { Post } from '@/lib/api'
import type { StepNo } from '@/lib/flow'

export type FlowApi = { update: (post: Post) => void; go: (step: StepNo, id?: number) => void }

export function useFlow(): FlowApi {
  return inject<FlowApi>('flow', { update: () => {}, go: () => {} })
}
