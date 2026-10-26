# lab02 — курс валют через API

## Что делает

Скрипт `currency_exchange_rate.py` берёт из командной строки две валюты и дату, отправляет запрос к сервису курсов (POST, ключ в теле запроса) и сохраняет полученные данные в `data/` в виде JSON. Имя файла содержит обе валюты и дату: `rate_EUR_USD_2025-02-03.json`.

До запроса аргументы проверяются: код валюты из 3 латинских букв (регистр не важен, приводится к верхнему), дата в формате `YYYY-MM-DD` и в диапазоне `2025-01-01..2025-09-15` (за пределами диапазона сервис молча отдаёт последний курс, поэтому такие даты не пропускаются).

Любая ошибка (аргументы, сеть, HTTP, не-JSON ответ, поле `error` от API) выводится в stderr, дописывается строкой в `error.log`, скрипт выходит с кодом 1.

`data/` и `error.log` всегда лежат рядом со скриптом, из какой бы папки его ни запускали.

## Зависимости

Только стандартная библиотека Python 3.8+, ставить ничего не нужно (HTTP через `urllib`).

Нужен запущенный сервис курсов на `http://localhost:8080`.

Откуда берутся ключ и адрес сервиса (сверху вниз, первое найденное побеждает):

| приоритет | источник | ключ | адрес |
|---|---|---|---|
| 1 | командная строка | `--key` | `--url` |
| 2 | окружение | `API_KEY` | `API_URL` |
| 3 | `lab02/.env` (образец в `.env.example`) | `API_KEY=` | `API_URL=` |
| 4 | зашито в скрипт под локальный lab02prep | `EXAMPLE_API_KEY` | `http://localhost:8080` |

```bash
cp .env.example .env   # необязательно
```

## Запуск

```bash
python3 currency_exchange_rate.py FROM TO YYYY-MM-DD [--key KEY] [--url URL]
python3 currency_exchange_rate.py --list      # какие валюты знает сервис
python3 currency_exchange_rate.py -h
```

Примеры:

```text
$ python3 currency_exchange_rate.py EUR USD 2025-02-03
[OK] EUR -> USD (2025-02-03): 0.9638569104351588
[OK] сохранено: data/rate_EUR_USD_2025-02-03.json

$ python3 lab02/currency_exchange_rate.py mdl uah 2025-03-31    # из корня репо, регистр не важен
[OK] MDL -> UAH (2025-03-31): 0.4352
[OK] сохранено: data/rate_MDL_UAH_2025-03-31.json

$ python3 currency_exchange_rate.py --list
Доступные валюты: MDL, USD, EUR, RON, RUS, UAH
```

Файл в `data/` содержит только объект `data` из ответа:

```json
{
  "from": "EUR",
  "to": "USD",
  "rate": 0.9638569104351588,
  "date": "2025-02-03"
}
```

## Примеры

```bash
./run_examples.sh
```

Скрипт делает:

- EUR -> USD на 6 дат с шагом 28 дней (все понедельники): 2025-02-03, 2025-03-03, 2025-03-31, 2025-04-28, 2025-05-26, 2025-06-23;
- MDL -> UAH на 2025-03-31;
- `--list`;
- 5 ошибочных вызовов (каждый с `|| true`, чтобы прогон шёл дальше).

Результаты (лежат в `data/`):

| файл | курс |
|---|---|
| `rate_EUR_USD_2025-02-03.json` | 0.9638569104351588 |
| `rate_EUR_USD_2025-03-03.json` | 0.961674848574913 |
| `rate_EUR_USD_2025-03-31.json` | 0.9281604023428861 |
| `rate_EUR_USD_2025-04-28.json` | 0.8786577810863591 |
| `rate_EUR_USD_2025-05-26.json` | 0.881212077986255 |
| `rate_EUR_USD_2025-06-23.json` | 0.8677566613129587 |
| `rate_MDL_UAH_2025-03-31.json` | 0.4352 |

Ошибочные вызовы дали 5 строк в `error.log` (см. ниже).

## Структура

```text
lab02/
├── currency_exchange_rate.py   # CLI: аргументы, проверка, сохранение, лог
├── rate_api.py                 # HTTP: запрос к сервису и разбор ответа
├── run_examples.sh             # прогон примеров и ошибок
├── .env.example                # образец настроек (API_KEY, API_URL)
├── .gitignore
├── data/                       # результаты rate_FROM_TO_DATE.json
└── error.log                   # лог ошибок
```

Поток:

```text
argv
 │
 ▼
parse_args ──► resolve_settings (--key > env > .env > default)
 │
 ▼
normalize_code / parse_day ──(ValueError)───────────────┐
 │                                                      │
 ▼                                                      │
rate_api.get_rate                                       │
 │  build_url → post_form → parse_response ──(ApiError)─┤
 ▼                                                      ▼
make_filename → save_json → data/*.json        fail → stderr + error.log, exit 1
```

Модули и функции:

| модуль | функция | что делает |
|---|---|---|
| `rate_api.py` | `ApiError` | исключение с видом ошибки (`NETWORK`, `HTTP`, `RESPONSE`, `API`) |
| | `build_url` | собирает URL с `from`, `to`, `date` |
| | `post_form` | POST с полями формы, возвращает текст; сеть/таймаут/HTTP → `ApiError` |
| | `parse_response` | JSON, проверка поля `error` и наличия `data` |
| | `get_rate` | курс на дату: `build_url` + `post_form` + `parse_response` |
| | `get_currencies` | список валют сервиса |
| `currency_exchange_rate.py` | `load_env` | читает `KEY=VALUE` из `.env`, пропускает `#` |
| | `parse_args` | argparse: 3 позиционных + `--key`, `--url`, `--list` |
| | `resolve_settings` | выбирает ключ и адрес по приоритету |
| | `normalize_code` | 3 латинские буквы, приводит к верхнему регистру |
| | `parse_day` | формат `YYYY-MM-DD` и диапазон 2025-01-01..2025-09-15 |
| | `make_filename` | `rate_{FROM}_{TO}_{DATE}.json` |
| | `save_json` | создаёт `data/` и пишет JSON |
| | `log_error` | дописывает строку `время \| вид \| сообщение` в `error.log` |
| | `fail` | stderr + `log_error` + `exit 1` |
| | `main` | связывает всё вместе |

## Ошибки

| вид | когда | пример сообщения |
|---|---|---|
| `ARGS` | плохие аргументы | `[ОШИБКА:ARGS] дата вне диапазона 2025-01-01..2025-09-15: 2025-10-01` |
| `NETWORK` | сервер недоступен, таймаут | `[ОШИБКА:NETWORK] сервер недоступен: http://localhost:1 ([Errno 61] Connection refused)` |
| `HTTP` | код ответа не 200 | `[ОШИБКА:HTTP] сервер ответил 404 Not Found` |
| `RESPONSE` | ответ не JSON или нет `data` | `[ОШИБКА:RESPONSE] ответ сервера не JSON` |
| `API` | непустое поле `error` | `[ОШИБКА:API] Unknown currency rus` |

`error.log` после `./run_examples.sh`:

```text
2026-10-26T02:16:41 | API | Unknown currency rus
2026-10-26T02:16:41 | API | Invalid API key
2026-10-26T02:16:42 | ARGS | дата вне диапазона 2025-01-01..2025-09-15: 2025-10-01
2026-10-26T02:16:42 | ARGS | код валюты должен состоять из 3 латинских букв: EU
2026-10-26T02:16:42 | NETWORK | сервер недоступен: http://localhost:1 ([Errno 61] Connection refused)
```
