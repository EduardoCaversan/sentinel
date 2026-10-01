FROM node:22-alpine AS swagger
WORKDIR /assets
RUN npm install --ignore-scripts --no-audit --no-fund swagger-ui-dist@5.31.0 @apidevtools/swagger-parser@12.1.0
COPY public/openapi.json /assets/openapi.json
RUN node -e "require('@apidevtools/swagger-parser').validate('/assets/openapi.json').then(() => console.log('OpenAPI valid')).catch(e => { console.error(e.message); process.exit(1); })"

FROM php:8.4-apache AS base
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libzip-dev libonig-dev libsqlite3-dev unzip git \
    && docker-php-ext-install pdo_mysql pdo_sqlite mbstring intl zip pcntl bcmath \
    && pecl install redis && docker-php-ext-enable redis \
    && a2enmod rewrite && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/sentinel.ini
COPY . .
COPY --from=swagger /assets/node_modules/swagger-ui-dist/swagger-ui-bundle.js public/vendor/swagger-ui-bundle.js
COPY --from=swagger /assets/node_modules/swagger-ui-dist/swagger-ui.css public/vendor/swagger-ui.css
COPY --from=swagger /assets/node_modules/swagger-ui-dist/LICENSE public/vendor/SWAGGER-LICENSE
RUN composer install --no-interaction --prefer-dist --optimize-autoloader \
    && touch .env \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache \
    && sed -i 's/\r$//' docker/*.sh
ENTRYPOINT ["sh", "/var/www/html/docker/entrypoint.sh"]
CMD ["apache2-foreground"]

FROM base AS production
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
