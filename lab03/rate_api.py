"""HTTP layer: talks to the currency service, knows nothing about CLI or files."""

import json
import socket
import urllib.error
import urllib.parse
import urllib.request


class ApiError(Exception):
    """Request or response problem; kind is NETWORK, HTTP, RESPONSE or API."""

    def __init__(self, kind, message):
        super().__init__(message)
        self.kind = kind


def build_url(base_url, from_code, to_code, day):
    """Build the request URL with from/to/date query params."""
    query = urllib.parse.urlencode({"from": from_code, "to": to_code, "date": day})
    return f"{base_url.rstrip('/')}/?{query}"


def post_form(url, fields, timeout=10):
    """Send fields as a form POST; gives back the body as text."""
    body = urllib.parse.urlencode(fields).encode()
    host = url.split("/?")[0]  # short form for messages
    request = urllib.request.Request(url, data=body, method="POST")
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            if response.status != 200:
                raise ApiError("HTTP", f"сервер ответил {response.status}")
            return response.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as err:
        # HTTPError is a subclass of URLError, so it goes first
        raise ApiError("HTTP", f"сервер ответил {err.code} {err.reason}") from err
    except (urllib.error.URLError, socket.timeout) as err:
        # connect timeout comes wrapped in URLError, read timeout comes bare
        reason = getattr(err, "reason", err)
        if isinstance(reason, socket.timeout):
            raise ApiError("NETWORK", f"таймаут запроса: {host}") from err
        raise ApiError("NETWORK", f"сервер недоступен: {host} ({reason})") from err


def parse_response(text):
    """Raw text -> data part of the answer; error field becomes ApiError."""
    try:
        payload = json.loads(text)
    except json.JSONDecodeError as err:
        raise ApiError("RESPONSE", "ответ сервера не JSON") from err
    if not isinstance(payload, dict):
        raise ApiError("RESPONSE", "неожиданный формат ответа")
    if payload.get("error"):
        raise ApiError("API", str(payload["error"]))
    if payload.get("data") is None:
        raise ApiError("RESPONSE", "в ответе нет поля data")
    return payload["data"]


def get_rate(base_url, key, from_code, to_code, day):
    """One rate: url -> POST -> parsed data (from, to, rate, date)."""
    url = build_url(base_url, from_code, to_code, day)
    return parse_response(post_form(url, {"key": key}))


def get_currencies(base_url, key):
    """Currency codes known to the service (for --list)."""
    url = f"{base_url.rstrip('/')}/?currencies"
    return parse_response(post_form(url, {"key": key}))
