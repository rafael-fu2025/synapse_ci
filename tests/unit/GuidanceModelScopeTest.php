<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Source contracts only: no model construction or database connections. */
final class GuidanceModelScopeTest extends TestCase
{
    private function source(string $model): string
    {
        $source = file_get_contents(__DIR__ . '/../../app/Models/' . $model . '.php');
        self::assertIsString($source);

        return $source;
    }

    public function testCaseJoinsStayWithinTheCaseTenant(): void
    {
        $source = $this->source('CounsellingCaseModel');

        foreach ([
            "->join('people p', 'p.id = counselling_cases.person_id AND p.tenant_id = counselling_cases.tenant_id')",
            "->join('users u', 'u.id = counselling_cases.counsellor_user_id AND u.tenant_id = counselling_cases.tenant_id')",
            "->join('student_profiles sp', 'sp.person_id = p.id AND sp.tenant_id = counselling_cases.tenant_id', 'left')",
            "->join('employee_profiles ep', 'ep.person_id = p.id AND ep.tenant_id = counselling_cases.tenant_id', 'left')",
            '$builder = $this->scoped()',
            "->where('counselling_cases.status', \$status)",
            "->where('counselling_cases.category', \$category)",
        ] as $contract) {
            self::assertStringContainsString($contract, $source);
        }
    }

    public function testSessionCounsellorJoinPreservesCaseScope(): void
    {
        $source = $this->source('CounsellingSessionModel');

        self::assertStringContainsString('return $this->scoped()', $source);
        self::assertStringContainsString(
            "->join('users u', 'u.id = counselling_sessions.counsellor_user_id AND u.tenant_id = counselling_sessions.tenant_id')",
            $source
        );
        self::assertStringContainsString("->where('counselling_sessions.case_id', \$caseId)", $source);
    }

    public function testNoteAuthorJoinPreservesSessionScope(): void
    {
        $source = $this->source('CounsellingNoteModel');

        self::assertSame(3, substr_count($source, 'return $this->scoped()'));
        self::assertStringContainsString(
            "->join('users u', 'u.id = counselling_notes.created_by_user_id AND u.tenant_id = counselling_notes.tenant_id', 'left')",
            $source
        );
        self::assertStringContainsString("->where('counselling_notes.session_id', \$sessionId)", $source);
    }

    public function testCaseNumberUsesInjectedConnectionForTransactionAndLocks(): void
    {
        $source = $this->source('CounsellingCaseModel');
        $method = substr($source, strpos($source, 'public function nextCaseNumber(): string'));

        foreach ([
            '$db = $this->db;',
            'if ($db->transDepth < 1 || !$db->transStatus())',
            '$db->protectIdentifiers($db->prefixTable(\'tenants\'))',
            '$tenant = \\App\\Services\\CurrentTenant::id();',
            '$db->query("SELECT id FROM {$tenantTable} WHERE id = ? FOR UPDATE", [$tenant])',
            '$db->protectIdentifiers($db->prefixTable(\'counselling_cases\'))',
            '$db->query("SELECT case_number FROM {$table} WHERE tenant_id = ? AND case_number LIKE ? ORDER BY id DESC LIMIT 1 FOR UPDATE",',
            "[\$tenant, \$prefix . '%']",
            '$year = \\App\\Services\\Scheduling\\SchedulingClock::now()->format(\'Y\');',
            '$prefix = "GC-{$year}-";',
            '$nextSeq = 1;',
            "\$numPart = (int) substr(\$last['case_number'], strlen(\$prefix));",
            '$nextSeq = $numPart + 1;',
            "return sprintf('%s%03d', \$prefix, \$nextSeq);",
        ] as $contract) {
            self::assertStringContainsString($contract, $method);
        }

        self::assertStringNotContainsString('Database::connect', $method);
        self::assertStringNotContainsString("date('Y')", $method);
        self::assertStringNotContainsString('deleted_at', $method);
    }
}
