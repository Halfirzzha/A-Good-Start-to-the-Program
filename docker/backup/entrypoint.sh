#!/usr/bin/env sh
set -e

SCHEDULE="${BACKUP_SCHEDULE:-0 2 * * *}"

cat <<CRON > /etc/crontabs/root
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
${SCHEDULE} /usr/local/bin/backup.sh >> /var/log/backup.log 2>&1
CRON

exec crond -f -l 2
