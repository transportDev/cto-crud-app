# PHP 8.3 FPM on Alpine with required extensions, Composer, and optional Xdebug toggle
FROM php:8.3-fpm-alpine

LABEL maintainer="Your Team <you@example.com>"

# Build args for default user/group ids (can be overridden at runtime)
ARG PUID=1000
ARG PGID=1000

# Environment defaults
ENV PUID=${PUID} \
    PGID=${PGID} \
    TZ=UTC \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    XDEBUG=0 \
    XDEBUG_MODE=off

# System packages and build deps
RUN apk add --no-cache \
      bash git curl unzip tzdata su-exec shadow openssl \
      linux-headers \
      icu icu-dev \
      libzip libzip-dev \
      libpng libpng-dev \
      libjpeg-turbo libjpeg-turbo-dev \
      freetype freetype-dev \
      oniguruma oniguruma-dev \
      libxml2 libxml2-dev \
      autoconf make g++ \
      mysql-client

# PHP extensions
# - gd with freetype & jpeg
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        bcmath \
        intl \
        gd \
        exif \
        zip \
        pcntl \
        opcache

# Xdebug via PECL (installed but not enabled by default)
RUN pecl install xdebug \
  && mkdir -p /usr/local/etc/php/conf.d /usr/local/etc/php/custom

# Composer from official image
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Create app user/group to match host uid/gid (for bind-mounted permissions)
RUN addgroup -g ${PGID} laravel || true \
  && adduser -D -G laravel -u ${PUID} laravel || true

WORKDIR /var/www/html

# PHP configuration (dev) and Xdebug template (enabled only when XDEBUG=1)
COPY conf/php.ini /usr/local/etc/php/conf.d/zzz-dev.ini
COPY conf/xdebug.ini /usr/local/etc/php/xdebug.ini
# PHP-FPM configuration (log to /dev/stderr, non-root-friendly)
COPY conf/php-fpm.conf /usr/local/etc/php-fpm.conf
COPY conf/pool.d/ /usr/local/etc/php-fpm.d/

# Entrypoint does idempotent bootstrap and toggles xdebug on demand
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

# Note: bin/wait-for will be available from the project bind mount at /var/www/html/bin/wait-for
ENTRYPOINT ["/bin/sh", "/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "-F"]