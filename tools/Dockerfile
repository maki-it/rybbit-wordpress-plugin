# Code quality tools for Integrate Rybbit.
# The repository is mounted at /app. Nothing is installed on the host.
FROM php:8.5-cli-alpine

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/tmp/composer \
    PATH=/tools/vendor/bin:$PATH

WORKDIR /tools
COPY composer.json ./
RUN composer install --no-interaction --no-progress --prefer-dist \
    && rm -rf /tmp/composer \
    && phpcs -i

WORKDIR /app
