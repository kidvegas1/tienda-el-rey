# Tienda Hispana El Rey — production PHP 8.2 + Apache
# Document root: /var/www/html (index.php router, assets/, api/)
FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        curl \
        libpq-dev \
        postgresql-client \
    && docker-php-ext-install pdo pdo_pgsql pdo_mysql \
    && a2enmod rewrite headers \
    && sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php-uploads.ini /usr/local/etc/php/conf.d/99-uploads.ini

WORKDIR /var/www/html

COPY . /var/www/html/

RUN mkdir -p assets/uploads \
    && chown -R www-data:www-data /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD sh -c 'curl -fsS "http://127.0.0.1:${PORT:-8080}/api/health" || exit 1'

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
