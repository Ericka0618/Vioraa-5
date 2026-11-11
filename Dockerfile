FROM php:8.3-apache-bookworm
WORKDIR /var/www/html
RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates libcurl4-openssl-dev libonig-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libxml2-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j2 mysqli pdo_mysql mbstring curl zip gd dom \
 && a2enmod rewrite headers \
 && sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
 && rm -rf /var/lib/apt/lists/*
COPY apache-config.conf /etc/apache2/sites-available/000-default.conf
COPY php.ini /usr/local/etc/php/conf.d/custom.ini
COPY deployment/runtime.php deployment/mysql-session.php /etc/viora/
COPY Viora/ /var/www/html/
RUN mkdir -p /var/www/html/uploads/apartamentos /var/www/viora_documentos_privados \
 && chown -R www-data:www-data /var/www/html/uploads /var/www/viora_documentos_privados
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=3s CMD curl -fsS http://localhost:8080/healthz.php || exit 1
CMD ["apache2-foreground"]
