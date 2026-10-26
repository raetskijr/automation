# --- база ---
FROM ubuntu:24.04
# часовой пояс задаётся при сборке (build arg)
ARG TZ=Europe/Chisinau
ARG DEBIAN_FRONTEND=noninteractive

# --- зависимости ---
# cron, python3 (только stdlib), tzdata, procps (pgrep для проверки)
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo "$TZ" > /etc/timezone \
 && apt-get update && apt-get install -y --no-install-recommends cron python3 tzdata procps \
 && rm -rf /var/lib/apt/lists/*

# --- файлы ---
WORKDIR /srv/lab03
COPY currency_exchange_rate.py rate_api.py run_examples.sh ./
COPY jobs/ ./jobs/
COPY cronjob entrypoint.sh ./
RUN chmod +x entrypoint.sh run_examples.sh jobs/*.sh

# --- запуск ---
ENTRYPOINT ["/srv/lab03/entrypoint.sh"]
