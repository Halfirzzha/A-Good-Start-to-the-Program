FROM alpine:3.20

RUN apk add --no-cache mysql-client bash tzdata

COPY docker/backup/backup.sh /usr/local/bin/backup.sh
COPY docker/backup/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/backup.sh /usr/local/bin/entrypoint.sh

CMD ["/usr/local/bin/entrypoint.sh"]
