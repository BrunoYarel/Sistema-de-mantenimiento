<!-- database/README.md -->
# database/

¿Dónde se crea la base? Nadie la crea a mano: `docker compose up` ejecuta esta cadena sola.

| Orden | Quién | Qué hace | Cuándo |
|---|---|---|---|
| 1 | `docker-compose.yml` | La imagen de PostgreSQL crea la base `caso_a` y su dueño `caso_a_dueno` (variables `POSTGRES_*`). | Primera vez (volumen vacío) |
| 2 | `dev/00_roles.sql` | Crea el rol de la aplicación `caso_a_app` y le da uso del esquema. Solo desarrollo. | Primera vez (volumen vacío) |
| 3 | `docker/backend/entrypoint.sh` | Corre `php bin/migrar.php --grants --seed`. | Cada arranque del backend |
| 4 | `migraciones/0001_esquema_inicial.sql` | Crea las tablas y los índices. `bin/migrar.php` registra cada una con su hash en `schema_migraciones` y se detiene si una ya aplicada fue editada. | Solo las pendientes |
| 5 | `grants.sql` | Permisos de `caso_a_app`: sin borrado; `evento`, `idempotencia` y `auditoria` solo `SELECT` e `INSERT`. | Cada arranque (re-ejecutable) |
| 6 | `seed/0001_catalogos.sql` | Sedes y feriados iniciales. | Cada arranque (re-ejecutable) |

- Feriados: `seed/0001_catalogos.sql` debe cubrir hasta hoy + 60 días (`tests/reloj_habiles_test.php` lo comprueba). Un año nuevo se agrega a ese mismo `INSERT`; el seed es re-ejecutable y no requiere `down -v`.
- Esquema nuevo: otra migración numerada (`0002_...sql`, la siguiente) y sus permisos en `grants.sql`. Una migración aplicada no se edita.
- El líder asigna el número de cada migración nueva antes de que se escriba: dos PR no pueden crear el mismo número.
- Base vacía en desarrollo: `docker compose down -v` borra el volumen `caso_a_datos`; el siguiente `docker compose up -d --build` la recrea.
- Servidor real, sin Docker: los roles y la base se crean una vez con el PASO 0 de `grants.sql`; después se corre `php bin/migrar.php --grants --seed`.