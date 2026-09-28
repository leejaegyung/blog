import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError, AxiosHeaders } from 'axios'
import LlmSettingsPanel from '@/components/LlmSettingsPanel.vue'
import { llmApi, type LlmState, type LlmTarget, type LlmTestResult } from '@/lib/api'

vi.mock('@/lib/api', () => ({
  llmApi: {
    get: vi.fn<() => Promise<LlmState>>(),
    saveKey: vi.fn<(provider: string, key: string) => Promise<LlmState>>(),
    deleteKey: vi.fn<(provider: string) => Promise<LlmState>>(),
    saveRoute: vi.fn<(route: LlmTarget[]) => Promise<LlmState>>(),
    resetRoute: vi.fn<() => Promise<LlmState>>(),
    test: vi.fn<(target?: string) => Promise<LlmTestResult>>(),
  },
}))

const STATE: LlmState = {
  providers: [
    { provider: 'anthropic', label: 'Anthropic (Claude)', models: ['claude-opus-5', 'claude-sonnet-5'], key_prefix: 'sk-ant-', console: 'https://c', source: 'admin', masked_key: 'sk-ant-…wAA', updated_at: null },
    { provider: 'openai', label: 'OpenAI (GPT)', models: ['gpt-5.5'], key_prefix: 'sk-', console: 'https://o', source: 'none', masked_key: null, updated_at: null },
  ],
  route: [
    { provider: 'anthropic', model: 'claude-opus-5' },
    { provider: 'openai', model: 'gpt-5.5' },
  ],
  route_source: 'env',
}

async function mountPanel() {
  const wrapper = mount(LlmSettingsPanel)
  await flushPromises()
  return wrapper
}

const button = (wrapper: Awaited<ReturnType<typeof mountPanel>>, text: string, nth = 0) =>
  wrapper.findAll('button').filter((b) => b.text() === text)[nth]!

describe('LlmSettingsPanel', () => {
  beforeEach(() => {
    Object.values(llmApi).forEach((fn) => vi.mocked(fn).mockReset())
    vi.mocked(llmApi.get).mockResolvedValue(STATE)
  })

  it('키 출처와 가린 키만 보여준다', async () => {
    const wrapper = await mountPanel()

    expect(wrapper.text()).toContain('관리 화면에서 설정')
    expect(wrapper.text()).toContain('키 없음')
    expect(wrapper.text()).toContain('sk-ant-…wAA')
    expect(button(wrapper, '연결 테스트', 1).attributes('disabled')).toBeDefined()
  })

  it('키를 저장하면 입력칸을 비운다', async () => {
    vi.mocked(llmApi.saveKey).mockResolvedValue(STATE)
    const wrapper = await mountPanel()
    const input = wrapper.get('input[aria-label="OpenAI (GPT) API 키"]')

    await input.setValue('sk-proj-new-key-0000000000')
    await wrapper.findAll('form')[1]!.trigger('submit')
    await flushPromises()

    expect(llmApi.saveKey).toHaveBeenCalledWith('openai', 'sk-proj-new-key-0000000000')
    expect((input.element as HTMLInputElement).value).toBe('')
    expect(wrapper.text()).toContain('바로 적용됩니다')
  })

  it('잘못된 키는 서버 메시지를 보여준다', async () => {
    vi.mocked(llmApi.saveKey).mockRejectedValue(
      new AxiosError('422', '422', undefined, undefined, {
        status: 422, statusText: '', headers: {}, config: { headers: new AxiosHeaders() },
        data: { errors: { api_key: ['sk-ant-로 시작하는 키를 공백 없이 넣어 주세요.'] } },
      }),
    )
    const wrapper = await mountPanel()

    await wrapper.get('input[aria-label="Anthropic (Claude) API 키"]').setValue('wrong')
    await wrapper.findAll('form')[0]!.trigger('submit')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe('sk-ant-로 시작하는 키를 공백 없이 넣어 주세요.')
  })

  it('연결 테스트는 순서에 있는 모델로 하고 오류를 사람이 읽는 말로 보여준다', async () => {
    vi.mocked(llmApi.test).mockResolvedValue({
      ok: false, reply: null,
      attempts: [{
        provider: 'anthropic', model: 'claude-opus-5', status: 'failed', error_kind: 'billing', latency_ms: 600,
        account: '92bb7358-org', error_message: 'Your credit balance is too low to access the Anthropic API.',
      }],
    })
    const wrapper = await mountPanel()

    await button(wrapper, '연결 테스트').trigger('click')
    await flushPromises()

    expect(llmApi.test).toHaveBeenCalledWith('anthropic:claude-opus-5')
    expect(wrapper.text()).toContain('claude-opus-5: API 크레딧 없음')
    expect(wrapper.text()).toContain('키가 속한 계정: 92bb7358-org')
    expect(wrapper.text()).toContain('구독은 API 크레딧과 별개')
    expect(wrapper.text()).toContain('Your credit balance is too low')
  })

  it('순서를 바꿔 저장한다', async () => {
    vi.mocked(llmApi.saveRoute).mockResolvedValue({ ...STATE, route_source: 'admin' })
    const wrapper = await mountPanel()

    await wrapper.get('[aria-label="2번 위로"]').trigger('click')
    await button(wrapper, '순서 저장').trigger('click')
    await flushPromises()

    expect(llmApi.saveRoute).toHaveBeenCalledWith([
      { provider: 'openai', model: 'gpt-5.5' },
      { provider: 'anthropic', model: 'claude-opus-5' },
    ])
    expect(wrapper.text()).toContain('순서를 저장했습니다.')
  })
})
