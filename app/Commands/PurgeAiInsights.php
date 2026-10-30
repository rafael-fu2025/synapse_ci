<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Ai\AnalysisCatalog;
use App\Services\Ai\InsightStore;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

class PurgeAiInsights extends BaseCommand
{
    protected $group = 'AI';
    protected $name = 'ai:purge';
    protected $description = 'Erase one bounded batch of expired department AI payloads for an explicit tenant and unit.';
    protected $usage = 'ai:purge --tenant <positive-id> [--unit <clinic|guidance|bmg>]';
    protected $options = ['--tenant' => 'Required positive tenant ID; no default tenant is used.',
        '--unit' => 'Unit to purge, including revoked units; defaults to clinic.'];
    protected string $unit = 'clinic';

    public function run(array $params)
    {
        $counts = ['ai_snapshots' => 0, 'ai_insights' => 0, 'failed' => 0];
        try {
            $options = CLI::getOptions();
            foreach (array_intersect_key($options, $params) as $key => $value) {
                if ($value !== $params[$key]) {
                    throw new \InvalidArgumentException();
                }
            }
            $options = array_replace($options, $params);
            $tenant = $options['tenant'] ?? null;
            if (array_diff(array_keys($options), ['tenant', 'unit']) !== [] || (! is_int($tenant) && ! is_string($tenant))
                || ! preg_match('/\A[1-9][0-9]*\z/D', (string) $tenant)
                || filter_var($tenant, FILTER_VALIDATE_INT) === false) {
                throw new \InvalidArgumentException();
            }
            $unit = array_key_exists('unit', $options) ? $options['unit'] : 'clinic';
            if (!is_string($unit) || AnalysisCatalog::forUnit($unit) === []) {
                throw new \InvalidArgumentException();
            }
            $this->unit = $unit;
            $result = $this->purge((int) $tenant);
            foreach (['ai_snapshots', 'ai_insights'] as $key) {
                if (! isset($result[$key]) || ! is_int($result[$key]) || $result[$key] < 0) {
                    throw new \UnexpectedValueException();
                }
            }
            $counts = ['ai_snapshots' => $result['ai_snapshots'], 'ai_insights' => $result['ai_insights'], 'failed' => 0];
        } catch (Throwable) {
            $counts = ['ai_snapshots' => 0, 'ai_insights' => 0, 'failed' => 1];
        }
        CLI::write('ai_snapshots=' . $counts['ai_snapshots'] . ' ai_insights=' . $counts['ai_insights'] . ' failed=' . $counts['failed']);
        return $counts['failed'] === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }

    protected function purge(int $tenantId): array
    {
        return (new InsightStore())->purgeExpired($tenantId, $this->unit);
    }
}
