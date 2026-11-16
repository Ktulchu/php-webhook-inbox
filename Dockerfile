FROM php:8.3-cli-alpine

RUN apk add --no-cache postgresql-dev oniguruma-dev libxml2-dev \
    && docker-php-ext-install pdo_pgsql mbstring dom xml xmlwriter

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY . /app
RUN composer install --no-interaction --prefer-dist --no-progress

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
