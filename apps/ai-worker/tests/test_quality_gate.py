from fastapi.testclient import TestClient

from app.analyzers.exposure import GuideChecks
from app.generators.draft import ContentBlock
from app.generators.writing_plan import FactInput
from app.main import app
from app.quality.gate import ImageState, QualityInput, check

P = lambda t: ContentBlock(type="paragraph", text=t)  # noqa: E731
H = lambda t: ContentBlock(type="heading", text=t)  # noqa: E731
IMG = lambda i: ContentBlock(type="image", image_id=i)  # noqa: E731

FACTS = [
    FactInput(fact_key="장소명", fact_value="OO파스타"),
    FactInput(fact_key="가격", fact_value="런치 세트 19,000원"),
    FactInput(fact_key="좋았던 점", fact_value="봉골레 면이 탱글했음"),
]
PLAN = {
    "outline": [{"heading": "OO파스타 위치"}, {"heading": "런치 세트 메뉴"}],
    "forbidden_claims": ["주차 가능 여부는 입력되지 않았으므로 단정하지 말 것"],
}
GOOD = [
    P("인계동 파스타 맛집 OO파스타에 다녀왔어요."),
    IMG(1),
    H("OO파스타 위치"),
    P("인계동 골목 안쪽에 있었어요."),
    H("런치 세트 메뉴"),
    P("런치 세트는 19,000원이었어요. 봉골레는 면이 탱글해서 좋았어요."),
    IMG(2),
    P("인계동 파스타 찾으시면 한번 가 보세요."),
]


def run(blocks=GOOD, facts=FACTS, title="인계동 파스타 OO파스타 런치 세트 후기", **extra) -> tuple:
    report = check(QualityInput(keyword="인계동 파스타", title=title, blocks=blocks, facts=facts, plan=PLAN,
                                images=[ImageState(id=1), ImageState(id=2)], target_length=100, **extra))
    return report, {i.code for i in report.issues}


def test_clean_post_scores_high() -> None:
    report, codes = run()

    assert codes <= {"length"}, [i.message for i in report.issues]
    assert report.score >= 90
    assert report.metrics["facts_reflected"] == 3
    assert {p.key for p in report.parts} == {"intent", "facts", "structure", "photos", "readability", "title", "risk"}
    assert sum(p.max for p in report.parts) == 100


def test_hallucinated_specifics_and_forbidden_slot_are_located() -> None:
    blocks = GOOD[:-1] + [P("디너는 25,000원이고 오후 3시부터 브레이크 타임이에요. 주차도 넓어서 편했어요.")]
    report, codes = run(blocks)

    specific = [i for i in report.issues if i.code == "unsupported_specific"]
    assert [i.excerpt for i in specific] == ["25,000원", "오후 3시"]
    assert all(i.severity == "error" and i.block_index == 7 for i in specific)
    forbidden = next(i for i in report.issues if i.code == "forbidden_claim")
    assert forbidden.excerpt == "주차도 넓어서 편했어요."
    assert [i.suggested_fact_key for i in specific] == ["가격", "시간"]
    assert forbidden.suggested_fact_key == "주차"
    assert report.issues[0].severity == "error"  # 심각도 순 정렬


def test_missing_facts_and_title_keyword() -> None:
    report, codes = run(GOOD[:3] + GOOD[-1:], title="파스타 맛집 다녀옴")

    missing = [i.message for i in report.issues if i.code == "fact_missing"]
    assert missing == ["입력한 '가격'(런치 세트 19,000원)이 본문에 보이지 않습니다.",
                       "입력한 '좋았던 점'(봉골레 면이 탱글했음)이 본문에 보이지 않습니다."]
    assert "title_keyword" in codes
    assert next(p for p in report.parts if p.key == "facts").score == round(20 / 3, 1)


def test_sponsorship_disclosure_and_self_paid_claims() -> None:
    sponsored = FACTS + [FactInput(fact_key="협찬", fact_value="식사 제공받음")]
    _, codes = run(facts=sponsored)
    assert "ad_disclosure" in codes

    _, codes = run(GOOD + [P("업체로부터 식사를 제공받아 작성한 글입니다.")], facts=sponsored)
    assert "ad_disclosure" not in codes

    report, codes = run(title="인계동 파스타 OO파스타 후기 (내돈내산)")
    assert "self_paid_claim" in codes


def test_exaggeration_repetition_personal_phone() -> None:
    repeated = "봉골레가 정말 최고로 맛있었어요."
    report, codes = run(GOOD + [P(repeated), P(repeated), P("사장님 번호는 010-1234-5678이에요.")])

    assert {"exaggeration", "repeated_sentence", "personal_info"} <= codes
    assert next(p for p in report.parts if p.key == "risk").score < 10


def test_keyword_stuffing_and_missing() -> None:
    stuffed = [P("인계동 파스타 " * 80)]  # 500자 이상이어야 판정
    _, codes = run(stuffed)
    assert "keyword_stuffing" in codes

    _, codes = run([P("그냥 파스타집 후기입니다.")])
    assert "keyword_missing" in codes


def test_photo_checks() -> None:
    report = check(QualityInput(
        keyword="인계동 파스타", title="인계동 파스타 후기", blocks=GOOD, facts=FACTS, plan=PLAN, target_length=100,
        images=[ImageState(id=1, privacy_flags=["사람 얼굴"]), ImageState(id=2), ImageState(id=3)],
    ))
    codes = {i.code for i in report.issues}

    assert {"privacy_photo", "unused_photos"} <= codes
    assert next(p for p in report.parts if p.key == "photos").score == 0
    privacy = next(i for i in report.issues if i.code == "privacy_photo")
    assert privacy.block_index == 1


def test_no_uploaded_photos_explains_zero_photo_score() -> None:
    report = check(QualityInput(keyword="인계동 파스타", title="인계동 파스타 후기", blocks=GOOD[:1], facts=FACTS))

    assert next(i for i in report.issues if i.code == "no_photos").message.startswith("올린 사진이 없습니다")
    assert next(p for p in report.parts if p.key == "photos").score == 0


def test_long_paragraph_and_hash_changes_with_content() -> None:
    long = P("첫 문장입니다. 둘째 문장입니다. 셋째 문장입니다. 넷째 문장입니다. 다섯째 문장입니다.")
    report, codes = run(GOOD + [long])

    assert "long_paragraph" in codes
    assert report.content_hash != run()[0].content_hash


def test_endpoint() -> None:
    response = TestClient(app).post("/posts/quality-check", json={
        "keyword": "인계동 파스타", "title": "인계동 파스타 후기",
        "blocks": [b.model_dump() for b in GOOD], "facts": [f.model_dump() for f in FACTS], "plan": PLAN,
    })

    assert response.status_code == 200
    assert response.json()["version"] == "quality-2"


def test_exposure_guide_checks() -> None:
    guide = GuideChecks(title_keyword_start=True, keyword_in_first_paragraph=True, photo_min=5, heading_min=3,
                        hashtag_min=5, hashtag_max=15)
    blocks = [P("오늘 다녀온 곳이에요.")] + GOOD[1:]
    report, codes = run(blocks, title="OO파스타 런치 세트 인계동 파스타 후기", tags=["인계동파스타"], guide=guide)

    assert {"hashtags_few", "photos_few", "headings_few", "first_paragraph_keyword", "title_keyword_start"} <= codes
    first = next(i for i in report.issues if i.code == "first_paragraph_keyword")
    assert first.block_index == 0 and first.severity == "info"
    assert report.metrics["tag_count"] == 1

    _, clean = run(tags=[f"태그{i}" for i in range(6)], guide=guide.model_copy(update={"photo_min": 2, "heading_min": 2}))
    assert not clean & {"hashtags_few", "photos_few", "headings_few", "first_paragraph_keyword", "title_keyword_start"}


def test_too_many_tags_is_flagged_without_guide() -> None:
    _, codes = run(tags=[f"태그{i}" for i in range(31)])

    assert "hashtags_many" in codes
