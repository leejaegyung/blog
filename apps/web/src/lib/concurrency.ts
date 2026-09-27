/** 작업을 최대 `limit`개씩 동시에 실행한다. 개별 실패는 다른 작업을 멈추지 않는다. */
export async function runWithConcurrency<T>(
  items: T[],
  limit: number,
  worker: (item: T) => Promise<void>,
): Promise<void> {
  let next = 0
  const lanes = Array.from({ length: Math.min(limit, items.length) }, async () => {
    while (next < items.length) {
      const item = items[next++]!
      try {
        await worker(item)
      } catch {
        // 실패 처리는 worker 안에서 한다
      }
    }
  })
  await Promise.all(lanes)
}
