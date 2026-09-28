import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ExposureGuide from '@/components/ExposureGuide.vue'
import { projectApi, type ExposureGuide as Guide, type Project } from '@/lib/api'

vi.mock('@/lib/api', () => ({
  projectApi: { saveHashtags: vi.fn<(id: number, tags: string[] | null) => Promise<Project>>() },
}))

const GUIDE: Guide = {
  version: 'guide-1',
  reference_count: 3,
  targets: [{ key: 'photos', label: '사진', target: '직접 찍은 사진 4~7장', basis: '참고 글 3개 사진 수의 가운데 50%', min: 4, max: 7 }],
  principles: ['키워드를 억지로 반복하지 마세요.'],
  hashtags: [
    { tag: '인계동파스타', source: 'keyword', share: null },
    { tag: '인계동맛집', source: 'references', share: 0.67 },
  ],
}

describe('ExposureGuide', () => {
  beforeEach(() => vi.mocked(projectApi.saveHashtags).mockReset())

  it('목표치·근거와 추천 해시태그를 보여준다', () => {
    const wrapper = mount(ExposureGuide, { props: { projectId: 3, guide: GUIDE, customHashtags: null } })

    expect(wrapper.text()).toContain('직접 찍은 사진 4~7장')
    expect(wrapper.text()).toContain('참고 글 3개 사진 수의 가운데 50%')
    expect(wrapper.text()).toContain('#인계동맛집')
    expect(wrapper.text()).toContain('참고 글 67%')
    expect(wrapper.text()).toContain('분석 추천')
    expect(wrapper.text()).toContain('순위 기준을 공개하지 않아요')
  })

  it('빼고 더한 해시태그를 저장하고, 추천으로 되돌린다', async () => {
    vi.mocked(projectApi.saveHashtags).mockResolvedValue({ id: 3, hashtags: ['인계동맛집', '수원데이트'] } as Project)
    const wrapper = mount(ExposureGuide, { props: { projectId: 3, guide: GUIDE, customHashtags: null } })

    await wrapper.get('[aria-label="인계동파스타 빼기"]').trigger('click')
    await wrapper.get('input[aria-label="해시태그 추가"]').setValue('#수원 데이트, 1')
    await wrapper.get('form').trigger('submit')
    expect(wrapper.text()).toContain('+ 인계동파스타')
    await wrapper.findAll('button').find((b) => b.text() === '해시태그 저장')!.trigger('click')
    await flushPromises()

    expect(projectApi.saveHashtags).toHaveBeenCalledWith(3, ['인계동맛집', '수원', '데이트'])
    expect(wrapper.emitted('saved')).toHaveLength(1)

    await wrapper.setProps({ customHashtags: ['인계동맛집'] })
    await wrapper.findAll('button').find((b) => b.text() === '분석 추천으로 되돌리기')!.trigger('click')
    await flushPromises()
    expect(projectApi.saveHashtags).toHaveBeenLastCalledWith(3, null)
  })
})
