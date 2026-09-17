FROM php:8.3-apache

#RUN docker-php-ext-install pdo_sqlite \
#    && a2enmod rewrite
RUN a2enmod rewrite


WORKDIR /var/www/html
COPY . /var/www/html/

RUN mkdir -p /data /var/www/html/storage /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html /data \
    && chmod -R 775 /var/www/html/storage /var/www/html/uploads /data

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8080
ENTRYPOINT ["docker-entrypoint.sh"]
