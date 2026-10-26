#!/bin/sh
# еженедельная задача: MDL -> USD за 7 прошлых дней (по одному курсу на день)

# --- параметры ---
# дата отсчёта: аргумент или сегодня (аргумент нужен для проверки)
BASE="${1:-$(date +%F)}"
URL="${API_URL:-http://php_apache}"

# --- запуск ---
echo "=== weekly $(date '+%F %T') | MDL -> USD | 7 дней до $BASE ==="
# от старого дня к новому, ошибка одного дня не останавливает остальные
status=0
for i in 7 6 5 4 3 2 1; do
    day=$(date -d "$BASE -$i day" +%F)
    /usr/bin/python3 /srv/lab03/currency_exchange_rate.py MDL USD "$day" --url "$URL" || status=1
done
exit $status
