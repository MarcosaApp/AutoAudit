# PHP con Apache + controlador de PostgreSQL; el servidor anfitrión puede ser Ubuntu 26.04.
FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*
RUN sed -ri 's!DocumentRoot /var/www/html!DocumentRoot /var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && printf '%s\n' 'ServerTokens Prod' 'ServerSignature Off' >> /etc/apache2/apache2.conf \
    && printf '%s\n' 'session.cookie_httponly=1' 'session.use_strict_mode=1' 'display_errors=Off' > /usr/local/etc/php/conf.d/autoaudit.ini
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;
WORKDIR /var/www/html
