FROM php:8.4-cli-alpine

# Собираем обязательные расширения Redis и librdkafka для transport и интеграции.
RUN apk add --no-cache $PHPIZE_DEPS librdkafka-dev linux-headers \
    && pecl install redis rdkafka \
    && docker-php-ext-enable redis rdkafka \
    && apk del $PHPIZE_DEPS
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json ./
RUN composer install --no-interaction --prefer-dist
COPY . .
CMD ["php", "bin/console", "messenger:consume", "async", "--time-limit=3600"]
