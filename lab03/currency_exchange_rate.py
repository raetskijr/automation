"""Get the exchange rate for a date from the local currency service and save it to data/.

Usage:
    python3 currency_exchange_rate.py EUR USD 2025-02-03 [--key KEY] [--url URL]
    python3 currency_exchange_rate.py --list
"""

import argparse
import json
import os
import string
import sys
from datetime import datetime
from pathlib import Path

import rate_api

# --- config ---
# all paths are relative to this file, not to the current directory
BASE_DIR = Path(__file__).resolve().parent
DATA_DIR = BASE_DIR / "data"
LOG_FILE = BASE_DIR / "error.log"
ENV_FILE = BASE_DIR / ".env"
DEFAULT_URL = "http://localhost:8080"
DEFAULT_KEY = "EXAMPLE_API_KEY"  # key of the local lab02prep service
DATE_MIN = "2025-01-01"
DATE_MAX = "2025-09-15"


def load_env(path):
    """KEY=VALUE lines of a .env file as a dict ({} if there is no file)."""
    values = {}
    if not path.is_file():
        return values
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        name, value = line.split("=", 1)
        values[name.strip()] = value.strip().strip("\"'")
    return values


def parse_args():
    """Command line: FROM TO DATE plus --key, --url, --list."""
    parser = argparse.ArgumentParser(
        description="Курс валют на дату через API, результат сохраняется в data/."
    )
    parser.add_argument("from_code", nargs="?", help="исходная валюта, например EUR")
    parser.add_argument("to_code", nargs="?", help="целевая валюта, например USD")
    parser.add_argument("date", nargs="?", help=f"дата YYYY-MM-DD ({DATE_MIN}..{DATE_MAX})")
    parser.add_argument("--key", help="API ключ (иначе API_KEY из окружения или .env)")
    parser.add_argument("--url", help=f"куда слать запросы, default: {DEFAULT_URL}")
    parser.add_argument("--list", action="store_true", help="показать доступные валюты")
    return parser.parse_args()


def resolve_settings(args, env):
    """Key and url by priority: CLI option, environment, .env, default."""
    key = args.key or os.environ.get("API_KEY") or env.get("API_KEY") or DEFAULT_KEY
    url = args.url or os.environ.get("API_URL") or env.get("API_URL") or DEFAULT_URL
    return key, url


# --- validation ---
def normalize_code(code):
    """Currency code -> 3 upper-case latin letters (ValueError otherwise)."""
    code = code.strip().upper()
    if len(code) != 3 or not set(code) <= set(string.ascii_uppercase):
        raise ValueError(f"код валюты должен состоять из 3 латинских букв: {code}")
    return code


def parse_day(text):
    """Date string -> YYYY-MM-DD inside DATE_MIN..DATE_MAX (ValueError otherwise)."""
    try:
        day = datetime.strptime(text, "%Y-%m-%d").date()
    except ValueError:
        raise ValueError(f"дата должна быть в формате YYYY-MM-DD: {text}") from None
    if not DATE_MIN <= day.isoformat() <= DATE_MAX:
        raise ValueError(f"дата вне диапазона {DATE_MIN}..{DATE_MAX}: {text}")
    return day.isoformat()


# --- storage ---
def make_filename(from_code, to_code, day):
    """File name for one result, e.g. rate_EUR_USD_2025-02-03.json."""
    return f"rate_{from_code}_{to_code}_{day}.json"


def save_json(data, path):
    """Dump data to path (indent 2); data/ is created on first save."""
    path.parent.mkdir(parents=True, exist_ok=True)
    with open(path, "w", encoding="utf-8") as file:
        json.dump(data, file, ensure_ascii=False, indent=2)


def log_error(kind, message):
    """Append one 'timestamp | kind | message' line to error.log."""
    stamp = datetime.now().isoformat(timespec="seconds")
    with open(LOG_FILE, "a", encoding="utf-8") as file:
        file.write(f"{stamp} | {kind} | {message}\n")


def fail(kind, message):
    """Error exit: message to stderr, line to error.log, code 1."""
    print(f"[ОШИБКА:{kind}] {message}", file=sys.stderr)
    log_error(kind, message)
    sys.exit(1)


# --- entry point ---
def main():
    """Validate input, ask the API, save the result."""
    args = parse_args()
    key, url = resolve_settings(args, load_env(ENV_FILE))

    if args.list:
        try:
            codes = rate_api.get_currencies(url, key)
        except rate_api.ApiError as err:
            fail(err.kind, str(err))
        print("Доступные валюты: " + ", ".join(codes))
        return

    if not (args.from_code and args.to_code and args.date):
        fail("ARGS", "нужны три аргумента: FROM TO YYYY-MM-DD (или --list)")

    try:
        from_code = normalize_code(args.from_code)
        to_code = normalize_code(args.to_code)
        day = parse_day(args.date)
    except ValueError as err:
        fail("ARGS", str(err))

    try:
        data = rate_api.get_rate(url, key, from_code, to_code, day)
    except rate_api.ApiError as err:
        fail(err.kind, str(err))

    path = DATA_DIR / make_filename(from_code, to_code, day)
    save_json(data, path)
    print(f"[OK] {from_code} -> {to_code} ({day}): {data.get('rate')}")
    print(f"[OK] сохранено: {path.relative_to(BASE_DIR)}")


if __name__ == "__main__":
    main()
