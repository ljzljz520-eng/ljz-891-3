#!/usr/bin/env bash
# 本地启动内置服务器（生产请用 Nginx/Apache + PHP-FPM，文档根指向 public/）
set -euo pipefail
cd "$(dirname "$0")/.."
PHP_BIN="${PHP_BIN:-./.tools/php}"
[ -x "$PHP_BIN" ] || PHP_BIN="php"
HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-8080}"
echo "启动: http://${HOST}:${PORT}  (客户查询)"
echo "      http://${HOST}:${PORT}/admin/ (后台)"
exec "$PHP_BIN" -d error_reporting=E_ALL -S "${HOST}:${PORT}" -t public router.php
