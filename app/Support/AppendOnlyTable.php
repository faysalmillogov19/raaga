<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Pose des triggers qui interdisent UPDATE et DELETE sur une table (journal d'audit,
 * registre des actions, preuves d'acceptation…). La protection est en base : elle
 * s'applique même à du code qui contournerait les modèles Eloquent.
 */
final class AppendOnlyTable
{
    public static function protect(string $table): void
    {
        $message = "La table {$table} est en ajout seul : modification et suppression interdites.";

        foreach (['UPDATE', 'DELETE'] as $operation) {
            $trigger = self::triggerName($table, $operation);

            match (DB::getDriverName()) {
                'mysql', 'mariadb' => DB::unprepared(
                    "CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table} FOR EACH ROW "
                    ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'"
                ),
                'sqlite' => DB::unprepared(
                    "CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table} "
                    ."BEGIN SELECT RAISE(ABORT, '{$message}'); END"
                ),
                'pgsql' => (function () use ($trigger, $operation, $table, $message): void {
                    DB::unprepared(
                        "CREATE OR REPLACE FUNCTION {$trigger}() RETURNS trigger AS \$\$ "
                        ."BEGIN RAISE EXCEPTION '{$message}'; END; \$\$ LANGUAGE plpgsql"
                    );
                    DB::unprepared(
                        "CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table} "
                        ."FOR EACH ROW EXECUTE FUNCTION {$trigger}()"
                    );
                })(),
                default => null,
            };
        }
    }

    public static function unprotect(string $table): void
    {
        foreach (['UPDATE', 'DELETE'] as $operation) {
            $trigger = self::triggerName($table, $operation);

            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
                DB::unprepared("DROP FUNCTION IF EXISTS {$trigger}()");
            } else {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            }
        }
    }

    private static function triggerName(string $table, string $operation): string
    {
        return "{$table}_no_".strtolower($operation);
    }
}
