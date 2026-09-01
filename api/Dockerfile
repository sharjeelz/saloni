# Production/staging image for the Laravel API (nginx + php-fpm, Laravel-ready).
FROM serversideup/php:8.2-fpm-nginx AS base

# System deps for the pgsql PDO driver.
USER root
RUN install-php-extensions pdo_pgsql pgsql intl bcmath
USER www-data

WORKDIR /var/www/html

# Install PHP deps first for better layer caching.
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

# App code.
COPY --chown=www-data:www-data . .

# Finish the optimized autoloader. NOTE: we deliberately do NOT run
# `php artisan config:cache` here — at build time there is no .env, so it would
# bake empty/default config (wrong APP_URL, DB creds, keys) into the image.
# config/route/view caching runs at DEPLOY time, after .env is in place
# (see .github/workflows/deploy-staging.yml).
RUN composer dump-autoload --optimize

# serversideup base serves public/ on :8080 and runs php-fpm; no CMD needed.
EXPOSE 8080
