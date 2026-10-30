# Development image for the Laravel site and the queue worker.
FROM php:8.5.12-cli-trixie

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip curl libzip-dev libicu-dev \
    && docker-php-ext-install pdo_mysql zip intl bcmath pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.10 /usr/bin/composer /usr/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-khmer-dub.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Match the host user so files written to the bind mount stay editable.
ARG UID=1000
ARG GID=1000
RUN groupadd -g "${GID}" app && useradd -u "${UID}" -g app -m app

WORKDIR /var/www/html
COPY --chown=app:app . .
USER app
RUN composer install --no-interaction --prefer-dist --no-progress

EXPOSE 8000
ENTRYPOINT ["entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
