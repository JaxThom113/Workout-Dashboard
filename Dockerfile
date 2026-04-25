FROM php:8.5-apache

RUN docker-php-ext-install mysqli curl

# Enable error logging for debugging
RUN echo "error_log = /var/log/apache2/php_errors.log" >> /usr/local/etc/php/conf.d/docker-php-ext-error-logging.ini