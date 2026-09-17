#!/bin/sh
set -e

PORT="${PORT:-8080}"

sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/g" /etc/apache2/sites-available/000-default.conf

mkdir -p "$(dirname "${SQLITE_DB_PATH:-/data/findit.sqlite}")" "${UPLOAD_PATH:-/data/uploads}"
chown -R www-data:www-data "$(dirname "${SQLITE_DB_PATH:-/data/findit.sqlite}")" "${UPLOAD_PATH:-/data/uploads}" || true
chmod -R 775 "$(dirname "${SQLITE_DB_PATH:-/data/findit.sqlite}")" "${UPLOAD_PATH:-/data/uploads}" || true

exec apache2-foreground
