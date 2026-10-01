#!/usr/bin/env bash


set -e
set -u
set -o pipefail

src_dir="$1"
dest_dir="${2:-/backup}"

function fail() {
    echo "[ОШИБКА] $1" >&2
    exit 1
}



if [ -z "${src_dir}" ]; then
    fail "не указана директория для резервного копирования. Использование: $0 <src> [dest]"
fi

if [ ! -d "${src_dir}" ]; then
    fail "директория '${src_dir}' не найдена."
fi

if [ ! -d "${dest_dir}" ]; then
    echo "[ИНФО] Директория '${dest_dir}' отсутствует, создаю её..."
    mkdir -p "${dest_dir}" || fail "не удалось создать директорию '${dest_dir}'."
fi

if [ ! -w "${dest_dir}" ]; then
    fail "нет прав на запись в директорию '${dest_dir}'."
fi



src_dir="${src_dir%/}"
folder_name=$(basename "${src_dir}")
parent_dir=$(dirname "${src_dir}")

today=$(date "+%Y%m%d_%H%M%S")
archive_file="${dest_dir%/}/backup-${folder_name}-${today}.tar.gz"

echo "[ИНФО] Архивирую '${src_dir}' -> '${archive_file}'"

tar -czf "${archive_file}" -C "${parent_dir}" "${folder_name}"
status=$?

if [ ${status} -eq 0 ]; then
    echo "[ГОТОВО] Резервная копия создана: ${archive_file}"
else
    rm -f "${archive_file}" 2>/dev/null
    fail "не удалось создать архив."
fi
