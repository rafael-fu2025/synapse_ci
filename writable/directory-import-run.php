<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ENVIRONMENT', 'development');
define('FCPATH', dirname(__DIR__) . '/public/');
require dirname(__DIR__) . '/app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootConsole($paths);
if (env('CI_ENVIRONMENT') !== 'development') { throw new RuntimeException('Local development only.'); }
$config = config('Database')->default;
if ($config['database'] !== 'humus_db' || !in_array($config['hostname'], ['localhost', '127.0.0.1', '::1'], true)
    || !empty($config['DSN']) || !empty($config['failover'])) {
    throw new RuntimeException('Unexpected import connection.');
}
$config['pConnect'] = false;
$config['DBPrefix'] = '';
$config['strictOn'] = true;
$target = \Config\Database::connect($config, false);
$config['database'] = 'synapse_zcode';
$source = \Config\Database::connect($config, false);
$importer = new \App\Services\Directory\LegacyDirectoryImporter($source, $target);
$apply = ($argv[1] ?? '') === '--apply';
$plan = $importer->plan();
$report = $importer->report($plan);
$manifest = __DIR__ . '/backups/directory_import_dry_run.json';
if (!$apply) {
    file_put_contents($manifest, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
} else {
    $expected = is_file($manifest) ? json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR) : null;
    if (!is_array($expected) || !hash_equals($expected['fingerprint'] ?? '', $report['fingerprint'])) {
        throw new RuntimeException('Source changed or no approved dry-run report exists. Run the dry run again.');
    }
    if (($report['planned'] ?? 0) > 10000) {
        throw new RuntimeException('Import exceeds the reviewed directory size.');
    }
    $backupPath = $argv[2] ?? '';
    $backupRoot = realpath(__DIR__ . '/backups');
    $backupFile = realpath($backupPath);
    if (!$backupRoot || !$backupFile || dirname($backupFile) !== $backupRoot || !str_ends_with($backupFile, '.sql')) {
        throw new RuntimeException('A restricted local pre-import SQL backup is required.');
    }
    $metadataPath = substr($backupFile, 0, -4) . '.json';
    $metadata = is_file($metadataPath) ? json_decode(file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR) : null;
    if (!is_array($metadata) || ($metadata['database'] ?? '') !== 'humus_db'
        || !($metadata['native_dump_completed'] ?? false)
        || !hash_equals($metadata['sha256'] ?? '', hash_file('sha256', $backupFile))
        || time() - filemtime($backupFile) > 3600) {
        throw new RuntimeException('A fresh, complete backup with matching checksum is required.');
    }
    // Fingerprint all existing target rows and non-directory tables inside the same
    // transaction-independent read-only pass; output only aggregate verification.
    $beforePeople = $target->table('people')->orderBy('id')->get()->getResultArray();
    $beforeStudents = $target->table('student_profiles')->orderBy('id')->get()->getResultArray();
    $beforeEmployees = $target->table('employee_profiles')->orderBy('id')->get()->getResultArray();
    $protected = [];
    foreach ($target->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->getResultArray() as $table) {
        $name = $table['TABLE_NAME'];
        if (in_array($name, ['people', 'student_profiles', 'employee_profiles'], true)) { continue; }
        $quoted = $target->protectIdentifiers($name);
        $checksum = $target->query('CHECKSUM TABLE ' . $quoted . ' EXTENDED')->getRowArray();
        $protected[$name] = $checksum['Checksum'];
    }
    try {
        $report = $importer->apply($plan);
    } catch (Throwable $error) {
        $causes = [];
        do {
            $causes[] = ['type' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine(), 'code' => $error->getCode()];
            $error = $error->getPrevious();
        } while ($error !== null);
        echo json_encode(['apply_succeeded' => false, 'error_locations_without_personal_data' => $causes], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        $source->close();
        $target->close();
        exit(1);
    }
    foreach (['people' => $beforePeople, 'student_profiles' => $beforeStudents, 'employee_profiles' => $beforeEmployees] as $table => $existing) {
        if ($existing === []) { continue; }
        $after = $target->table($table)->whereIn('id', array_column($existing, 'id'))->orderBy('id')->get()->getResultArray();
        if ($after !== $existing) { throw new RuntimeException('Existing directory data changed unexpectedly; review before further use.'); }
    }
    foreach ($protected as $table => $beforeChecksum) {
        $checksum = $target->query('CHECKSUM TABLE ' . $target->protectIdentifiers($table) . ' EXTENDED')->getRowArray();
        if ($checksum['Checksum'] !== $beforeChecksum) { throw new RuntimeException('A protected target table changed; concurrent activity or unexpected mutation requires review.'); }
    }
    $report['verification'] = ['existing_directory_rows_unchanged' => true, 'non_directory_tables_unchanged' => count($protected),
        'target_people_counts' => $target->query('SELECT kind,COUNT(*) AS count FROM people WHERE tenant_id=1 AND deleted_at IS NULL GROUP BY kind')->getResultArray()];
    $verificationPlan = $importer->plan();
    $report['rerun_would_insert'] = $importer->report($verificationPlan)['planned'];
    $manifest = __DIR__ . '/backups/directory_import_applied_' . gmdate('Ymd_His') . '.json';
    file_put_contents($manifest, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
}
$source->close();
$target->close();
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
