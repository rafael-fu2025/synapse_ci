<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\Ai\ChatClient;
use App\Services\Ai\Profile;
use App\Services\Ai\SettingsStore;
use App\Services\Api\ApiContext;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Guidance\SurveyDraftAiExtractor;
use App\Services\Guidance\SurveySourceTextExtractor;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Ai;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;
use ZipArchive;

/**
 * @internal
 */
final class SurveyDraftAiExtractorTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';
    protected $seed      = 'App\Database\Seeds\PermissionsAndRolesSeeder';

    private string $tempRoot;
    private SettingsStore $settingsStore;
    private int $adminUserId;
    private int $studentUserId;

    protected function setUp(): void
    {
        parent::setUp();
        CurrentTenant::set(1);

        $this->tempRoot = sys_get_temp_dir() . '/humus-survey-ai-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->tempRoot, 0700));
        $defaults = (new ReflectionClass(Ai::class))->newInstanceWithoutConstructor();
        $this->settingsStore = new SettingsStore($this->tempRoot . '/ai-settings', $defaults);

        $unique  = bin2hex(random_bytes(4));
        $persons = new PersonModel();
        $users   = new UserModel();

        $adminPersonId = (int) $persons->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'SAI_ADM_' . $unique,
            'first_name' => 'Guidance',
            'last_name'  => 'Admin',
        ]);
        $this->adminUserId = (int) $users->insert([
            'tenant_id' => 1,
            'person_id' => $adminPersonId,
            'username'  => 'sai_adm_' . $unique,
            'role'      => 'guidance_admin',
            'status'    => 'active',
        ]);

        $studentPersonId = (int) $persons->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => 'SAI_STU_' . $unique,
            'first_name' => 'Student',
            'last_name'  => 'One',
        ]);
        $this->studentUserId = (int) $users->insert([
            'tenant_id' => 1,
            'person_id' => $studentPersonId,
            'username'  => 'sai_stu_' . $unique,
            'role'      => 'student',
            'status'    => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        ApiContext::clear();
        AuthenticatedUser::clear();
        session()->destroy();
        CurrentTenant::reset();
        putenv('SURVEY_PDFTOTEXT_PATH');

        if (isset($this->tempRoot) && is_dir($this->tempRoot)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tempRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $entry) {
                if ($entry->isDir() && ! $entry->isLink()) {
                    @rmdir($entry->getPathname());
                } else {
                    @unlink($entry->getPathname());
                }
            }
            @rmdir($this->tempRoot);
        }

        parent::tearDown();
    }

    private function actingAs(int $userId): void
    {
        CurrentTenant::set(1);
        $actor = (new AuthenticatedUser())->forIdentity($userId, 1);
        self::assertNotNull($actor);
        ApiContext::set($actor, str_repeat('a', 64));
        $now = time();
        session()->set([
            'tenant_id' => 1,
            'user'      => $actor,
            '_auth'     => [
                'started_at'   => $now,
                'last_seen_at' => $now,
                'version'      => (int) ($actor['auth_version'] ?? 1),
                'session_id'   => session_id(),
            ],
        ]);
    }

    private function configureGuidanceAi(bool $allowGuidance = true, bool $enable = true): Profile
    {
        $saved = $this->settingsStore->save(1, $this->adminUserId, [
            'version'               => '',
            'base_url'              => 'https://gateway.example.test/v1',
            'api_key'               => 'synthetic-test-key',
            'remove_api_key'        => '0',
            'model'                 => 'synthetic/test-model',
            'reasoning_effort'      => 'low',
            'thinking_mode'         => 'disabled',
            'max_input_tokens'      => '16384',
            'max_output_tokens'     => '2048',
            'auth_mode'             => 'bearer',
            'token_field'           => 'max_tokens',
            'structured_output'     => 'json_object',
            'allow_private_network' => '0',
            'unit_selection'        => '1',
            'allow_clinic'          => '1',
            'allow_guidance'        => $allowGuidance ? '1' : '0',
            'allow_bmg'             => '0',
        ]);
        $testing = $this->settingsStore->testingProfile(1, $saved['version']);
        $tested  = $this->settingsStore->markTested(1, $this->adminUserId, $saved['version'], $testing->revision());
        if ($enable) {
            return $this->settingsStore->enable(1, $this->adminUserId, $tested['version']);
        }
        return Profile::fromConfig($this->settingsStore->configuration());
    }

    private function makeDocx(string $path, string $documentXml): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->close();
    }

    public function testSourceTextExtractorHandlesTxtCsvDocxAndPdf(): void
    {
        $extractor = new SurveySourceTextExtractor(static fn (string $p): string => "1. How helpful was counseling?\nScale 1 to 5");

        // Plain TXT with BOM + CRLF
        $txt = $this->tempRoot . '/sample.txt';
        file_put_contents($txt, "\xEF\xBB\xBFQ1. Preferred support?\r\n- Individual\r\n- Group\r\n");
        self::assertStringContainsString('Q1. Preferred support?', $extractor->extractFromPath($txt, 'sample.txt'));

        // CSV formatting
        $csv = $this->tempRoot . '/sample.csv';
        file_put_contents($csv, "Question,Type,Options\n\"How confident do you feel?\",single,\"Very|Somewhat|Not at all\"\n");
        self::assertStringContainsString('How confident do you feel? | single | Very|Somewhat|Not at all', $extractor->extractFromPath($csv, 'sample.csv'));

        // DOCX paragraphs + table cells
        $docx = $this->tempRoot . '/sample.docx';
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>'
            . '<w:p><w:r><w:t>Student Orientation Survey</w:t></w:r></w:p>'
            . '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>1. Overall rating</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>1-5</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
            . '</w:body></w:document>';
        $this->makeDocx($docx, $xml);
        $docxText = $extractor->extractFromPath($docx, 'sample.docx');
        self::assertStringContainsString('Student Orientation Survey', $docxText);
        self::assertStringContainsString('1. Overall rating | 1-5', $docxText);

        // Mocked PDF extraction
        $pdf = $this->tempRoot . '/sample.pdf';
        file_put_contents($pdf, '%PDF-1.4 fake');
        $pdfText = $extractor->extractFromPath($pdf, 'sample.pdf');
        self::assertStringContainsString('How helpful was counseling?', $pdfText);
    }

    public function testSourceTextExtractorRejectsZipBombsUnsafeXmlBadUtf8OversizedAndScannedPdf(): void
    {
        $extractor = new SurveySourceTextExtractor(static fn (): string => "   \n  ");

        // Scanned empty PDF
        $emptyPdf = $this->tempRoot . '/scanned.pdf';
        file_put_contents($emptyPdf, '%PDF-1.4 scanned');
        try {
            $extractor->extractFromPath($emptyPdf, 'scanned.pdf');
            self::fail('Blank PDF text should fail cleanly.');
        } catch (RuntimeException $e) {
            self::assertSame(422, $e->getCode());
            self::assertStringContainsString('scanned image PDF', $e->getMessage());
        }

        // DOCX exceeding 2 MB XML expansion cap
        $bombDocx = $this->tempRoot . '/bomb.docx';
        $hugeXml  = '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'
            . str_repeat('A', SurveySourceTextExtractor::MAX_DOCX_XML_BYTES + 1024)
            . '</w:t></w:r></w:p></w:body></w:document>';
        $this->makeDocx($bombDocx, $hugeXml);
        try {
            $extractor->extractFromPath($bombDocx, 'bomb.docx');
            self::fail('Oversized DOCX XML should be rejected.');
        } catch (RuntimeException $e) {
            self::assertSame(422, $e->getCode());
        }

        // Unsafe XML ENTITY declaration
        $entityDocx = $this->tempRoot . '/entity.docx';
        $this->makeDocx($entityDocx, '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe "bar">]><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');
        try {
            $extractor->extractFromPath($entityDocx, 'entity.docx');
            self::fail('DOCX DTD/ENTITY must be rejected.');
        } catch (RuntimeException $e) {
            self::assertSame(422, $e->getCode());
        }

        // Oversized > 2 MB uploaded file
        $oversized = $this->tempRoot . '/big.txt';
        file_put_contents($oversized, str_repeat('B', SurveySourceTextExtractor::MAX_FILE_BYTES + 10));
        try {
            $extractor->extractFromPath($oversized, 'big.txt');
            self::fail('Files above 2 MB must be rejected.');
        } catch (RuntimeException $e) {
            self::assertSame(422, $e->getCode());
        }

        // Unsupported file extension
        $exe = $this->tempRoot . '/script.exe';
        file_put_contents($exe, 'not allowed');
        try {
            $extractor->extractFromPath($exe, 'script.exe');
            self::fail('Unsupported extensions must be rejected.');
        } catch (RuntimeException $e) {
            self::assertSame(422, $e->getCode());
        }

        // Missing pdftotext when custom binary path points nowhere
        putenv('SURVEY_PDFTOTEXT_PATH=' . $this->tempRoot . '/missing-pdftotext');
        $realExtractor = new SurveySourceTextExtractor();
        try {
            $realExtractor->extractFromPath($emptyPdf, 'scanned.pdf');
            self::fail('Missing pdftotext should return 422 guidance message.');
        } catch (RuntimeException $e) {
            self::assertSame(422, $e->getCode());
            self::assertStringContainsString('pdftotext', $e->getMessage());
        }
    }

    public function testStatusRespectsDisabledAndClinicOnlyAiSettings(): void
    {
        $unconfigured = (new SurveyDraftAiExtractor($this->settingsStore))->status(1);
        self::assertFalse($unconfigured['available']);

        $this->configureGuidanceAi(allowGuidance: false, enable: true);
        $clinicOnly = (new SurveyDraftAiExtractor($this->settingsStore))->status(1);
        self::assertFalse($clinicOnly['available']);
        self::assertStringContainsString('Guidance', $clinicOnly['reason']);

        $this->actingAs($this->adminUserId);
        try {
            (new SurveyDraftAiExtractor($this->settingsStore))->extract(null, '1. Sample question', false);
            self::fail('Clinic-only AI profile must deny Guidance extraction.');
        } catch (RuntimeException $e) {
            self::assertSame(403, $e->getCode());
        }
    }

    public function testExtractValidatesNormalizesIsolatesDatabaseAndAudits(): void
    {
        $profile = $this->configureGuidanceAi(allowGuidance: true, enable: true);

        $capturedBody = null;
        $mockJson = json_encode([
            'suggested_title'       => 'Midterm Wellbeing Check',
            'suggested_description' => 'Quick check-in from the Guidance Center.',
            'questions'             => [
                ['type' => 'likert', 'label' => 'Rate your stress balance this week', 'required' => true, 'options' => ['1', '2', '3', '4', '5']],
                ['type' => 'radio', 'label' => 'Preferred counseling format', 'required' => '1', 'options' => ['In-person', 'Online', 'Online']],
                ['type' => 'checkbox', 'label' => 'Topics of interest', 'required' => false, 'options' => ['Time Management', 'Career Planning', 'Sleep']],
                ['type' => 'short_text', 'label' => 'Anything else you want to share?', 'required' => false, 'options' => []],
                ['type' => 'single', 'label' => 'Only one option falls back to text', 'required' => true, 'options' => ['Solo']],
            ],
        ], JSON_THROW_ON_ERROR);

        $transport = static function (Profile $p, array $endpoint, array $headers, string $body, callable $receive) use (&$capturedBody, $mockJson): int {
            $capturedBody = $body;
            $receive(json_encode([
                'choices' => [[
                    'index'         => 0,
                    'finish_reason' => 'stop',
                    'message'       => [
                        'role'    => 'assistant',
                        'content' => $mockJson,
                    ],
                ]],
            ], JSON_THROW_ON_ERROR));
            return 200;
        };
        $resolver = static fn (string $host): array => ['93.184.216.34'];
        $client   = new ChatClient($profile, $transport, $resolver);

        // Build a fake UploadedFile (.docx)
        $docxPath = $this->tempRoot . '/upload.docx';
        $this->makeDocx(
            $docxPath,
            '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . '<w:p><w:r><w:t>1. Rate your stress balance this week (1-5)</w:t></w:r></w:p>'
            . '</w:body></w:document>'
        );
        $uploaded = new class($docxPath, 'orientation-survey.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', filesize($docxPath), UPLOAD_ERR_OK) extends UploadedFile {
            public function isValid(): bool
            {
                return $this->getError() === UPLOAD_ERR_OK && is_file($this->getTempName());
            }
        };

        // Set acting admin; ensure non-admin student is rejected first.
        $this->actingAs($this->studentUserId);
        $extractor = new SurveyDraftAiExtractor($this->settingsStore, new SurveySourceTextExtractor(), $client);
        self::assertTrue($extractor->status(1)['available']);
        try {
            $extractor->extract($uploaded, null, false);
            self::fail('Students must never invoke survey AI prefill.');
        } catch (RuntimeException $e) {
            self::assertSame(403, $e->getCode());
        }

        // Now invoke as Guidance Admin and verify zero reads against sensitive tables.
        $this->actingAs($this->adminUserId);
        $queries  = [];
        $listener = static function ($query) use (&$queries): void {
            $queries[] = (string) $query;
        };
        Events::on('DBQuery', $listener);
        try {
            $result = $extractor->extract($uploaded, 'Additional pasted item: Anything else?', false);
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        self::assertSame('Midterm Wellbeing Check', $result['suggested_title']);
        self::assertSame('Quick check-in from the Guidance Center.', $result['suggested_description']);
        self::assertSame('mixed', $result['source_kind']);
        self::assertCount(5, $result['questions']);

        // Assert normalized Question contracts
        self::assertSame('rating', $result['questions'][0]['type']);
        self::assertSame([], $result['questions'][0]['options']);

        self::assertSame('single', $result['questions'][1]['type']);
        self::assertSame(['In-person', 'Online'], $result['questions'][1]['options']);

        self::assertSame('multiple', $result['questions'][2]['type']);
        self::assertFalse($result['questions'][2]['required']);

        self::assertSame('text', $result['questions'][3]['type']);
        self::assertSame('text', $result['questions'][4]['type']);

        // Confirm prompt received both file & pasted content, never DB rows
        self::assertIsString($capturedBody);
        self::assertStringContainsString('Rate your stress balance this week', $capturedBody);
        self::assertStringContainsString('Additional pasted item', $capturedBody);

        foreach ($queries as $sql) {
            if (preg_match('/\A\s*INSERT\s+INTO\s+[`"]?audit_events[`"]?/i', $sql) === 1) {
                continue;
            }
            self::assertDoesNotMatchRegularExpression(
                '/\b(?:people|student_profiles|employee_profiles|counselling_cases|clinic_encounters|survey_responses|surveys)\b/i',
                $sql,
                'AI survey extractor must never read student/clinical/survey tables: ' . $sql
            );
        }

        $audits = $this->db->table('audit_events')->where('event_type', 'guidance.survey.ai_prefill')->get()->getResultArray();
        self::assertCount(1, $audits);
    }

    public function testExtractStreamEmitsStagesRawSseRatingAnchorsAndClarifications(): void
    {
        $profile = $this->configureGuidanceAi(allowGuidance: true, enable: true);
        $this->actingAs($this->adminUserId);

        $firstPayload = json_encode([
            'suggested_title'       => 'Workload Check',
            'suggested_description' => '',
            'questions'             => [
                [
                    'type'            => 'rating',
                    'label'           => 'Rate your current academic load',
                    'required'        => true,
                    'options'         => [],
                    'scale_min_label' => '1 - Very light',
                    'scale_max_label' => '5 - Overwhelming',
                ],
                [
                    'type'     => 'single',
                    'label'    => 'Preferred channel',
                    'required' => true,
                    'options'  => ['Email', 'SMS', 'Other (please specify)'],
                ],
            ],
            'clarifications'        => [
                [
                    'id'                => 'c1',
                    'question'          => 'Should open follow-up feedback be required?',
                    'suggested_options' => ['Optional', 'Required'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $chunks = str_split($firstPayload, 45);
        $bodies = [];
        $transport = static function (Profile $p, array $endpoint, array $headers, string $body, callable $receive) use (&$bodies, $chunks): int {
            $bodies[] = $body;
            foreach ($chunks as $piece) {
                $receive('data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['content' => $piece]]]], JSON_THROW_ON_ERROR) . "\n\n");
            }
            $receive('data: ' . json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]], JSON_THROW_ON_ERROR) . "\n\n");
            $receive("data: [DONE]\n\n");
            return 200;
        };

        $client = new ChatClient($profile, $transport, static fn (): array => ['93.184.216.34']);
        $extractor = new SurveyDraftAiExtractor($this->settingsStore, new SurveySourceTextExtractor(), $client);

        $events = [];
        $result = $extractor->extractStream(
            null,
            '1. Rate your current academic load (1 - Very light to 5 - Overwhelming)',
            static function (string $event, array $data) use (&$events): void {
                $events[] = ['event' => $event, 'data' => $data];
            },
            false
        );

        $stages = array_values(array_map(
            static fn (array $e): string => (string) ($e['data']['stage'] ?? ''),
            array_filter($events, static fn (array $e): bool => $e['event'] === 'stage')
        ));
        self::assertContains('extracting', $stages);
        self::assertContains('connecting', $stages);
        self::assertContains('streaming', $stages);
        self::assertContains('normalizing', $stages);

        $rawEvents = array_values(array_filter($events, static fn (array $e): bool => $e['event'] === 'raw'));
        self::assertGreaterThanOrEqual(2, count($rawEvents));
        self::assertSame('1 - Very light', $result['questions'][0]['scale_min_label']);
        self::assertSame('5 - Overwhelming', $result['questions'][0]['scale_max_label']);
        self::assertSame(['Email', 'SMS'], $result['questions'][1]['options']);
        self::assertTrue($result['questions'][1]['allow_other']);
        self::assertCount(1, $result['clarifications']);
        self::assertSame(['Optional', 'Required'], $result['clarifications'][0]['suggested_options']);

        // Re-extract passing clarification answer cleanly inside prompt data block
        $extractor->extractStream(
            null,
            '1. Rate your current academic load',
            static function (): void {},
            false,
            [['question' => 'Should open follow-up feedback be required?', 'answer' => 'Optional']]
        );
        self::assertCount(2, $bodies);
        self::assertStringContainsString('AUTHOR CLARIFICATIONS', $bodies[1]);
        self::assertStringContainsString('Optional', $bodies[1]);
    }
}
