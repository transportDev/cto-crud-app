FROM php:8.2-fpm

# Proxy support (optional)
ARG HTTP_PROXY
ARG HTTPS_PROXY
ARG NO_PROXY

ENV HTTP_PROXY=${HTTP_PROXY} \
    HTTPS_PROXY=${HTTPS_PROXY} \
    NO_PROXY=${NO_PROXY} \
    http_proxy=${HTTP_PROXY} \
    https_proxy=${HTTPS_PROXY} \
    no_proxy=${NO_PROXY}

# If proxy is set, tell apt to use it
RUN if [ -n "$HTTP_PROXY" ] || [ -n "$HTTPS_PROXY" ]; then \
      echo "Acquire::http::Proxy \"${HTTP_PROXY}\";"  > /etc/apt/apt.conf.d/00proxy; \
      echo "Acquire::https::Proxy \"${HTTPS_PROXY}\";" >> /etc/apt/apt.conf.d/00proxy; \
    fi

# System deps for Laravel/Filament
RUN apt-get update && apt-get install -y \
    git curl zip unzip \
    libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    libonig-dev libxml2-dev libzip-dev libicu-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl \
 && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# PHP defaults
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && { \
      echo "memory_limit=512M"; \
      echo "upload_max_filesize=64M"; \
      echo "post_max_size=64M"; \
      echo "opcache.enable=1"; \
      echo "opcache.validate_timestamps=1"; \
    } > /usr/local/etc/php/conf.d/override.ini

WORKDIR /var/www/html

CMD ["php-fpm", "-F"]