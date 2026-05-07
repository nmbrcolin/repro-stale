FROM php:8.5-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libzip-dev zlib1g-dev libxml2-dev \
        default-mysql-client \
    && docker-php-ext-install -j$(nproc) pdo_mysql bcmath intl pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

WORKDIR /var/www

# Install vendor inside the image so cold start is fast.
# At runtime the host source is bind-mounted over /var/www, but vendor
# lives in a named volume, so this baked-in vendor is the seed.
COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize
