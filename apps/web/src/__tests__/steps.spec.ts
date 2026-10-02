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
import PhotoStep from '@/components/steps/PhotoStep.vue'
import { analysisApi, imageApi, placeApi, postApi, projectApi, referenceApi, type ExportResult, type ParsedMapInput, type Place, type Post, type PostImage, type Project } from '@/lib/api'
import { copyImage, copyRich, copyText } from '@/lib/clipboard'
import { photoPngBlob } from '@/lib/naverExport'
import { useAuthStore } from '@/stores/auth'
import { naverWriteUrl } from '@/lib/naver'

vi.mock('@/lib/api', () => ({
  placeApi: { lookup: vi.fn<(input: string) => Promise<{ data: Place[]; parsed: ParsedMapInput }>>() },
  postApi: {
    get: vi.fn<(id: number) => Promise<Post>>(),
    start: vi.fn<(keyword: string, category?: string, learningCategoryId?: number | null, platform?: string, twinLearningCategoryId?: number | null) => Promise<Post>>(),
    update: vi.fn<(id: number, input: object) => Promise<Post>>(),
    autopilot: vi.fn<(id: number, until?: string) => Promise<Post>>(),
    savePlan: vi.fn<(id: number, input: object) => Promise<Post>>(),
    generate: vi.fn<(id: number) => Promise<Post>>(),
    qualityCheck: vi.fn<(id: number) => Promise<Post>>(),
    exportPost: vi.fn<(id: number, record?: boolean, platform?: string) => Promise<ExportResult>>(),
    publish: vi.fn<(id: number, url: string, platform?: string) => Promise<Post>>(),
    twin: vi.fn<(id: number) => Promise<{ data: Post | null; overlap: number | null }>>(),
    writeTwin: vi.fn<(id: number, learningCategoryId?: number | null) => Promise<Post>>(),
    photosZipUrl: (id: number) => `/zip/${id}`,
  },
  imageApi: {
    analyze: vi.fn<(id: number) => Promise<{ data: PostImage[]; queued: number }>>(),
    upload: vi.fn<() => Promise<PostImage>>(),
    remove: vi.fn<() => Promise<void>>(),
    reorder: vi.fn<(postId: number, ids: number[]) => Promise<PostImage[]>>(),
  },
  projectApi: { list: vi.fn<(kind?: string, platform?: string) => Promise<Project[]>>() },
  analysisApi: { get: vi.fn<(id: number) => Promise<{ data: null; status: string }>>() },
  referenceApi: {
    list: vi.fn<(id: number) => Promise<never[]>>(),
    addUrls: vi.fn<(id: number, urls: string[]) => Promise<{ data: never[]; skipped: never[] }>>(),
    pasteText: vi.fn<(id: number, text: string) => Promise<unknown>>(),
  },
}))
vi.mock('@/lib/clipboard', () => ({
  copyImage: vi.fn<(png: Promise<Blob> | Blob) => Promise<void>>(),
  copyRich: vi.fn<(html: string, text: string) => Promise<void>>(),
  copyText: vi.fn<(text: string) => Promise<void>>(),
}))
vi.mock('@/lib/naverExport', async (importOriginal) => ({
  splitPieces: (await importOriginal<typeof import('@/lib/naverExport')>()).splitPieces,
  photoPngBlob: vi.fn<(url: string) => Promise<Blob>>(async (url) => new Blob([url], { type: 'image/png' })),
  buildPasteHtml: async (r: ExportResult) => ({ html: r.html + '<img>', embedded: r.photos.length }),
}))

const base = {
  id: 7, keyword: '인계동 파스타', keyword_project_id: 3, title: '제목 A', tone: 'natural', target_length: 2500,
  status: 'draft', updated_at: 't1', images: [], facts: [], plan: null, content: null, quality: null,
} as unknown as Post
const post = (extra: Partial<Post> = {}) => ({ ...base, ...extra }) as Post

function mountStep(component: DefineComponent, props: Record<string, unknown>) {
  const flow = { update: vi.fn<(p: Post) => void>(), go: vi.fn<(step: number, id?: number) => void>() }
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/projects', name: 'projects', component: { render: () => null } }, { path: '/posts/:id/step/:step', name: 'flow', component: { render: () => null } }, { path: '/:p(.*)*', component: { render: () => null } }] })
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
  vi.mocked(projectApi.list).mockResolvedValue([])
})
afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('1 키워드', () => {
  it('키워드와 학습 카테고리로 글을 시작하고 모아 둔 참고 글 주소를 추가한다', async () => {
    vi.mocked(projectApi.list).mockResolvedValue([
      { id: 5, keyword: '맛집', kind: 'category', status: 'analyzed', reference_count: 4 },
      { id: 6, keyword: '카페', kind: 'category', status: 'draft', reference_count: 0 },
    ] as Project[])
    vi.mocked(postApi.start).mockResolvedValue(post())
    vi.mocked(referenceApi.addUrls).mockResolvedValue({ data: [], skipped: [] })
    const { wrapper, flow } = mountStep(KeywordStep as unknown as DefineComponent, { post: null })

    await flushPromises()
    expect(projectApi.list).toHaveBeenCalledWith('category')
    expect(wrapper.text()).toContain('맛집 · 학습한 글 4개 · 학습 완료')  // 처음엔 가장 최근 카테고리
    await wrapper.get('input[aria-label="키워드"]').setValue('인계동 파스타')
    await button(wrapper, '카페').trigger('click')
    expect(wrapper.text()).toContain('카페 · 학습한 글 0개 · 학습 전')
    await wrapper.get('textarea[aria-label="참고할 글 주소"]').setValue('https://ex.com/a')
    await button(wrapper, '추가하고 분석').trigger('click')
    expect(wrapper.text()).toContain('1개는 다음을 누르면 추가돼요')
    await button(wrapper, '다음 · 사진 올리기').trigger('click')
    await flushPromises()

    expect(postApi.start).toHaveBeenCalledWith('인계동 파스타', '카페', 6, 'naver', null)
    expect(referenceApi.addUrls).toHaveBeenCalledWith(3, ['https://ex.com/a'])
    expect(flow.go).toHaveBeenCalledWith(2, 7)
  })

  it('둘 다를 고르면 네이버·티스토리 카테고리를 하나씩 골라 동시에 올릴 글을 시작한다', async () => {
    vi.mocked(projectApi.list).mockResolvedValue([
      { id: 5, keyword: '맛집', kind: 'category', platform: 'naver', status: 'analyzed', reference_count: 4 },
      { id: 8, keyword: '맛집T', kind: 'category', platform: 'tistory', status: 'analyzed', reference_count: 10 },
    ] as Project[])
    vi.mocked(postApi.start).mockReset().mockResolvedValue(post())
    const { wrapper } = mountStep(KeywordStep as unknown as DefineComponent, { post: null })
    await flushPromises()

    // 네이버만 고른 상태에서는 티스토리 카테고리가 보이지 않는다
    expect(wrapper.text()).not.toContain('맛집T')
    await wrapper.findAll('[role=tab]').find((t) => t.text().includes('둘 다'))!.trigger('click')
    expect(wrapper.text()).toContain('티스토리용 글을 따로 써서')
    expect(wrapper.text()).toContain('맛집T · 학습한 글 10개')
    await wrapper.get('input[aria-label="키워드"]').setValue('인계동 파스타')
    await button(wrapper, '다음 · 사진 올리기').trigger('click')
    await flushPromises()

    expect(postApi.start).toHaveBeenCalledWith('인계동 파스타', '맛집', 5, 'both', 8)
    localStorage.clear()
  })
})

describe('2 사진', () => {
  it('올린 사진을 바로 분석하고, 끝나면 "모두 분석됨"을 보여준다', async () => {
    vi.useFakeTimers()
    const img = (id: number, extra: Partial<PostImage> = {}) =>
      ({ id, sort_order: id, thumb_url: `/t/${id}`, url: `/u/${id}`, vision: null, vision_status: null, ...extra }) as PostImage
    const vision = { type: 'food', description: '', usable: true, quality_score: 1, suggested_section: '', caption_hint: '', privacy_flags: [] }
    vi.mocked(imageApi.analyze).mockResolvedValue({ data: [img(1, { vision_status: 'pending' }), img(2, { vision_status: 'pending' })], queued: 2 })
    vi.mocked(postApi.get).mockResolvedValue(post({ images: [img(1, { vision, vision_status: 'done' }), img(2, { vision, vision_status: 'done' })] }))

    const { wrapper } = mountStep(PhotoStep as unknown as DefineComponent, { post: post({ images: [img(1), img(2)] }) })
    await flushPromises()
    expect(imageApi.analyze).toHaveBeenCalledWith(7)
    expect(wrapper.text()).toContain('사진 분석 중 · 0 / 2장')

    await vi.advanceTimersByTimeAsync(2600)
    await flushPromises()
    expect(wrapper.text()).toContain('✓ 2장 모두 분석됨')
    vi.useRealTimers()
  })

  it('찍은 시각을 보여 주고, 원하면 찍은 시간순으로 정렬한다(올린 순서는 그대로 두는 게 기본)', async () => {
    const img = (id: number, taken: string | null) =>
      ({ id, sort_order: id, thumb_url: `/t/${id}`, url: `/u/${id}`, vision: null, vision_status: 'done', taken_at: taken }) as PostImage
    const images = [img(1, '2026-09-28T12:40:00.000000Z'), img(2, null), img(3, '2026-09-28T12:02:00.000000Z')]
    vi.mocked(imageApi.analyze).mockResolvedValue({ data: images, queued: 0 })
    vi.mocked(imageApi.reorder).mockImplementation(async (_post, ids) => ids.map((id) => images.find((i) => i.id === id)!))
    const { wrapper } = mountStep(PhotoStep as unknown as DefineComponent, { post: post({ images }) })
    await flushPromises()

    expect(wrapper.text()).toContain('12:40')
    expect(wrapper.text()).toContain('찍은 시각이 있어요(2/3장)')
    expect(imageApi.reorder).not.toHaveBeenCalled()
    await button(wrapper, '찍은 시간순으로 정렬').trigger('click')
    await flushPromises()
    expect(imageApi.reorder).toHaveBeenCalledWith(7, [3, 1, 2])
    expect(wrapper.text()).not.toContain('찍은 시간순으로 정렬')
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
      place: null,
    })
    expect(postApi.autopilot).toHaveBeenCalledWith(7, 'plan')
    expect(flow.go).toHaveBeenCalledWith(4)
  })

  it('지도 링크로 장소를 찾아 고르면 이름·주소·연락처·업종이 채워지고 장소도 함께 저장한다', async () => {
    const found = {
      kakao_id: '111', name: '파스타인계', category: '음식점 > 양식 > 이탈리안', phone: '031-000-0000',
      address: '경기 수원시 팔달구 인계동 1', road_address: '경기 수원시 팔달구 인계로 1', lat: 37.26, lng: 127.03, distance_m: 120,
    }
    vi.mocked(placeApi.lookup).mockResolvedValue({
      data: [found], parsed: { source: 'google', url: 'https://maps.google.com/x', name: '파스타인계', lat: 37.26, lng: 127.03, short: false },
    })
    vi.mocked(postApi.update).mockReset().mockResolvedValue(post())
    vi.mocked(postApi.autopilot).mockResolvedValue(post({ pipeline_status: 'running' }))
    const { wrapper } = mountStep(FactsStep as unknown as DefineComponent, { post: post() })

    await wrapper.get('textarea[aria-label="지도 링크나 가게 이름"]').setValue('https://maps.google.com/x')
    await button(wrapper, '장소 찾기').trigger('click')
    await flushPromises()
    expect(placeApi.lookup).toHaveBeenCalledWith('https://maps.google.com/x')
    expect(wrapper.text()).toContain('링크에서 120m')
    await button(wrapper, '파스타인계').trigger('click')
    expect((wrapper.get('input[aria-label="주소 내용"]').element as HTMLInputElement).value).toBe('경기 수원시 팔달구 인계로 1')
    expect(wrapper.text()).toContain('구글 지도 열기')

    await button(wrapper, '다음 · 글 계획 만들기').trigger('click')
    await flushPromises()
    const saved = vi.mocked(postApi.update).mock.calls[0]![1]
    expect(saved.facts).toEqual([
      { fact_key: '장소명', fact_value: '파스타인계' },
      { fact_key: '주소', fact_value: '경기 수원시 팔달구 인계로 1' },
      { fact_key: '연락처', fact_value: '031-000-0000' },
      { fact_key: '업종', fact_value: '음식점 > 양식 > 이탈리안' },
    ])
    expect(saved.place).toMatchObject({ name: '파스타인계', map_url: 'https://maps.google.com/x', source: 'google' })
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

  it('초안에 자동으로 달 해시태그를 보여준다', () => {
    const { wrapper } = mountStep(PlanStep as unknown as DefineComponent, {
      post: post({ plan: PLAN, status: 'planned', keyword_project_id: null, recommended_hashtags: ['인계동파스타', '수원맛집'] }),
    })
    expect(wrapper.text()).toContain('달 해시태그 2개')
    expect(wrapper.text()).toContain('#수원맛집')
  })

  it('아직 안 쓰인 사진을 목차로 끌어다 놓으면 그 섹션에 넣어 저장한다', async () => {
    vi.mocked(postApi.savePlan).mockResolvedValue(post({ plan: PLAN }))
    vi.mocked(postApi.generate).mockResolvedValue(post({ status: 'generating' }))
    const images = [{ id: 11, sort_order: 1, thumb_url: '/t/11' }, { id: 12, sort_order: 2, thumb_url: '/t/12' }] as PostImage[]
    const withPhoto = { ...PLAN, outline: [{ ...PLAN.outline[0]!, image_ids: [11] }, PLAN.outline[1]!] }
    const { wrapper } = mountStep(PlanStep as unknown as DefineComponent, { post: post({ plan: withPhoto, images, status: 'planned' }) })

    expect(wrapper.text()).toContain('2번 — 목차에 끌어다 놓기')
    await wrapper.get('img[alt="사진 2"]').element.parentElement!.dispatchEvent(new Event('dragstart'))
    const rows = wrapper.findAll('[draggable="true"]').filter((r) => r.find('input[aria-label$="소제목"]').exists())
    await rows[1]!.trigger('drop')
    expect(wrapper.text()).not.toContain('목차에 끌어다 놓기')

    await button(wrapper, '이 계획으로 초안 쓰기').trigger('click')
    await flushPromises()
    const saved = vi.mocked(postApi.savePlan).mock.calls[0]![1].outline
    expect(saved.map((s) => s.image_ids)).toEqual([[11], [12]])
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
  it('제목 복사로 네이버 글쓰기를 열고, 글·사진을 한 조각씩 복사해 붙여넣게 한 뒤, 게시 주소를 기록한다', async () => {
    useAuthStore().naverBlogId = 'leejk4791'  // 관리 화면에서 넣은 내 블로그 → 내 블로그 편집기를 연다
    const open = vi.fn<(...args: unknown[]) => void>()
    vi.stubGlobal('open', open)
    vi.mocked(copyRich).mockReset().mockResolvedValue()
    vi.mocked(copyImage).mockReset().mockResolvedValue()
    vi.mocked(postApi.exportPost).mockResolvedValue({
      html: '<p>본문</p>\n<p data-photo="1"><strong>[사진 1]</strong></p>\n<p>끝</p>', text: '제목 A\n\n본문\n\n[사진 1]\n\n끝', tags: [], warnings: [],
      photos: [{ number: 1, image_id: 5, filename: '01.jpg', url: '/p/5' }],
    })
    vi.mocked(postApi.publish).mockResolvedValue(post({ status: 'published', published_url: 'https://blog.naver.com/me/1' }))
    const { wrapper, flow } = mountStep(UploadStep as unknown as DefineComponent, { post: post({ content: { blocks: [], tags: [] } }) })
    await flushPromises()

    expect(postApi.exportPost).toHaveBeenCalledWith(7, false, 'naver')
    const steps = wrapper.text()
    // 네이버는 붙여넣은 글 속 사진을 버려서 한 조각씩 붙여넣기가 기본
    expect(steps.indexOf('제목 복사')).toBeLessThan(steps.indexOf('조각 붙여넣기'))
    expect(steps).toContain('사진 1장 받기')

    await button(wrapper, '제목 복사').trigger('click')
    await flushPromises()
    expect(open).toHaveBeenCalledWith('https://blog.naver.com/leejk4791?Redirect=Write&', '_blank', 'noopener')
    expect(wrapper.text()).toContain('네이버 글쓰기를 열었어요')

    await button(wrapper, '조각 붙여넣기').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('글 2묶음 + 사진 1장')
    await button(wrapper, '1번째 조각 복사').trigger('click')
    await flushPromises()
    expect(copyRich).toHaveBeenCalledWith('<p>본문</p>', '본문')
    expect(wrapper.text()).toContain('1번째 조각(글)을 복사했어요')

    // 네이버 창에 다녀오면 다음 조각(사진)을 자동으로 복사한다
    const now = vi.spyOn(Date, 'now').mockReturnValue(1000)
    window.dispatchEvent(new Event('blur'))
    now.mockReturnValue(3000)
    window.dispatchEvent(new Event('focus'))
    await flushPromises()
    expect(copyImage).toHaveBeenCalledTimes(1)
    expect(photoPngBlob).toHaveBeenCalledWith('/p/5')
    now.mockRestore()

    await button(wrapper, '3번째 조각 복사').trigger('click')
    await flushPromises()
    expect(copyRich).toHaveBeenLastCalledWith('<p>끝</p>', '끝')
    expect(wrapper.text()).toContain('마지막 조각까지 복사했어요')

    await wrapper.get('#published-url').setValue('https://blog.naver.com/me/1')
    await button(wrapper, '게시 완료로 기록').trigger('click')
    await flushPromises()
    expect(postApi.publish).toHaveBeenCalledWith(7, 'https://blog.naver.com/me/1', 'naver')
    expect(flow.update).toHaveBeenCalled()
  })

  it('해시태그가 있으면 태그 복사 단계가 생기고 #을 붙여 복사한다', async () => {
    vi.mocked(postApi.exportPost).mockResolvedValue({ html: '<p>본문</p>', text: '본문', tags: ['인계동파스타', '수원맛집'], warnings: [], photos: [] })
    const { wrapper } = mountStep(UploadStep as unknown as DefineComponent, { post: post({ content: { blocks: [], tags: [] } }) })
    await flushPromises()

    expect(wrapper.text()).toContain('해시태그 2개는 본문 끝에 들어가요')
    await button(wrapper, '태그 복사').trigger('click')
    await flushPromises()
    expect(copyText).toHaveBeenCalledWith('#인계동파스타 #수원맛집')
    const rows = wrapper.findAll('span').filter((el) => el.text() === '게시한 글 주소 붙여넣기')
    expect(rows[0]!.element.previousElementSibling!.textContent).toBe('4')
  })

  it('다른 플랫폼 탭은 따로 쓴 짝 글을 올리고, 없으면 따로 쓰기를 권한다', async () => {
    vi.mocked(postApi.exportPost).mockReset().mockResolvedValue({ html: '<p>본문</p>', text: '본문', tags: [], warnings: [], photos: [] })
    vi.mocked(postApi.writeTwin).mockResolvedValue(post({ id: 9, platform: 'tistory', twin_of_post_id: 7 }))
    vi.mocked(postApi.twin).mockResolvedValue({ data: post({ id: 9, platform: 'tistory', title: '티스토리 제목', pipeline_status: 'done', twin_of_post_id: 7 }), overlap: 0.08 })
    const { wrapper } = mountStep(UploadStep as unknown as DefineComponent, { post: post({ content: { blocks: [], tags: [] } }) })
    await flushPromises()

    await wrapper.findAll('[role=tab]').find((t) => t.text().includes('티스토리'))!.trigger('click')
    expect(wrapper.text()).toContain('중복 문서')
    await button(wrapper, '티스토리용으로 따로 쓰기').trigger('click')
    await flushPromises()
    expect(postApi.writeTwin).toHaveBeenCalledWith(7)
    expect(wrapper.text()).toContain('티스토리용 글을 쓰고 있어요')
    expect(wrapper.text()).not.toContain('제목 복사')

    await new Promise((r) => setTimeout(r, 3100))
    await flushPromises()
    await new Promise((r) => setTimeout(r, 1300))
    await flushPromises()
    expect(wrapper.text()).toContain('문장 겹침 8%')
    expect(postApi.exportPost).toHaveBeenLastCalledWith(9, false, 'tistory')
  }, 10000)

  it('티스토리 탭은 내 티스토리 글쓰기를 열고, 태그는 쉼표로 복사하고, 티스토리 주소로 기록한다', async () => {
    useAuthStore().tistoryHost = 'myblog.tistory.com'
    const open = vi.fn<(...args: unknown[]) => void>()
    vi.stubGlobal('open', open)
    vi.mocked(copyText).mockClear()
    vi.mocked(postApi.exportPost).mockReset().mockResolvedValue({ html: '<p>본문</p>', text: '본문', tags: ['파스타', '수원'], warnings: [], photos: [] })
    vi.mocked(postApi.publish).mockResolvedValue(post({ tistory_url: 'https://myblog.tistory.com/3' }))
    const { wrapper } = mountStep(UploadStep as unknown as DefineComponent, { post: post({ platform: 'tistory', content: { blocks: [], tags: [] } }) })
    await flushPromises()

    expect(wrapper.text()).toContain('티스토리에')
    expect(postApi.exportPost).toHaveBeenCalledWith(7, false, 'tistory')
    await button(wrapper, '제목 복사').trigger('click')
    expect(open).toHaveBeenCalledWith('https://myblog.tistory.com/manage/newpost', '_blank', 'noopener')
    expect(wrapper.text()).toContain('본문에는 넣지 않았어요')
    await button(wrapper, '태그 복사').trigger('click')
    await flushPromises()
    expect(copyText).toHaveBeenLastCalledWith('파스타,수원')

    // 티스토리는 한 번에 붙여넣기(평문에는 제목을 넣지 않는다)
    vi.mocked(copyRich).mockReset().mockResolvedValue()
    await button(wrapper, '본문 복사').trigger('click')
    await flushPromises()
    expect(copyRich).toHaveBeenCalledWith('<p>본문</p><img>', '본문')
    expect(wrapper.text()).toContain('제목 칸이 아니라 본문 칸에 붙여넣으세요')

    await wrapper.get('#published-url').setValue('https://myblog.tistory.com/3')
    await button(wrapper, '게시 완료로 기록').trigger('click')
    await flushPromises()
    expect(postApi.publish).toHaveBeenCalledWith(7, 'https://myblog.tistory.com/3', 'tistory')

    // 네이버 탭으로 바꾸면 네이버 기준으로 다시 준비한다
    await wrapper.findAll('[role=tab]').find((t) => t.text().includes('네이버'))!.trigger('click')
    await new Promise((r) => setTimeout(r, 1300))
    await flushPromises()
    expect(postApi.exportPost).toHaveBeenLastCalledWith(7, false, 'naver')
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

describe('네이버 글쓰기 주소', () => {
  it('블로그 아이디가 있으면 내 블로그 편집기, 없으면 기본 글쓰기', () => {
    expect(naverWriteUrl('leejk4791')).toBe('https://blog.naver.com/leejk4791?Redirect=Write&')
    expect(naverWriteUrl(null)).toBe('https://blog.naver.com/GoBlogWrite.naver')
  })
})
