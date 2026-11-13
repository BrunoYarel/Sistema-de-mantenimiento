-- database/dev/00_roles.sql
-- SOLO DESARROLLO. Lo ejecuta el contenedor de PostgreSQL la primera vez (docker-compose.yml).
-- Las claves de aqui son de juguete; en el servidor real se crean con otras y fuera del repositorio.
-- El contenedor ya crea la base caso_a y el rol caso_a_dueno (variables POSTGRES_*).

CREATE ROLE caso_a_app LOGIN PASSWORD 'app_dev';

REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT ALL ON SCHEMA public TO caso_a_dueno;
GRANT USAGE ON SCHEMA public TO caso_a_app;
