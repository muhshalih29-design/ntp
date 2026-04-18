FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev \
        libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install mysqli pdo pdo_mysql pdo_pgsql mbstring curl

WORKDIR /var/www/html
COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
