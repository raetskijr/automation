# lab03: курс валют по расписанию (cron + docker)

## Что делает

Контейнер с cron по расписанию запускает скрипт из lab02 `currency_exchange_rate.py`:

| когда | cron | задача | что получает |
|---|---|---|---|
| каждый день в 06:00 | `0 6 * * *` | `jobs/daily.sh` | курс MDL -> EUR за вчера |
| пятница в 17:00 | `0 17 * * 5` | `jobs/weekly.sh` | курс MDL -> USD за каждый из 7 прошлых дней (пт..чт) |

Весь вывод задач (stdout и stderr) дописывается в `/var/log/cron.log`, результаты сохраняются в `data/` как в lab02.

Идея: `cronjob` говорит только «когда», обёртки в `jobs/` говорят «что». Поэтому в `cronjob` две короткие строки и нет ни одного `%` (даты считаются в обёртках, экранировать нечего).

## Схема

```text
cron (06:00 / пт 17:00)
 │
 ▼
jobs/daily.sh | jobs/weekly.sh          считают даты, печатают заголовок
 │
 ▼
currency_exchange_rate.py MDL EUR|USD DATE --url $API_URL
 │
 ▼
rate_api.get_rate ──► php_apache:80 (сеть lab02prep_default)
 │
 ├──► data/*.json                       (том rates-data)
 └──► stdout/stderr ──► /var/log/cron.log ──► tail -f ──► docker logs
```

Старт контейнера (`entrypoint.sh`):

```text
main
 ├─ save_env       env > /etc/environment   (cron не наследует окружение)
 ├─ create_log     touch + chmod 666 /var/log/cron.log
 ├─ install_jobs   crontab /srv/lab03/cronjob
 ├─ monitor_logs & tail -f /var/log/cron.log
 └─ run_cron       exec cron -f              (pid 1)
```

## Структура

```text
lab03/
├── .dockerignore
├── .env.example
├── .gitignore
├── Dockerfile
├── cronjob
├── currency_exchange_rate.py
├── docker-compose.yml
├── entrypoint.sh
├── jobs/
│   ├── daily.sh
│   └── weekly.sh
├── rate_api.py
├── readme.md
└── run_examples.sh
```

| файл | зачем |
|---|---|
| `cronjob` | расписание в формате пользовательского crontab, ставится в entrypoint |
| `jobs/daily.sh` | MDL -> EUR за день до даты отсчёта (по умолчанию сегодня) |
| `jobs/weekly.sh` | MDL -> USD за 7 дней до даты отсчёта, по порядку, ошибка дня не останавливает цикл |
| `entrypoint.sh` | окружение, лог, crontab, `tail -f`, `cron -f` (каждый шаг отдельной функцией) |
| `Dockerfile` | `ubuntu:24.04` + `cron python3 tzdata procps`, часовой пояс через build arg |
| `docker-compose.yml` | сборка, переменные, том `rates-data`, внешняя сеть `lab02prep_default` |
| `.dockerignore` | не тащить в образ `data/`, `error.log`, `.env`, `__pycache__/`, `readme.md` |
| `currency_exchange_rate.py` | скрипт из lab02 без изменений (CLI, проверка, сохранение) |
| `rate_api.py` | HTTP-слой из lab02 без изменений |
| `run_examples.sh` | прогон примеров из lab02 (в контейнере тоже есть) |
| `.env.example` | образец `.env` из lab02 (для запуска без docker) |
| `.gitignore` | `__pycache__/`, `.env` |

Python-зависимостей нет: скрипт только на стандартной библиотеке, поэтому pip и `requirements.txt` не нужны, хватает `python3` (3.12) из apt.

## Настройки

| переменная | где задаётся | значение |
|---|---|---|
| `API_URL` | `docker-compose.yml`, `environment` | `http://php_apache` |
| `API_KEY` | `docker-compose.yml`, `environment` | `${API_KEY:-EXAMPLE_API_KEY}` (можно передать из shell) |
| `TZ` | `docker-compose.yml`, `build.args` -> `Dockerfile` | `Europe/Chisinau` (симлинк `/etc/localtime` при сборке) |

Путь переменных до задачи: compose `environment` -> окружение контейнера -> `env > /etc/environment` в entrypoint -> pam_env при запуске задачи cron. `.env` в образ не копируется, встроенный в скрипт загрузчик `.env` в контейнере не участвует.

## Запуск

Нужен запущенный lab02prep (контейнер `php_apache` в сети `lab02prep_default`), иначе compose не найдёт внешнюю сеть.

```bash
cd lab03
docker compose up -d --build
```

```text
$ docker compose ps scheduler
NAME              IMAGE             COMMAND                  SERVICE     CREATED         STATUS         PORTS
rates-scheduler   lab03-scheduler   "/srv/lab03/entrypoi…"   scheduler   3 seconds ago   Up 3 seconds

$ docker compose logs scheduler
rates-scheduler  | [entrypoint] окружение сохранено в /etc/environment
rates-scheduler  | [entrypoint] лог: /var/log/cron.log
rates-scheduler  | [entrypoint] crontab установлен
rates-scheduler  | SHELL=/bin/sh
rates-scheduler  |
rates-scheduler  | 0 6 * * * /srv/lab03/jobs/daily.sh >> /var/log/cron.log 2>&1
rates-scheduler  | 0 17 * * 5 /srv/lab03/jobs/weekly.sh >> /var/log/cron.log 2>&1
rates-scheduler  | [entrypoint] запуск cron
```

## Проверка

Порядок как на схеме: от лога назад к cron.

1. что cron пишет в лог, видно в логах контейнера (их выводит `tail -f`):

   ```text
   $ docker compose logs -f scheduler
   ...
   rates-scheduler  | === daily 2026-10-26 03:24:07 | MDL -> EUR | 2025-09-14 ===
   rates-scheduler  | [OK] MDL -> EUR (2025-09-14): 19.487
   rates-scheduler  | [OK] сохранено: data/rate_MDL_EUR_2025-09-14.json
   ```

   или напрямую: `docker compose exec scheduler tail -n 20 /var/log/cron.log`.

2. обёртка `daily.sh` вручную, точная команда из `cronjob`, строка попадает в лог:

   ```text
   $ docker compose exec scheduler sh -c '/srv/lab03/jobs/daily.sh >> /var/log/cron.log 2>&1'
   $ docker compose exec scheduler tail -n 5 /var/log/cron.log
   === daily 2026-10-26 03:24:01 | MDL -> EUR | 2026-10-25 ===
   [ОШИБКА:ARGS] дата вне диапазона 2025-01-01..2025-09-15: 2026-10-25
   ```

   Ошибка ожидаемая, см. «Даты и ошибка диапазона». `weekly.sh` с реальной датой даёт 7 таких строк (2026-10-19..2026-10-25) и код выхода 1.

3. та же обёртка с датой отсчёта из 2025 года даёт 7 курсов за 2025-09-05..2025-09-11:

   ```text
   $ docker compose exec scheduler sh -c '/srv/lab03/jobs/weekly.sh 2025-09-12 >> /var/log/cron.log 2>&1'
   $ docker compose exec scheduler tail -n 15 /var/log/cron.log
   === weekly 2026-10-26 03:24:01 | MDL -> USD | 7 дней до 2025-09-12 ===
   [OK] MDL -> USD (2025-09-05): 16.7187
   [OK] сохранено: data/rate_MDL_USD_2025-09-05.json
   [OK] MDL -> USD (2025-09-06): 16.7187
   [OK] сохранено: data/rate_MDL_USD_2025-09-06.json
   [OK] MDL -> USD (2025-09-07): 16.7187
   [OK] сохранено: data/rate_MDL_USD_2025-09-07.json
   [OK] MDL -> USD (2025-09-08): 16.6978
   [OK] сохранено: data/rate_MDL_USD_2025-09-08.json
   [OK] MDL -> USD (2025-09-09): 16.6883
   [OK] сохранено: data/rate_MDL_USD_2025-09-09.json
   [OK] MDL -> USD (2025-09-10): 16.6409
   [OK] сохранено: data/rate_MDL_USD_2025-09-10.json
   [OK] MDL -> USD (2025-09-11): 16.6846
   [OK] сохранено: data/rate_MDL_USD_2025-09-11.json
   ```

   `daily.sh 2025-09-15` так же: `[OK] MDL -> EUR (2025-09-14): 19.487`.

4. расписание, которое запускает обёртки:

   ```text
   $ docker compose exec scheduler crontab -l
   # --- окружение ---
   SHELL=/bin/sh

   # --- расписание ---
   # каждый день в 06:00: MDL -> EUR за вчера
   0 6 * * * /srv/lab03/jobs/daily.sh >> /var/log/cron.log 2>&1
   # пятница 17:00: MDL -> USD за 7 прошлых дней (пт..чт)
   0 17 * * 5 /srv/lab03/jobs/weekly.sh >> /var/log/cron.log 2>&1
   ```

5. процесс cron:

   ```text
   $ docker compose top scheduler
   SERVICE    #   UID   PID   PPID  C   STIME  TTY  TIME      CMD
   scheduler  1   root  3062  3037  0   01:23  ?    00:00:00  cron -f
   scheduler  1   root  3093  3062  0   01:23  ?    00:00:00  /bin/sh /srv/lab03/entrypoint.sh
   scheduler  1   root  3094  3093  0   01:23  ?    00:00:00  tail -f /var/log/cron.log

   $ docker compose exec scheduler pgrep -a cron
   1 cron -f
   ```

   `cron -f` имеет pid 1, `tail -f` запускает фоновая функция `monitor_logs` (поэтому в её родителе виден `entrypoint.sh`). `STIME` в UTC.

6. часовой пояс, по которому cron считает 06:00 и 17:00:

   ```text
   $ docker compose exec scheduler date
   Mon Oct 26 03:23:56 EET 2026
   ```

7. результаты в томе `rates-data`:

   ```text
   $ docker compose exec scheduler ls data/
   rate_MDL_EUR_2025-09-14.json
   rate_MDL_USD_2025-09-05.json
   rate_MDL_USD_2025-09-06.json
   rate_MDL_USD_2025-09-07.json
   rate_MDL_USD_2025-09-08.json
   rate_MDL_USD_2025-09-09.json
   rate_MDL_USD_2025-09-10.json
   rate_MDL_USD_2025-09-11.json
   ```

Что именно cron (а не ручной запуск) видит переменные и сеть, можно проверить временной строкой каждую минуту (в репозиторий не входит):

```bash
docker compose exec scheduler sh -c '(cat /srv/lab03/cronjob; echo "* * * * * env > /tmp/env.txt; /srv/lab03/jobs/daily.sh 2025-09-15 >> /var/log/cron.log 2>&1") | crontab -'
# через минуту
docker compose exec scheduler cat /tmp/env.txt          # есть API_URL=http://php_apache и API_KEY=EXAMPLE_API_KEY
docker compose exec scheduler tail -n 3 /var/log/cron.log
# вернуть расписание
docker compose exec scheduler crontab /srv/lab03/cronjob
```

```text
=== daily 2026-10-26 03:25:01 | MDL -> EUR | 2025-09-14 ===
[OK] MDL -> EUR (2025-09-14): 19.487
[OK] сохранено: data/rate_MDL_EUR_2025-09-14.json
```

## Даты и ошибка диапазона

`daily.sh` и `weekly.sh` считают даты от сегодняшнего дня по часам контейнера, то есть в 2026 году, а у сервиса данные только за `2025-01-01..2025-09-15`. Скрипт из lab02 сам отклоняет даты вне диапазона (иначе сервис молча отдаёт последний курс), поэтому каждый настоящий запуск обёртки из cron пишет в `cron.log` заголовок и `[ОШИБКА:ARGS] дата вне диапазона ...`. Это ожидаемо: важно, что задача сработала и её вывод попал в лог.

Чтобы проверить ту же цепочку с успешным ответом, обёртки принимают необязательную дату отсчёта первым аргументом (`daily.sh 2025-09-15`, `weekly.sh 2025-09-12`). Без аргумента берётся сегодняшняя дата, в `cronjob` аргумента нет.

## Остановка

```bash
docker compose down          # контейнер, том rates-data остаётся
docker compose down -v       # вместе с томом (результаты удалятся)
docker compose down --rmi local -v   # ещё и собранный образ
```
