FROM php:8.2-apache
RUN a2enmod rewrite
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html/
EXPOSE 80
# Worker de surveillance : un tick toutes les 30s, relancé quoi qu'il arrive.
# Démarre avec le conteneur — aucune Scheduled Task Coolify nécessaire.
CMD ["sh", "-c", "(while true; do php /var/www/html/cron/tick.php; sleep 15; done) & exec apache2-foreground"]
