"""참고자료를 분석용 공통 구조로 바꾼 결과. 원문 HTML은 저장하지 않는다."""

import hashlib
import re
from typing import Literal

from pydantic import BaseModel

EXTRACTOR_VERSION = "1"

BlockType = Literal["heading", "paragraph", "image", "list", "quote", "table"]


class Block(BaseModel):
    type: BlockType
    text: str = ""
    level: int | None = None  # heading 단계(1~6)
    alt: str | None = None  # image 설명


class ParsedDocument(BaseModel):
    source: Literal["url", "text"]
    url: str | None = None
    title: str | None = None
    author: str | None = None
    published_at: str | None = None
    extractor: str
    extractor_version: str = EXTRACTOR_VERSION
    blocks: list[Block]

    @property
    def plain_text(self) -> str:
        return "\n".join(block.text for block in self.blocks if block.text)

    @property
    def content_hash(self) -> str:
        # 공백 차이만 있는 같은 글을 같은 글로 본다.
        normalized = re.sub(r"\s+", " ", self.plain_text).strip()
        return hashlib.sha256(normalized.encode()).hexdigest()

    def count(self, block_type: BlockType) -> int:
        return sum(1 for block in self.blocks if block.type == block_type)
