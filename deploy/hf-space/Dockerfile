# Hugging Face Space (free CPU, Docker): the whole Khmer Dub site in one container.
# Build context: the folder deploy/hf-space/build.sh assembles (build/hf-space). Not used by compose.yaml.
# Keep the base image tags and TORCH_VERSION in step with web/Dockerfile and worker/Dockerfile.

# --- Stage 1: PHP 8.5 with the site's extensions and Composer packages ---
FROM php:8.5.12-cli-trixie AS php

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libicu-dev \
    && docker-php-ext-install zip intl bcmath pcntl \
    && rm -rf /var/lib/apt/lists/*

# The Debian packages PHP and its extensions load at run time, so the final image installs only those.
RUN { ldd /usr/local/bin/php; find /usr/local/lib/php/extensions -name '*.so' -exec ldd {} \; ; } \
    | awk '/=> \//{print $3}' | sort -u | xargs -r realpath | xargs -r dpkg-query -S 2>/dev/null \
    | cut -d: -f1 | sort -u > /php-packages.txt

COPY --from=composer:2.10 /usr/bin/composer /usr/bin/composer
WORKDIR /app/web
COPY web/composer.json web/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader
COPY web/ ./
# Also rebuilds bootstrap/cache/packages.php without the dev packages.
RUN composer dump-autoload --optimize --no-dev --no-interaction

# --- Stage 2: Python 3.14 worker, with PHP copied in ---
FROM python:3.14.10-slim-trixie

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    HF_HOME=/opt/models \
    TORCH_HOME=/opt/models/torch

COPY --from=php /php-packages.txt /tmp/php-packages.txt
RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg curl $(cat /tmp/php-packages.txt) \
    && rm -rf /var/lib/apt/lists/* /tmp/php-packages.txt

COPY --from=php /usr/local/bin/php /usr/local/bin/php
COPY --from=php /usr/local/lib/php /usr/local/lib/php
COPY --from=php /usr/local/etc/php /usr/local/etc/php
COPY web/docker/php.ini /usr/local/etc/php/conf.d/zz-khmer-dub.ini

# CPU-only PyTorch first, so the other packages do not pull the large CUDA build.
ARG TORCH_VERSION=2.15.0
RUN pip install "torch==${TORCH_VERSION}" --index-url https://download.pytorch.org/whl/cpu
COPY worker/requirements.txt /tmp/requirements.txt
RUN pip install -r /tmp/requirements.txt && rm /tmp/requirements.txt

# Hugging Face runs the container as user 1000.
RUN useradd -u 1000 -m user && mkdir -p /data /opt/models && chown user:user /data /opt/models

COPY --chown=user:user worker/ /app/worker/
COPY --chown=user:user preload.py /app/preload.py
USER user

# Download every model now, so the Space starts without downloads (about 1.5 GB in the image).
ARG WHISPER_MODEL=small
ENV WHISPER_MODEL=${WHISPER_MODEL}
RUN python /app/preload.py

COPY --from=php --chown=user:user /app/web /app/web
COPY --chown=user:user start.sh /app/start.sh

# Settings without secrets. start.sh makes the token and APP_KEY; Space variables override any of these.
ENV APP_NAME="Khmer Dub" \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/db/database.sqlite \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=database \
    DB_QUEUE_RETRY_AFTER=3800 \
    MAIL_MAILER=log \
    DUBBER_URL=http://127.0.0.1:8001 \
    TRUSTED_PROXIES=* \
    SESSION_SECURE_COOKIE=true \
    SESSION_SAME_SITE=none \
    SESSION_PARTITIONED_COOKIE=true \
    WHISPER_DEVICE=cpu \
    TTS_ENGINE=edge \
    WORK_DIR=/data/jobs \
    MAX_VIDEO_MINUTES=15 \
    ORIGINAL_VOLUME=0.15 \
    KHMER_SPACES=keep

WORKDIR /app
EXPOSE 7860
CMD ["bash", "/app/start.sh"]
