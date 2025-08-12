# syntax=docker/dockerfile:1.7
# Multi-stage build for Laravel (PHP-FPM) with production optimizations

ARG PHP_VERSION=8.2
ARG NODE_VERSION=20

# 🔧 FIX: Base PHP stage FIRST with all extensions
FROM php:${PHP_VERSION}-fpm-alpine AS php_base
WORKDIR /var/www/html

# Install ALL system deps and PHP extensions first
RUN set -eux; \
    apk add --no-cache bash curl libpng libjpeg-turbo libwebp libzip icu-libs \
        oniguruma shadow tzdata mysql-client; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        autoconf \
        dpkg-dev \
        dpkg \
        file \
        g++ \
        gcc \
        libc-dev \
        make \
        pkgconf \
        re2c \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        libwebp-dev; \
    docker-php-ext-configure gd --with-jpeg --with-webp; \
    docker-php-ext-install -j$(nproc) pdo_mysql gd zip intl opcache bcmath; \
    pecl install redis && docker-php-ext-enable redis; \
    apk del .build-deps; \
    usermod -u 1000 www-data || true && groupmod -g 1000 www-data || true

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 🔧 FIX: Composer stage now inherits from php_base (with extensions)
FROM php_base AS composer_base
WORKDIR /app

# Copy composer files
COPY laravel/composer.json laravel/composer.lock ./
RUN composer validate --no-ansi --no-interaction --no-scripts

# Install with dev deps first for proper dependency resolution
RUN composer install --prefer-dist --no-ansi --no-interaction --no-progress --no-scripts --no-plugins

# Copy full app for autoload generation
COPY laravel/ ./

# Now optimize for production (remove dev deps)
RUN composer install --no-dev --prefer-dist --no-ansi --no-interaction --no-progress --no-scripts --no-plugins --optimize-autoloader

# Node build stage for frontend assets (unchanged)
FROM node:${NODE_VERSION}-alpine AS node_build
WORKDIR /app
COPY laravel/package.json laravel/package-lock.json ./
RUN npm ci --include=dev
COPY laravel/ ./
RUN npm run build

# Final app stage
FROM php_base AS app
WORKDIR /var/www/html

# Copy PHP configuration
COPY docker/prod/php.ini /usr/local/etc/php/php.ini
COPY docker/prod/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Copy app code
COPY laravel/ ./
# Copy optimized vendor from composer stage
COPY --from=composer_base /app/vendor ./vendor
# Copy built assets from node stage
COPY --from=node_build /app/public/build ./public/build

# Ensure storage and bootstrap cache are writable
RUN set -eux; \
    mkdir -p storage bootstrap/cache; \
    chown -R www-data:www-data storage bootstrap/cache; \
    find storage -type d -exec chmod 775 {} \; ; \
    find storage -type f -exec chmod 664 {} \; ; \
    chmod -R 775 bootstrap/cache

# Copy scripts
COPY docker/prod/healthcheck.sh /usr/local/bin/healthcheck.sh
RUN chmod +x /usr/local/bin/healthcheck.sh

COPY docker/prod/php-entrypoint.sh /usr/local/bin/php-entrypoint.sh
RUN chmod +x /usr/local/bin/php-entrypoint.sh

HEALTHCHECK --interval=15s --timeout=5s --retries=10 CMD ["/usr/local/bin/healthcheck.sh"]

USER www-data

ENV APP_ENV=production \
    PHP_FPM_PM=max_children=20

ENTRYPOINT ["/usr/local/bin/php-entrypoint.sh"]
CMD ["php-fpm", "-F"]