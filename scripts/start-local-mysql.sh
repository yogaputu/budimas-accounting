#!/usr/bin/env bash

set -euo pipefail

mysql_base='/Users/Shared/DBngin/mysql/8.0.33'
mysql_home="$HOME/Library/Application Support/budimas-accounting/mysql-8.0.33"
data_dir="$mysql_home/data"
run_dir="$mysql_home/run"

if [[ ! -x "$mysql_base/bin/mysqld" ]]; then
    echo "MySQL 8.0.33 dari DBngin tidak ditemukan: $mysql_base" >&2
    exit 1
fi

if [[ ! -d "$data_dir/mysql" ]]; then
    echo "Data MySQL Accounting belum diinisialisasi: $data_dir" >&2
    exit 1
fi

if lsof -nP -iTCP:3308 -sTCP:LISTEN >/dev/null 2>&1; then
    echo 'MySQL Accounting sudah berjalan di 127.0.0.1:3308.'
    exit 0
fi

mkdir -p "$run_dir"

"$mysql_base/bin/mysqld" \
    --no-defaults \
    --basedir="$mysql_base" \
    --datadir="$data_dir" \
    --socket="$run_dir/mysql.sock" \
    --pid-file="$run_dir/mysql.pid" \
    --log-error="$run_dir/mysql.err" \
    --port=3308 \
    --bind-address=127.0.0.1 \
    --mysqlx=OFF \
    --skip-name-resolve \
    --daemonize

for _ in {1..10}; do
    if lsof -nP -iTCP:3308 -sTCP:LISTEN >/dev/null 2>&1; then
        echo 'MySQL Accounting siap di 127.0.0.1:3308.'
        exit 0
    fi

    sleep 1
done

tail -n 80 "$run_dir/mysql.err" >&2 || true
exit 1
