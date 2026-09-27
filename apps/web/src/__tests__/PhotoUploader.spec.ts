import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import PhotoUploader from '@/components/PhotoUploader.vue'
import { imageApi, type PostImage } from '@/lib/api'

vi.mock('@/lib/api', () => ({
  imageApi: {
    upload: vi.fn<(postId: number, file: File, options: object) => Promise<PostImage>>(),
  },
}))

function file(name: string, type: string, size = 10) {
  return new File([new Uint8Array(size)], name, { type })
}

function image(id: number): PostImage {
  return {
    id,
    url: '',
    thumb_url: '',
    original_name: null,
    width: 1,
    height: 1,
    size_bytes: 1,
    taken_at: null,
    sort_order: id,
    caption: null,
  }
}

describe('PhotoUploader', () => {
  beforeEach(() => vi.mocked(imageApi.upload).mockReset())

  it('형식·크기·장수 제한을 넘는 파일은 올리지 않는다', async () => {
    vi.mocked(imageApi.upload).mockResolvedValue(image(1))
    const wrapper = mount(PhotoUploader, { props: { postId: 5, remaining: 1 } })

    await wrapper.vm.addFiles([
      file('a.gif', 'image/gif'),
      file('big.jpg', 'image/jpeg', 21 * 1024 * 1024),
      file('ok.jpg', 'image/jpeg'),
      file('over.jpg', 'image/jpeg'),
    ])
    await flushPromises()

    expect(imageApi.upload).toHaveBeenCalledTimes(1)
    const text = wrapper.text()
    expect(text).toContain('지원하지 않는 형식입니다.')
    expect(text).toContain('20MB를 넘습니다.')
    expect(text).toContain('최대 30장까지')
    expect(wrapper.emitted('uploaded')).toHaveLength(1)
  })

  it('type이 비어 있는 HEIC도 받는다', async () => {
    vi.mocked(imageApi.upload).mockResolvedValue(image(1))
    const wrapper = mount(PhotoUploader, { props: { postId: 5, remaining: 30 } })

    await wrapper.vm.addFiles([file('IMG_0001.HEIC', '')])
    await flushPromises()

    expect(imageApi.upload).toHaveBeenCalledOnce()
  })

  it('동시에 최대 3장만 업로드한다', async () => {
    let active = 0
    let peak = 0
    vi.mocked(imageApi.upload).mockImplementation(async () => {
      active++
      peak = Math.max(peak, active)
      await new Promise((r) => setTimeout(r, 5))
      active--
      return image(1)
    })
    const wrapper = mount(PhotoUploader, { props: { postId: 5, remaining: 30 } })

    await wrapper.vm.addFiles(Array.from({ length: 7 }, (_, i) => file(`${i}.jpg`, 'image/jpeg')))

    expect(imageApi.upload).toHaveBeenCalledTimes(7)
    expect(peak).toBe(3)
  })

  it('EXIF 제거 여부를 함께 보낸다', async () => {
    vi.mocked(imageApi.upload).mockResolvedValue(image(1))
    const wrapper = mount(PhotoUploader, { props: { postId: 5, remaining: 30 } })

    await wrapper.get('input[type="checkbox"]').setValue(false)
    await wrapper.vm.addFiles([file('a.jpg', 'image/jpeg')])

    expect(vi.mocked(imageApi.upload).mock.calls[0]![2]).toMatchObject({ stripExif: false })
  })
})
