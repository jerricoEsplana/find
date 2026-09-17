FROM php:8.3-apache

# 1. Enable Apache rewrite engine
RUN a2enmod rewrite

# 2. Fix the MPM conflict permanently by removing the event module configuration file
ARG CACHEBUST=1
RUN rm -f /etc/apache2/mods-enabled/mpm_event.load \
    && rm -f /etc/apache2/mods-enabled/mpm_event.conf \
    && a2enmod mpm_prefork

# 2b. Debug check: confirm which MPM modules are enabled at build time
RUN ls -la /etc/apache2/mods-enabled/ | grep mpm

# 3. Set the working directory and copy your project files
WORKDIR /var/www/html
COPY . /var/www/html/

# 4. Create internal project folders and set permissions
RUN mkdir -p /var/www/html/storage /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage /var/www/html/uploads

# 5. Set up the entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
