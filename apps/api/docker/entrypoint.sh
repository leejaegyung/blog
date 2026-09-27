#!/bin/sh
set -e

DB_FILE="${DB_DATABASE:-/data/database/blog-ai.sqlite}"

# 볼륨은 root 소유로 생성되므로 매 시작 시 권한을 맞춘다.
mkdir -p "$(dirname "$DB_FILE")" /data/uploads /data/backups \
    storage/app/public storage/framework/cache storage/framework/sessions \
    storage/framework/views storage/logs
[ -f "$DB_FILE" ] || touch "$DB_FILE"
chown -R www-data:www-data "$(dirname "$DB_FILE")" /data/uploads /data/backups storage bootstrap/cache

# 마이그레이션은 app 컨테이너에서만 실행한다(queue/scheduler와의 경합 방지).
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    su-exec www-data php artisan migrate --force
fi

if [ "$1" = "php-fpm" ]; then
    # 운영 환경에서만 설정/라우트 캐시를 만든다.
    if [ "${APP_ENV:-production}" = "production" ]; then
        su-exec www-data php artisan optimize
    fi
    exec "$@"
fi

exec su-exec www-data "$@"
