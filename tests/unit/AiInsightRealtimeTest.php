<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Realtime\AiInsightInvalidation;
use App\Services\Realtime\AiInsightTopics;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

final class AiInsightRealtimeTest extends CIUnitTestCase
{
    public function testHintsAreMetadataOnlyAndTenantDepartmentScoped(): void
    {
        $sent = [];
        $publisher = new AiInsightInvalidation(static function (...$args) use (&$sent): void { $sent[] = $args; });
        foreach (['clinic', 'guidance', 'bmg'] as $unit) { $publisher->changed(7, $unit); }
        $this->assertSame([
            ['private.tenant.7.ai.insights.clinic', 'ai.insights.changed', ['unit' => 'clinic']],
            ['private.tenant.7.ai.insights.guidance', 'ai.insights.changed', ['unit' => 'guidance']],
            ['private.tenant.7.ai.insights.bmg', 'ai.insights.changed', ['unit' => 'bmg']],
        ], $sent);
        $publisher->changed(0, 'clinic');
        $publisher->changed(7, 'main');
        $this->assertCount(3, $sent);
        (new AiInsightInvalidation(static function (): void { throw new \RuntimeException('offline'); }))->changed(7, 'clinic');
    }

    public function testGrantsRequireRoleReadAndSourcePermissionWithoutWildcard(): void
    {
        $actor = ['id' => 8, 'tenant_id' => 7, 'logged_in' => true, 'role' => 'clinic_staff',
            'permissions' => ['ai.insights.read', 'clinic.encounters.read', 'guidance.cases.read_any']];
        $this->assertSame([AiInsightTopics::department(7, 'clinic')], AiInsightTopics::grants($actor));
        $actor['permissions'] = ['ai.insights.read'];
        $this->assertSame([], AiInsightTopics::grants($actor));
        $actor['permissions'] = ['clinic.encounters.read'];
        $this->assertSame([], AiInsightTopics::grants($actor));
        $actor['permissions'] = ['ai.insights.read', 'clinic.encounters.read'];
        $actor['role'] = 'student';
        $this->assertSame([], AiInsightTopics::grants($actor));
        $actor['role'] = 'superadmin';
        $actor['permissions'] = ['rbac.manage'];
        $this->assertSame([], AiInsightTopics::grants($actor));
        $actor['logged_in'] = false;
        $this->assertSame([], AiInsightTopics::grants($actor));
    }

    public function testTicketsUseFreshTenantDepartmentGrantsAndRevocation(): void
    {
        if (!extension_loaded('sqlite3')) { $this->markTestSkipped('Disposable SQLite requires sqlite3.'); }
        $db = \Config\Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'ai_rt_', 'DBDebug' => true], false);
        require_once APPPATH . 'Database/Migrations/2026-10-28-000001_CreateAiInsights.php';
        require_once APPPATH . 'Database/Migrations/2026-10-29-000001_AddAiAnalysisScope.php';
        (new \App\Database\Migrations\CreateAiInsights(\Config\Database::forge($db)))->up();
        (new \App\Database\Migrations\AddAiAnalysisScope(\Config\Database::forge($db)))->up();
        $instances = new \ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $previous = $instances->getValue();
        $instances->setValue(null, ['tests' => $db]);
        try {
            $actor = ['id' => 8, 'tenant_id' => 7, 'logged_in' => true, 'role' => 'guidance_admin',
                'permissions' => ['guidance.cases.read_any']];
            $this->assertSame([], AiInsightTopics::grants($actor));
            $db->table('ai_department_access')->insert(['tenant_id' => 7, 'unit' => 'guidance', 'role' => 'guidance_admin',
                'permission' => 'ai.insights.read', 'created_by' => 1, 'created_at' => '2026-10-30 00:00:00']);
            $config = new \Config\Realtime();
            $config->enabled = true;
            $config->signingKey = str_repeat('s', 32);
            $config->publishKey = str_repeat('p', 32);
            $config->allowedOrigins = 'https://portal.example.test';
            $config->publicUrl = 'wss://socket.example.test/realtime';
            $issuer = new \App\Services\Realtime\TicketIssuer($config);
            $ticket = $issuer->issue($actor, 'https://portal.example.test');
            $claims = (new \App\Services\Realtime\TicketSigner($config->signingKey))->verify($ticket['ticket'], 'https://portal.example.test');
            $this->assertSame(['private.tenant.7.user.8', AiInsightTopics::department(7, 'guidance')], $claims['channels']);
            $otherTenant = $actor; $otherTenant['tenant_id'] = 9;
            $this->assertSame([], AiInsightTopics::grants($otherTenant));
            $merged = (new \App\Services\Ai\AiGrantStore($db))->merge($actor, 'guidance');
            $db->table('ai_department_access')->where('tenant_id', 7)->delete();
            $this->assertSame([], AiInsightTopics::grants($merged), 'Previously merged grants are not cached');
            $ticket = $issuer->issue($actor, 'https://portal.example.test');
            $claims = (new \App\Services\Realtime\TicketSigner($config->signingKey))->verify($ticket['ticket'], 'https://portal.example.test');
            $this->assertSame(['private.tenant.7.user.8'], $claims['channels']);
        } finally {
            $instances->setValue(null, $previous);
            $db->close();
        }
    }

    public function testInvalidTopicFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AiInsightTopics::department(7, 'clinic.user.8');
    }
}
