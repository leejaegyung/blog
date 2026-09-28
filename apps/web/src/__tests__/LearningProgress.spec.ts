import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import LearningProgress from '@/components/LearningProgress.vue'
import type { AnalysisProgress } from '@/lib/api'

const states = (wrapper: ReturnType<typeof mount>) =>
  wrapper.findAll('li').map((li) => (li.text().startsWith('✓') ? 'done' : li.find('.animate-pulse').exists() ? 'current' : 'todo'))

describe('LearningProgress', () => {
  afterEach(() => vi.useRealTimers())

  it('글을 읽는 중이면 읽기 단계와 개수를 보여준다', () => {
    const progress: AnalysisProgress = { step: 'queued', started_at: null, references: { total: 3, parsed: 1, pending: 2 } }
    const wrapper = mount(LearningProgress, { props: { progress, learning: true } })

    expect(states(wrapper)).toEqual(['current', 'todo', 'todo', 'todo'])
    expect(wrapper.text()).toContain('1/3개 읽는 중')
    expect(wrapper.text()).toContain('학습하고 있어요')
  })

  it('AI 정리 중이면 앞 단계는 끝났고 경과 시간과 막대가 움직인다', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-09-28T10:00:30Z'))
    const progress: AnalysisProgress = { step: 'ai', started_at: '2026-09-28T10:00:00Z', references: { total: 2, parsed: 2, pending: 0 } }
    const wrapper = mount(LearningProgress, { props: { progress, learning: true } })

    expect(states(wrapper)).toEqual(['done', 'done', 'current', 'todo'])
    expect(wrapper.text()).toContain('30초째')
    const width = () => Number.parseInt((wrapper.get('.progress-fill').element as HTMLElement).style.width)
    const before = width()
    await vi.advanceTimersByTimeAsync(20_000)
    expect(wrapper.text()).toContain('50초째')
    expect(width()).toBeGreaterThan(before)
    expect(width()).toBeLessThanOrEqual(92)
  })
})
