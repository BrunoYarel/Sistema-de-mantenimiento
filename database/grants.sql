-- database/grants.sql
-- Permisos del usuario de la aplicacion (caso_a_app). Versionado y re-ejecutable:
--     php bin/migrar.php --grants
--
-- Principio: nada se concede por defecto (default deny). Cada tabla nueva se agrega AQUI.
-- evento, idempotencia y auditoria: solo SELECT + INSERT. Nunca UPDATE, DELETE ni TRUNCATE
-- (RN-07, RNF-06). Sin triggers: lo hace cumplir el permiso, no un programa.
--
-- PASO 0 (una sola vez, como superusuario; las claves NO se versionan):
--     CREATE ROLE caso_a_dueno LOGIN PASSWORD '...';
--     CREATE ROLE caso_a_app   LOGIN PASSWORD '...';
--     CREATE DATABASE caso_a OWNER caso_a_dueno;
--     \c caso_a
--     REVOKE ALL ON SCHEMA public FROM PUBLIC;
--     GRANT ALL ON SCHEMA public TO caso_a_dueno;
--     GRANT USAGE ON SCHEMA public TO caso_a_app;
-- En desarrollo lo hace docker compose (database/dev/00_roles.sql).
--
-- El dueno de las tablas (caso_a_dueno) NUNCA es el usuario de la aplicacion.

REVOKE ALL ON ALL TABLES IN SCHEMA public FROM caso_a_app;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM caso_a_app;
GRANT USAGE ON SCHEMA public TO caso_a_app;

-- Lectura, alta y modificacion; sin borrado
GRANT SELECT, INSERT, UPDATE ON
    sede, ubicacion, usuario, equipo, incidencia, reporte_vinculado,
    atencion, atencion_repuesto, autorizacion, bloqueo_login
TO caso_a_app;

-- Solo agregar
GRANT SELECT, INSERT ON evento, idempotencia, auditoria TO caso_a_app;

-- Solo leer (se mantiene con migraciones / seed)
GRANT SELECT ON feriado TO caso_a_app;

GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO caso_a_app;