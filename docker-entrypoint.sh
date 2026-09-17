##!/bin/sh
set -e

# 1. Read Render's assigned port, default to 8080 if not set
PORT="${PORT:-8080}"

# 2. Update Apache port configurations dynamically
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/g" /etc/apache2/sites-available/000-default.conf

# 3. Handle your SQLite persistent storage directories
mkdir -p "$(dirname "${SQLITE_DB_PATH:-/data/findit.sqlite}")" "${UPLOAD_PATH:-/data/uploads}"
chown -R www-data:www-data "$(dirname "${SQLITE_DB_PATH:-/data/findit.sqlite}")" "${UPLOAD_PATH:-/data/uploads}" || true
chmod -R 775 "$(dirname "${SQLITE_DB_PATH:-/data/findit.sqlite}")" "${UPLOAD_PATH:-/data/uploads}" || true

# 4. Start Apache web server
exec apache2-foreground
