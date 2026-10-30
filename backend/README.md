<!-- backend/README.md -->
# Backend (PHP + PostgreSQL)

API JSON bajo `/api`, mismo origen que el frontend. Sin framework y sin Composer: PHP 8.0 o superior
(extensiones `pdo_pgsql` y `mbstring`) y PostgreSQL 12 o superior. [P] Confirmar versiones con el servidor del instituto.
Las reglas de negocio y los 12 requerimientos funcionales (RF-01 a RF-12) mandan desde `Sistema de mantenimiento.md`.

## Arrancar en desarrollo

Con Docker, desde la raíz del repositorio (guía completa para el equipo: `GUIA_EQUIPO.md`):

```bash
docker compose up -d --build        # frontend (nginx) + backend (PHP) + base de datos (PostgreSQL); abre http://localhost:8080
# En local los usuarios de prueba ya existen (clave dev-clave-12345; ver GUIA_EQUIPO.md). Para crear otro: php bin/usuarios.php crear <usuario> <rol> "<Nombre>"
docker compose exec backend php tests/ejecutar.php --con-bd      # pruebas
docker compose exec backend bash bin/verificar.sh                # reglas que se hacen cumplir con grep
```

El backend aplica solo las migraciones, los permisos y los datos de arranque cada vez que inicia (`bin/migrar.php --grants --seed`).
No hace falta `backend/.env`: las variables las define `docker-compose.yml`.

Sin Docker para PHP (alternativa): levanta solo la base con `docker compose up -d db`, copia `backend/.env.example` a `backend/.env`
y cambia `BD_HOST` a `localhost`. Luego `php bin/migrar.php --grants --seed` y `php -S localhost:8000 -t frontend bin/router-dev.php`.
Si PHP dice que no encuentra el driver, activa `extension=pdo_pgsql` en `php.ini`.
Sin Docker para nada: crea los roles y la base con los pasos de `database/grants.sql` (PASO 0) y ajusta `backend/.env`.

## Mapa

```
backend/
├── public/index.php      único archivo expuesto a la web
├── nucleo/               lo crítico: se toca con 2 aprobaciones
└── modulos/
    ├── index.php         lista de módulos (una línea por módulo)
    └── <modulo>/         rutas.php · Controlador · Servicio · Repositorio (· acciones.php en incidencias)
database/   migraciones/ (numeradas) · grants.sql · seed/ · dev/ (solo desarrollo)
bin/        migrar · usuarios · importar-equipos · respaldo · router-dev · verificar.sh
tests/      ejecutar.php · *_test.php · oraculo_acciones.json y matriz.json (escritos a mano)
docs/api/   un contrato por módulo, escrito ANTES de codificar
```

## Reglas que aplican desde la primera línea

- **Capas (B1):** Controlador (solo HTTP) → Servicio (reglas + transacción) → Repositorio (SQL). Sin SQL en controladores, sin reglas en repositorios. [verificador]
- **Una tabla, un módulo dueño (B2):** entre módulos se llaman servicios, no tablas. Dueños: `catalogos` → `sede` y `ubicacion`; `equipos` → `equipo`; `usuarios` → `usuario`; `incidencias` → `incidencia` y `atencion`. Excepción de lectura (vigente): los repositorios que solo *leen* (historial, fuera de servicio, indicadores) pueden cruzar tablas, nunca escriben. Hoy la usa `equipos` (lee `incidencia` y `atencion`); los nombres de personas se piden igual al Servicio de `usuarios` y los de ubicaciones al de `catalogos`. Esas dos lecturas (`/equipos/{id}/historial` y `/equipos/fuera-de-servicio`) pasan a `incidencias` con la misma URL cuando se construya (`docs/api/incidencias.md`).
- **Lo repetido vive en `nucleo/` (no se copia entre módulos):** `Paginacion` (tamaño, tope y forma `{items,total,pagina,por_pagina}`), `Texto` (normalizar espacios, motivos y nombres obligatorios, patrón de búsqueda), `Cambios` (qué cambió de verdad + auditoría en un PATCH), `Errores::deRestriccion()` (constraint → error de campo; cada módulo declara su mapa `RESTRICCIONES`) y `Errores::modificadoPorOtro()` (409 cuando `rowCount()` da 0).
- **Quién puede llamar a quién (B2):** de abajo hacia arriba, `auth` · `catalogos` < `usuarios` · `equipos` < `incidencias` < el resto. Un módulo de abajo no usa a uno de arriba, y entre módulos solo se usa el `Servicio` del otro. `tests/dependencias_test.php` lo hace cumplir: falla ante un ciclo o un `Repositorio`/`Controlador` ajeno. Hoy `equipos` usa a `catalogos` y `usuarios`; `usuarios` usa a `auth`, `catalogos` e `incidencias`. **Único ciclo aceptado:** `usuarios` ↔ `incidencias` (RN-02: un técnico con incidencias abiertas no se desactiva ni cambia de rol), declarado en `DEPENDENCIAS_ACEPTADAS` con su motivo. Si de verdad hace falta otra arista que cierre un ciclo, se agrega ahí con su motivo y se anota en esta línea.
- **Toda ruta declara `permiso`** (de `Dominio::PERMISOS`, o `null` = basta con sesión). Si falta o no existe, la app no arranca. Default deny.
- **Todo POST que inserta** usa `'idempotente' => true` y `Idempotencia::ejecutar()` en el servicio.
- **Toda transición:** `SELECT … FOR UPDATE` → `Politica::exigirAccion()` → `UPDATE` → `Eventos::registrar()`, en una transacción.
- **Toda fecha** sale de `Reloj` (`Reloj::paraBd()` al escribir, `Reloj::iso()` al responder). Nada de funciones de hora en SQL ni `date()` en PHP. [verificador]
- **401 solo significa "no hay sesión"** (B6). Un error de negocio es 403, 404, 409 o 422.
- **Cada archivo trae su ruta en un comentario de las 3 primeras líneas.** [verificador]
- **El esquema cambia solo con migraciones nuevas** (`database/migraciones/NNNN_*.sql`); una aplicada no se edita. [bin/migrar.php]

## De MySQL a PostgreSQL: qué cambia en las reglas del README

| Regla anterior | Con PostgreSQL |
|---|---|
| `SET time_zone='-05:00'` | `SET TIME ZONE 'America/Lima'` al conectar (`Conexion`), más `date_default_timezone_set` (`Reloj`) |
| `PDO::MYSQL_ATTR_FOUND_ROWS` | No existe ni hace falta: `rowCount()` ya cuenta filas que cumplen el `WHERE`. Lo comprueba `tests/humo_evento_test.php` |
| Prohibido `NOW()`, `CURRENT_TIMESTAMP` | Se amplía a `current_date`, `localtimestamp`, `clock_timestamp()`, etc. |
| Sin funciones de ventana (MySQL 5.7) | Se pueden usar. Para el estado al corte: `DISTINCT ON (incidencia_id) … ORDER BY incidencia_id, fecha DESC, id DESC` |
| `evento` solo INSERT: GRANT por tabla | Igual, más `REVOKE` de `UPDATE/DELETE/TRUNCATE`. El dueño de las tablas NO es el usuario de la app |
| `AUTO_INCREMENT` + `lastInsertId()` | `GENERATED ALWAYS AS IDENTITY` + `INSERT … RETURNING id` |
| JSON en `datos` | `JSONB` |
| Booleanos | Un `false` de PHP en `execute([…])` llega vacío y falla: usa `TRUE`/`FALSE` en el SQL o los textos `'true'`/`'false'` |
| Una transacción serializa dos POST con la misma clave | `pg_advisory_xact_lock` en `Idempotencia::ejecutar()` |
| Migraciones | PostgreSQL permite DDL transaccional: cada migración se aplica entera o no se aplica |

## Servidor real

Solo `backend/public/` queda expuesto; el resto del backend vive fuera de la raíz web.

```apache
DocumentRoot /ruta/al/repo/frontend
Alias /api /ruta/al/repo/backend/public
<Directory /ruta/al/repo/backend/public>
    AllowOverride All
    Require all granted
</Directory>
```

```nginx
location /api/ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /ruta/al/repo/backend/public/index.php;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
```

En producción: `APP_ENV=produccion`, claves propias en `backend/.env`, HTTPS si es posible (`COOKIE_SEGURA=1`) y
respaldo semanal con `bin/respaldo.php` (RNF-03) con una restauración de prueba.

## Pendiente de decidir

- **[P]** Versiones de PHP y PostgreSQL del servidor.
- **[P]** Regla de normalización del código de equipo (`bin/importar-equipos.php`) y que cada equipo lleve el código rotulado.
- **[P]** "3 días hábiles": ¿vence a la misma hora o al final del tercer día? (`Habiles::sumar`).
- **[S]** `equipo.dar_de_baja` y lista de `resultado`.

Ya decidido (no reabrir sin motivo): `fuera_de_servicio` es un dato **derivado**, no una columna (`database/migraciones/0001_esquema_inicial.sql`): equipo activo con al menos una incidencia abierta. El historial usa la fecha de **registro** de la incidencia.
- **TODO** Usuario de sistema `carga_inicial` y las 10 incidencias iniciales (D11); importación de docentes; test de oro (`tests/oro_test.php`).