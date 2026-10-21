<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ENVIRONMENT', 'development');
define('FCPATH', dirname(__DIR__) . '/public/');
require dirname(__DIR__) . '/app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootConsole($paths);
if (env('CI_ENVIRONMENT') !== 'development' || config('Database')->default['database'] !== 'humus_db') {
    throw new RuntimeException('Explicit development database required.');
}
$db = \Config\Database::connect('default');
if ($db->query('SELECT DATABASE() AS name')->getRow()->name !== 'humus_db') { throw new RuntimeException('Database mismatch.'); }
\App\Services\CurrentTenant::set(1);
$actor = $db->table('users')->select('id,role')->where('tenant_id', 1)->where('role', 'superadmin')->where('status', 'active')->where('deleted_at', null)->get()->getRowArray();
if (!$actor) { throw new RuntimeException('Existing platform administrator required for read-only smoke checks.'); }
$directory = new \App\Services\Clinic\PatientDirectoryService($db);
$directory->assertAccess((int) $actor['id']);
$first = $directory->paginatePatients(\App\Libraries\TablePage::fromQuery([]));
$third = $directory->paginatePatients(\App\Libraries\TablePage::fromQuery(['page' => '3']));
if (count($first['patients']) !== 25 || count($third['patients']) !== 25
    || array_intersect(array_column($first['patients'], 'id'), array_column($third['patients'], 'id'))) {
    throw new RuntimeException('Directory pages are not bounded/distinct.');
}
$probe = $third['patients'][0];
$search = $directory->paginatePatients(\App\Libraries\TablePage::fromQuery(['q' => $probe['mis_id']]));
if (!in_array($probe['id'], array_column($search['patients'], 'id'), true)) { throw new RuntimeException('Search did not find a record beyond the old first 50.'); }
foreach ([50,100] as $size) {
    $page = $directory->paginatePatients(\App\Libraries\TablePage::fromQuery(['per_page' => (string) $size]));
    if (count($page['patients']) !== min($size, $page['pagination']['filtered'])) { throw new RuntimeException('Page size mismatch.'); }
}
$inventory = new \App\Services\Clinic\InventoryService($db);
$inventory->assertAccess((int) $actor['id']);
$inventory->assertInstalled();
$catalog = $inventory->paginateMedicines(\App\Libraries\TablePage::fromQuery([]));
helper(['url','form']);
session()->set(['tenant_id'=>1,'user'=>['id'=>(int)$actor['id'],'tenant_id'=>1,'role'=>$actor['role'],'name'=>'Read-only verification','logged_in'=>true]]);
$checks = [];
try {
    foreach ([['patients', '\\App\\Controllers\\ClinicController', '/clinic/patients'], ['inventory', '\\App\\Controllers\\ClinicInventoryController', '/clinic/inventory']] as [$name,$class,$path]) {
        foreach ([false,true] as $fragment) {
            \Config\Services::resetSingle('renderer');
            \Config\Services::resetSingle('response');
            $app = config('App');
            $_GET = $fragment ? ['fragment'=>'1','page'=>'2','per_page'=>'25'] : [];
            $_SERVER['HTTP_HOST'] = 'localhost:8080';
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $request = new \CodeIgniter\HTTP\IncomingRequest($app,new \CodeIgniter\HTTP\SiteURI($app,ltrim($path,'/')),null,new \CodeIgniter\HTTP\UserAgent());
            $request->setGlobal('get',$_GET);
            \Config\Services::injectMock('request',$request);
            \Config\Services::injectMock('uri',$request->getUri());
            $response = service('response');
            $controller = new $class();
            $controller->initController($request,$response,service('logger'));
            $result = $controller->{($name==='patients'?'patients':'index')}();
            $body = $result->getBody();
            if ($result->getStatusCode()!==200 || !str_contains($body,'data-table-pagination')
                || ($fragment && (!str_contains($body,'data-table-content') || str_contains($body,'<html')))
                || (!$fragment && !str_contains($body,'live-table.js'))) {
                throw new RuntimeException('List rendering check failed: '.$name.($fragment?' fragment':' page'));
            }
            $checks[$name.($fragment?'_fragment':'_page')] = 200;
        }
    }
    echo json_encode(['patient_pagination'=>$first['pagination'],'third_page_rows'=>count($third['patients']),
        'search_beyond_old_50_limit'=>true,'page_sizes_25_50_100'=>true,'inventory_pagination'=>$catalog['pagination'],
        'render_checks'=>$checks,'database_writes_performed'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
} finally {
    session()->remove(['user','tenant_id']);
    if(session_status()===PHP_SESSION_ACTIVE) session()->destroy();
    \App\Services\CurrentTenant::reset();
}
