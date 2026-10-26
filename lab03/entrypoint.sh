#!/bin/sh
# точка входа контейнера: окружение, лог, crontab, cron

# --- функции ---
# окружение контейнера для задач cron (cron его не наследует)
save_env() {
    env > /etc/environment
    echo "[entrypoint] окружение сохранено в /etc/environment"
}

# пустой лог, доступный на запись всем
create_log() {
    touch /var/log/cron.log
    chmod 666 /var/log/cron.log
    echo "[entrypoint] лог: /var/log/cron.log"
}

# расписание из cronjob в crontab root
install_jobs() {
    crontab /srv/lab03/cronjob
    echo "[entrypoint] crontab установлен"
    crontab -l | grep -v '^#'
}

# лог cron в stdout контейнера (docker logs)
monitor_logs() {
    tail -f /var/log/cron.log
}

# cron на переднем плане, pid 1
run_cron() {
    echo "[entrypoint] запуск cron"
    exec cron -f
}

# --- main ---
main() {
    save_env
    create_log
    install_jobs
    monitor_logs &
    run_cron
}

main "$@"
