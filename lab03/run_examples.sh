#!/bin/bash
# прогон примеров: 6 дат через каждые 28 дней + ошибочные случаи
cd "$(dirname "$0")"

echo "== EUR -> USD, каждые 28 дней =="
for d in 2025-02-03 2025-03-03 2025-03-31 2025-04-28 2025-05-26 2025-06-23; do
    python3 currency_exchange_rate.py EUR USD "$d"
done

echo "== MDL -> UAH =="
python3 currency_exchange_rate.py MDL UAH 2025-03-31

echo "== список валют =="
python3 currency_exchange_rate.py --list

echo "== ошибочные случаи (должны упасть) =="
python3 currency_exchange_rate.py RUS MDL 2025-02-03 || true
python3 currency_exchange_rate.py EUR USD 2025-02-03 --key wrong || true
python3 currency_exchange_rate.py EUR USD 2025-10-01 || true
python3 currency_exchange_rate.py EU USD 2025-02-03 || true
python3 currency_exchange_rate.py EUR USD 2025-02-03 --url http://localhost:1 || true
