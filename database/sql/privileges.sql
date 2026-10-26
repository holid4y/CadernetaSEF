-- Least-privilege grants. Run after every migration, connected as the owner
-- of the tables (agenda_owner, created by roles.sql) or as an administrator
-- that is a member of it. Safe to run again.
--
--   psql "host=... dbname=caderneta_sef user=agenda_owner sslmode=verify-full sslrootcert=..." \
--        -f database/sql/privileges.sql
--
-- A database whose tables belong to another role (an older installation
-- owned by "agenda", for example) passes it as -v owner=agenda.

\set ON_ERROR_STOP on
\if :{?owner}
\else
    \set owner agenda_owner
\endif

BEGIN;

REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO agenda_app, agenda_reports;

-- Application: data only, no DDL.
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO agenda_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO agenda_app;

-- History, audit and webhook receipts are append-only; people, appointments
-- and the catalog are deactivated, cancelled or soft deleted, never removed.
-- The triggers enforce the same rules even for the owner; the grants make
-- the attempt fail earlier.
REVOKE UPDATE, DELETE, TRUNCATE ON appointment_activities, data_audits, form_webhook_events FROM agenda_app;
REVOKE DELETE, TRUNCATE ON clients, appointments, appointment_documents, users, services, service_categories, external_forms FROM agenda_app;
REVOKE ALL ON migrations FROM agenda_app;
GRANT SELECT ON migrations TO agenda_app;

-- Reports: the view without personal data, nothing else.
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM agenda_reports;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM agenda_reports;
GRANT SELECT ON appointment_facts TO agenda_reports;

-- Tables created by future migrations follow the same rule for the app.
-- Append-only tables added later need their own REVOKE above.
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO agenda_app;
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner" IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO agenda_app;

COMMIT;
