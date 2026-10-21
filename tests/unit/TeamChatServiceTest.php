<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TeamChatService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class TeamChatServiceTest extends TestCase
{
    private function invoke(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(TeamChatService::class, $method))->invoke(
            (new ReflectionClass(TeamChatService::class))->newInstanceWithoutConstructor(), ...$args);
    }

    public static function invalidPayloads(): iterable
    {
        $base = ['body' => 'Text', 'client_nonce' => '01234567-89ab-4cde-8fab-0123456789ab'];
        foreach ([null, true, [], 1, '', ' ', "\t\n", '　', "bad\0text", "\xFF", str_repeat('😀', 2001)] as $value) {
            yield [array_replace($base, ['body' => $value])];
        }
        foreach ([null, [], true, '', '01234567-89ab-1cde-8fab-0123456789ab',
            '01234567-89ab-4cde-7fab-0123456789ab', '01234567-89ab-4cde-8fab-0123456789ab\n'] as $value) {
            yield [array_replace($base, ['client_nonce' => $value])];
        }
        foreach ([0, -1, true, false, 1.0, [], '', '01', '+1', ' 1', '1.0', '1e2', '9223372036854775808'] as $value) {
            yield [$base + ['task_id' => $value]];
        }
        foreach (['attachments', 'patient_id', 'sender_user_id', 'tenant_id', 'unit', 'draft', 'recipient_id'] as $field) {
            yield [$base + [$field => 1]];
        }
        yield [[]];
    }

    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidPayload(array $input): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        $this->invoke('payload', $input);
    }

    public function testVerbatimUnicodeAndCanonicalNonce(): void
    {
        $body = ' ' . str_repeat('😀', 1997) . "\n ";
        $data = $this->invoke('payload', ['body' => $body,
            'client_nonce' => '01234567-89AB-4CDE-AFAB-0123456789AB', 'task_id' => '12']);
        $this->assertSame($body, $data['body']);
        $this->assertSame('01234567-89ab-4cde-afab-0123456789ab', $data['client_nonce']);
        $this->assertSame(12, $data['task_id']);
        $this->assertSame(0, $this->invoke('integer', '0', true));
        $this->assertSame(PHP_INT_MAX, $this->invoke('integer', (string) PHP_INT_MAX, false));
    }

    public function testForgeDeclarationsPreserveMysqlTypesAndScopeConstraints(): void
    {
        require_once APPPATH . 'Database/Migrations/2026-10-19-000002_CreateTeamChat.php';
        $db = new \CodeIgniter\Database\MySQLi\Connection(['DBDriver' => 'MySQLi']);
        $forge = $this->createMock(\CodeIgniter\Database\Forge::class);
        $forge->method('getConnection')->willReturn($db);
        $definitions = [];
        $forge->expects($this->exactly(2))->method('addField')->willReturnCallback(function ($fields) use (&$definitions, $forge) {
            $definitions[] = $fields;
            return $forge;
        });
        $forge->expects($this->exactly(2))->method('createTable')->willReturnCallback(function ($table, $ifNotExists, $attributes) {
            $this->assertContains($table, ['team_chat_messages', 'team_chat_reads']);
            $this->assertFalse($ifNotExists);
            $this->assertSame(['ENGINE' => 'InnoDB'], $attributes);
            return true;
        });
        $forge->expects($this->once())->method('addUniqueKey')
            ->with(['tenant_id', 'unit', 'sender_user_id', 'client_nonce'], 'team_chat_nonce');
        (new \App\Database\Migrations\CreateTeamChat($forge))->up();
        [$messages, $reads] = $definitions;
        foreach (['id', 'sender_user_id', 'task_id'] as $field) {
            $this->assertSame('BIGINT', $messages[$field]['type']);
            $this->assertTrue($messages[$field]['unsigned']);
        }
        foreach ([$messages, $reads] as $fields) {
            $this->assertSame(['type' => 'INT', 'unsigned' => true], $fields['tenant_id']);
            $this->assertSame(['type' => 'VARCHAR', 'constraint' => 16], $fields['unit']);
        }
        $this->assertSame(['type' => 'VARCHAR', 'constraint' => 36], $messages['client_nonce']);
        $this->assertSame(['type' => 'TEXT'], $messages['body']);
        $this->assertSame(['type' => 'DATETIME'], $messages['created_at']);
        $this->assertTrue($messages['task_id']['null']);
        $this->assertTrue($messages['id']['auto_increment']);
        $this->assertSame(['type' => 'BIGINT', 'unsigned' => true], $reads['user_id']);
        $this->assertSame(['type' => 'BIGINT', 'unsigned' => true, 'default' => 0], $reads['last_read_message_id']);
    }

    public function testOnlyRequestedPublicOperationsExist(): void
    {
        $methods = array_map(static fn ($method) => $method->name,
            (new ReflectionClass(TeamChatService::class))->getMethods(ReflectionMethod::IS_PUBLIC));
        sort($methods);
        $this->assertSame(['__construct', 'context', 'history', 'markRead', 'send'], $methods);
    }
}
