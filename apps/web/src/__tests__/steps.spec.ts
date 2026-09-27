import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { DefineComponent } from 'vue'
import FactsStep from '@/components/steps/FactsStep.vue'
import PlanStep from '@/components/steps/PlanStep.vue'
import DraftStep from '@/components/steps/DraftStep.vue'
import UploadStep from '@/components/steps/UploadStep.vue'
import KeywordStep from '@/components/steps/KeywordStep.vue'
import { analysisApi, postApi, referenceApi, type ExportResult, type Post } from '@/lib/api'
import { copyRich } from '@/lib/clipboard'

vi.mock('@/lib/api', () => ({
  postApi: {
    get: vi.fn<(id: number) => Promise<Post>>(),
    start: vi.fn<(keyword: string, category?: string) => Promise<Post>>(),
    update: vi.fn<(id: number, input: object) => Promise<Post>>(),
    autopilot: vi.fn<(id: number, until?: string) => Promise<Post>>(),
    savePlan: vi.fn<(id: number, input: object) => Promise<Post>>(),
    generate: vi.fn<(id: number) => Promise<Post>>(),
    qualityCheck: vi.fn<(id: number) => Promise<Post>>(),
    exportPost: vi.fn<(id: number, record?: boolean) => Promise<ExportResult>>(),
    publish: vi.fn<(id: number, url: string) => Promise<Post>>(),
    photosZipUrl: (id: number) => `/zip/${id}`,
  },
  analysisApi: { get: vi.fn<(id: number) => Promise<{ data: null; status: string }>>() },
  referenceApi: {
    list: vi.fn<(id: number) => Promise<never[]>>(),
    addUrls: vi.fn<(id: number, urls: string[]) => Promise<{ data: never[]; skipped: never[] }>>(),
    pasteText: vi.fn<(id: number, text: string) => Promise<unknown>>(),
  },
}))
vi.mock('@/lib/clipboard', () => ({
  copyRich: vi.fn<(html: string, text: string) => Promise<void>>(),
  copyText: vi.fn<(text: string) => Promise<void>>(),
}))
vi.mock('@/lib/naverExport', () => ({
  buildPasteHtml: async (r: ExportResult) => ({ html: r.html + '<img>', embedded: r.photos.length }),
}))

const base = {
  id: 7, keyword: '인계동 파스타', keyword_project_id: 3, title: '제목 A', tone: 'natural', target_length: 2500,
  status: 'draft', updated_at: 't1', images: [], facts: [], plan: null, content: null, quality: null,
} as unknown as Post
const post = (extra: Partial<Post> = {}) => ({ ...base, ...extra }) as Post

function mountStep(component: DefineComponent, props: Record<string, unknown>) {
  const flow = { update: vi.fn<(p: Post) => void>(), go: vi.fn<(step: number, id?: number) => void>() }
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
  const wrapper = mount(component, { props, global: { plugins: [router], provide: { flow } }, attachTo: document.body })
  return { wrapper, flow }
}
const button = (w: ReturnType<typeof mountStep>['wrapper'], text: string) =>
  w.findAll('button').find((b) => b.text().includes(text))!

beforeEach(() => {
  setActivePinia(createPinia())
  for (const fn of [postApi.get, postApi.start, postApi.update, postApi.autopilot, postApi.savePlan, postApi.generate, postApi.qualityCheck, postApi.exportPost, postApi.publish]) {
    vi.mocked(fn).mockReset()
  }
  vi.mocked(analysisApi.get).mockResolvedValue({ data: null, status: 'draft' })
  vi.mocked(referenceApi.list).mockResolvedValue([])
})
afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('1 키워드', () => {
  it('키워드·카테고리로 글을 시작하고 모아 둔 참고 글 주소를 추가한다', async () => {
    vi.mocked(postApi.start).mockResolvedValue(post())
    vi.mocked(referenceApi.addUrls).mockResolvedValue({ data: [], skipped: [] })
    const { wrapper, flow } = mountStep(KeywordStep as unknown as DefineComponent, { post: null })

    await wrapper.get('input[aria-label="키워드"]').setValue('인계동 파스타')
    await button(wrapper, '카페').trigger('click')
    await wrapper.get('textarea[aria-label="참고할 글 주소"]').setValue('https://ex.com/a')
    await button(wrapper, '추가하고 분석').trigger('click')
    expect(wrapper.text()).toContain('1개는 다음을 누르면 추가돼요')
    await button(wrapper, '다음 · 사진 올리기').trigger('click')
    await flushPromises()

    expect(postApi.start).toHaveBeenCalledWith('인계동 파스타', '카페')
    expect(referenceApi.addUrls).toHaveBeenCalledWith(3, ['https://ex.com/a'])
    expect(flow.go).toHaveBeenCalledWith(2, 7)
  })
})

describe('3 알려줄 내용', () => {
  it('칩으로 항목을 더하고, 저장한 뒤 계획까지만 만든다', async () => {
    vi.mocked(postApi.update).mockResolvedValue(post())
    vi.mocked(postApi.autopilot).mockResolvedValue(post({ pipeline_status: 'running' }))
    const { wrapper, flow } = mountStep(FactsStep as unknown as DefineComponent, { post: post() })

    await button(wrapper, '가격').trigger('click')
    await wrapper.get('input[aria-label="장소명 내용"]').setValue('파스타 인계')
    await wrapper.get('input[aria-label="가격 내용"]').setValue('런치 19,000원')
    await button(wrapper, '친근한 말투').trigger('click')
    await button(wrapper, '다음 · 글 계획 만들기').trigger('click')
    await flushPromises()

    expect(postApi.update).toHaveBeenCalledWith(7, {
      facts: [{ fact_key: '장소명', fact_value: '파스타 인계' }, { fact_key: '가격', fact_value: '런치 19,000원' }],
      tone: 'friendly',
      target_length: 2500,
    })
    expect(postApi.autopilot).toHaveBeenCalledWith(7, 'plan')
    expect(flow.go).toHaveBeenCalledWith(4)
  })

  it('비어 있으면 진행하지 않고, 계획이 있고 바뀐 게 없으면 다시 만들지 않는다', async () => {
    const empty = mountStep(FactsStep as unknown as DefineComponent, { post: post() })
    await button(empty.wrapper, '다음').trigger('click')
    expect(empty.wrapper.text()).toContain('1개 이상 적어주세요')

    const planned = mountStep(FactsStep as unknown as DefineComponent, {
      post: post({ facts: [{ id: 1, fact_key: '가격', fact_value: '19,000원' }], plan: {} as Post['plan'] }),
    })
    await button(planned.wrapper, '다음 · 글 계획 만들기').trigger('click')
    expect(postApi.autopilot).not.toHaveBeenCalled()
    expect(planned.flow.go).toHaveBeenCalledWith(4)
  })
})

const PLAN = {
  title_candidates: ['제목 A', '제목 B'],
  search_intent: '인계동 파스타집 찾기',
  outline: [
    { heading: '첫인상', purpose: '도입', key_points: [], fact_keys: [], image_ids: [] },
    { heading: '메뉴', purpose: '가격', key_points: [], fact_keys: [], image_ids: [] },
  ],
  keywords: { primary: [], secondary: ['런치 세트'] },
  required_fact_keys: [],
  forbidden_claims: ['주차 가능 여부는 입력되지 않았으므로 단정하지 말 것'],
  unused_fact_keys: [],
  unplaced_image_ids: [],
  corrections: [],
}

describe('4 글 계획', () => {
  it('만드는 중이면 진행 상황을 보여준다', () => {
    const { wrapper } = mountStep(PlanStep as unknown as DefineComponent, { post: post({ pipeline_status: 'running', pipeline_step: 'analysis' }) })
    expect(wrapper.text()).toContain('글 계획을 만들고 있어요')
    expect(wrapper.text()).toContain('키워드 분석')
  })

  it('제목을 고르면 계획을 저장하고 초안을 쓴다', async () => {
    vi.mocked(postApi.savePlan).mockResolvedValue(post({ plan: PLAN }))
    vi.mocked(postApi.generate).mockResolvedValue(post({ status: 'generating' }))
    const { wrapper, flow } = mountStep(PlanStep as unknown as DefineComponent, { post: post({ plan: PLAN, status: 'planned' }) })

    expect(wrapper.text()).toContain('주차 가능 여부는 입력되지 않았으므로 단정하지 말 것')
    await wrapper.findAll('input[type="radio"]')[1]!.setValue(true)
    await button(wrapper, '이 계획으로 초안 쓰기').trigger('click')
    await flushPromises()

    expect(vi.mocked(postApi.savePlan).mock.calls[0]![1]).toMatchObject({ title: '제목 B' })
    expect(postApi.generate).toHaveBeenCalledWith(7)
    expect(flow.go).toHaveBeenCalledWith(5)
  })

  it('이미 초안이 있으면 바로 다듬기로 가고, 다시 쓰려면 한 번 더 확인한다', async () => {
    const withDraft = post({ plan: PLAN, status: 'review', content: { blocks: [], tags: [] } })
    const { wrapper, flow } = mountStep(PlanStep as unknown as DefineComponent, { post: withDraft })

    await button(wrapper, '이 계획으로 초안 쓰기').trigger('click')
    expect(flow.go).toHaveBeenCalledWith(5)

    await wrapper.findAll('input[type="radio"]')[1]!.setValue(true)
    await button(wrapper, '이 계획으로 초안 쓰기').trigger('click')
    expect(wrapper.text()).toContain('한 번 더 누르면 시작합니다')
    expect(postApi.generate).not.toHaveBeenCalled()
  })
})

describe('5 초안 다듬기', () => {
  it('꼭 고치기 문장을 사실로 추가하고 다시 검사한다', async () => {
    const quality = {
      version: 'quality-1', score: 72, parts: [], metrics: {},
      issues: [{ code: 'unsupported_specific', severity: 'error', message: '입력하지 않은 가격 정보가 있습니다: 25,000원', block_index: 0, excerpt: '25,000원', suggested_fact_key: '가격' }],
    }
    const p = post({
      status: 'review', quality, facts: [{ id: 1, fact_key: '장소명', fact_value: 'OO' }],
      content: { blocks: [{ type: 'paragraph', text: '디너는 25,000원이에요.' }], tags: [] },
    } as unknown as Partial<Post>)
    vi.mocked(postApi.update).mockResolvedValue(p)
    vi.mocked(postApi.qualityCheck).mockResolvedValue(p)
    const { wrapper } = mountStep(DraftStep as unknown as DefineComponent, { post: p })
    await flushPromises()

    expect(wrapper.text()).toContain('꼭 고치기 1')
    await button(wrapper, '사실로 추가').trigger('click')
    await flushPromises()

    expect(postApi.update).toHaveBeenCalledWith(7, {
      facts: [{ fact_key: '장소명', fact_value: 'OO' }, { fact_key: '가격', fact_value: '25,000원' }],
    })
    expect(postApi.qualityCheck).toHaveBeenCalledWith(7)
    expect(wrapper.text()).toContain('"가격: 25,000원"을 알려줄 내용에 추가했어요')
  })
})

describe('6 네이버에 올리기', () => {
  it('제목 복사로 네이버 글쓰기를 열고, 본문을 사진과 함께 한 번에 복사한 뒤, 게시 주소를 기록한다', async () => {
    const open = vi.fn<(...args: unknown[]) => void>()
    vi.stubGlobal('open', open)
    vi.mocked(copyRich).mockResolvedValue()
    vi.mocked(postApi.exportPost).mockResolvedValue({
      html: '<p>본문</p>', text: '본문', tags: [], warnings: [],
      photos: [{ number: 1, image_id: 5, filename: '01.jpg', url: '/p/5' }],
    })
    vi.mocked(postApi.publish).mockResolvedValue(post({ status: 'published', published_url: 'https://blog.naver.com/me/1' }))
    const { wrapper, flow } = mountStep(UploadStep as unknown as DefineComponent, { post: post({ content: { blocks: [], tags: [] } }) })
    await flushPromises()

    expect(postApi.exportPost).toHaveBeenCalledWith(7, false)
    const steps = wrapper.text()
    expect(steps.indexOf('제목 복사')).toBeLessThan(steps.indexOf('본문 복사'))
    expect(steps).toContain('사진 1장 받기')

    await button(wrapper, '제목 복사').trigger('click')
    await flushPromises()
    expect(open).toHaveBeenCalledWith('https://blog.naver.com/GoBlogWrite.naver', '_blank', 'noopener')
    expect(wrapper.text()).toContain('네이버 글쓰기를 열었어요')

    await button(wrapper, '본문 복사').trigger('click')
    await flushPromises()
    expect(copyRich).toHaveBeenCalledWith('<p>본문</p><img>', '본문')
    expect(open).toHaveBeenCalledTimes(1)
    expect(postApi.exportPost).toHaveBeenLastCalledWith(7, true)
    expect(wrapper.text()).toContain('본문과 사진 1장을 복사했어요')

    await wrapper.get('#published-url').setValue('https://blog.naver.com/me/1')
    await button(wrapper, '게시 완료로 기록').trigger('click')
    await flushPromises()
    expect(postApi.publish).toHaveBeenCalledWith(7, 'https://blog.naver.com/me/1')
    expect(flow.update).toHaveBeenCalled()
  })

  it('붙여넣기는 클립보드의 글 주소를 게시 주소 칸에 넣는다', async () => {
    vi.mocked(postApi.exportPost).mockResolvedValue({ html: '<p>본문</p>', text: '본문', tags: [], warnings: [], photos: [] })
    const readText = vi.fn<() => Promise<string>>().mockResolvedValue(' https://blog.naver.com/me/2 ')
    vi.stubGlobal('navigator', { ...navigator, clipboard: { readText } })
    const { wrapper } = mountStep(UploadStep as unknown as DefineComponent, { post: post({ content: { blocks: [], tags: [] } }) })
    await flushPromises()

    expect(wrapper.text()).not.toContain('장 받기')
    await button(wrapper, '붙여넣기').trigger('click')
    await flushPromises()
    expect((wrapper.get('#published-url').element as HTMLInputElement).value).toBe('https://blog.naver.com/me/2')
    vi.unstubAllGlobals()
  })
})
