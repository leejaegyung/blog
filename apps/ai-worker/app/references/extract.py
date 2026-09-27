import re

import trafilatura
from lxml import etree

from app.references.document import Block, ParsedDocument


class EmptyContentError(ValueError):
    pass


# 이보다 짧으면 본문 대신 메뉴·푸터 조각을 건진 것으로 본다.
MIN_HTML_CHARS = 50


def from_html(html: bytes | str, url: str) -> ParsedDocument:
    xml = trafilatura.extract(
        html,
        url=url,
        output_format="xml",
        include_images=True,
        include_links=False,
        include_comments=False,
        include_tables=True,
        with_metadata=True,
    )
    if not xml:
        raise EmptyContentError("본문을 찾지 못했습니다.")

    doc = etree.fromstring(xml.encode())
    main = doc.find("main")
    blocks = [block for element in (main if main is not None else []) if (block := _block(element))]
    if sum(len(block.text) for block in blocks) < MIN_HTML_CHARS:
        raise EmptyContentError("본문을 찾지 못했습니다.")

    return ParsedDocument(
        source="url",
        url=doc.get("url") or url,
        title=doc.get("title"),
        author=doc.get("author"),
        published_at=doc.get("date"),
        extractor="trafilatura",
        blocks=blocks,
    )


def _block(element: etree._Element) -> Block | None:
    text = " ".join("".join(element.itertext()).split())
    match element.tag:
        case "head":
            level = element.get("rend", "h2").lstrip("h")
            return Block(type="heading", text=text, level=int(level) if level.isdigit() else 2)
        case "p":
            return Block(type="paragraph", text=text) if text else None
        case "graphic":
            return Block(type="image", alt=element.get("alt") or None)
        case "list":
            items = [" ".join("".join(item.itertext()).split()) for item in element.iter("item")]
            return Block(type="list", text="\n".join(item for item in items if item))
        case "quote":
            return Block(type="quote", text=text) if text else None
        case "table":
            return Block(type="table", text=text) if text else None
    return None


# 붙여넣기 본문: 사진은 복사되지 않으므로 사용자가 사진 자리에 "[사진]"을 적으면 위치를 살린다.
IMAGE_MARKER = re.compile(r"^\[(사진|이미지|photo|image)\]$", re.IGNORECASE)
MARKDOWN_HEADING = re.compile(r"^(#{1,6})\s+(.+)$")
LIST_ITEM = re.compile(r"^\s*(?:[-*•·]|\d+[.)])\s+(.+)$")
SENTENCE_END = re.compile(r"[.!?~。…다요죠음함]$")


def from_text(text: str, title: str | None = None) -> ParsedDocument:
    blocks: list[Block] = []
    for chunk in re.split(r"\n\s*\n", text.replace("\r\n", "\n")):
        lines = [line.strip() for line in chunk.split("\n") if line.strip()]
        if not lines:
            continue
        blocks.extend(_text_blocks(lines))

    if not any(block.text for block in blocks):
        raise EmptyContentError("본문이 비어 있습니다.")

    return ParsedDocument(source="text", title=title, extractor="text", blocks=blocks)


def _text_blocks(lines: list[str]) -> list[Block]:
    blocks: list[Block] = []
    paragraph: list[str] = []
    items: list[str] = []

    def flush() -> None:
        if paragraph:
            blocks.append(Block(type="paragraph", text=" ".join(paragraph)))
            paragraph.clear()
        if items:
            blocks.append(Block(type="list", text="\n".join(items)))
            items.clear()

    for line in lines:
        if IMAGE_MARKER.match(line):
            flush()
            blocks.append(Block(type="image"))
        elif heading := MARKDOWN_HEADING.match(line):
            flush()
            blocks.append(Block(type="heading", text=heading.group(2).strip(), level=len(heading.group(1))))
        elif item := LIST_ITEM.match(line):
            if paragraph:
                flush()
            items.append(item.group(1).strip())
        else:
            if items:
                flush()
            paragraph.append(line)

    flush()

    # 한 줄짜리 짧은 문단이 문장으로 끝나지 않으면 소제목으로 본다(붙여넣기에는 서식이 없다).
    if len(blocks) == 1 and blocks[0].type == "paragraph":
        only = blocks[0].text
        if len(only) <= 30 and not SENTENCE_END.search(only):
            return [Block(type="heading", text=only, level=2)]
    return blocks
