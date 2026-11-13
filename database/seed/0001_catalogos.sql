-- database/seed/0001_catalogos.sql
-- Datos de arranque. Re-ejecutable (ON CONFLICT DO NOTHING). Se aplica con: php bin/migrar.php --seed
--
-- [P] Cambiar los nombres de sede por los reales.
-- Solo si no hay ninguna sede: así, si el equipo las renombra, el seed NO vuelve a crear "Sede 1" y "Sede 2"
-- en cada arranque del contenedor.
INSERT INTO sede (nombre)
SELECT v.nombre FROM (VALUES ('Sede 1'), ('Sede 2')) AS v (nombre)
WHERE NOT EXISTS (SELECT 1 FROM sede);

-- Feriados nacionales 2026 y 2027 (16 por año). Los feriados o días no laborables extra que decrete el
-- gobierno se agregan aquí. La tabla debe cubrir hasta hoy + 60 días: si no, tests/reloj_habiles_test.php
-- falla y Habiles::feriados() deja un aviso en el log. Sin el calendario completo, "3 días hábiles" (RN-06)
-- da fechas incorrectas.
INSERT INTO feriado (fecha, nombre) VALUES
    ('2026-01-01', 'Año Nuevo'),
    ('2026-04-02', 'Jueves Santo'),
    ('2026-04-03', 'Viernes Santo'),
    ('2026-05-01', 'Día del Trabajo'),
    ('2026-06-07', 'Batalla de Arica y Día de la Bandera'),
    ('2026-06-29', 'San Pedro y San Pablo'),
    ('2026-07-23', 'Día de la Fuerza Aérea del Perú'),
    ('2026-07-28', 'Fiestas Patrias'),
    ('2026-07-29', 'Fiestas Patrias'),
    ('2026-08-06', 'Batalla de Junín'),
    ('2026-08-30', 'Santa Rosa de Lima'),
    ('2026-10-08', 'Combate de Angamos'),
    ('2026-11-01', 'Todos los Santos'),
    ('2026-12-08', 'Inmaculada Concepción'),
    ('2026-12-09', 'Batalla de Ayacucho'),
    ('2026-12-25', 'Navidad'),
    ('2027-01-01', 'Año Nuevo'),
    ('2027-03-25', 'Jueves Santo'),
    ('2027-03-26', 'Viernes Santo'),
    ('2027-05-01', 'Día del Trabajo'),
    ('2027-06-07', 'Batalla de Arica y Día de la Bandera'),
    ('2027-06-29', 'San Pedro y San Pablo'),
    ('2027-07-23', 'Día de la Fuerza Aérea del Perú'),
    ('2027-07-28', 'Fiestas Patrias'),
    ('2027-07-29', 'Fiestas Patrias'),
    ('2027-08-06', 'Batalla de Junín'),
    ('2027-08-30', 'Santa Rosa de Lima'),
    ('2027-10-08', 'Combate de Angamos'),
    ('2027-11-01', 'Todos los Santos'),
    ('2027-12-08', 'Inmaculada Concepción'),
    ('2027-12-09', 'Batalla de Ayacucho'),
    ('2027-12-25', 'Navidad')
ON CONFLICT (fecha) DO NOTHING;

-- TODO: usuario de sistema carga_inicial (sin credenciales, clave_hash NULL) para las 10 incidencias
-- de los equipos fuera de servicio (D11). Se crea con bin/usuarios.php o aqui, cuando se defina su rol.