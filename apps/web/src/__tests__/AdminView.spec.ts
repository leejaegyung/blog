import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import AdminView from '@/views/AdminView.vue'
import { adminApi, type FailedJob, type Usage } from '@/lib/api'

vi.mock('@/components/LlmSettingsPanel.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/lib/api', () => ({
  adminApi: {
    usage: vi.fn<(days: number) => Promise<Usage>>(),
    failedJobs: vi.fn<() => Promise<FailedJob[]>>(),
    retry: vi.fn<(uuid: string) => Promise<void>>(),
    forget: vi.fn<(uuid: string) => Promise<void>>(),
  },
}))

const USAGE: Usage = {
  days: 30,
  kpi: { posts: 5, drafted: 4, published: 2, llm_calls: 20, llm_failure_rate: 0.25, tokens: 123456, avg_draft_latency_ms: 42300 },
  by_group: [
    { purpose: 'draft', provider: 'anthropic', model: 'claude-opus-5', calls: 6, success: 5, input_tokens: 18000, output_tokens: 24000, avg_latency_ms: 42300 },
  ],
  daily: [{ day: '2026-09-27', calls: 20, failed: 5, tokens: 123456 }],
}
const JOB: FailedJob = { uuid: 'u1', job: 'App\\Jobs\\GenerateDraftJob', queue: 'default', error: 'RuntimeException: boom', failed_at: '2026-09-27T10:00:00Z' }

describe('AdminView', () => {
  beforeEach(() => {
    vi.mocked(adminApi.usage).mockReset().mockResolvedValue(USAGE)
    vi.mocked(adminApi.failedJobs).mockReset().mockResolvedValue([JOB])
    vi.mocked(adminApi.retry).mockReset().mockResolvedValue()
  })

  it('지표·용도별 표·실패 작업을 보여준다', async () => {
    const wrapper = mount(AdminView)
    await flushPromises()
    const text = wrapper.text()

    expect(text).toContain('5 / 4 / 2')
    expect(text).toContain('실패 25%')
    expect(text).toContain('123,456')
    expect(text).toContain('42.3초')
    expect(wrapper.get('tbody tr').text()).toContain('초안')
    expect(text).toContain('RuntimeException: boom')
  })

  it('실패 작업을 다시 실행하고 기간을 바꾸면 다시 불러온다', async () => {
    const wrapper = mount(AdminView)
    await flushPromises()

    await wrapper.findAll('button').find((b) => b.text() === '다시 실행')!.trigger('click')
    await flushPromises()
    expect(adminApi.retry).toHaveBeenCalledWith('u1')
    expect(wrapper.text()).toContain('초안 작업을 다시 실행하도록 넣었습니다.')

    await wrapper.get('select').setValue(7)
    await flushPromises()
    expect(adminApi.usage).toHaveBeenLastCalledWith(7)
  })
})
