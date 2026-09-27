import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import PlanPanel from '@/components/PlanPanel.vue'
import { postApi, type PlanSection, type Post, type PostImage } from '@/lib/api'

vi.mock('@/lib/api', () => ({
  postApi: {
    savePlan: vi.fn<(id: number, input: { title?: string | null; outline: PlanSection[] }) => Promise<Post>>(),
  },
}))

const image = (id: number, order: number): PostImage => ({
  id,
  url: '',
  thumb_url: `/t/${id}`,
  original_name: null,
  width: 1,
  height: 1,
  size_bytes: 1,
  taken_at: null,
  sort_order: order,
  caption: null,
})

const POST: Post = {
  id: 4,
  keyword_project_id: 1,
  title: '인계동 파스타 OO파스타 후기',
  tone: 'natural',
  target_length: 2500,
  status: 'planned',
  published_url: null,
  published_at: null,
  plan_error: null,
  plan_stale: false,
  content: null,
  content_original: null,
  draft_meta: null,
  draft_error: null,
  quality: null,
  quality_checked_at: null,
  quality_stale: null,
  created_at: '',
  updated_at: '',
  plan: {
    title_candidates: ['인계동 파스타 OO파스타 후기', '인계동 파스타 런치 세트'],
    search_intent: '인계동 파스타집 찾기',
    outline: [
      { heading: '첫인상', purpose: '도입', key_points: ['방문 이유'], fact_keys: ['장소명'], image_ids: [21] },
      { heading: '메뉴와 가격', purpose: '가격', key_points: [], fact_keys: ['가격'], image_ids: [22, 20] },
    ],
    keywords: { primary: ['인계동 파스타'], secondary: ['수원 파스타'] },
    required_fact_keys: ['장소명', '가격'],
    forbidden_claims: ['주차 가능 여부는 입력되지 않았으므로 단정하지 말 것'],
    unused_fact_keys: [],
    unplaced_image_ids: [23],
    corrections: [],
  },
}
const IMAGES = [image(20, 0), image(21, 1), image(22, 2), image(23, 3)]

describe('PlanPanel', () => {
  beforeEach(() => vi.mocked(postApi.savePlan).mockReset())

  it('계획을 보여주고 사진은 올린 순서 번호로 표시한다', () => {
    const wrapper = mount(PlanPanel, { props: { post: POST, images: IMAGES } })

    expect(wrapper.text()).toContain('주차 가능 여부는 입력되지 않았으므로 단정하지 말 것')
    expect(wrapper.text()).toContain('아직 배치되지 않은 사진: 4번')
    expect(wrapper.findAll('figcaption').map((f) => f.text())).toEqual(['2', '3', '1'])
    expect(wrapper.get('input[type="radio"]').element).toHaveProperty('checked', true)
    const saveButton = wrapper.findAll('button').find((b) => b.text() === '계획 저장')!
    expect(saveButton.attributes('disabled')).toBeDefined() // 고친 게 없으면 저장 비활성
  })

  it('제목 선택·목차 수정·순서 변경 후 저장한다', async () => {
    vi.mocked(postApi.savePlan).mockResolvedValue(POST)
    const wrapper = mount(PlanPanel, { props: { post: POST, images: IMAGES } })

    await wrapper.findAll('input[type="radio"]')[1]!.setValue(true)
    await wrapper.get('[aria-label="2번 섹션 소제목"]').setValue('런치 세트 가격')
    await wrapper.get('[aria-label="2번 섹션 위로"]').trigger('click')
    await wrapper.findAll('button').find((b) => b.text() === '계획 저장')!.trigger('click')
    await flushPromises()

    const [id, input] = vi.mocked(postApi.savePlan).mock.calls[0]!
    expect(id).toBe(4)
    expect(input.title).toBe('인계동 파스타 런치 세트')
    expect(input.outline.map((s) => s.heading)).toEqual(['런치 세트 가격', '첫인상'])
    expect(wrapper.emitted('updated')).toHaveLength(1)
    expect(wrapper.text()).toContain('계획을 저장했습니다.')
  })

  it('계획 뒤에 사실·사진이 바뀌었으면 안내한다', () => {
    const wrapper = mount(PlanPanel, { props: { post: { ...POST, plan_stale: true }, images: IMAGES } })

    expect(wrapper.text()).toContain('계획을 다시 만들어 주세요')
  })
})
