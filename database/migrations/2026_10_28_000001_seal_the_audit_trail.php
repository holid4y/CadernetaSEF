<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // The audit rows are written with the rights of the function's owner, so
    // privileges.sql can take every right on data_audits away from the
    // application: it can no longer add rows that did not happen. Inside the
    // function current_user is the owner, so the role that made the change
    // comes from session_user.
    public function up(): void
    {
        DB::unprepared($this->auditFunction('SECURITY DEFINER SET search_path = public, pg_temp', 'session_user'));
    }

    public function down(): void
    {
        DB::unprepared($this->auditFunction('', 'current_user'));
    }

    private function auditFunction(string $security, string $role): string
    {
        return <<<SQL
            CREATE OR REPLACE FUNCTION audit_row_change() RETURNS trigger LANGUAGE plpgsql {$security} AS \$\$
            DECLARE
                changed jsonb;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    SELECT jsonb_agg(n.key ORDER BY n.key) INTO changed
                    FROM jsonb_each(to_jsonb(NEW)) AS n
                    WHERE n.value IS DISTINCT FROM to_jsonb(OLD) -> n.key
                      AND n.key NOT IN ('updated_at', 'remember_token');

                    IF changed IS NULL THEN
                        RETURN NULL;
                    END IF;
                END IF;

                INSERT INTO data_audits (table_name, row_id, operation, changed_columns, app_user_id, db_user, created_at)
                VALUES (
                    TG_TABLE_NAME,
                    (CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END ->> 'id')::bigint,
                    TG_OP,
                    changed,
                    NULLIF(current_setting('app.user_id', true), '')::bigint,
                    {$role},
                    now()
                );

                RETURN NULL;
            END;
            \$\$;
            SQL;
    }
};
