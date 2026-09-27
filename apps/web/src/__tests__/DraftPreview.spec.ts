import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import DraftPreview from '@/components/DraftPreview.vue'
import type { Post, PostImage } from '@/lib/api'

const IMAGE: PostImage = {
  id: 7,
  url: '/img/7',
  thumb_url: '/t/7',
  original_name: 'pasta.jpg',
  width: 1,
  height: 1,
  size_bytes: 1,
  taken_at: null,
  sort_order: 0,
  caption: null,
}

const POST = {
  id: 1,
  title: '인계동 파스타 후기',
  content: {
    blocks: [
      { type: 'paragraph', text: '인계동 파스타집에 다녀왔어요.' },
      { type: 'heading', text: '메뉴와 가격' },
      { type: 'image', image_id: 7 },
      { type: 'image', image_id: 99 },
      { type: 'list', items: ['봉골레', '크림'] },
      { type: 'quote', text: '재방문 의사 있어요' },
    ],
    tags: ['인계동파스타', '수원맛집'],
  },
  draft_meta: {
    char_count: 2410,
    target_length: 2500,
    keyword_count: 4,
    warnings: [
      { code: 'length', message: '길이 경고' },
      { code: 'unsupported_specific', message: '입력하지 않은 시간 정보: 오전 11시' },
    ],
  },
} as unknown as Post

describe('DraftPreview', () => {
  it('블록을 네이버 글처럼 렌더링한다', () => {
    const wrapper = mount(DraftPreview, { props: { post: POST, images: [IMAGE] } })

    expect(wrapper.get('h1').text()).toBe('인계동 파스타 후기')
    expect(wrapper.get('h2').text()).toBe('메뉴와 가격')
    expect(wrapper.get('article img').attributes('src')).toBe('/img/7')
    expect(wrapper.text()).toContain('삭제된 사진')
    expect(wrapper.findAll('article li').map((li) => li.text())).toEqual(['봉골레', '크림'])
    expect(wrapper.get('blockquote').text()).toBe('재방문 의사 있어요')
    expect(wrapper.text()).toContain('#인계동파스타 #수원맛집')
    expect(wrapper.text()).toContain('2,410자 / 목표 2,500자')
  })

  it('입력하지 않은 구체 정보 경고를 가장 먼저 보여준다', () => {
    const wrapper = mount(DraftPreview, { props: { post: POST, images: [IMAGE] } })

    const items = wrapper.findAll('[aria-label="초안 확인 필요"] li').map((li) => li.text())
    expect(items[0]).toBe('확인 필요 · 입력하지 않은 시간 정보: 오전 11시')
  })

  it('모바일 폭으로 전환한다', async () => {
    const wrapper = mount(DraftPreview, { props: { post: POST, images: [IMAGE] } })

    await wrapper.findAll('button').find((b) => b.text() === '모바일')!.trigger('click')

    expect(wrapper.get('article').classes()).toContain('max-w-[390px]')
  })
})
