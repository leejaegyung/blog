from app.references.extract import from_html

PAGE = """<!doctype html><html><head><meta charset="utf-8">
<meta property="og:title" content="[수원맛집] 인계동 파스타">
<meta property="article:published_time" content="2026-09-01T12:00:00+09:00">
<title>블로그 이름</title></head><body>
<nav>메뉴 홈 방명록</nav>
<div class="tt_article_useless_p_margin contents_style">
<p data-ke-size="size16">인계동 파스타 집에 다녀왔어요. 크림 파스타가 진하고 면이 쫄깃했어요.</p>
<figure class="imageblock alignCenter" data-origin-width="800"><span><img src="https://blog.kakaocdn.net/a.jpg" alt="파스타"></span></figure>
<p><img src="https://t1.daumcdn.net/tistory_admin/emoticon/1.png" width="60">&nbsp;</p>
<h2>메뉴와 가격</h2>
<figure class="imagegridblock"><div><img src="https://blog.kakaocdn.net/b.jpg"><img src="https://blog.kakaocdn.net/c.jpg"></div></figure>
<ul><li>봉골레</li><li>크림</li></ul>
<figure data-ke-type="opengraph"><a href="https://ex.com">링크 미리보기</a></figure>
<div class="revenue_unit_wrap">광고 문구</div>
<blockquote>또 갈래요</blockquote>
</div>
<div class="another_category">관련 글 목록</div>
</body></html>""".encode()


def test_tistory_body_keeps_photos_in_order() -> None:
    doc = from_html(PAGE, "https://me.tistory.com/1")

    assert doc.extractor == "tistory"
    assert doc.title == "[수원맛집] 인계동 파스타"
    assert doc.published_at == "2026-09-01T12:00:00+09:00"
    # 사진 블록(그리드 포함)은 세고, 이모티콘·링크 미리보기·광고·관련 글은 뺀다
    assert [b.type for b in doc.blocks] == ["paragraph", "image", "heading", "image", "image", "list", "quote"]
    assert doc.blocks[1].alt == "파스타"
    assert doc.blocks[2].level == 2
    assert "광고" not in doc.plain_text and "관련 글" not in doc.plain_text


def test_pages_without_a_tistory_body_use_the_general_extractor() -> None:
    html = "<html><body><article><p>" + "일반 사이트 본문 문장입니다. " * 20 + "</p></article></body></html>"
    assert from_html(html, "https://ex.com/a").extractor == "trafilatura"
