import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PhotoGrid from '@/components/PhotoGrid.vue'
import type { PostImage } from '@/lib/api'

const images = [1, 2, 3].map(
  (id): PostImage => ({
    id,
    url: '',
    thumb_url: `/t/${id}`,
    original_name: `${id}.jpg`,
    width: 1,
    height: 1,
    size_bytes: 1,
    taken_at: null,
    sort_order: id,
    caption: null,
  }),
)

describe('PhotoGrid', () => {
  it('버튼으로 순서를 바꾼다', async () => {
    const wrapper = mount(PhotoGrid, { props: { images } })

    await wrapper.get('[aria-label="1번 사진 뒤로"]').trigger('click')
    await wrapper.get('[aria-label="3번 사진 앞으로"]').trigger('click')

    expect(wrapper.emitted('reorder')).toEqual([[[2, 1, 3]], [[1, 3, 2]]])
  })

  it('드래그로 순서를 바꾼다', async () => {
    const wrapper = mount(PhotoGrid, { props: { images } })
    const items = wrapper.findAll('li')

    await items[2]!.trigger('dragstart')
    await items[0]!.trigger('drop')

    expect(wrapper.emitted('reorder')).toEqual([[[3, 1, 2]]])
  })

  it('처음과 끝에서는 이동 버튼이 비활성화된다', () => {
    const wrapper = mount(PhotoGrid, { props: { images } })

    expect(wrapper.get('[aria-label="1번 사진 앞으로"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[aria-label="3번 사진 뒤로"]').attributes('disabled')).toBeDefined()
  })
})

describe('PhotoGrid 사진 분석 표시', () => {
  it('종류·설명·사용 비추천·개인정보 확인을 보여준다', () => {
    const analyzed = [
      {
        ...images[0]!,
        vision: {
          type: 'receipt',
          description: '영수증으로 보이는 사진',
          usable: false,
          quality_score: 0.4,
          suggested_section: '총평',
          caption_hint: '',
          privacy_flags: ['영수증·카드 정보'],
        },
        vision_status: 'done' as const,
      },
      { ...images[1]!, vision_status: 'pending' as const },
      { ...images[2]!, vision_status: 'failed' as const },
    ]
    const wrapper = mount(PhotoGrid, { props: { images: analyzed } })
    const cards = wrapper.findAll('li').map((li) => li.text())

    expect(cards[0]).toContain('영수증')
    expect(cards[0]).toContain('사용 비추천')
    expect(cards[0]).toContain('개인정보 확인')
    expect(cards[0]).toContain('영수증으로 보이는 사진')
    expect(cards[1]).toContain('분석 중…')
    expect(cards[2]).toContain('분석 실패')
  })
})
