<?php

declare(strict_types=1);

/**
 * Tenant-scope lint.
 *
 * MariaDB has no row-level security, so a `tenant_id` predicate is the *only*
 * isolation mechanism. A forgotten predicate is invisible in review because the
 * defect is the absence of a line, so this lint fails when a statement touches a
 * tenant-scoped table through `table()` or `join()` without naming `tenant_id`.
 *
 * Existing, reviewed occurrences are recorded in tenant-scope-allowlist.json so
 * the gate is precise rather than heuristic: it lands green and only blocks new
 * violations. Entries are keyed by `path#table#ordinal`, never by line number, so
 * unrelated edits above a query cannot invalidate the list.
 *
 * `app/Database/` is not scanned: migrations and seeds are schema/backfill work
 * that deliberately touches every tenant.
 *
 * Usage:
 *   php tools/tenant-scope-lint.php               # exit 1 on new violations
 *   php tools/tenant-scope-lint.php --list-tables # print every table name in use
 *   php tools/tenant-scope-lint.php --write-allow-list # regenerate the allow-list
 */

const TENANT_COLUMN = 'tenant_id';

/** Tables with no tenant_id column: framework tables, global lookups, cross-tenant administration. */
const NON_TENANT_TABLES = [
    'information_schema.columns',
    'information_schema.tables',
    'login_attempt_buckets',
    'migrations',
    'permissions',
    'role_permissions',
    'tenants',
];

/** Tables whose rows are tenant-scoped, derived from the migrations that give them a tenant_id. */
const TENANT_SCOPED_TABLES = [
    'ai_dispatch_attempts',
    'ai_insights',
    'ai_jobs',
    'ai_releases',
    'ai_reviews',
    'ai_snapshots',
    'announcements',
    'announcements_reads',
    'appointments',
    'audit_events',
    'availability_exceptions',
    'bmg_batches',
    'bmg_devices',
    'bmg_rotation_commands',
    'bmg_rotation_runs',
    'bmg_rotation_schedules',
    'bmg_units',
    'carousel_slides',
    'clinic_dispensations',
    'clinic_encounters',
    'clinic_medicine_batches',
    'clinic_medicines',
    'clinic_treatments',
    'counselling_cases',
    'counselling_notes',
    'counselling_sessions',
    'department_staff',
    'employee_profiles',
    'health_profiles',
    'inventory_operations',
    'notifications',
    'people',
    'push_tokens',
    'queue_entries',
    'referrals',
    'stock_movements',
    'student_profiles',
    'survey_question_bank',
    'survey_question_bank_revisions',
    'survey_responses',
    'surveys',
    'team_chat_messages',
    'team_chat_reads',
    'team_event_participants',
    'team_events',
    'team_task_activity',
    'team_task_comments',
    'team_tasks',
    'users',
    'workflow_alert_states',
    'workflow_events',
];

/** Directories whose queries are schema/backfill work rather than request paths. */
const SKIPPED_DIRECTORIES = ['/app/Database/'];

/**
 * @return list<string> absolute paths of every scanned PHP file
 */
function scannedFiles(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        foreach (SKIPPED_DIRECTORIES as $skipped) {
            if (str_contains(str_replace('\\', '/', $path), $skipped)) {
                continue 2;
            }
        }
        $files[] = $path;
    }
    sort($files);

    return $files;
}

/**
 * Every `->table('<name>')` / `->join('<name>', ...)` in a file, paired with the
 * source text of the statement it belongs to.
 *
 * @return list<array{line: int, table: string, statement: string}>
 */
function tableReferences(string $source): array
{
    $tokens = token_get_all($source);
    $references = [];
    $count = count($tokens);

    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if (! is_array($token) || $token[0] !== T_OBJECT_OPERATOR) {
            continue;
        }
        $name = $tokens[$index + 1] ?? null;
        if (! is_array($name) || $name[0] !== T_STRING || ! in_array($name[1], ['table', 'join'], true)) {
            continue;
        }
        $open = $tokens[$index + 2] ?? null;
        $argument = $tokens[$index + 3] ?? null;
        if ($open !== '(' || ! is_array($argument) || $argument[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue; // dynamic or non-literal table name: not statically decidable here
        }

        // The statement runs to the next top-level ';'.
        $statement = '';
        for ($cursor = $index; $cursor < $count; $cursor++) {
            $piece = $tokens[$cursor];
            $text = is_array($piece) ? $piece[1] : $piece;
            if ($text === ';') {
                break;
            }
            $statement .= $text . ' ';
        }

        $table = strtolower(preg_replace('/\s+.*$/', '', trim($argument[1], "'\"")) ?? $argument[1]);
        $references[] = [
            'line'      => $token[2],
            'table'     => $table,
            'statement' => $statement,
        ];
    }

    return $references;
}

function isTenantScoped(string $table): bool
{
    return in_array($table, TENANT_SCOPED_TABLES, true) && ! in_array($table, NON_TENANT_TABLES, true);
}

$root = dirname(__DIR__);
$arguments = $argv ?? [];
$listTables = in_array('--list-tables', $arguments, true);
$writeAllowList = in_array('--write-allow-list', $arguments, true);

/** @var array<string, array{table: string, line: int, statement: string}> $violations */
$violations = [];
$discovered = [];
foreach (scannedFiles($root) as $path) {
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $ordinals = [];
    foreach (tableReferences((string) file_get_contents($path)) as $reference) {
        $table = $reference['table'];
        $discovered[$table] = true;
        if (! isTenantScoped($table)) {
            continue;
        }
        if (str_contains($reference['statement'], TENANT_COLUMN)) {
            continue;
        }
        $ordinals[$table] = ($ordinals[$table] ?? 0) + 1;
        $key = $relative . '#' . $table . '#' . $ordinals[$table];
        $violations[$key] = ['table' => $table, 'line' => $reference['line']];
    }
}
ksort($violations);

if ($listTables) {
    ksort($discovered);
    echo implode(PHP_EOL, array_keys($discovered)), PHP_EOL;
    exit(0);
}

$allowPath = __DIR__ . '/tenant-scope-allowlist.json';
if ($writeAllowList) {
    file_put_contents($allowPath, json_encode(array_keys($violations), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    echo sprintf('Recorded %d reviewed exception(s) in tools/tenant-scope-allowlist.json.', count($violations)), PHP_EOL;
    exit(0);
}

$allowList = is_file($allowPath)
    ? array_values(array_filter((array) json_decode((string) file_get_contents($allowPath), true), 'is_string'))
    : [];

$new = array_values(array_diff(array_keys($violations), $allowList));
$stale = array_values(array_diff($allowList, array_keys($violations)));

if ($new !== []) {
    fwrite(STDERR, 'Tenant-scope lint failed: a statement touches a tenant-scoped table without a tenant_id predicate.' . PHP_EOL);
    foreach ($new as $key) {
        fwrite(STDERR, sprintf('  %s  (line %d)', $key, $violations[$key]['line']) . PHP_EOL);
    }
    fwrite(STDERR, PHP_EOL . 'Add the tenant predicate, or record a reviewed exception with --write-allow-list.' . PHP_EOL);
    exit(1);
}

if ($stale !== []) {
    fwrite(STDERR, 'Tenant-scope lint: allow-list entries no longer match a violation (remove them):' . PHP_EOL);
    foreach ($stale as $key) {
        fwrite(STDERR, '  ' . $key . PHP_EOL);
    }
    exit(1);
}

echo sprintf('Tenant-scope lint passed (%d reviewed exception%s).', count($allowList), count($allowList) === 1 ? '' : 's'), PHP_EOL;
exit(0);
