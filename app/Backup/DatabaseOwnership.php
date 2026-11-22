<?php

namespace App\Backup;

use Illuminate\Support\Facades\DB;

/**
 * Tables, views and sequences in the app's schema that the app's own database user does not own (19–22 Nov 2026: a
 * copy made by hand as `postgres` stopped pg_dump four nights running). The backup names them, and leaves out of the
 * dump only the ones the app's user cannot read, so the rest is still saved.
 */
class DatabaseOwnership
{
    /** @return list<array{name: string, owner: string, readable: bool}> */
    public function foreign(): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return [];
        }

        $rows = DB::select(<<<'SQL'
            select c.relname as name, pg_get_userbyid(c.relowner) as owner,
                case when c.relkind = 'S' then has_sequence_privilege(c.oid, 'SELECT') else has_table_privilege(c.oid, 'SELECT') end as readable
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = current_schema()
              and c.relkind in ('r', 'p', 'v', 'm', 'S', 'f')
              and c.relowner <> (select oid from pg_roles where rolname = current_user)
            order by c.relname
            SQL);

        return array_map(fn ($r) => ['name' => (string) $r->name, 'owner' => (string) $r->owner, 'readable' => (bool) $r->readable], $rows);
    }

    public function user(): string
    {
        return (string) config('database.connections.pgsql.username');
    }
}
