from app.analyzers.aggregate import aggregate
from app.analyzers.exposure import build_guide, merge_tags, normalize_tag, recommend_hashtags
from tests.test_aggregate import DOCS, P, features


def test_normalize_and_merge_tags() -> None:
    assert normalize_tag(" #인계동 맛집! ") == "인계동맛집"
    assert normalize_tag("#123") is None and normalize_tag("  ") is None
    assert merge_tags(["인계동맛집", "Pasta"], ["#인계동 맛집", "pasta", "데이트"], limit=3) == ["인계동맛집", "Pasta", "데이트"]


def test_hashtags_from_keyword_category_references_and_ai() -> None:
    tagged = [features([P("인계동 파스타."), P("#인계동맛집 #수원데이트")]), features([P("인계동 파스타."), P("#수원데이트")])]
    stats = aggregate(tagged, "수원 인계동 파스타")

    tags = recommend_hashtags("수원 인계동 파스타", "맛집", stats, ["#수원데이트", "브런치"])

    assert [(t.tag, t.source) for t in tags] == [
        ("수원인계동파스타", "keyword"), ("인계동파스타", "keyword"), ("수원파스타", "keyword"),
        ("인계동맛집", "keyword"), ("수원맛집", "keyword"), ("수원데이트", "references"), ("브런치", "ai"),
    ]
    assert tags[5].share == 1.0


def test_guide_targets_come_from_reference_stats() -> None:
    guide = build_guide("인계동 파스타", "맛집", aggregate(DOCS, "인계동 파스타"))
    targets = {t.key: t for t in guide.targets}

    assert guide.reference_count == 3
    assert targets["photos"].min == guide.checks.photo_min
    assert "참고 글 3개" in targets["length"].basis
    assert targets["hashtags"].target == "5~15개"  # 참고 글에 태그가 없으면 일반 기준
    assert targets["info_items"].target.startswith("메뉴")
    assert guide.principles and all("보장" not in p for p in guide.principles)


def test_default_guide_without_references() -> None:
    guide = build_guide("인계동 파스타", None, None)

    assert guide.reference_count == 0
    assert guide.checks.photo_min == 5 and guide.checks.hashtag_min == 5
    assert all("일반 기준" in t.basis for t in guide.targets)


def test_single_reference_shows_one_value_instead_of_a_range() -> None:
    guide = build_guide("인계동 파스타", None, aggregate(DOCS[:1], "인계동 파스타"))
    targets = {t.key: t.target for t in guide.targets}

    assert "~" not in targets["length"] and targets["length"].endswith("자")
    assert "~" not in targets["photos"]
