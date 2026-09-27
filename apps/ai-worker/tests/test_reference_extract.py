import pytest

from app.references.extract import EmptyContentError, from_html, from_text

LONG = "인계동에 있는 파스타집에 다녀왔습니다. 주차가 가능해서 편했어요. 가격은 적당한 편이었고 직원분들도 친절했습니다. " * 4

ARTICLE = f"""<html><head><title>수원 파스타 후기</title>
<meta property="article:published_time" content="2026-09-01T10:00:00+09:00"></head><body>
<nav><a href="/">홈</a></nav><article>
<h1>수원 파스타 후기</h1><p>{LONG}</p>
<h2>메뉴</h2><p>{LONG}</p>
<figure><img src="https://x.com/a.jpg" alt="봉골레"></figure><p>{LONG}</p>
<h3>총평</h3><ul><li>주차 가능</li><li>런치 세트 19,000원</li></ul>
<blockquote>재방문 의사 있음</blockquote><p>{LONG}</p>
</article><footer>ⓒ 2026</footer></body></html>"""


def test_html_keeps_structure_in_order() -> None:
    doc = from_html(ARTICLE.encode(), "https://ex.com/p/1")

    assert [b.type for b in doc.blocks] == [
        "heading", "paragraph", "heading", "paragraph", "image", "paragraph", "heading", "list", "quote", "paragraph",
    ]
    assert [(b.text, b.level) for b in doc.blocks if b.type == "heading"] == [
        ("수원 파스타 후기", 1), ("메뉴", 2), ("총평", 3),
    ]
    assert doc.blocks[4].alt == "봉골레"
    assert doc.blocks[7].text == "주차 가능\n런치 세트 19,000원"
    assert doc.title == "수원 파스타 후기"
    assert doc.published_at == "2026-09-01"
    assert "홈" not in doc.plain_text and "ⓒ" not in doc.plain_text


def test_html_without_content_raises() -> None:
    with pytest.raises(EmptyContentError):
        from_html(b"<html><body><nav>menu</nav></body></html>", "https://ex.com")


def test_pasted_text_structure() -> None:
    text = """# 수원 인계동 파스타

인계동에 다녀왔습니다.
주차가 가능해서 편했어요.

[사진]

메뉴 구성

- 봉골레
- 크림 파스타
가격은 적당했습니다.

[이미지]
"""
    doc = from_text(text, title="붙여넣은 글")

    assert [(b.type, b.text) for b in doc.blocks] == [
        ("heading", "수원 인계동 파스타"),
        ("paragraph", "인계동에 다녀왔습니다. 주차가 가능해서 편했어요."),
        ("image", ""),
        ("heading", "메뉴 구성"),
        ("list", "봉골레\n크림 파스타"),
        ("paragraph", "가격은 적당했습니다."),
        ("image", ""),
    ]
    assert doc.title == "붙여넣은 글"


def test_short_sentence_is_not_mistaken_for_heading() -> None:
    doc = from_text("정말 맛있었어요\n\n다음에 또 올게요.")

    assert [b.type for b in doc.blocks] == ["paragraph", "paragraph"]


def test_hash_ignores_whitespace_differences() -> None:
    a = from_text("첫 문단입니다.\n\n둘째 문단입니다.")
    b = from_text("첫   문단입니다.\r\n\r\n\r\n둘째 문단입니다.  ")

    assert a.content_hash == b.content_hash


def test_empty_paste_raises() -> None:
    with pytest.raises(EmptyContentError):
        from_text("\n\n[사진]\n\n")
