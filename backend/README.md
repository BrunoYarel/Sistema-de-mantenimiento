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
docs/       funcionalidades/ (qué debe lograr cada módulo) · api/ (un contrato por módulo, escrito ANTES de codificar)
```

## Reglas que aplican desde la primera línea

- **Capas (B1):** Controlador (solo HTTP) → Servicio (reglas + transacción) → Repositorio (SQL). Sin SQL en controladores, sin reglas en repositorios. [verificador]
- **Una tabla, un módulo dueño (B2):** entre módulos se llaman servicios, no tablas. Dueños: `catalogos` → `sede` y `ubicacion`; `equipos` → `equipo`; `usuarios` → `usuario`; `incidencias` → `incidencia` y `atencion`. Excepción de lectura (vigente): los repositorios que solo *leen* (historial, fuera de servicio, indicadores) pueden cruzar tablas, nunca escriben. Hoy la usa `equipos` (lee `incidencia` y `atencion`); los nombres de personas se piden igual al Servicio de `usuarios` y los de ubicaciones al de `catalogos`. Esas dos lecturas (`/equipos/{id}/historial` y `/equipos/fuera-de-servicio`) pasan a `incidencias` con la misma URL cuando se construya (`docs/api/incidencias.md`).
- **Lo repetido vive en `nucleo/` (no se copia entre módulos):** `Paginacion` (tamaño, tope y forma `{items,total,pagina,por_pagina}`), `Texto` (normalizar espacios, motivos y nombres obligatorios, patrón de búsqueda), `Cambios` (qué cambió de verdad + auditoría en un PATCH), `Errores::deRestriccion()` (constraint → error de campo; cada módulo declara su mapa `RESTRICCIONES`) y `Errores::modificadoPorOtro()` (409 cuando `rowCount()` da 0).
- **Quién puede llamar a quién (B2):** de abajo hacia arriba, `auth` · `catalogos` < `usuarios` · `equipos` < `incidencias` < el resto. Un módulo de abajo no usa a uno de arriba, y entre módulos solo se usa el `Servicio` del otro. `tests/dependencias_test.php` lo hace cumplir: falla ante un ciclo o un `Repositorio`/`Controlador` ajeno. Hoy `equipos` usa a `catalogos` y `usuarios`; `usuarios` usa a `auth`, `catalogos` e `incidencias`. **Único ciclo aceptado:** `usuarios` ↔ `incidencias` (RN-02: un técnico con incidencias abiertas no se desactiva ni cambia de rol), declarado en `DEPENDENCIAS_ACEPTADAS` con su motivo. Si de verdad hace falta otra arista que cierre un ciclo, se agrega ahí con su motivo y se anota en esta línea.
- **Toda ruta declara `permiso`** (de `Dominio::PERMISOS`, o `null` = basta con sesión). Si falta o no existe, la app no arranca. Default deny.
- **Todo POST que inserta** usa `'idempotente' => true` y `Idempotencia::ejecutar()` en el servicio: escribe `idempotencia(usuario_id, clave, accion, recurso_id)` con `UNIQUE(usuario_id, clave)` en la misma transacción y el reintento relee el recurso (RNF-07). La clave se comprueba antes que el duplicado (RF-03, RF-06, RF-07).
- **Toda transición:** `SELECT … FOR UPDATE` → `Politica::exigirAccion()` → `UPDATE` → `Eventos::registrar()`, en una transacción.
- **Toda fecha** sale de `Reloj` (`Reloj::paraBd()` al escribir, `Reloj::iso()` al responder). Prohibidos `now()`, `current_timestamp`, `DEFAULT now()`, `current_date`, `localtimestamp`, `clock_timestamp()` y similares en SQL, y `date()` en PHP. [verificador]
- **401 solo significa "no hay sesión"** (B6). Un error de negocio es 403, 404, 409 o 422.
- **Cada archivo trae su ruta en un comentario de las 3 primeras líneas.** [verificador]
- **El esquema cambia solo con migraciones nuevas** (`database/migraciones/NNNN_*.sql`); una aplicada no se edita. [bin/migrar.php]

## Particularidades de PostgreSQL

- Ids: `GENERATED ALWAYS AS IDENTITY` y `INSERT … RETURNING id`. `JSONB` para `datos`.
- Zona horaria: `SET TIME ZONE 'America/Lima'` al conectar (`Conexion`) y `date_default_timezone_set` (`Reloj`).
- Booleanos: un `false` de PHP en `execute([…])` llega vacío y falla; usa `TRUE`/`FALSE` en el SQL o los textos `'true'`/`'false'`.
- `rowCount()` de un UPDATE ya cuenta las filas que cumplen el `WHERE`: 0 filas = conflicto real (409). **[CI]** `tests/humo_evento_test.php`.
- Dos POST con la misma clave se serializan con `pg_advisory_xact_lock` en `Idempotencia::ejecutar()`.
- `evento`: solo INSERT. GRANT por tabla y `REVOKE` de `UPDATE/DELETE/TRUNCATE`; el dueño de las tablas NO es el usuario de la app.
- Estado al corte: `DISTINCT ON (incidencia_id) … ORDER BY incidencia_id, fecha DESC, id DESC`.
- DDL transaccional: cada migración se aplica entera o no se aplica.

## Reglas de negocio del backend

**Tiempo**
- Hábiles = lunes a sábado (§2, RN-06). **[CI]** test de tabla: sábado, domingo y feriado.
- Hora de Lima en un solo lugar del núcleo (RN-10). **[CI]** test en 23:59:59 del último día del mes.
- La tabla `feriado` debe cubrir hasta hoy + 60 días (`Habiles::MARGEN_CALENDARIO_DIAS`). Se carga en `database/seed/0001_catalogos.sql`. **[CI]** `tests/reloj_habiles_test.php` falla si no, y `Habiles::feriados()` deja un aviso en el log.

**Escritura**
- Una sola función calcula y ejecuta las acciones permitidas (rol × relación × estado, §4 y §5). **[CI]** se compara con `tests/oraculo_acciones.json`, escrito a mano por QA desde §4 y §5 y revisado por otra persona; nunca generado desde el código (RN-09, RF-12).

**Datos**
- `evento` no se modifica (RN-07, RNF-06): GRANT por tabla en `database/grants.sql` versionado, sin triggers. **[CI]** humo: un `UPDATE` sobre `evento` con el usuario de la app debe dar error.
- `evento` lleva columnas tipadas (`incidencia_id`, `accion`, `estado_antes`, `estado_despues`, `fecha`); lo que un indicador filtre nunca va en el JSON `datos`.
- `incidencia.sede_id` se copia al registrar y no cambia: el reporte de marzo no varía si el equipo cambia de sede en abril (RF-11).
- `ubicacion(id, sede_id, nombre)` con FK; no hay ubicación en texto libre (RF-04, RF-09).
- Invariante: estado ∈ {asignada, en_atencion, en_espera, resuelta} ⇒ `tecnico_id` NOT NULL (RN-02).

**Indicadores (RF-11, RN-10)** — la fórmula de negocio y la forma de la respuesta están en `docs/funcionalidades/indicadores.md` y `docs/api/indicadores.md`.
- Columnas para el estado actual (listas y colas); eventos para el corte. **[CI]** test de invariantes: ambos coinciden.
- Estado al corte con subconsulta correlacionada ordenada por (`fecha`, `id`), o `DISTINCT ON` (ver arriba).
- Tiempo de atención: una muestra por incidencia, su primer `resolver`; el mes es el de ese evento.
- Un mes cerrado fija el fin de mes; el mes en curso corta en "ahora".
- **[CI]** El dataset de oro se genera con los servicios reales y un `Reloj` controlado, no con `INSERT`.

**Visibilidad (RN-09, RF-09)**
- Tres serializadores; el tercero, `historial_item`, trae exactamente fecha, falla, solución y técnico, sin reportante ni vinculados.

**Flujo**
- Cierre sin confirmación (RN-06, D9): lo hace el jefe de soporte a los 3 hábiles desde `max(fecha_resuelta, último vínculo)`.
- Duplicado sobre una Resuelta: "Sigue fallando" = `vincular_reporte` + `rechazar_solucion` en una transacción con dos eventos (RNF-06); "Es otro problema" = `confirmar_nueva`.
- Confirma solo el reportante titular; un vinculado solo puede rechazar (RN-06).
- Prioridad manual: el vínculo siempre genera evento. Si la prioridad calculada supera a la manual, la incidencia entra en la cola del jefe de soporte con marca "revisar prioridad" hasta que la confirme o la cambie (RF-05, RN-04, D10).
- `desactivar_usuario` responde `409` con la lista de incidencias abiertas del técnico (RF-12, RN-02).
- `no_reparable`: `dar_de_baja` (jefe de soporte, motivo, evento) pone `equipo.activo=0` (RF-09). **[S]**
- "Fuera de servicio" **no se guarda**: se deriva (equipo activo con al menos una incidencia abierta), así que un equipo dado de baja sale de la lista (RF-09).

**Login (RNF-01)**
- Límite de intentos por (usuario, IP), no por usuario solo; fallos en log y desbloqueo manual. Se acepta y documenta: un atacante interno en la misma IP puede bloquear al jefe.
- Con `debe_cambiar_clave` solo se admite cambiar la clave y logout.

**Carga inicial (D11)**
- Sin pantalla de carga: `bin/importar-equipos.php` con `--dry-run`, informe de filas rechazadas y código normalizado. Lo mismo para docentes, con clave temporal aleatoria y `debe_cambiar_clave`.
- El docente no recibe RF-04 (§4): usa el CSV cargado y, si falta algo, soporte registra por RF-02.
- Requerimientos de salida: el CSV de equipos y el código rotulado en cada equipo; sin rótulo los reportes caen en el equipo equivocado y se contamina el historial. **[P]** confirmar con el cliente.
- Seed: los 10 equipos fuera de servicio entran como 10 incidencias `registrada` del usuario de sistema `carga_inicial` (sin credenciales), canal `sistema`, por el flujo normal. Inflan Pendientes desde la fecha de carga y se cierran sin confirmación (RN-06); documentarlo.

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