# Runs SchedMaster on any Docker host (Railway, Render, Fly.io, a VPS...).
FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

# Hosts like Railway/Render tell the app which port to listen on via $PORT.
CMD ["sh", "-c", "sed -i \"s/80/${PORT:-80}/g\" /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf && apache2-foreground"]
