#!/bin/sh
# ежедневная задача: MDL -> EUR за вчера

# --- параметры ---
# дата отсчёта: аргумент или сегодня (аргумент нужен для проверки)
BASE="${1:-$(date +%F)}"
DAY=$(date -d "$BASE -1 day" +%F)
URL="${API_URL:-http://php_apache}"

# --- запуск ---
echo "=== daily $(date '+%F %T') | MDL -> EUR | $DAY ==="
exec /usr/bin/python3 /srv/lab03/currency_exchange_rate.py MDL EUR "$DAY" --url "$URL"
