# syntax=docker/dockerfile:1
#
# One image runs everything: the web server, the queue worker and the scheduler (compose
# starts it with a different command). Build for the server's CPU:
#   docker build --platform linux/amd64 -t pixelforge-app:<commit> .
# The build context must be a clean `git archive HEAD` (never the working tree).

# ---- base: PHP 8.3 + FrankenPHP. FrankenPHP is Caddy with PHP inside it: ONE process serves
# HTTP and runs PHP, so there is no php-fpm, no nginx, no supervisor to explain.
FROM dunglas/frankenphp:1-php8.3 AS base
RUN install-php-extensions pdo_pgsql bcmath intl opcache
WORKDIR /var/www/html

# ---- vendor: PHP dependencies without dev packages. Composer only exists in this stage.
FROM base AS vendor
RUN install-php-extensions zip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
# Writes the optimized autoloader and runs package:discover (the post-autoload-dump scripts).
RUN composer dump-autoload --optimize --no-dev --no-interaction

# ---- assets: Vite build. It needs PHP as well as Node: the wayfinder plugin runs
# `php artisan wayfinder:generate` to make the TypeScript route helpers, so this stage is the
# PHP stage above plus Node 22 (copied from the official image).
FROM vendor AS assets
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
ARG VITE_APP_NAME=Pixelforge
ENV VITE_APP_NAME=${VITE_APP_NAME}
# --ignore-scripts: install scripts of dependencies never run (also set in .npmrc).
RUN npm ci --ignore-scripts && npm run build

# ---- app: what actually runs. No Composer, no Node, no dev packages, no .env.
FROM base AS app
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY deploy/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
# The image starts FrankenPHP with `--config /etc/frankenphp/Caddyfile`; the /etc/caddy copy is a separate file.
COPY deploy/Caddyfile /etc/frankenphp/Caddyfile
COPY --from=vendor /var/www/html /var/www/html
COPY --from=assets /var/www/html/public/build /var/www/html/public/build
# Laravel writes to storage/ and bootstrap/cache/; Caddy writes to /data and /config. Run as www-data
# on port 8080 (ports below 1024 would need extra capabilities).
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache /data /config
USER www-data
EXPOSE 8080
