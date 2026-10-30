<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Conservative local input allowance, NOT an exact provider tokenizer or context-window guarantee.
 * Count one unit per UTF-8 content byte, plus 32 per message (role/framing) and 64 per chat.
 * Providers may account for additional framing or reasoning tokens; output is controlled separately.
 */
final class InputBudget
{
    /** @param list<array{role:string,content:string}> $messages Already validated by ChatClient. */
    public static function assertFits(#[\SensitiveParameter] array $messages, int $maxInputTokens): void
    {
        $remaining = $maxInputTokens - 64;
        foreach ($messages as $message) {
            $cost = strlen($message['content']) + 32;
            if ($cost > $remaining) {
                throw new AiFailure('input_budget_exceeded', 422);
            }
            $remaining -= $cost;
        }
    }
}
