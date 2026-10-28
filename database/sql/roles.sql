-- Login roles for the application database. Run once per server, and again
-- to change passwords, connected to the application database as its
-- administrator: the master user on Amazon RDS, a superuser elsewhere.
--
--   agenda_owner    owns the tables and runs the migrations
--   agenda_app      what the application connects as (see privileges.sql)
--   agenda_reports  reads the report view only
--
-- Passwords are read from the environment, so they never appear in the
-- shell history or in the process list:
--
--   read -rs AGENDA_OWNER_PASSWORD && export AGENDA_OWNER_PASSWORD
--   read -rs AGENDA_APP_PASSWORD && export AGENDA_APP_PASSWORD
--   read -rs AGENDA_REPORTS_PASSWORD && export AGENDA_REPORTS_PASSWORD
--   psql "host=... dbname=caderneta_sef user=postgres sslmode=verify-full sslrootcert=..." \
--        -f database/sql/roles.sql
--
-- The administrator does not need to be a superuser: CREATEROLE and the
-- ownership of the database are enough, which is what RDS grants.

\set ON_ERROR_STOP on
\getenv owner_password AGENDA_OWNER_PASSWORD
\getenv app_password AGENDA_APP_PASSWORD
\getenv reports_password AGENDA_REPORTS_PASSWORD

SELECT :{?owner_password} AND :{?app_password} AND :{?reports_password} AS has_passwords \gset
\if :has_passwords
\else
    DO $$ BEGIN RAISE EXCEPTION 'Export AGENDA_OWNER_PASSWORD, AGENDA_APP_PASSWORD and AGENDA_REPORTS_PASSWORD first.'; END $$;
\endif

SELECT least(length(:'owner_password'), length(:'app_password'), length(:'reports_password')) >= 16 AS strong_enough \gset
\if :strong_enough
\else
    DO $$ BEGIN RAISE EXCEPTION 'Each password needs at least 16 characters.'; END $$;
\endif

BEGIN;

SELECT format('CREATE ROLE %I LOGIN', name)
FROM (VALUES ('agenda_owner'), ('agenda_app'), ('agenda_reports')) AS roles (name)
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = name) \gexec

-- New roles are never superusers, replicators or RLS bypassers, and on RDS
-- only rdsadmin could even mention those attributes; verify.sql checks them.
ALTER ROLE agenda_owner NOCREATEDB NOCREATEROLE CONNECTION LIMIT 5;
ALTER ROLE agenda_app NOCREATEDB NOCREATEROLE CONNECTION LIMIT 40;
ALTER ROLE agenda_reports NOCREATEDB NOCREATEROLE CONNECTION LIMIT 5;

-- A runaway query or a transaction left open by a crashed worker would
-- otherwise hold locks and connections until someone notices.
ALTER ROLE agenda_app SET statement_timeout = '30s';
ALTER ROLE agenda_app SET idle_in_transaction_session_timeout = '1min';
ALTER ROLE agenda_reports SET statement_timeout = '1min';
ALTER ROLE agenda_reports SET idle_in_transaction_session_timeout = '1min';

SELECT format('ALTER ROLE agenda_owner PASSWORD %L', :'owner_password') \gexec
SELECT format('ALTER ROLE agenda_app PASSWORD %L', :'app_password') \gexec
SELECT format('ALTER ROLE agenda_reports PASSWORD %L', :'reports_password') \gexec

-- PostgreSQL 16+ only lets the administrator hand the database over (and
-- fix grants later) if it may act as the owner role.
SELECT format('GRANT agenda_owner TO %I WITH INHERIT TRUE, SET TRUE', current_user) \gexec

SELECT format('ALTER DATABASE %I OWNER TO agenda_owner', current_database()) \gexec
SELECT format('REVOKE ALL ON DATABASE %I FROM PUBLIC', current_database()) \gexec
SELECT format('GRANT CONNECT ON DATABASE %I TO agenda_app, agenda_reports', current_database()) \gexec

-- On PostgreSQL 15+ the public schema belongs to the database owner, so
-- agenda_owner may now create the tables and the btree_gist extension.
REVOKE CREATE ON SCHEMA public FROM PUBLIC;

COMMIT;

\echo 'Roles ready. Run the migrations as agenda_owner, then privileges.sql.'
