"""사용자가 등록한 URL을 가져온다.

- 네이버 도메인은 가져오지 않는다: robots.txt가 AI 분석·RAG 목적의 봇 접근을 명시적으로 금지한다(2026-09-27 결정).
- 사이트의 robots.txt를 지킨다. User-Agent를 숨기지 않는다.
- 사설/내부 주소로의 요청(SSRF)을 막는다. 리다이렉트마다 다시 검사한다.
  (DNS 조회와 실제 연결 사이에 주소가 바뀌는 경우는 1인용 규모에서 감수한다.)
"""

import asyncio
import ipaddress
import socket
import time
from collections.abc import Awaitable, Callable
from dataclasses import dataclass
from urllib.parse import urljoin, urlsplit

import httpx2
from protego import Protego

USER_AGENT = "BlogAIReferenceFetcher/0.1 (personal single-user tool)"
ROBOTS_AGENT = "BlogAIReferenceFetcher"
BLOCKED_HOST_SUFFIXES = ("naver.com", "naver.me", "naver.net")
MAX_BYTES = 5 * 1024 * 1024
MAX_REDIRECTS = 5
HTML_TYPES = ("text/html", "application/xhtml+xml")

Resolver = Callable[[str, int], Awaitable[list[str]]]


class FetchError(Exception):
    def __init__(self, code: str, message: str, *, transient: bool = False) -> None:
        super().__init__(message)
        self.code = code
        self.message = message
        self.transient = transient


@dataclass(frozen=True)
class FetchedPage:
    url: str
    content: bytes


def is_blocked_host(host: str) -> bool:
    host = host.lower().rstrip(".")
    return any(host == suffix or host.endswith("." + suffix) for suffix in BLOCKED_HOST_SUFFIXES)


async def system_resolver(host: str, port: int) -> list[str]:
    infos = await asyncio.get_running_loop().getaddrinfo(host, port, type=socket.SOCK_STREAM)
    return [info[4][0] for info in infos]


class PageFetcher:
    def __init__(
        self,
        client: httpx2.AsyncClient,
        resolver: Resolver = system_resolver,
        min_interval: float = 1.0,
    ) -> None:
        self._client = client
        self._resolver = resolver
        self._min_interval = min_interval
        self._last_request: dict[str, float] = {}
        self._host_locks: dict[str, asyncio.Lock] = {}
        self._robots: dict[str, Protego] = {}

    async def fetch(self, url: str) -> FetchedPage:
        current = url
        for _ in range(MAX_REDIRECTS + 1):
            await self._check_url(current)
            await self._check_robots(current)
            response = await self._get(current)
            if response.is_redirect:
                location = response.headers.get("location")
                await response.aclose()
                if not location:
                    raise FetchError("http_error", "리다이렉트 대상이 없습니다.")
                current = urljoin(current, location)
                continue
            return FetchedPage(url=current, content=await self._read_html(response))
        raise FetchError("too_many_redirects", "리다이렉트가 너무 많습니다.")

    async def _check_url(self, url: str) -> None:
        parts = urlsplit(url)
        if parts.scheme not in ("http", "https") or not parts.hostname:
            raise FetchError("invalid_url", "http/https 주소만 가져올 수 있습니다.")
        if is_blocked_host(parts.hostname):
            raise FetchError("blocked_domain", "네이버 글은 가져오지 않습니다. 본문을 복사해 붙여넣어 주세요.")

        port = parts.port or (443 if parts.scheme == "https" else 80)
        try:
            addresses = await self._resolver(parts.hostname, port)
        except OSError as error:
            raise FetchError("dns_error", "주소를 찾을 수 없습니다.") from error
        if not addresses or not all(ipaddress.ip_address(a.split("%")[0]).is_global for a in addresses):
            raise FetchError("private_address", "내부 네트워크 주소는 가져올 수 없습니다.")

    async def _check_robots(self, url: str) -> None:
        parts = urlsplit(url)
        origin = f"{parts.scheme}://{parts.netloc}"
        parser = self._robots.get(origin)
        if parser is None:
            response = await self._get(origin + "/robots.txt")
            status = response.status_code
            body = (await response.aread())[:512 * 1024].decode("utf-8", "replace") if status == 200 else ""
            await response.aclose()
            if status >= 500:
                raise FetchError("upstream_error", "robots.txt를 확인하지 못했습니다.", transient=True)
            # 4xx(없음)는 제한 없음으로 본다. 표준 라이브러리 robotparser는 User-Agent를 부분 문자열로
            # 비교하고(예: "Fetch" 규칙이 우리에게 적용됨) 가장 긴 규칙 우선을 따르지 않아 RFC 9309 파서를 쓴다.
            parser = Protego.parse(body)
            self._robots[origin] = parser
        if not parser.can_fetch(url, ROBOTS_AGENT):
            raise FetchError("robots_disallowed", "이 사이트의 robots.txt가 수집을 허용하지 않습니다.")

    async def _get(self, url: str) -> httpx2.Response:
        host = urlsplit(url).hostname or ""
        async with self._host_locks.setdefault(host, asyncio.Lock()):
            wait = self._last_request.get(host, 0) + self._min_interval - time.monotonic()
            if wait > 0:
                await asyncio.sleep(wait)
            try:
                request = self._client.build_request("GET", url, headers={"User-Agent": USER_AGENT})
                return await self._client.send(request, stream=True, follow_redirects=False)
            except httpx2.TimeoutException as error:
                raise FetchError("timeout", "응답 시간이 초과됐습니다.", transient=True) from error
            except httpx2.HTTPError as error:
                raise FetchError("upstream_error", "페이지에 연결하지 못했습니다.", transient=True) from error
            finally:
                self._last_request[host] = time.monotonic()

    async def _read_html(self, response: httpx2.Response) -> bytes:
        try:
            if response.status_code >= 500:
                raise FetchError("upstream_error", f"사이트 오류 ({response.status_code})", transient=True)
            if response.status_code >= 400:
                raise FetchError("http_error", f"페이지를 가져오지 못했습니다 ({response.status_code}).")
            content_type = response.headers.get("content-type", "").split(";")[0].strip().lower()
            if content_type not in HTML_TYPES:
                raise FetchError("not_html", "웹페이지(HTML)가 아닙니다.")

            chunks: list[bytes] = []
            size = 0
            async for chunk in response.aiter_bytes():
                size += len(chunk)
                if size > MAX_BYTES:
                    raise FetchError("too_large", "페이지가 너무 큽니다(5MB 초과).")
                chunks.append(chunk)
            return b"".join(chunks)
        finally:
            await response.aclose()
