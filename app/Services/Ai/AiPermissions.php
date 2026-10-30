<?php

declare(strict_types=1);

namespace App\Services\Ai;

final class AiPermissions
{
    /** Catalog only. Role grants require a separate deliberate deployment decision. */
    public static function catalog(): array
    {
        $names = [
            'ai.insights.read' => 'Read approved departmental AI insights',
            'ai.insights.generate' => 'Prepare protected clinic analytics and request AI drafts',
            'ai.insights.review' => 'Accept or reject departmental AI drafts',
            'ai.insights.approve' => 'Approve reviewed operational program drafts without publishing',
            'ai.configuration.read' => 'Read AI provider settings without exposing saved secrets',
            'ai.configuration.write' => 'Manage the encrypted AI provider configuration',
            'ai.configuration.test' => 'Run a quota-bound synthetic AI compatibility test',
        ];
        $rows = [];
        foreach ($names as $code => $name) { $rows[] = ['code' => $code, 'name' => $name, 'module' => 'ai']; }
        return $rows;
    }
}
