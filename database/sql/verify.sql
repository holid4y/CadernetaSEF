-- Read-only check of a database prepared by roles.sql, the migrations and
-- privileges.sql. It changes nothing, so it can run against production with
-- any of the three roles. It stops at the end with the list of problems.
--
--   psql "host=... dbname=caderneta_sef user=agenda_app sslmode=verify-full sslrootcert=..." \
--        -f database/sql/verify.sql

\set ON_ERROR_STOP on

DO $$
DECLARE
    problems text[] := '{}';
    expected record;
BEGIN
    FOR expected IN
        SELECT * FROM (VALUES
            ('agenda_app', 'appointments', 'SELECT', true),
            ('agenda_app', 'appointments', 'INSERT', true),
            ('agenda_app', 'appointments', 'UPDATE', true),
            ('agenda_app', 'appointments', 'DELETE', false),
            ('agenda_app', 'appointments', 'TRUNCATE', false),
            ('agenda_app', 'appointment_documents', 'UPDATE', true),
            ('agenda_app', 'appointment_documents', 'DELETE', false),
            ('agenda_app', 'appointment_activities', 'INSERT', true),
            ('agenda_app', 'appointment_activities', 'UPDATE', false),
            ('agenda_app', 'appointment_activities', 'DELETE', false),
            ('agenda_app', 'clients', 'UPDATE', true),
            ('agenda_app', 'clients', 'DELETE', false),
            ('agenda_app', 'users', 'UPDATE', true),
            ('agenda_app', 'users', 'DELETE', false),
            ('agenda_app', 'data_audits', 'INSERT', true),
            ('agenda_app', 'data_audits', 'UPDATE', false),
            ('agenda_app', 'data_audits', 'DELETE', false),
            ('agenda_app', 'form_webhook_events', 'INSERT', true),
            ('agenda_app', 'form_webhook_events', 'UPDATE', false),
            ('agenda_app', 'external_forms', 'DELETE', false),
            ('agenda_app', 'service_documents', 'DELETE', true),
            ('agenda_app', 'services', 'DELETE', false),
            ('agenda_app', 'personal_access_tokens', 'DELETE', true),
            ('agenda_app', 'sessions', 'DELETE', true),
            ('agenda_app', 'jobs', 'UPDATE', true),
            ('agenda_app', 'jobs', 'DELETE', true),
            ('agenda_app', 'cache_locks', 'DELETE', true),
            ('agenda_app', 'migrations', 'SELECT', true),
            ('agenda_app', 'migrations', 'INSERT', false),
            ('agenda_reports', 'appointment_facts', 'SELECT', true),
            ('agenda_reports', 'appointments', 'SELECT', false),
            ('agenda_reports', 'clients', 'SELECT', false),
            ('agenda_reports', 'users', 'SELECT', false),
            ('agenda_reports', 'personal_access_tokens', 'SELECT', false)
        ) AS rows (role_name, table_name, privilege, allowed)
    LOOP
        IF has_table_privilege(expected.role_name, 'public.' || expected.table_name, expected.privilege) <> expected.allowed THEN
            problems := problems || format('%s %s on %s should be %s',
                expected.role_name, expected.privilege, expected.table_name,
                CASE WHEN expected.allowed THEN 'granted' ELSE 'revoked' END);
        END IF;
    END LOOP;

    IF has_schema_privilege('agenda_app', 'public', 'CREATE') OR has_schema_privilege('agenda_reports', 'public', 'CREATE') THEN
        problems := array_append(problems, 'agenda_app and agenda_reports must not create objects');
    END IF;

    -- A membership (in agenda_owner or a pg_* role) would hand over its rights through SET ROLE.
    IF EXISTS (SELECT FROM pg_auth_members m JOIN pg_roles r ON r.oid = m.member
               WHERE r.rolname IN ('agenda_app', 'agenda_reports')) THEN
        problems := array_append(problems, 'agenda_app and agenda_reports must not be members of another role');
    END IF;

    IF EXISTS (SELECT FROM pg_roles WHERE rolname IN ('agenda_owner', 'agenda_app', 'agenda_reports')
               AND (rolsuper OR rolcreaterole OR rolcreatedb OR rolbypassrls)) THEN
        problems := array_append(problems, 'application roles must not be superuser, create roles or databases, or bypass RLS');
    END IF;

    IF EXISTS (SELECT FROM pg_tables WHERE schemaname = 'public' AND tableowner IN ('agenda_app', 'agenda_reports')) THEN
        problems := array_append(problems, 'tables must belong to the owner, not to agenda_app or agenda_reports');
    END IF;

    IF NOT EXISTS (SELECT FROM pg_extension WHERE extname = 'btree_gist') THEN
        problems := array_append(problems, 'extension btree_gist is missing');
    END IF;

    IF NOT EXISTS (SELECT FROM pg_constraint WHERE conname = 'appointments_no_overlap' AND contype = 'x') THEN
        problems := array_append(problems, 'exclusion constraint appointments_no_overlap is missing');
    END IF;

    IF (SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgenabled = 'O' AND tgname IN (
            'appointments_keep_rows', 'appointment_activities_append_only', 'appointment_activities_no_truncate',
            'data_audits_append_only', 'data_audits_no_truncate', 'clients_audit', 'users_audit')) <> 7 THEN
        problems := array_append(problems, 'one of the history, audit or keep-rows triggers is missing or disabled');
    END IF;

    IF NOT coalesce((SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()), false) THEN
        problems := array_append(problems, 'this connection is not encrypted');
    END IF;

    IF cardinality(problems) > 0 THEN
        RAISE EXCEPTION E'Database check failed:\n- %', array_to_string(problems, E'\n- ');
    END IF;

    RAISE NOTICE 'Database check passed: grants, triggers, constraints and TLS are as expected.';
END
$$;
