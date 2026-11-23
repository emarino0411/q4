#!/bin/sh
set -e

cd /var/www/html

# Use Railway volume for SQLite if available, otherwise use default path
if [ -n "$RAILWAY_VOLUME_MOUNT_PATH" ]; then
    mkdir -p "$RAILWAY_VOLUME_MOUNT_PATH"
    export DB_DATABASE="$RAILWAY_VOLUME_MOUNT_PATH/database.sqlite"
    touch "$DB_DATABASE"
    chown www-data:www-data "$DB_DATABASE"
fi

# Generate app key if not set
if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force
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
