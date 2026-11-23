#!/bin/sh
set -e

cd /var/www/html

# Use Railway volume for SQLite if available
if [ -n "$RAILWAY_VOLUME_MOUNT_PATH" ]; then
    mkdir -p "$RAILWAY_VOLUME_MOUNT_PATH"
    DB_DATABASE="$RAILWAY_VOLUME_MOUNT_PATH/database.sqlite"
    touch "$DB_DATABASE"
    chown www-data:www-data "$DB_DATABASE"
else
    DB_DATABASE="${DB_DATABASE:-/data/database.sqlite}"
    mkdir -p "$(dirname $DB_DATABASE)"
    touch "$DB_DATABASE"
    chown www-data:www-data "$DB_DATABASE"
fi

# Always regenerate .env from environment variables
{
    echo "APP_NAME=\"${APP_NAME:-C4}\""
    echo "APP_ENV=\"${APP_ENV:-production}\""
    echo "APP_KEY=\"${APP_KEY:-}\""
    echo "APP_DEBUG=\"${APP_DEBUG:-false}\""
    echo "APP_URL=\"${APP_URL:-http://localhost}\""
    echo ""
    echo "LOG_CHANNEL=\"${LOG_CHANNEL:-stderr}\""
    echo "LOG_LEVEL=\"${LOG_LEVEL:-error}\""
    echo ""
    echo "DB_CONNECTION=\"${DB_CONNECTION:-sqlite}\""
    echo "DB_DATABASE=\"$DB_DATABASE\""
    echo ""
    echo "SESSION_DRIVER=\"${SESSION_DRIVER:-file}\""
    echo "SESSION_LIFETIME=\"${SESSION_LIFETIME:-120}\""
    echo ""
    echo "CACHE_STORE=\"${CACHE_STORE:-file}\""
    echo "QUEUE_CONNECTION=\"${QUEUE_CONNECTION:-sync}\""
    echo ""
    echo "FILESYSTEM_DISK=\"${FILESYSTEM_DISK:-local}\""
    echo "BROADCAST_CONNECTION=\"${BROADCAST_CONNECTION:-log}\""
    echo ""
    echo "MAIL_MAILER=\"${MAIL_MAILER:-log}\""
    echo "MAIL_FROM_ADDRESS=\"${MAIL_FROM_ADDRESS:-hello@example.com}\""
    echo "MAIL_FROM_NAME=\"${APP_NAME:-C4}\""
} > .env

# Generate app key if not set
if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force
    # Re-export the generated key
    APP_KEY=$(grep APP_KEY .env | cut -d '=' -f2-)
fi

# Run migrations
php artisan migrate --force

# Cache config/routes/views for performance
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Fix storage permissions
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Create storage symlink
php artisan storage:link --force 2>/dev/null || true

# Start supervisor (nginx + php-fpm)
mkdir -p /var/log/supervisor
exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
