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
if ($config['database'] !== 'humus_db' || !in_array($config['hostname'], ['localhost', '127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Unexpected target connection.');
}
$db = \Config\Database::connect('default');
if ($db->query('SELECT DATABASE() AS name')->getRow()->name !== 'humus_db') { throw new RuntimeException('Actual target mismatch.'); }
$source = 'synapse_zcode';
if (!$db->query('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?', [$source])->getRowArray()) {
    throw new RuntimeException('Confirmed source database was not found.');
}
$tables = $db->query('SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME', [$source])->getResultArray();
$columns = $db->query("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND (TABLE_NAME REGEXP 'student|employee|staff|people|department|program|course' OR TABLE_NAME IN ('users','tenants')) ORDER BY TABLE_NAME,ORDINAL_POSITION", [$source])->getResultArray();
$targetCounts = $db->query('SELECT kind,COUNT(*) AS count FROM people WHERE tenant_id=1 AND deleted_at IS NULL GROUP BY kind')->getResultArray();
$tenants = $db->query('SELECT id,name,slug,is_active FROM synapse_zcode.tenants ORDER BY id')->getResultArray();
$targetTenant = $db->query('SELECT id,name,slug,is_active FROM tenants WHERE id=1')->getRowArray();
$sourceCounts = $db->query("SELECT tenant_id,kind,COUNT(*) AS rows_total,SUM(deleted_at IS NOT NULL) AS deleted_rows,SUM(archived_at IS NOT NULL) AS archived_rows,SUM(CASE WHEN kind='student' THEN student_number IS NULL OR TRIM(student_number)='' WHEN kind='employee' THEN employee_number IS NULL OR TRIM(employee_number)='' ELSE 0 END) AS missing_official_id FROM synapse_zcode.users GROUP BY tenant_id,kind ORDER BY tenant_id,kind")->getResultArray();
$report = ['source'=>$source,'target'=>'humus_db','source_tables'=>$tables,'directory_columns'=>$columns,'source_tenants'=>$tenants,'target_tenant'=>$targetTenant,'source_directory_counts'=>$sourceCounts,'existing_target_people'=>$targetCounts];
file_put_contents(__DIR__ . '/directory-import-schema.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
