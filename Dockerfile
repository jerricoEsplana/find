FROM php:8.3-apache

# 1. Enable Apache rewrite engine
RUN a2enmod rewrite

# 2. Fix the MPM conflict by ensuring only prefork is active
RUN a2dismod mpm_event || true && a2enmod mpm_prefork

# 3. Set the working directory and copy your project files
WORKDIR /var/www/html
COPY . /var/www/html/

# 4. Create directories and set strict permissions for Apache (www-data)
RUN mkdir -p /data /var/www/html/storage /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html /data \
    && chmod -R 775 /var/www/html/storage /var/www/html/uploads /data

# 5. Set up the entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# 6. Change Apache's default port from 80 to 8080 to match your EXPOSE instruction
RUN sed -i 's/80/8080/g' /etc/apache2/ports.conf /etc/apache2/sites-available/*.conf

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
