from pathlib import Path

from fastapi.testclient import TestClient

from app.api.references import get_fetcher
from app.main import app
from app.references.fetcher import FetchedPage, FetchError
from tests.test_reference_extract import ARTICLE


class StubFetcher:
    def __init__(self, result: FetchedPage | FetchError) -> None:
        self.result = result

    async def fetch(self, url: str) -> FetchedPage:
        if isinstance(self.result, FetchError):
            raise self.result
        return self.result


def client(result: FetchedPage | FetchError) -> TestClient:
    app.dependency_overrides[get_fetcher] = lambda: StubFetcher(result)
    return TestClient(app)


def teardown_function() -> None:
    app.dependency_overrides.clear()


def test_parse_url_returns_features_and_stores_nothing(upload_root: Path) -> None:
    page = FetchedPage(url="https://ex.com/p/1", content=ARTICLE.encode())

    body = client(page).post("/references/parse", json={
        "source": "url", "url": "https://ex.com/p/1", "keyword": "수원 파스타",
    }).json()

    assert body["title"] == "수원 파스타 후기"
    assert body["features"]["version"] == "features-1"
    assert (body["features"]["heading_count"], body["features"]["image_count"]) == (3, 1)
    assert body["features"]["title"]["keyword_position"] == "start"
    assert len(body["content_hash"]) == 64
    assert "blocks" not in body
    assert list(upload_root.rglob("*")) == []


def test_parse_pasted_text(upload_root: Path) -> None:
    (upload_root / "references/1").mkdir(parents=True)
    (upload_root / "references/1/9.txt").write_text("첫 문단입니다.\n\n[사진]\n\n둘째 문단입니다.")

    body = client(FetchError("x", "x")).post("/references/parse", json={
        "source": "text", "text_key": "references/1/9.txt", "title": "네이버 글", "keyword": "문단",
    }).json()

    assert (body["title"], body["extractor"]) == ("네이버 글", "text")
    assert body["features"]["layout"] == "PIP"
    assert body["features"]["keyword"]["full_match_count"] == 2


def test_missing_pasted_text_is_422(upload_root: Path) -> None:
    response = client(FetchError("x", "x")).post("/references/parse", json={
        "source": "text", "text_key": "references/1/none.txt", "keyword": "k",
    })

    assert response.status_code == 422
    assert response.json()["detail"]["code"] == "missing_text"


def test_permanent_fetch_error_is_422(upload_root: Path) -> None:
    error = FetchError("blocked_domain", "네이버 글은 가져오지 않습니다.")

    response = client(error).post("/references/parse", json={
        "source": "url", "url": "https://blog.naver.com/a/1", "keyword": "k",
    })

    assert response.status_code == 422
    assert response.json()["detail"]["code"] == "blocked_domain"


def test_transient_fetch_error_is_502(upload_root: Path) -> None:
    response = client(FetchError("timeout", "느림", transient=True)).post("/references/parse", json={
        "source": "url", "url": "https://ex.com/p", "keyword": "k",
    })

    assert response.status_code == 502


def test_rejects_paths_outside_upload_root(upload_root: Path) -> None:
    response = client(FetchError("x", "x")).post("/references/parse", json={
        "source": "text", "text_key": "../../etc/passwd", "keyword": "k",
    })

    assert response.status_code == 400
