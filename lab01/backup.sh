#!/bin/bash
# резервная копия папки в tar.gz

fail() { echo "$*" >&2; exit 1; }

what="$1"
# куда сохраняем, по умолчанию /backup
where="${2:-/backup}"

[ -z "$what" ] && fail "Не указана папка для резервной копии. Пример: ./backup.sh ~/games/saves [куда]"

# слэш в конце убираем, иначе имя архива будет пустым
what="${what%/}"

[ -d "$what" ] || fail "Папка \"$what\" не найдена. Проверьте путь."
[ -d "$where" ] || fail "Папка \"$where\" не найдена. Создайте её (sudo mkdir $where) и запустите скрипт снова."

name="$(basename "$what")"
today="$(date +%d.%m.%Y)"
arch="$where/${name}-${today}.tar.gz"

tar -czf "$arch" -C "$(dirname "$what")" "$name" || fail "Ошибка при создании архива."
echo "Готово: $arch"
