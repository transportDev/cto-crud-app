FROM php:8.2-fpm

# System deps for common Laravel/Filament needs
RUN apt-get update && apt-get install -y \
    git curl zip unzip \
    libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    libonig-dev libxml2-dev libzip-dev libicu-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl \
 && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Reasonable PHP defaults
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && { \
      echo "memory_limit=512M"; \
      echo "upload_max_filesize=64M"; \
      echo "post_max_size=64M"; \
      echo "opcache.enable=1"; \
      echo "opcache.validate_timestamps=1"; \
    } > /usr/local/etc/php/conf.d/override.ini

WORKDIR /var/www/html

# Entrypoint to bootstrap the app
COPY docker/php/init.sh /usr/local/bin/init.sh
RUN chmod +x /usr/local/bin/init.sh

ENTRYPOINT ["/usr/local/bin/init.sh"]