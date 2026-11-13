#!/bin/sh
# docker/backend/entrypoint.sh
# Corre cada vez que arranca el contenedor del backend, antes de PHP-FPM:
#   1. deja listas las carpetas donde PHP escribe (sesiones y logs)
#   2. aplica migraciones pendientes, permisos y datos de arranque.
#      Es re-ejecutable: si no hay nada nuevo, no cambia nada.
#   3. solo con APP_ENV=local: deja un usuario por rol listo para entrar (bin/usuarios.php sembrar-dev).
# Si algo falla, el contenedor se detiene y el motivo sale en:  docker compose logs backend
set -e
cd /app

mkdir -p backend/storage/sesiones backend/storage/logs backend/storage/respaldos
chmod -R 777 backend/storage   # solo desarrollo: PHP-FPM corre como www-data y debe poder escribir

echo "[backend] Preparando la base de datos..."
php bin/migrar.php --grants --seed

if [ "${APP_ENV:-}" = "local" ]; then
    echo "[backend] Desarrollo: preparando usuarios de prueba..."
    php bin/usuarios.php sembrar-dev
fi

echo "[backend] Listo. Iniciando PHP-FPM."
exec "$@"