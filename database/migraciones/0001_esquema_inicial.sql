-- database/migraciones/0001_esquema_inicial.sql
-- Esquema para PostgreSQL 12 o superior. Se aplica con: php bin/migrar.php
--
-- Convenciones
--   * Fechas: TIMESTAMPTZ sin valor por defecto. Las pone la clase Reloj (RN-10).
--   * Ids: BIGINT GENERATED ALWAYS AS IDENTITY. Se leen con INSERT ... RETURNING id.
--   * Valores fijos: TEXT + CHECK (cambiar un CHECK es mas simple que alterar un ENUM).
--     Deben coincidir con backend/nucleo/Dominio.php; lo comprueba tests/dominio_test.php.
--   * Nada se borra (RN-07): el usuario de la aplicacion no tiene DELETE (database/grants.sql).
--   * Una migracion aplicada NO se edita: se agrega otra numerada (B5). El lider asigna el numero.
--     bin/migrar.php vigila que las aplicadas no cambien.

-- ------------------------------------------------------------------ catalogos

CREATE TABLE sede (
    id     BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nombre TEXT NOT NULL UNIQUE CHECK (btrim(nombre) <> '')
);

-- No hay ubicacion en texto libre (RF-04, RF-09).
CREATE TABLE ubicacion (
    id      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sede_id BIGINT NOT NULL REFERENCES sede (id),
    nombre  TEXT   NOT NULL CHECK (btrim(nombre) <> ''),
    UNIQUE (sede_id, nombre),
    UNIQUE (id, sede_id) -- destino de la FK compuesta de equipo
);

CREATE TABLE feriado (
    fecha  DATE PRIMARY KEY,
    nombre TEXT NOT NULL
);

-- ------------------------------------------------------------------ usuarios

CREATE TABLE usuario (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nombre             TEXT        NOT NULL CHECK (btrim(nombre) <> ''),
    usuario            TEXT        NOT NULL UNIQUE CHECK (usuario = lower(btrim(usuario)) AND usuario <> ''),
    clave_hash         TEXT,       -- NULL = sin credenciales (usuario de sistema, p. ej. carga_inicial)
    rol                TEXT        NOT NULL CHECK (rol IN (
                           'docente', 'admin', 'soporte_tecnico', 'soporte_informatica', 'jefe_soporte', 'jefe_informatica')),
    sede_id            BIGINT      REFERENCES sede (id),
    activo             BOOLEAN     NOT NULL DEFAULT TRUE,
    debe_cambiar_clave BOOLEAN     NOT NULL DEFAULT FALSE,
    creado_en          TIMESTAMPTZ NOT NULL
);

CREATE TABLE bloqueo_login (
    usuario         TEXT        NOT NULL,
    ip              TEXT        NOT NULL,
    fallos          INTEGER     NOT NULL DEFAULT 0,
    ultimo_fallo    TIMESTAMPTZ NOT NULL,
    bloqueado_hasta TIMESTAMPTZ,
    PRIMARY KEY (usuario, ip)
);

-- ------------------------------------------------------------------ equipos

-- "Fuera de servicio" no es una columna: se deriva (equipo activo con al menos una incidencia abierta).
CREATE TABLE equipo (
    id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    codigo       TEXT        NOT NULL UNIQUE CHECK (codigo = upper(btrim(codigo)) AND codigo <> ''),
    tipo         TEXT        NOT NULL CHECK (tipo IN (
                     'computadora', 'impresora', 'proyector', 'dispositivo_red', 'equipo_laboratorio')),
    sede_id      BIGINT      NOT NULL,
    ubicacion_id BIGINT      NOT NULL,
    criticidad   TEXT        NOT NULL CHECK (criticidad IN ('alta', 'media', 'baja')),
    activo       BOOLEAN     NOT NULL DEFAULT TRUE,  -- FALSE = dado de baja (no_reparable)
    creado_en    TIMESTAMPTZ NOT NULL,
    -- la ubicacion del equipo pertenece a su sede
    FOREIGN KEY (ubicacion_id, sede_id) REFERENCES ubicacion (id, sede_id)
);

-- ------------------------------------------------------------------ incidencias

CREATE TABLE incidencia (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    equipo_id           BIGINT      NOT NULL REFERENCES equipo (id),
    sede_id             BIGINT      NOT NULL REFERENCES sede (id), -- se copia al registrar y NO cambia (RF-11)
    reportante_id       BIGINT      NOT NULL REFERENCES usuario (id),
    registrador_id      BIGINT      NOT NULL REFERENCES usuario (id),
    canal               TEXT        NOT NULL CHECK (canal IN ('sistema', 'llamada', 'mensaje', 'formulario')),
    descripcion         TEXT        NOT NULL CHECK (btrim(descripcion) <> ''),
    impacto             TEXT        NOT NULL DEFAULT 'ninguno' CHECK (impacto IN ('ninguno', 'clase', 'evaluacion')),
    prioridad           TEXT        NOT NULL CHECK (prioridad IN ('alta', 'media', 'baja')),
    prioridad_manual    BOOLEAN     NOT NULL DEFAULT FALSE,
    revisar_prioridad   BOOLEAN     NOT NULL DEFAULT FALSE,    -- RF-05, RN-04, D10
    estado              TEXT        NOT NULL DEFAULT 'registrada' CHECK (estado IN (
                            'registrada', 'asignada', 'en_atencion', 'en_espera', 'resuelta', 'cerrada')),
    tecnico_id          BIGINT      REFERENCES usuario (id),
    fecha_registro      TIMESTAMPTZ NOT NULL,
    fecha_resuelta      TIMESTAMPTZ,
    fecha_cierre        TIMESTAMPTZ,
    fecha_ultimo_cambio TIMESTAMPTZ NOT NULL,                  -- RF-08

    -- RN-02: con tecnico desde Asignada hasta Resuelta
    CONSTRAINT incidencia_tecnico_requerido CHECK (
        estado NOT IN ('asignada', 'en_atencion', 'en_espera', 'resuelta') OR tecnico_id IS NOT NULL),
    CONSTRAINT incidencia_fecha_resuelta CHECK (
        estado NOT IN ('resuelta', 'cerrada') OR fecha_resuelta IS NOT NULL),
    CONSTRAINT incidencia_fecha_cierre CHECK (
        estado <> 'cerrada' OR fecha_cierre IS NOT NULL)
);

-- Reportes que se vinculan a una incidencia abierta (RF-03)
CREATE TABLE reporte_vinculado (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    incidencia_id  BIGINT      NOT NULL REFERENCES incidencia (id),
    reportante_id  BIGINT      NOT NULL REFERENCES usuario (id),
    registrador_id BIGINT      NOT NULL REFERENCES usuario (id),
    canal          TEXT        NOT NULL CHECK (canal IN ('sistema', 'llamada', 'mensaje', 'formulario')),
    descripcion    TEXT        NOT NULL CHECK (btrim(descripcion) <> ''),
    fecha          TIMESTAMPTZ NOT NULL
);

CREATE TABLE atencion (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    incidencia_id BIGINT      NOT NULL REFERENCES incidencia (id),
    tecnico_id    BIGINT      NOT NULL REFERENCES usuario (id),
    diagnostico   TEXT        NOT NULL,
    acciones      TEXT        NOT NULL,
    resultado     TEXT        CHECK (resultado IN ('reparado', 'no_reparable')), -- NULL = atencion parcial
    fecha         TIMESTAMPTZ NOT NULL
);

-- Repuestos como filas (no en JSON): un indicador los cuenta (RF-11)
CREATE TABLE atencion_repuesto (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    atencion_id BIGINT  NOT NULL REFERENCES atencion (id),
    nombre      TEXT    NOT NULL CHECK (btrim(nombre) <> ''),
    cantidad    INTEGER NOT NULL DEFAULT 1 CHECK (cantidad > 0)
);

CREATE TABLE autorizacion (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    incidencia_id   BIGINT      NOT NULL REFERENCES incidencia (id),
    tipo            TEXT        NOT NULL CHECK (tipo IN ('reparacion_externa', 'compra', 'reemplazo')),
    justificacion   TEXT        NOT NULL CHECK (btrim(justificacion) <> ''),
    solicitante_id  BIGINT      NOT NULL REFERENCES usuario (id),
    estado          TEXT        NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente', 'aprobada', 'rechazada')),
    decidido_por    BIGINT      REFERENCES usuario (id),
    motivo          TEXT,
    fecha_solicitud TIMESTAMPTZ NOT NULL,
    fecha_decision  TIMESTAMPTZ,
    CONSTRAINT autorizacion_decision_completa CHECK (
        (estado = 'pendiente' AND decidido_por IS NULL AND fecha_decision IS NULL)
        OR (estado IN ('aprobada', 'rechazada') AND decidido_por IS NOT NULL
            AND fecha_decision IS NOT NULL AND motivo IS NOT NULL AND btrim(motivo) <> '')
    )
);

-- ------------------------------------------------------------------ registros inmutables

-- Solo INSERT para el usuario de la aplicacion; sin triggers (RN-07, RNF-06).
-- Lo que un indicador filtre va en columnas tipadas, nunca dentro de `datos`.
CREATE TABLE evento (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    incidencia_id  BIGINT      NOT NULL REFERENCES incidencia (id),
    usuario_id     BIGINT      NOT NULL REFERENCES usuario (id),
    accion         TEXT        NOT NULL,
    estado_antes   TEXT        CHECK (estado_antes IN (
                       'registrada', 'asignada', 'en_atencion', 'en_espera', 'resuelta', 'cerrada')),
    estado_despues TEXT        CHECK (estado_despues IN (
                       'registrada', 'asignada', 'en_atencion', 'en_espera', 'resuelta', 'cerrada')),
    motivo         TEXT,
    datos          JSONB       NOT NULL DEFAULT '{}'::jsonb,
    fecha          TIMESTAMPTZ NOT NULL
);

-- Auditoria de lo que no es una incidencia (altas de usuarios, cambios de rol, bajas de equipo, criticidad...).
-- `evento` exige una incidencia, asi que estos cambios van en una tabla aparte, solo-INSERT (database/grants.sql).
CREATE TABLE auditoria (
    id         BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    usuario_id BIGINT      NOT NULL REFERENCES usuario (id),
    entidad    TEXT        NOT NULL,   -- 'usuario', 'equipo', 'ubicacion'
    entidad_id BIGINT      NOT NULL,
    accion     TEXT        NOT NULL,
    motivo     TEXT,
    datos      JSONB       NOT NULL DEFAULT '{}'::jsonb,
    fecha      TIMESTAMPTZ NOT NULL
);

-- Idempotencia (RNF-07, D14): misma (usuario, clave) = mismo resultado
CREATE TABLE idempotencia (
    usuario_id BIGINT      NOT NULL REFERENCES usuario (id),
    clave      TEXT        NOT NULL,
    accion     TEXT        NOT NULL,
    recurso_id BIGINT      NOT NULL,
    creado_en  TIMESTAMPTZ NOT NULL,
    UNIQUE (usuario_id, clave)
);

-- ------------------------------------------------------------------ indices
-- PostgreSQL no indexa las claves foraneas: se indexan las que usan las consultas.

-- Equipos: filtros por sede y por ubicacion
CREATE INDEX equipo_sede_idx      ON equipo (sede_id);
CREATE INDEX equipo_ubicacion_idx ON equipo (ubicacion_id);

-- Incidencias: lista por sede ordenada por fecha, y colas de abiertas por estado actual (columnas, no eventos)
CREATE INDEX incidencia_sede_fecha_idx ON incidencia (sede_id, fecha_registro);
CREATE INDEX incidencia_abiertas_idx
    ON incidencia (sede_id, prioridad, fecha_registro)
    WHERE estado IN ('registrada', 'asignada', 'en_atencion', 'en_espera');
CREATE INDEX incidencia_tecnico_idx    ON incidencia (tecnico_id) WHERE tecnico_id IS NOT NULL;
CREATE INDEX incidencia_reportante_idx ON incidencia (reportante_id);
CREATE INDEX incidencia_equipo_idx     ON incidencia (equipo_id);

CREATE INDEX reporte_vinculado_incidencia_idx ON reporte_vinculado (incidencia_id);
CREATE INDEX reporte_vinculado_reportante_idx ON reporte_vinculado (reportante_id);
CREATE INDEX atencion_incidencia_idx          ON atencion (incidencia_id);
CREATE INDEX atencion_repuesto_atencion_idx   ON atencion_repuesto (atencion_id);
CREATE INDEX autorizacion_incidencia_idx      ON autorizacion (incidencia_id);

-- Una sola autorizacion pendiente por incidencia
CREATE UNIQUE INDEX autorizacion_una_pendiente_idx
    ON autorizacion (incidencia_id) WHERE estado = 'pendiente';

-- Evento: historial de una incidencia (ordenado por fecha, id), consultas por rango de fechas y por accion
CREATE INDEX evento_incidencia_idx ON evento (incidencia_id, fecha, id);
CREATE INDEX evento_fecha_idx      ON evento (fecha, id);
CREATE INDEX evento_accion_idx     ON evento (accion, fecha);

-- Auditoria: cambios de una entidad
CREATE INDEX auditoria_entidad_idx ON auditoria (entidad, entidad_id, fecha);
