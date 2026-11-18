<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\ReportExportController;
use App\Controllers\SurveyController;
use App\Services\AuthenticatedUser;
use App\Services\Guidance\SurveyService;
use App\Services\Reporting\ExportAccess;
use App\Services\Reporting\ExportCsv;
use App\Services\Reporting\ExportRange;
use App\Services\Reporting\OperationalExportService;
use CodeIgniter\Database\SQLite3\Connection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/** No database traits, real identities, operational records or persistent plaintext fixtures. */
final class ReportExportAuditTest extends CIUnitTestCase
{
    public function testPermissionAndRoleMatrix(): void
    {
        foreach ([false, true] as $identified) {
            foreach (['superadmin', 'report_viewer', 'audit_reader', 'guidance_admin', 'guidance_supervisor',
                'counsellor', 'clinic_admin', 'bmg_admin', 'student', 'employee'] as $role) {
                foreach ([[], ['reports.export'], ['reports.read'], ['guidance.responses.read'],
                    ['reports.read', 'guidance.responses.read', 'reports.export']] as $permissions) {
                    $roles = $identified ? ['guidance_admin', 'guidance_supervisor']
                                            : ['report_viewer', 'audit_reader', 'clinic_admin', 'guidance_admin', 'bmg_admin'];
                    $expected = $role === 'superadmin' || (in_array($role, $roles, true)
                        && in_array('reports.export', $permissions, true)
                        && in_array($identified ? 'guidance.responses.read' : 'reports.read', $permissions, true));
                    try {
                        ExportAccess::assertAllowed(['role' => $role, 'permissions' => $permissions], $identified);
                        $this->assertTrue($expected, $role);
                    } catch (RuntimeException $e) {
                        $this->assertFalse($expected, $role);
                        $this->assertSame(403, $e->getCode());
                    }
                }
            }
        }
    }

    public function testManilaRangeAndStorageTimezoneBoundaries(): void
    {
        $range = ExportRange::fromInput(['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertSame(['2026-09-30 16:00:00', '2026-10-31 16:00:00'], $range->bounds());
        $this->assertSame(['2026-10-01 00:00:00', '2026-11-01 00:00:00'], $range->bounds('Asia/Manila'));
        foreach ([[], ['from' => [], 'to' => '2026-10-01'], ['from' => '2026-02-30', 'to' => '2026-03-01'],
            ['from' => '2026-10-02', 'to' => '2026-10-01'], ['from' => '2026-10-01', 'to' => '2026-11-01'],
            ['from' => '2026-10-01T00:00:00', 'to' => '2026-10-01']] as $input) {
            try { ExportRange::fromInput($input); $this->fail('Invalid range accepted.'); }
            catch (RuntimeException $e) { $this->assertSame(422, $e->getCode()); }
        }
    }

    public function testCsvEscapesQuotesNewlinesAndFormulaPrefixes(): void
    {
        foreach (['=1+1', '+cmd', '-1', '@SUM(A1)', '  =1', "\tformula", "\rformula", "\nformula", "\xEF\xBB\xBF=1"] as $cell) {
            $this->assertSame("'" . $cell, ExportCsv::cell($cell));
        }
        $this->assertSame('Ordinary', ExportCsv::cell('Ordinary'));
        $this->assertSame("\"A,\"\"B\"\"\",\"line\nbreak\",'=1\r\n", ExportCsv::encode([['A,"B"', "line\nbreak", '=1']]));
    }

    public function testInvalidEncodingFailsClosedRatherThanBypassingFormulaDetection(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(503);
        ExportCsv::cell("=1\xff");
    }

    public function testCsvByteLimitRejectsInsteadOfTruncating(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        ExportCsv::encode([[str_repeat('x', ExportCsv::MAX_BYTES)]]);
    }

    public function testNoResponseDownloadUsesRetainedHeadersOnly(): void
    {
        $csv = (new ReflectionMethod(SurveyService::class, 'responseCsv'))->invoke(new SurveyService(),
            ['questions' => [['label' => 'Retained archived-bank question', 'bank_id' => 44, 'bank_revision' => 2]]], []);
        $this->assertSame("response_id,first_name,last_name,submitted_at_manila,\"Q1: Retained archived-bank question\"\r\n", $csv);
    }

    public function testDownloadIsAnAttachmentWithNoStore(): void
    {
        $response = ExportCsv::response(Services::response(null, false), 'private-fixture', 'survey_7.csv');
        $this->assertSame('private-fixture', $response->getBody());
        $this->assertSame((string) strlen('private-fixture'), $response->getHeaderLine('Content-Length'));
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('attachment;', $response->getHeaderLine('Content-Disposition'));
        $this->assertSame('text/csv; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testOperationalQueriesOnlyCountTenantScopedCompletedActivity(): void
    {
        $db = new Connection(['database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true]);
        try {
            $db->query('CREATE TABLE clinic_encounters (tenant_id INTEGER, status TEXT, started_at TEXT)');
            $db->query('CREATE TABLE counselling_cases (id INTEGER PRIMARY KEY, tenant_id INTEGER)');
            $db->query('CREATE TABLE counselling_sessions (tenant_id INTEGER, case_id INTEGER, status TEXT, started_at TEXT)');
            $db->table('counselling_cases')->insertBatch([['id' => 1, 'tenant_id' => 37], ['id' => 2, 'tenant_id' => 38]]);
            foreach (['2026-09-30 16:00:00', '2026-10-01 05:00:00', '2026-10-01 15:59:59'] as $time) {
                $db->table('clinic_encounters')->insert(['tenant_id' => 37, 'status' => 'completed', 'started_at' => $time]);
                $db->table('counselling_sessions')->insert(['tenant_id' => 37, 'case_id' => 1, 'status' => 'completed', 'started_at' => $time]);
            }
            foreach ([[38, 'completed', '2026-09-30 16:00:00'], [37, 'open', '2026-09-30 16:00:00'],
                [37, 'completed', '2026-10-01 16:00:00'], [37, 'completed', null]] as [$tenant, $status, $time]) {
                $db->table('clinic_encounters')->insert(['tenant_id' => $tenant, 'status' => $status, 'started_at' => $time]);
                $db->table('counselling_sessions')->insert(['tenant_id' => $tenant, 'case_id' => $tenant === 37 ? 1 : 2,
                    'status' => $status, 'started_at' => $time]);
            }
            foreach ([2, 99] as $case) {
                $db->table('counselling_sessions')->insert(['tenant_id' => 37, 'case_id' => $case,
                    'status' => 'completed', 'started_at' => '2026-09-30 16:00:00']);
            }
            // The unselected BMG table does not exist in this private fixture.
            $rows = (new OperationalExportService())->aggregate($db, 37,
                ExportRange::fromInput(['from' => '2026-10-01', 'to' => '2026-10-01']), 'UTC', ['clinic', 'guidance']);
            $this->assertSame([['date_manila', 'unit', 'metric', 'count'],
                ['2026-10-01', 'clinic', 'completed_clinic_encounters_started', 3],
                ['2026-10-01', 'guidance', 'completed_guidance_sessions_started', 3]], $rows);
        } finally {
            $db->close();
        }
    }

    public function testIdentifiedExportRejectsOverflowBeforeDecryption(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        (new ReflectionMethod(SurveyService::class, 'responseCsv'))->invoke(new SurveyService(), [], array_fill(0, 501, []));
    }

    public function testPrivateFixtureDecryptionUsesAadAndImmutableSnapshot(): void
    {
        // A synthetic versioned key exists only for this test, never on disk or in logs.
        $name = 'SURVEY_KEY_V65001';
        $oldEnv = $_ENV[$name] ?? null;
        $oldServer = $_SERVER[$name] ?? null;
        $_ENV[$name] = $_SERVER[$name] = str_repeat('ab', 32);
        try {
            $row = ['id' => 9, 'tenant_id' => 37, 'survey_id' => 7, 'person_id' => 11,
                'first_name' => '=private-name', 'last_name' => 'Fixture', 'submitted_at' => '2026-10-01 16:01:00',
                'payload_key_version' => 65001, 'payload_nonce' => str_repeat('n', 12)];
            $answers = [['question' => 'Retained question', 'type' => 'text', 'value' => '=private-answer']];
            $row['payload_cipher'] = openssl_encrypt(json_encode($answers), 'aes-256-gcm', hex2bin(str_repeat('ab', 32)),
                OPENSSL_RAW_DATA, $row['payload_nonce'], $tag, 'humus:survey:v1:37:7:11');
            $row['payload_tag'] = $tag;
            $survey = ['questions' => [['label' => 'Retained question', 'bank_id' => 44, 'bank_revision' => 2]]];
            $method = new ReflectionMethod(SurveyService::class, 'responseCsv');
            $csv = $method->invoke(new SurveyService(), $survey, [$row]);
            $this->assertStringContainsString('Q1: Retained question', $csv);
            $this->assertStringContainsString("'=private-name", $csv);
            $this->assertStringContainsString("'=private-answer", $csv);
            $this->assertStringContainsString('2026-10-02 00:01:00', $csv);
            $this->assertStringNotContainsString('person_id', $csv);
            $row['tenant_id'] = 38;
            try { $method->invoke(new SurveyService(), $survey, [$row]); $this->fail('Tampered AAD accepted.'); }
            catch (RuntimeException $e) { $this->assertSame(503, $e->getCode()); }
        } finally {
            if ($oldEnv === null) { unset($_ENV[$name]); } else { $_ENV[$name] = $oldEnv; }
            if ($oldServer === null) { unset($_SERVER[$name]); } else { $_SERVER[$name] = $oldServer; }
        }
    }

    public function testUnauthenticatedHandlersFailClosedWithoutDatabase(): void
    {
        AuthenticatedUser::clear();
        $request = Services::incomingrequest(null, false);
        $request->setMethod('POST');
        $request->setGlobal('post', ['from' => '2026-10-01', 'to' => '2026-10-01', 'confirm_identified' => '1']);
        foreach ([[new ReportExportController(), 'index', []], [new ReportExportController(), 'operational', []],
            [new SurveyController(), 'exportResponses', [7]]] as [$controller, $method, $args]) {
            $controller->initController($request, Services::response(null, false), new NullLogger());
            $response = $controller->{$method}(...$args);
            $this->assertSame(401, $response->getStatusCode());
            $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
            $this->assertSame('', $response->getHeaderLine('Content-Disposition'));
        }
    }

    public function testAuditAndFreshAuthorizationWiring(): void
    {
        $access = file_get_contents(APPPATH . 'Services/Reporting/ExportAccess.php');
        $this->assertStringContainsString('(new AuthenticatedUser())->current()', $access);
        $this->assertStringNotContainsString("session()->get('user')", $access);
        $survey = file_get_contents(APPPATH . 'Services/Guidance/SurveyService.php');
        $export = substr($survey, strpos($survey, 'public function exportResponses'),
            strpos($survey, 'private function decryptResponse') - strpos($survey, 'public function exportResponses'));
        foreach (['ExportAccess::actor(true)', "->where('r.tenant_id', \$actor['tenant_id'])", "->where('r.survey_id', \$id)",
            '->limit(501)', "\$this->audit('responses_exported'", "'row_count' => count(\$rows)"] as $guard) {
            $this->assertStringContainsString($guard, $export);
        }
        $this->assertStringNotContainsString("'answers' =>", $export);
        $this->assertStringNotContainsString("'csv' =>", substr($export, strpos($export, "\$this->audit("),
            strpos($export, 'return [') - strpos($export, "\$this->audit(")));
        foreach (['(new QuestionBankService())->verifyCopies($questions, $existing)', "'bank_id'"] as $retained) {
            $this->assertStringContainsString($retained, $survey);
        }
        $operational = file_get_contents(APPPATH . 'Services/Reporting/OperationalExportService.php');
        $this->assertLessThan(strpos($operational, '$range ='), strpos($operational, 'ExportAccess::actor()'));
        $auditStart = strpos($operational, '(new AuditService())->log(');
        $auditEnd = strpos($operational, "return [\$format =>");
        $this->assertIsInt($auditStart);
        $this->assertIsInt($auditEnd);
        $this->assertLessThan($auditEnd, $auditStart);
        $audit = substr($operational, $auditStart, $auditEnd - $auditStart);
        $this->assertStringContainsString("'format' => \$format", $audit);
        foreach (['$content', "'content' =>", "'csv' =>", "'pdf' =>"] as $excluded) {
            $this->assertStringNotContainsString($excluded, $audit);
        }
        foreach (['ReportExportController.php', 'SurveyController.php'] as $file) {
            $source = file_get_contents(APPPATH . 'Controllers/' . $file);
            $method = $file === 'SurveyController.php' ? substr($source, strpos($source, 'public function exportResponses'),
                strpos($source, 'public function responseDetail') - strpos($source, 'public function exportResponses')) : $source;
            $this->assertStringNotContainsString('log_message(', $method);
        }
    }
}
