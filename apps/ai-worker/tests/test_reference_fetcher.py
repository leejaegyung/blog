import time

import httpx2
import pytest

from app.references.fetcher import FetchError, PageFetcher, is_blocked_host

HTML = {"content-type": "text/html; charset=utf-8"}


def fetcher(routes: dict, resolve: dict | None = None, min_interval: float = 0.0, log: list | None = None) -> PageFetcher:
    def handler(request: httpx2.Request) -> httpx2.Response:
        url = str(request.url)
        if log is not None:
            log.append((url, request.headers["user-agent"]))
        if url not in routes:
            return httpx2.Response(404)
        status, headers, body = routes[url]
        return httpx2.Response(status, headers=headers, content=body)

    async def resolver(host: str, port: int) -> list[str]:
        return (resolve or {}).get(host, ["93.184.216.34"])

    client = httpx2.AsyncClient(transport=httpx2.MockTransport(handler))
    return PageFetcher(client, resolver=resolver, min_interval=min_interval)


@pytest.mark.parametrize("host", ["blog.naver.com", "m.blog.naver.com", "naver.me", "NAVER.COM", "cafe.naver.com."])
def test_naver_hosts_are_blocked(host: str) -> None:
    assert is_blocked_host(host)


def test_lookalike_host_is_not_blocked() -> None:
    assert not is_blocked_host("notnaver.com")


async def test_fetches_page_with_honest_user_agent() -> None:
    log: list = []
    f = fetcher({"https://ex.com/p/1": (200, HTML, b"<html>ok</html>")}, log=log)

    page = await f.fetch("https://ex.com/p/1")

    assert page.content == b"<html>ok</html>"
    assert log[0][0] == "https://ex.com/robots.txt"
    assert all(ua.startswith("BlogAIReferenceFetcher/") for _, ua in log)


async def test_rejects_naver_url_without_any_request() -> None:
    log: list = []
    with pytest.raises(FetchError) as error:
        await fetcher({}, log=log).fetch("https://blog.naver.com/someone/223000000000")

    assert error.value.code == "blocked_domain"
    assert log == []


async def test_redirect_into_naver_is_blocked() -> None:
    routes = {"https://short.ly/x": (302, {"location": "https://m.blog.naver.com/a/1"}, b"")}

    with pytest.raises(FetchError) as error:
        await fetcher(routes).fetch("https://short.ly/x")

    assert error.value.code == "blocked_domain"


@pytest.mark.parametrize("address", ["127.0.0.1", "10.0.0.5", "172.19.0.2", "192.168.1.1", "169.254.169.254", "::1"])
async def test_rejects_private_addresses(address: str) -> None:
    with pytest.raises(FetchError) as error:
        await fetcher({}, resolve={"internal.test": [address]}).fetch("http://internal.test/")

    assert error.value.code == "private_address"


async def test_redirect_to_private_address_is_rejected() -> None:
    routes = {"https://ex.com/r": (301, {"location": "http://metadata.test/latest"}, b"")}

    with pytest.raises(FetchError) as error:
        await fetcher(routes, resolve={"metadata.test": ["169.254.169.254"]}).fetch("https://ex.com/r")

    assert error.value.code == "private_address"


async def test_follows_safe_redirect() -> None:
    routes = {
        "https://ex.com/old": (301, {"location": "/new"}, b""),
        "https://ex.com/new": (200, HTML, b"<html>new</html>"),
    }

    page = await fetcher(routes).fetch("https://ex.com/old")

    assert page.url == "https://ex.com/new"


async def test_respects_robots_txt() -> None:
    routes = {
        "https://ex.com/robots.txt": (200, {"content-type": "text/plain"}, b"User-agent: *\nDisallow: /private/"),
        "https://ex.com/private/p": (200, HTML, b"<html>x</html>"),
    }

    with pytest.raises(FetchError) as error:
        await fetcher(routes).fetch("https://ex.com/private/p")

    assert error.value.code == "robots_disallowed"


async def test_robots_rules_for_other_bots_do_not_apply() -> None:
    # 표준 robotparser는 "Fetch"가 우리 이름에 들어 있다고 이 규칙을 적용했다(위키백과 실제 사례).
    robots = b"User-agent: Fetch\nDisallow: /\n\nUser-agent: *\nDisallow: /wiki/Special:\nAllow: /\n"
    routes = {
        "https://ex.com/robots.txt": (200, {"content-type": "text/plain"}, robots),
        "https://ex.com/wiki/Pasta": (200, HTML, b"<html>ok</html>"),
    }

    page = await fetcher(routes).fetch("https://ex.com/wiki/Pasta")

    assert page.content == b"<html>ok</html>"


async def test_longest_robots_rule_wins() -> None:
    robots = b"User-agent: *\nDisallow: /blog/\nAllow: /blog/public/\n"
    routes = {
        "https://ex.com/robots.txt": (200, {"content-type": "text/plain"}, robots),
        "https://ex.com/blog/public/1": (200, HTML, b"ok"),
    }

    assert (await fetcher(routes).fetch("https://ex.com/blog/public/1")).content == b"ok"


async def test_robots_server_error_is_transient() -> None:
    routes = {"https://ex.com/robots.txt": (503, {}, b"")}

    with pytest.raises(FetchError) as error:
        await fetcher(routes).fetch("https://ex.com/p")

    assert error.value.transient


@pytest.mark.parametrize(
    ("status", "headers", "body", "code", "transient"),
    [
        (404, HTML, b"", "http_error", False),
        (500, HTML, b"", "upstream_error", True),
        (200, {"content-type": "application/pdf"}, b"%PDF", "not_html", False),
        (200, HTML, b"x" * (5 * 1024 * 1024 + 1), "too_large", False),
    ],
)
async def test_page_errors(status: int, headers: dict, body: bytes, code: str, transient: bool) -> None:
    with pytest.raises(FetchError) as error:
        await fetcher({"https://ex.com/p": (status, headers, body)}).fetch("https://ex.com/p")

    assert (error.value.code, error.value.transient) == (code, transient)


async def test_too_many_redirects() -> None:
    routes = {"https://ex.com/loop": (302, {"location": "/loop"}, b"")}

    with pytest.raises(FetchError) as error:
        await fetcher(routes).fetch("https://ex.com/loop")

    assert error.value.code == "too_many_redirects"


async def test_waits_between_requests_to_same_host() -> None:
    f = fetcher({"https://ex.com/a": (200, HTML, b"a"), "https://ex.com/b": (200, HTML, b"b")}, min_interval=0.2)

    started = time.monotonic()
    await f.fetch("https://ex.com/a")  # robots + a
    await f.fetch("https://ex.com/b")  # b (robots 캐시)

    assert time.monotonic() - started >= 0.4
