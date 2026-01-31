#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR="/backups"
TIMESTAMP="$(date +'%Y%m%d_%H%M%S')"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-7}"

DB_HOST="${DB_HOST:-mysql}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_DATABASE:-db_creativetrees}"
DB_USER="${BACKUP_DB_USER:-root}"
DB_PASS="${BACKUP_DB_PASSWORD:-${DB_ROOT_PASSWORD:-root}}"

mkdir -p "${BACKUP_DIR}"

DUMP_FILE="${BACKUP_DIR}/${DB_NAME}_${TIMESTAMP}.sql.gz"

mysqldump \
  --host="${DB_HOST}" \
  --port="${DB_PORT}" \
  --user="${DB_USER}" \
  --password="${DB_PASS}" \
  --single-transaction \
  --quick \
  --routines \
  --events \
  "${DB_NAME}" | gzip > "${DUMP_FILE}"

# cleanup old backups
find "${BACKUP_DIR}" -type f -name "${DB_NAME}_*.sql.gz" -mtime +"${RETENTION_DAYS}" -delete
