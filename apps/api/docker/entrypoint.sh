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
    # DB 구조를 바꾸기 직전에 백업을 남긴다(적용할 변경이 있을 때만). 실패해도 시작은 계속한다
    if su-exec www-data php artisan migrate:status --pending 2>/dev/null | grep -q Pending; then
        su-exec www-data php artisan app:backup --tag=before-migrate --keep=20 || true
    fi
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
