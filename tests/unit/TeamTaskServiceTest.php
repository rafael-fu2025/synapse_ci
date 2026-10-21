<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TeamTaskService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class TeamTaskServiceTest extends TestCase
{
    private function service(): TeamTaskService
    {
        return (new ReflectionClass(TeamTaskService::class))->newInstanceWithoutConstructor();
    }

    public function testEditPermissionIsTenantAndDepartmentScoped(): void
    {
        $service = $this->service();
        $task = ['tenant_id' => 1, 'unit' => 'clinic', 'created_by_user_id' => 10, 'assignee_user_id' => 11];
        foreach (['clinic_staff' => [10, 11], 'clinic_admin' => [12], 'superadmin' => [13]] as $role => $ids) {
            foreach ($ids as $id) {
                $this->assertTrue($service->canEdit($task, ['id' => $id, 'tenant_id' => 1, 'role' => $role]));
            }
        }
        foreach ([['id' => 12, 'tenant_id' => 1, 'role' => 'clinic_staff'],
            ['id' => 10, 'tenant_id' => 2, 'role' => 'superadmin'],
            ['id' => 10, 'tenant_id' => 1, 'role' => 'guidance_admin'], []] as $actor) {
            $this->assertFalse($service->canEdit($task, $actor));
        }
        foreach (['guidance' => 'guidance_supervisor', 'facilities' => 'bmg_admin'] as $unit => $role) {
            $task['unit'] = $unit;
            $this->assertTrue($service->canEdit($task, ['id' => 12, 'tenant_id' => 1, 'role' => $role]));
        }
    }

    public static function invalidFields(): iterable
    {
        yield 'empty title' => [['title' => ' ']];
        yield 'long title' => [['title' => str_repeat('a', 161)]];
        yield 'long description' => [['description' => str_repeat('a', 4001)]];
        yield 'array title' => [['title' => []]];
        yield 'null description' => [['description' => null]];
        yield 'invalid UTF8' => [['title' => "\xFF"]];
        yield 'control character' => [['title' => "bad\0text"]];
        yield 'status' => [['status' => 'archived']];
        yield 'priority' => [['priority' => 'urgent']];
        yield 'assignee array' => [['assignee_user_id' => []]];
        yield 'assignee boolean' => [['assignee_user_id' => true]];
        yield 'assignee zero' => [['assignee_user_id' => 0]];
        foreach (['2026-02-29', '2026-04-31', '2026-1-01', '2026-01-01T00:00:00Z', '0000-01-01', '2026-01-01\n', [], true] as $date) {
            yield 'date ' . json_encode($date) => [['due_date' => $date]];
        }
    }

    #[DataProvider('invalidFields')]
    public function testInvalidEditableFieldsAreRejected(array $changes): void
    {
        $method = new \ReflectionMethod(TeamTaskService::class, 'editable');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        $method->invoke($this->service(), $changes + ['title' => 'Valid'], false);
    }

    public function testDefaultsUnicodeBoundariesAndLeapDay(): void
    {
        $method = new \ReflectionMethod(TeamTaskService::class, 'editable');
        $data = $method->invoke($this->service(), ['title' => str_repeat('日', 160), 'due_date' => '2028-02-29'], false);
        $this->assertSame('todo', $data['status']);
        $this->assertSame('normal', $data['priority']);
        $this->assertSame('', $data['description']);
        $this->assertNull($data['assignee_user_id']);
        $this->assertSame('2028-02-29', $data['due_date']);
    }

    public static function invalidChecklists(): iterable
    {
        foreach ([null, true, 1, 1.5, new \stdClass(), '', '{', '{}', 'null', 'true', '1', '"text"',
            '{"0":{"text":"Object, not list","done":false}}',
            ['text' => 'Not a list', 'done' => false], [1 => ['text' => 'Sparse', 'done' => false]],
            [null], ['text'], [[]], [['text' => 'Missing done']], [['done' => false]],
            [['text' => 'Bad boolean', 'done' => 'false']], [['text' => 'Bad boolean', 'done' => 0]],
            [['text' => 'Bad boolean', 'done' => null]], [['text' => [], 'done' => false]],
            [['text' => '', 'done' => false]], [['text' => ' ', 'done' => false]],
            [['text' => str_repeat('a', 161), 'done' => false]], [['text' => "bad\0text", 'done' => false]],
            [['text' => "\xFF", 'done' => false]], [['text' => 'Extra', 'done' => false, 'id' => 1]],
            array_fill(0, 21, ['text' => 'Too many', 'done' => false]),
            str_repeat(' ', TeamTaskService::CHECKLIST_BYTES_LIMIT) . '[]',
            '[[[[[[]]]]]]', '[{"text":"Nested","done":{}}]', '[{"text":"Bool","done":"true"}]',
        ] as $index => $value) {
            yield 'invalid checklist ' . $index => [$value];
        }
    }

    #[DataProvider('invalidChecklists')]
    public function testInvalidChecklistIsRejected(mixed $value): void
    {
        $method = new \ReflectionMethod(TeamTaskService::class, 'editable');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        $method->invoke($this->service(), ['title' => 'Valid', 'checklist' => $value], false);
    }

    public function testChecklistDefaultsNormalizationAndMaximumUnicodePayload(): void
    {
        $method = new \ReflectionMethod(TeamTaskService::class, 'editable');
        $service = $this->service();
        $this->assertSame('[]', $method->invoke($service, ['title' => 'Default'], false)['checklist']);
        $items = array_fill(0, 20, ['text' => str_repeat('😀', 160), 'done' => true]);
        foreach ([$items, json_encode($items, JSON_THROW_ON_ERROR)] as $value) {
            $data = $method->invoke($service, ['title' => 'Maximum', 'checklist' => $value], false);
            $this->assertSame($items, json_decode($data['checklist'], true, 512, JSON_THROW_ON_ERROR));
        }
        foreach ([[], '[]'] as $value) {
            $this->assertSame('[]', $method->invoke($service, ['title' => 'Empty', 'checklist' => $value], false)['checklist']);
        }
        $data = $method->invoke($service, ['title' => 'Trimmed', 'checklist' => '[{"text":" Step ","done":false}]'], false);
        $this->assertSame('[{"text":"Step","done":false}]', $data['checklist']);
    }

    public function testFullUpdateCannotSilentlyClearOmittedFields(): void
    {
        $method = new \ReflectionMethod(TeamTaskService::class, 'editable');
        $this->expectExceptionCode(422);
        $method->invoke($this->service(), ['title' => 'Only title'], true);
    }
}
