# docker/backend/Dockerfile
# Imagen del backend: PHP-FPM + el driver de PostgreSQL (pdo_pgsql). mbstring ya viene incluido.
# [P] Cambia PHP_VERSION a la del servidor del instituto (backend/README.md pide 8.0 o superior).
# Cuando llegue la semana del respaldo (bin/respaldo.php), agrega aqui el paquete postgresql-client.
ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-fpm

RUN sed -i 's|http://|https://|g' /etc/apt/sources.list.d/debian.sources || true \
 && apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*

# PHP-FPM no debe borrar las variables de entorno: por ahi llegan las claves de la base
# desde docker-compose.yml.
RUN printf '[www]\nclear_env = no\n' > /usr/local/etc/php-fpm.d/zz-entorno.conf

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
# sed quita los \r si Windows convirtio el archivo: un .sh con \r no arranca en Linux.
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /app
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]