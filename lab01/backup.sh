#!/bin/bash
# бэкап папки в tar.gz, всё просто

oops() { echo "$*"; exit 1; }

what="$1"
# куда кидаем, по умолчанию /backup
where="${2:-/backup}"

[ -z "$what" ] && oops "Эй, а что бэкапить? Пример: ./backup.sh ~/games/saves [куда]"

# слэш в конце не нужен, а то имя пустое
what="${what%/}"

[ -d "$what" ] || oops "Упс, папки \"$what\" нет. Проверь путь."
[ -d "$where" ] || oops "Папки \"$where\" нет. Создай её (sudo mkdir $where) и попробуй снова."

name="$(basename "$what")"
today="$(date +%d.%m.%Y)"
arch="$where/${name}-${today}.tar.gz"

tar -czf "$arch" -C "$(dirname "$what")" "$name" || oops "Что-то пошло не так с tar :("
echo "Готово! -> $arch"
