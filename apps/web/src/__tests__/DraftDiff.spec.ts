import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import DraftDiff from '@/components/DraftDiff.vue'

describe('DraftDiff', () => {
  it('추가·삭제를 표시하고 수정률을 계산한다', () => {
    const wrapper = mount(DraftDiff, {
      props: {
        original: [{ type: 'paragraph', text: '봉골레가 맛있었어요.' }],
        current: [{ type: 'paragraph', text: '봉골레가 정말 맛있었어요.' }],
      },
    })

    expect(wrapper.get('ins').text()).toBe('정말')
    expect(wrapper.text()).toMatch(/AI 초안 대비 수정 \d+%/)
  })

  it('바뀐 게 없으면 0%', () => {
    const blocks = [{ type: 'paragraph' as const, text: '같아요' }]
    const wrapper = mount(DraftDiff, { props: { original: blocks, current: blocks } })

    expect(wrapper.text()).toContain('AI 초안 대비 수정 0%')
    expect(wrapper.find('ins').exists()).toBe(false)
  })
})
