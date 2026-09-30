"""티스토리 글 본문을 글 순서대로 블록으로 읽는다.

일반 추출기(trafilatura)는 티스토리 사진 블록(<figure class="imageblock">)을 버려 사진 배치를 배울 수 없다.
티스토리 편집기가 본문을 넣는 상자(.tt_article_useless_p_margin 등)를 직접 읽는다. 못 찾으면 None(일반 추출기로).
"""

from lxml import html as lxml_html
from lxml.html import HtmlElement

from app.references.document import Block, ParsedDocument

# 티스토리 스킨마다 본문 상자 이름이 조금씩 다르다(앞에 있을수록 본문에 가깝다)
CONTAINERS = (
    "tt_article_useless_p_margin",
    "contents_style",
    "article-view",
    "entry-content",
)
# 본문 상자 안에 스킨이 넣는 광고·관련 글·공감 버튼
SKIP_CLASSES = ("revenue_unit", "adsbygoogle", "another_category", "container_postbtn", "related-articles", "tt-plugin")
SKIP_TAGS = {"script", "style", "noscript", "iframe", "button", "form"}
IMAGE_FIGURES = ("imageblock", "imagegridblock", "imageslideblock")
# 이보다 작다고 적힌 그림은 이모티콘·아이콘으로 본다
MIN_IMAGE_WIDTH = 200


def _classes(el: HtmlElement) -> str:
    return f" {el.get('class', '')} "


def _text(el: HtmlElement) -> str:
    return " ".join(el.text_content().split())


def _find_container(root: HtmlElement) -> HtmlElement | None:
    for name in CONTAINERS:
        found = root.xpath(f'//*[contains(concat(" ", normalize-space(@class), " "), " {name} ")]')
        if found:
            return found[0]
    return None


def _is_photo(img: HtmlElement) -> bool:
    src = img.get("src", "") + img.get("data-src", "")
    if "emoticon" in src or "emoticon" in _classes(img):
        return False
    width = img.get("width") or img.get("data-origin-width") or ""
    return not (width.isdigit() and int(width) < MIN_IMAGE_WIDTH)


def _photos(el: HtmlElement) -> list[Block]:
    imgs = [el] if el.tag == "img" else el.iter("img")
    return [Block(type="image", alt=img.get("alt") or None) for img in imgs if _is_photo(img)]


def _walk(el: HtmlElement, out: list[Block]) -> None:
    for child in el:
        if not isinstance(child, HtmlElement) or child.tag in SKIP_TAGS:
            continue
        classes = _classes(child)
        if any(f" {name}" in classes or f"{name} " in classes for name in SKIP_CLASSES):
            continue
        tag = child.tag
        if tag in ("h1", "h2", "h3", "h4", "h5", "h6"):
            if text := _text(child):
                out.append(Block(type="heading", text=text, level=int(tag[1])))
        elif tag == "figure":
            # 사진 블록만 센다(링크 미리보기·파일 첨부 블록은 뺀다)
            if any(name in classes for name in IMAGE_FIGURES):
                out.extend(_photos(child))
        elif tag == "img":
            out.extend(_photos(child))
        elif tag in ("ul", "ol"):
            items = [_text(li) for li in child.iter("li")]
            if items := [item for item in items if item]:
                out.append(Block(type="list", text="\n".join(items)))
        elif tag == "blockquote":
            if text := _text(child):
                out.append(Block(type="quote", text=text))
        elif tag == "table":
            if text := _text(child):
                out.append(Block(type="table", text=text))
        elif tag == "p":
            out.extend(_photos(child))
            if text := _text(child):
                out.append(Block(type="paragraph", text=text))
        elif len(child):
            # div·section 같은 상자: 안에 블록이 있으면 들어가 읽고, 글자만 있으면 문단으로
            before = len(out)
            _walk(child, out)
            if len(out) == before and (text := _text(child)):
                out.append(Block(type="paragraph", text=text))
        elif text := _text(child):
            out.append(Block(type="paragraph", text=text))


def _meta(root: HtmlElement, prop: str) -> str | None:
    found = root.xpath(f'//meta[@property="{prop}"]/@content')
    return str(found[0]).strip() or None if found else None


def parse(html: bytes | str, url: str) -> ParsedDocument | None:
    if isinstance(html, bytes):
        # 티스토리는 UTF-8. 바이트로 주면 lxml이 문자 집합을 잘못 짐작해 한글이 깨진다
        try:
            html = html.decode("utf-8")
        except UnicodeDecodeError:
            return None
    try:
        root = lxml_html.fromstring(html)
    except (ValueError, lxml_html.etree.ParserError):
        return None
    container = _find_container(root)
    if container is None:
        return None
    blocks: list[Block] = []
    _walk(container, blocks)
    if not blocks:
        return None
    title = _meta(root, "og:title") or (root.findtext(".//title") or "").strip() or None
    return ParsedDocument(
        source="url",
        url=_meta(root, "og:url") or url,
        title=title,
        author=_meta(root, "og:article:author"),
        published_at=_meta(root, "article:published_time"),
        extractor="tistory",
        blocks=blocks,
    )
