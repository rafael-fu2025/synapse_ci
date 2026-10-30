<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use App\Services\Ai\AiAccess;
use App\Services\Ai\AnalysisCatalog;
use InvalidArgumentException;
use Throwable;

final class AiInsightTopics
{
    public static function department(int $tenantId, string $unit): string
    {
        if ($tenantId < 1 || !in_array($unit, ['clinic', 'guidance', 'bmg'], true)) {
            throw new InvalidArgumentException('Invalid AI insight scope.');
        }
        return "private.tenant.{$tenantId}.ai.insights.{$unit}";
    }

    /** No client-selected topics or superadmin bypass; use the same fresh grants as panel reads. */
    public static function grants(array $actor): array
    {
        $channels = [];
        foreach (['clinic', 'guidance', 'bmg'] as $unit) {
            try {
                $authorized = AiAccess::assertActor($actor, (int) ($actor['tenant_id'] ?? 0), 'ai.insights.read', $unit);
                $permission = AnalysisCatalog::forUnit($unit)[0]['permission'];
                if (in_array($permission, $authorized['permissions'], true)) {
                    $channels[] = self::department((int) $authorized['tenant_id'], $unit);
                }
            } catch (Throwable) {
                // Missing or unavailable authority never expands a ticket.
            }
        }
        return $channels;
    }
}
