<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\AiFailure;
use App\Services\Ai\ChatClient;
use App\Services\Ai\InputBudget;
use App\Services\Ai\Profile;
use Config\Ai;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Only synthetic messages and injected DNS/HTTP callbacks; no provider connections. */
final class AiTokenControlsTest extends TestCase
{
    private function config(): Ai
    {
        $c = (new ReflectionClass(Ai::class))->newInstanceWithoutConstructor();
        $c->baseUrl = 'https://gateway.example.test/v1';
        $c->allowedOrigins = 'https://gateway.example.test';
        $c->model = 'org/synthetic-model';
        $c->apiKey = 'synthetic-secret';
        $c->maxInputTokens = 512;

        return $c;
    }

    public static function byteBoundaries(): iterable
    {
        yield 'ASCII' => [str_repeat('x', 416)];
        yield 'two-byte UTF-8' => [str_repeat('é', 208)];
        yield 'three-byte UTF-8' => [str_repeat('界', 138) . 'ab'];
        yield 'four-byte UTF-8' => [str_repeat('😀', 104)];
        yield 'JSON escaping is a separate byte cap' => [str_repeat('"', 416)];
    }

    #[DataProvider('byteBoundaries')]
    public function testBudgetCountsUtf8BytesAndExactFramingBoundary(string $content): void
    {
        self::assertSame(416, strlen($content));
        $messages = [['role' => 'user', 'content' => $content]];
        $original = $messages;
        InputBudget::assertFits($messages, 512); // 416 content + 32 message + 64 chat.
        self::assertSame($original, $messages);
        $messages[0]['content'] .= 'x';
        $this->budgetFailure(static fn () => InputBudget::assertFits($messages, 512));
    }

    public function testBudgetIncludesSystemAllUserMessagesAndEachFramingAllowance(): void
    {
        $messages = [
            ['role' => 'system', 'content' => str_repeat('s', 100)],
            ['role' => 'user', 'content' => str_repeat('u', 100)],
            ['role' => 'user', 'content' => str_repeat('v', 152)],
        ];
        InputBudget::assertFits($messages, 512); // 352 content + 3 * 32 + 64.
        $messages[0]['content'] .= 's';
        $this->budgetFailure(static fn () => InputBudget::assertFits($messages, 512));
        $messages[0]['content'] = str_repeat('s', 100);
        $messages[2]['content'] .= 'v';
        $this->budgetFailure(static fn () => InputBudget::assertFits($messages, 512));
    }

    public function testBudgetHandlesMaximumConfiguredAllowanceAndMessageCount(): void
    {
        $messages = array_fill(0, 32, ['role' => 'user', 'content' => 'x']);
        $messages[31]['content'] = str_repeat('x', 129953);
        InputBudget::assertFits($messages, 131072); // 129984 content + 1024 + 64.
        self::assertCount(32, $messages);
        $messages[31]['content'] .= 'x';
        $this->budgetFailure(static fn () => InputBudget::assertFits($messages, 131072));
    }

    public function testOversizedInputsNeverResolveDispatchOrSendAndAreNotTruncated(): void
    {
        $client = new ChatClient(Profile::fromConfig($this->config()), static function (): never {
            self::fail('Over-budget input must never reach HTTP.');
        }, static function (): never {
            self::fail('Over-budget input must never reach DNS.');
        });
        $fixtures = [
            [['role' => 'user', 'content' => str_repeat('😀', 105)]],
            [['role' => 'system', 'content' => str_repeat('s', 200)], ['role' => 'user', 'content' => str_repeat('u', 185)]],
            array_fill(0, 32, ['role' => 'user', 'content' => 'x']),
        ];
        foreach ($fixtures as $messages) {
            $original = $messages;
            $this->budgetFailure(static fn () => $client->complete($messages, static function (): never {
                self::fail('Over-budget input must never reach the dispatch callback.');
            }));
            self::assertSame($original, $messages);
        }
    }

    public function testAllMessagesAreValidatedBeforeBudgetFailure(): void
    {
        $client = new ChatClient(Profile::fromConfig($this->config()), static function (): never {
            self::fail('Invalid input must never reach HTTP.');
        }, static function (): never {
            self::fail('Invalid input must never reach DNS.');
        });
        foreach (["\xff", ['image_url' => 'not-supported']] as $invalid) {
            try {
                $client->complete([
                    ['role' => 'user', 'content' => str_repeat('x', 417)],
                    ['role' => 'user', 'content' => $invalid],
                ]);
                self::fail('Expected invalid_request before input budgeting.');
            } catch (AiFailure $error) {
                self::assertSame('invalid_request', $error->reason);
            }
        }
    }

    public function testProfileIsValidatedBeforeMessagesAndBudget(): void
    {
        $profile = Profile::fromConfig($this->config());
        $client = new ChatClient($profile, static function (): never {
            self::fail('Mutated config must never reach HTTP.');
        }, static function (): never {
            self::fail('Mutated config must never reach DNS.');
        });
        $profile->config->maxInputTokens = 131073;
        $this->expectExceptionMessage('invalid_config');
        $client->complete([]);
    }

    public static function requestControls(): iterable
    {
        foreach (['', 'none', 'minimal', 'low', 'medium', 'high', 'xhigh'] as $effort) {
            foreach (['max_tokens', 'max_completion_tokens'] as $field) {
                foreach (['', 'disabled', 'enabled'] as $thinking) {
                    yield ($effort === '' ? 'omitted' : $effort) . '/' . $field . '/thinking-' . ($thinking === '' ? 'default' : $thinking) => [$effort, $field, $thinking];
                }
            }
        }
    }

    #[DataProvider('requestControls')]
    public function testReasoningIsExplicitAndInputAndOutputControlsStayIndependent(string $effort, string $field, string $thinking): void
    {
        $c = $this->config();
        $c->reasoningEffort = $effort;
        $c->thinkingMode = $thinking;
        $c->maxOutputTokensField = $field;
        $c->maxOutputTokens = 1;
        $messages = [['role' => 'user', 'content' => str_repeat('😀', 104)]];
        $calls = 0;
        $client = new ChatClient(Profile::fromConfig($c), static function ($p, $e, $h, $body, $receive) use ($effort, $field, $thinking, $messages, &$calls): int {
            ++$calls;
            $expected = ['model' => 'org/synthetic-model', 'messages' => $messages, $field => 1, 'stream' => false];
            if ($effort !== '') {
                $expected['reasoning_effort'] = $effort;
            }
            if ($thinking !== '') {
                $expected['thinking'] = ['type' => $thinking];
            }
            self::assertSame($expected, json_decode($body, true, 32, JSON_THROW_ON_ERROR));
            $receive('{"choices":[{"index":0,"finish_reason":"stop","message":{"role":"assistant","content":"ok"}}]}');

            return 200;
        }, static fn (): array => ['8.8.8.8']);
        self::assertSame('ok', $client->complete($messages));
        self::assertSame(1, $calls);
    }

    private function budgetFailure(callable $action): void
    {
        try {
            $action();
            self::fail('Expected input_budget_exceeded.');
        } catch (AiFailure $error) {
            self::assertSame('input_budget_exceeded', $error->reason);
            self::assertSame('input_budget_exceeded', $error->getMessage());
            self::assertSame(422, $error->getCode());
            self::assertNull($error->getPrevious());
        }
    }
}
