// jsdom에는 레이아웃 API가 없다. ProseMirror가 선택 영역으로 스크롤할 때 부르므로 빈 값을 돌려준다.
const emptyRect = () => ({ x: 0, y: 0, top: 0, left: 0, bottom: 0, right: 0, width: 0, height: 0, toJSON() {} })
const emptyRects = () => Object.assign([], { item: () => null }) as unknown as DOMRectList

for (const proto of [Range.prototype, Element.prototype, Text.prototype as unknown as Element]) {
  if (!('getClientRects' in proto)) Object.defineProperty(proto, 'getClientRects', { value: emptyRects })
  if (!('getBoundingClientRect' in proto))
    Object.defineProperty(proto, 'getBoundingClientRect', { value: emptyRect })
}
if (!document.elementFromPoint) document.elementFromPoint = () => null
