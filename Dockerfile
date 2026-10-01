FROM php:8.2-apache

# PDO MySQL for the database, zip/unzip for composer.
# mod_php needs mpm_prefork; make sure no other MPM is enabled
# (otherwise Apache stops with "More than one MPM loaded").
RUN apt-get update && apt-get install -y --no-install-recommends unzip libzip-dev \
    && docker-php-ext-install pdo_mysql zip \
    && (a2dismod -f mpm_event mpm_worker || true) \
    && a2enmod mpm_prefork rewrite headers \
    && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*

# Production PHP settings: errors go to the log, never to the browser
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --optimize-autoloader --no-scripts

COPY . .
RUN mkdir -p uploads/requirements && chown -R www-data:www-data uploads

COPY docker/start.sh /usr/local/bin/start.sh
RUN sed -i 's/\r$//' /usr/local/bin/start.sh && chmod +x /usr/local/bin/start.sh
CMD ["/usr/local/bin/start.sh"]
