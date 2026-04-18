FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev \
        libonig-dev \
        libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install mysqli pdo pdo_mysql pdo_pgsql mbstring curl

# Render uploads can exceed default 8M; increase limits and avoid sending warnings to output
# (warnings break sessions by sending headers early).
RUN { \
      echo "upload_max_filesize=64M"; \
      echo "post_max_size=64M"; \
      echo "memory_limit=256M"; \
      echo "max_execution_time=300"; \
      echo "max_input_time=300"; \
      echo "display_errors=Off"; \
      echo "log_errors=On"; \
      echo "error_reporting=E_ALL"; \
    } > /usr/local/etc/php/conf.d/ntp.ini

WORKDIR /var/www/html
COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
