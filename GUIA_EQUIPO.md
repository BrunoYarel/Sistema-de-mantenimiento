<!-- GUIA_EQUIPO.md -->
# Guía del equipo

Léela completa una vez (10 minutos). Después úsala de consulta.
Solo cubre dos cosas: **cómo levantar el sistema** y **cómo se organiza un módulo**.

---

## 1. Levantar el sistema (3 servidores, 1 comando)

Necesitas Docker Desktop (Windows/Mac) o Docker con Compose (Linux). Nada más: **no instales PHP ni PostgreSQL**.

```bash
docker compose up -d --build
```

La primera vez tarda unos minutos (descarga las imágenes). Cuando termine, abre **http://localhost:8080**.

### Qué se levantó

| Servidor | Qué es | Qué hace | Carpeta tuya |
|---|---|---|---|
| `frontend` | nginx | Entrega las pantallas (html, css, js) y reenvía todo lo que empiece con `/api` al backend | `frontend/` |
| `backend` | PHP | La API: recibe lo que manda la pantalla y responde JSON. Al arrancar, crea o actualiza las tablas solo | `backend/` |
| `db` | PostgreSQL | Guarda los datos | `database/` |

Tú solo abres **una dirección** (`localhost:8080`). El servidor `frontend` es la puerta de entrada: si el js pide `/api/...`, él se lo pasa al `backend`. Por eso el login funciona: para el navegador todo es el mismo sitio.

### Configuración inicial (una sola vez por persona)

**a) Usuarios de prueba.** En local el backend crea solo un usuario por rol cada vez que arranca. La clave de todos es `dev-clave-12345`:

| Usuario | Rol |
|---|---|
| `docente` | docente |
| `administrativo` | admin |
| `soporte.tecnico` | soporte_tecnico |
| `soporte.informatica` | soporte_informatica |
| `jefe.soporte` | jefe_soporte |
| `jefe.info` | jefe_informatica |

Si olvidaste la clave, la cambiaste o el usuario se bloqueó por intentos fallidos, restablece todos con:

```bash
docker compose exec backend php bin/usuarios.php sembrar-dev
```

Solo funciona con `APP_ENV=local`: en el servidor real no existen estos usuarios y se crean con `bin/usuarios.php crear <usuario> <rol> "<Nombre>"`, que genera una clave temporal aleatoria (se ve **una sola vez** y obliga a cambiarla al entrar). Úsalo también para probar la pantalla de cambio de clave.

**b) Elige backend real o datos de prueba.** `http://localhost:8080` sin parámetros habla con el backend real. Para trabajar una pantalla sin backend: `python3 -m http.server 8000 -d frontend` y abre `http://localhost:8000/?mocks=1` (también sirve `http://localhost:8080/?mocks=1`). Usa los mismos usuarios y la misma clave de la tabla de arriba; en modo mocks, el botón «Acceso rápido» (abajo a la izquierda) cambia de usuario con un clic. Si no ves un cambio con `http.server`, recarga con Ctrl+F5: el `8080` (nginx) no necesita eso. Cómo funcionan los datos de prueba (mocks), sus límites y cómo dar de alta el de tu módulo: `frontend/js/mocks/index.js`.

### Comandos de todos los días

| Quiero... | Comando |
|---|---|
| Encender todo | `docker compose up -d` |
| Apagar todo (los datos se conservan) | `docker compose down` |
| Ver errores del backend | `docker compose logs -f backend` |
| Correr las pruebas | `docker compose exec backend php tests/ejecutar.php --con-bd` |
| Revisar las reglas antes de subir mis cambios | `docker compose exec backend bash bin/verificar.sh` |
| Empezar con la base vacía | `docker compose down -v` y luego `docker compose up -d` |

Cambios en html/css/js/php: **solo recarga la página**, no hay que reiniciar nada.
Solo si tocas `docker-compose.yml`, `docker/` o el nginx: `docker compose up -d --build`.

### Si algo falla

| Síntoma | Qué hacer |
|---|---|
| `502 Bad Gateway` al abrir la página o llamar a `/api` | El backend sigue arrancando o falló. Revisa `docker compose logs backend`: ahí sale el error de la migración o de PHP |
| El puerto 8080 está ocupado | En `docker-compose.yml` cambia `"8080:80"` por `"8081:80"` y abre `localhost:8081` |
| El puerto 5432 está ocupado (tienes PostgreSQL instalado) | Cambia `"127.0.0.1:5432:5432"` por `"127.0.0.1:5433:5432"` |
| El backend no conecta con la base | ¿Creaste un `backend/.env` viejo con `BD_HOST=localhost`? Bórralo o pon `BD_HOST=db` (con Docker no hace falta) |

---

## 2. Cómo se organiza el trabajo: un módulo = dos carpetas gemelas

En una página web, el html llama al css y al js. Aquí es parecido: **la pantalla (js) le pide datos a la API (php), y la API responde JSON**. Cada funcionalidad del sistema es un *módulo*, y un módulo siempre vive en **dos carpetas con el mismo nombre**, más un contrato:

```
frontend/js/modulos/incidencias/   ← la pantalla (js + css): lo que ya saben hacer
backend/modulos/incidencias/       ← la API (php): recibe, valida y responde
docs/api/incidencias.md            ← el contrato: el acuerdo entre las dos
```

### El contrato

Es el acuerdo entre quien hace la pantalla y quien hace el backend: qué URL se llama, qué se envía, qué se responde y qué errores pueden salir. **Se escribe antes de programar** (usa `docs/api/_plantilla.md`). Con el contrato firmado, cada uno trabaja por su lado sin esperar al otro. Si el contrato cambia, se cambia en el mismo PR en ambos lados.

### Dentro de la carpeta del backend

| Archivo | Para qué sirve | Se parece a... |
|---|---|---|
| `rutas.php` | Es el índice: "esta URL la atiende esta función" y quién tiene permiso | El `href` de un enlace |
| `Controlador.php` | Lee lo que mandó la pantalla y devuelve el JSON. **Nada de reglas ni SQL** | El js que lee los campos de un formulario |
| `Servicio.php` | Las reglas del negocio ("¿se puede resolver una incidencia que está registrada?") | La lógica de tu js |
| `Repositorio.php` | Es el **único** que escribe SQL | Quien guarda en la base |

El orden es siempre **Controlador → Servicio → Repositorio**, y cada uno solo habla con el siguiente. Eso hace que cualquiera entienda el módulo de otro compañero.

### Dónde NO tocar sin avisar

`backend/nucleo/` es el corazón compartido (login, permisos, historial, fechas). Se cambia con **2 aprobaciones** (regla que ya está en `backend/README.md`). Si crees que necesitas cambiarlo, antes habla con el equipo.