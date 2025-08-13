# syntax=docker/dockerfile:1.7
# Optimized multi-stage build for Laravel (PHP-FPM) with production optimizations
ARG PHP_VERSION=8.2
ARG NODE_VERSION=20

# ============================================
# Base PHP stage with pre-installed extensions
# ============================================
FROM php:${PHP_VERSION}-fpm-alpine AS php_base
WORKDIR /var/www/html

# Use BuildKit cache mounts for package installation
# Install system deps and PHP extensions in a single optimized layer
RUN --mount=type=cache,target=/var/cache/apk \
    --mount=type=cache,target=/usr/src/php/ext \
    --mount=type=cache,target=/root/.pecl \
    set -eux; \
    \
    # Install runtime dependencies
    apk add --no-cache \
        bash \
        curl \
        libpng \
        libjpeg-turbo \
        libwebp \
        libzip \
        icu-libs \
        oniguruma \
        shadow \
        tzdata \
        mysql-client; \
    \
    # Install build dependencies temporarily
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        libwebp-dev; \
    \
    # Configure and install PHP extensions in parallel
    docker-php-ext-configure gd \
        --with-jpeg \
        --with-webp; \
    \
    docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        gd \
        zip \
        intl \
        opcache \
        bcmath; \
    \
    # Install Redis via PECL with caching
    pecl install redis-5.3.7 && \
    docker-php-ext-enable redis; \
    \
    # Clean up build dependencies
    apk del .build-deps; \
    \
    # Clean up PECL temp files
    rm -rf /tmp/pear ~/.pearrc; \
    \
    # Fix permissions for www-data user
    usermod -u 1000 www-data 2>/dev/null || true; \
    groupmod -g 1000 www-data 2>/dev/null || true

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ============================================
# Composer dependencies stage
# ============================================
FROM php_base AS composer_deps
WORKDIR /app

# Copy only composer files first for better caching
COPY laravel/composer.json laravel/composer.lock ./

# Validate composer files
RUN composer validate --no-ansi --no-interaction --no-scripts

# Install dependencies with cache mount
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install \
        --no-dev \
        --prefer-dist \
        --no-ansi \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --no-autoloader \
        --ignore-platform-reqs

# Copy application code
COPY laravel/ ./

# Generate optimized autoloader after copying all files
RUN --mount=type=cache,target=/root/.composer/cache \
    composer dump-autoload \
        --no-dev \
        --optimize \
        --classmap-authoritative \
        --no-ansi \
        --no-interaction

# ============================================
# Node build stage for frontend assets
# ============================================
FROM node:${NODE_VERSION}-alpine AS node_build
WORKDIR /app

# Copy package files for better caching
COPY laravel/package*.json ./

# Install dependencies with cache
RUN --mount=type=cache,target=/root/.npm \
    npm ci --no-audit --no-fund

# Copy source files
COPY laravel/ ./

# Build production assets
RUN npm run build && \
    # Clean up node_modules to reduce size
    rm -rf node_modules

# ============================================
# Final production stage
# ============================================
FROM php_base AS app

WORKDIR /var/www/html

# Copy PHP configuration files
COPY docker/prod/php.ini /usr/local/etc/php/php.ini
COPY docker/prod/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Copy application code first (for better layer caching)
COPY laravel/ ./

# Copy optimized vendor from composer stage
COPY --from=composer_deps --chown=www-data:www-data /app/vendor ./vendor

# Copy built assets from node stage
COPY --from=node_build --chown=www-data:www-data /app/public/build ./public/build

# Create and set permissions for storage and cache directories
RUN set -eux; \
    # Create all necessary directories
    mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache; \
    \
    # Set ownership
    chown -R www-data:www-data storage bootstrap/cache; \
    \
    # Set directory permissions (775 for directories)
    find storage bootstrap/cache -type d -exec chmod 775 {} \;; \
    \
    # Set file permissions (664 for files)
    find storage bootstrap/cache -type f -exec chmod 664 {} \; 2>/dev/null || true; \
    \
    # Create .gitkeep files to preserve directory structure
    touch storage/logs/.gitkeep \
        storage/framework/cache/.gitkeep \
        storage/framework/sessions/.gitkeep \
        storage/framework/views/.gitkeep

# Copy and prepare scripts
COPY --chmod=755 docker/prod/healthcheck.sh /usr/local/bin/healthcheck.sh
COPY --chmod=755 docker/prod/php-entrypoint.sh /usr/local/bin/php-entrypoint.sh

# Pre-create Laravel cache to speed up first boot
RUN --mount=type=secret,id=app_key \
    set -eux; \
    # Only run if APP_KEY is provided via secret
    if [ -f /run/secrets/app_key ]; then \
        export APP_KEY=$(cat /run/secrets/app_key); \
        php artisan config:cache || true; \
        php artisan route:cache || true; \
        php artisan view:cache || true; \
        # Clear the caches since we don't have real env yet
        php artisan config:clear; \
    fi || true

# Health check configuration
HEALTHCHECK --interval=15s --timeout=5s --retries=10 \
    CMD ["/usr/local/bin/healthcheck.sh"]

# Switch to www-data user for security
USER www-data

# Set production environment variables
ENV APP_ENV=production \
    PHP_OPCACHE_ENABLE=1 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0 \
    PHP_FPM_PM_MAX_CHILDREN=20

# Set the entrypoint and default command
ENTRYPOINT ["/usr/local/bin/php-entrypoint.sh"]
CMD ["php-fpm", "-F"]