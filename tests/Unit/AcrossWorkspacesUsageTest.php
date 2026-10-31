<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Review rule as a test (docs/MULTI-USER-PLAN.md §5, §10): workspace-unscoped queries exist only where they are allowed
 * and never feed a response. acrossWorkspaces() = global uniqueness of ticket prefix/key and the parent lookup of
 * console writes; the scope is removed nowhere else; raw DB::table() touches only tables that are not workspace-owned
 * (sessions) or writes with an explicit workspace_id. A new use must be reviewed and added here on purpose.
 */
class AcrossWorkspacesUsageTest extends TestCase
{
    /** "file: trimmed line" for every match of $pattern under app/ and routes/ */
    private function uses(string $pattern): array
    {
        $root = dirname(__DIR__, 2);
        $hits = [];
        foreach (['app', 'routes'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir")) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                foreach (file($file->getPathname()) as $line) {
                    if (preg_match($pattern, $line)) {
                        $hits[] = substr($file->getPathname(), strlen($root) + 1);
                    }
                }
            }
        }
        sort($hits);

        return array_count_values($hits);
    }

    #[Test]
    public function across_workspaces_is_used_only_in_the_allowed_places(): void
    {
        $this->assertSame([
            'app/Models/Concerns/BelongsToWorkspace.php' => 2, // the definition + parentWorkspaceId() (console writes)
            'app/Models/Project.php' => 1,                     // ticket_prefix: globally unique for now
            'app/Services/Tickets/TicketService.php' => 2,     // ticket_prefix + ticket key: globally unique for now
            'app/Services/Usage/CliUsageImporter.php' => 3,    // markers/rows/upsert, each filtered by the explicit current workspace_id
            'app/Services/Usage/CursorCsvImporter.php' => 2,   // edge-day marker + stored calls, filtered by the same explicit workspace_id
        ], $this->uses('/acrossWorkspaces\s*\(/'));
    }

    #[Test]
    public function the_workspace_scope_is_removed_nowhere_else(): void
    {
        $this->assertSame([
            'app/Models/Concerns/BelongsToWorkspace.php' => 1, // inside acrossWorkspaces()
        ], $this->uses('/withoutGlobalScopes?\s*\(/'));
    }

    #[Test]
    public function raw_table_queries_touch_only_unowned_tables_or_write_an_explicit_workspace(): void
    {
        $this->assertSame([
            'app/Services/Auth/AccountPurge.php' => 7,   // console purge: rows of the purged user's own workspaces only, never a response
            'app/Services/Auth/DeviceSessions.php' => 3, // sessions (not workspace-owned)
            'app/Services/BridgeRoster.php' => 1,        // project_pm_events insert, workspace_id = the project's
        ], $this->uses('/DB::(table|select|update|delete|insert|statement)\s*\(/'));
    }
}
