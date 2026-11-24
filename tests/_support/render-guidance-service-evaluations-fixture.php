<?php

declare(strict_types=1);

// CLI-only invented aggregates. Load the view, never CI, Composer, dotenv,
// sessions, controllers, services, database connections, or application records.
namespace App\Services\Guidance {
    final class GuidanceServiceEvaluationService
    {
        public const TYPE_PSYCHOSOCIAL = 'psychosocial_support_evaluation';
        public const TYPE_COUNSELING = 'counseling_evaluation';
    }
}

namespace {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }

    $checkKeys = static function (mixed $value, array $allowed, array $required = []): array {
        if (!is_array($value)
            || array_diff(array_keys($value), $allowed) !== []
            || array_diff($required, array_keys($value)) !== []) {
            throw new \InvalidArgumentException('Unexpected or missing synthetic fixture fields.');
        }
        return $value;
    };
    $checkText = static function (mixed $value, int $maximum, bool $synthetic = false, array $fixed = []): void {
        if (!is_string($value) || strlen($value) > $maximum
            || ($synthetic && !str_starts_with($value, 'Synthetic') && !in_array($value, $fixed, true))) {
            throw new \InvalidArgumentException('Invalid bounded synthetic fixture text.');
        }
    };
    $checkInt = static function (mixed $value, int $minimum, int $maximum): void {
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new \InvalidArgumentException('Invalid synthetic fixture integer.');
        }
    };
    $checkNumber = static function (mixed $value, float $maximum): void {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)
            || $value < 0 || $value > $maximum) {
            throw new \InvalidArgumentException('Invalid synthetic aggregate number.');
        }
    };

    // Literal question/display labels are safe fixture vocabulary, not imported
    // controller constants. Synthetic-prefixed strings may contain hostile text.
    $psychosocialDimensions = [
        'rating_welcoming' => 'The guidance personnel were approachable and welcoming.',
        'rating_empathy' => 'The guidance personnel understood and empathized with me.',
        'rating_clarity' => 'The goal of the psychosocial support session was clear and achieved.',
        'rating_helpfulness' => 'The psychosocial support session was helpful.',
        'rating_environment' => 'Confidentiality and a safe, supportive environment were maintained.',
    ];
    $counselingDimensions = [
        'rating_rapport' => 'The counselor was approachable, welcoming, and established good rapport.',
        'rating_understanding' => 'The counselor understood and empathized with me.',
        'rating_goal_progress' => 'The goal of the counseling session was achieved and progress was made.',
        'rating_confidentiality_comfort' => 'Confidentiality was kept and I felt comfortable during the session.',
        'rating_overall_service' => 'Overall, the counseling session was helpful and effective.',
    ];
    $visitLabels = [
        'walk_in' => 'Voluntarily came / Walk-in',
        'called' => 'Called by Guidance Personnel / Counselor',
        'referred' => 'Referred',
        'follow_up' => 'Follow-up',
    ];
    $checkLabels = static function (mixed $value, array $fixed) use ($checkKeys, $checkText): void {
        $labels = $checkKeys($value, array_keys($fixed));
        foreach ($labels as $key => $label) {
            $checkText($label, 65536, true, [$fixed[$key]]);
        }
    };
    $checkAggregate = static function (mixed $value, array $dimensions, string $type, string $formCode)
        use ($checkKeys, $checkText, $checkInt, $checkNumber, $visitLabels): void {
        $aggregate = $checkKeys($value, [
            'total_responses', 'minimum_required', 'cohort_met', 'averages',
            'boolean_rate', 'boolean_percentage', 'boolean_positive_count',
            'visit_type_distribution', 'comments', 'evaluation_type', 'form_code',
            'provider_user_id', 'academic_term', 'academic_year',
        ], ['total_responses', 'minimum_required', 'cohort_met']);
        $checkInt($aggregate['total_responses'], 0, 1000);
        if ($aggregate['minimum_required'] !== 5 || !is_bool($aggregate['cohort_met'])
            || $aggregate['cohort_met'] !== ($aggregate['total_responses'] >= 5)) {
            throw new \InvalidArgumentException('Invalid synthetic cohort threshold.');
        }
        if (isset($aggregate['averages'])) {
            $averages = $checkKeys($aggregate['averages'], [...array_keys($dimensions), 'overall_mean']);
            foreach ($averages as $average) {
                $checkNumber($average, 5.0);
            }
        }
        foreach (['boolean_rate' => 1.0, 'boolean_percentage' => 100.0] as $field => $maximum) {
            if (isset($aggregate[$field])) {
                $checkNumber($aggregate[$field], $maximum);
            }
        }
        if (array_key_exists('boolean_positive_count', $aggregate)) {
            $checkInt($aggregate['boolean_positive_count'], 0, 1000);
        }
        if (isset($aggregate['visit_type_distribution'])) {
            $distribution = $checkKeys($aggregate['visit_type_distribution'], array_keys($visitLabels));
            foreach ($distribution as $count) {
                $checkInt($count, 0, 1000);
            }
        }
        if (array_key_exists('comments', $aggregate)) {
            if (!is_array($aggregate['comments']) || !array_is_list($aggregate['comments'])
                || count($aggregate['comments']) > 10) {
                throw new \InvalidArgumentException('Invalid synthetic comment list.');
            }
            foreach ($aggregate['comments'] as $comment) {
                $checkText($comment, 65536, true);
            }
        }
        foreach (['evaluation_type' => $type, 'form_code' => $formCode] as $field => $expected) {
            if (array_key_exists($field, $aggregate) && $aggregate[$field] !== $expected) {
                throw new \InvalidArgumentException('Invalid synthetic aggregate metadata.');
            }
        }
        if (array_key_exists('provider_user_id', $aggregate)) {
            $checkInt($aggregate['provider_user_id'], 97001, 97040);
        }
        foreach (['academic_term' => 64, 'academic_year' => 32] as $field => $maximum) {
            if (isset($aggregate[$field])) {
                $checkText($aggregate[$field], $maximum);
            }
        }
        // Normally suppressed averages/rates/distribution are null and comments
        // empty. Bounded synthetic canaries are deliberately passed unchanged,
        // even below five responses, so tests prove the VIEW discards them.
        // This renderer never computes, decrypts, or fetches aggregate data.
    };

    function esc($value, string $context = 'html'): string {
        if (!in_array($context, ['html', 'attr'], true)) {
            throw new \RuntimeException('Unexpected fixture escaping dependency.');
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    function site_url(string $path = ''): string {
        return $GLOBALS['guidanceEvaluationFixtureOrigin'] . '/' . ltrim($path, '/');
    }
    function base_url(string $path = ''): string { return site_url($path); }
    function current_url(): string { return $GLOBALS['guidanceEvaluationFixtureCurrentUrl']; }

    final class GuidanceServiceEvaluationsFixtureView
    {
        private array $sections = ['content' => '', 'page_actions' => '', 'styles' => ''];
        private array $seen = [];
        private array $stack = [];

        public function extend(string $name): string {
            if ($name !== 'layouts/app') {
                throw new \RuntimeException('Unexpected fixture layout dependency.');
            }
            return '';
        }
        public function section(string $name): string {
            if (!in_array($name, ['content', 'page_actions', 'styles', 'scripts'], true)
                || $this->stack !== [] || isset($this->seen[$name])) {
                throw new \RuntimeException('Unexpected or nested fixture section.');
            }
            $this->seen[$name] = true;
            $this->stack[] = $name;
            ob_start();
            return '';
        }
        public function endSection(): string {
            if ($this->stack === []) {
                throw new \RuntimeException('Unbalanced fixture sections.');
            }
            $this->sections[array_pop($this->stack)] = (string) ob_get_clean();
            return '';
        }
        public function __call(string $name, array $arguments): never {
            throw new \RuntimeException('Unexpected fixture view dependency.');
        }
        public function render(array $data): array {
            $initialBufferLevel = ob_get_level();
            extract($data, EXTR_SKIP);
            ob_start();
            try {
                require dirname(__DIR__, 2) . '/app/Views/guidance/evaluations/dashboard.php';
                if ($this->stack !== [] || ob_get_level() !== $initialBufferLevel + 1) {
                    throw new \RuntimeException('Unbalanced fixture sections.');
                }
                $outsideSections = (string) ob_get_clean();
                if (trim($outsideSections) !== '' || !isset($this->seen['content'], $this->seen['page_actions'])) {
                    throw new \RuntimeException('Unexpected output outside fixture sections.');
                }
                return $this->sections;
            } finally {
                while (ob_get_level() > $initialBufferLevel) {
                    ob_end_clean();
                }
            }
        }
    }

    try {
        $input = stream_get_contents(STDIN, 131073);
        if ($input === false || strlen($input) > 131072) {
            throw new \InvalidArgumentException('Fixture input exceeds 128 KiB.');
        }
        $keys = [
            'origin', 'currentUrl', 'providerUserId', 'providerName', 'providers',
            'canViewAnyProvider', 'canModerate', 'cohortGateEnabled', 'filters',
            'psychosocial', 'counseling', 'psychosocialLabels', 'counselingLabels', 'visitTypeLabels',
        ];
        $fixture = $checkKeys(json_decode($input, true, 16, JSON_THROW_ON_ERROR), $keys, $keys);
        $origin = $fixture['origin'];
        if (!is_string($origin) || !preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{0,4}$~D', $origin)
            || (parse_url($origin, PHP_URL_PORT) ?? 0) > 65535) {
            throw new \InvalidArgumentException('Only a loopback HTTP fixture origin is allowed.');
        }
        $currentUrl = $fixture['currentUrl'];
        if (!is_string($currentUrl) || strlen($currentUrl) > 4096
            || preg_match('~[\x00-\x20\x7f#]~', $currentUrl)
            || !preg_match('~^' . preg_quote($origin, '~') . '/guidance/evaluations/dashboard(?:\?[^#]*)?$~D', $currentUrl)) {
            throw new \InvalidArgumentException('Invalid same-origin fixture dashboard URL.');
        }
        $query = parse_url($currentUrl, PHP_URL_QUERY) ?? '';
        $queryKeys = [];
        foreach ($query === '' ? [] : explode('&', $query) as $pair) {
            if ($pair === '' || preg_match('~%(?![0-9a-fA-F]{2})~', $pair)) {
                throw new \InvalidArgumentException('Invalid fixture dashboard query.');
            }
            [$rawKey, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($rawKey);
            $value = urldecode($rawValue);
            if (!in_array($key, ['provider_user_id', 'academic_term', 'academic_year'], true)
                || isset($queryKeys[$key])) {
                throw new \InvalidArgumentException('Unexpected or repeated fixture query field.');
            }
            $queryKeys[$key] = true;
            if ($key === 'provider_user_id') {
                if (!preg_match('~^[0-9]{5}$~D', $value)) {
                    throw new \InvalidArgumentException('Invalid synthetic query provider.');
                }
                $checkInt((int) $value, 97001, 97040);
            } else {
                $checkText($value, $key === 'academic_term' ? 64 : 32);
            }
        }
        $checkInt($fixture['providerUserId'], 97001, 97040);
        $checkText($fixture['providerName'], 65536, true);
        foreach (['canViewAnyProvider', 'canModerate', 'cohortGateEnabled'] as $field) {
            if (!is_bool($fixture[$field])) {
                throw new \InvalidArgumentException('Fixture capabilities must be booleans.');
            }
        }
        $filters = $checkKeys($fixture['filters'], ['academic_term', 'academic_year']);
        foreach (['academic_term' => 64, 'academic_year' => 32] as $field => $maximum) {
            if (array_key_exists($field, $filters)) {
                $checkText($filters[$field], $maximum);
            }
        }
        if (!is_array($fixture['providers']) || !array_is_list($fixture['providers'])
            || count($fixture['providers']) > 40) {
            throw new \InvalidArgumentException('Invalid synthetic provider list.');
        }
        $providerIds = [];
        foreach ($fixture['providers'] as $provider) {
            $provider = $checkKeys($provider, ['id', 'first_name', 'last_name', 'username', 'role'], ['id', 'role', 'username']);
            $checkInt($provider['id'], 97001, 97040);
            if (isset($providerIds[$provider['id']])
                || !in_array($provider['role'], ['counsellor', 'guidance_supervisor', 'guidance_admin'], true)) {
                throw new \InvalidArgumentException('Invalid synthetic provider identity or role.');
            }
            $providerIds[$provider['id']] = true;
            foreach (['first_name', 'last_name', 'username'] as $field) {
                if (array_key_exists($field, $provider)) {
                    $checkText($provider[$field], 65536, true);
                }
            }
        }
        $checkLabels($fixture['psychosocialLabels'], $psychosocialDimensions);
        $checkLabels($fixture['counselingLabels'], $counselingDimensions);
        $checkLabels($fixture['visitTypeLabels'], $visitLabels);
        $checkAggregate($fixture['psychosocial'], $psychosocialDimensions, 'psychosocial_support_evaluation', 'F-OGC-GPS-PEF-008-00');
        $checkAggregate($fixture['counseling'], $counselingDimensions, 'counseling_evaluation', 'F-OGC-GCS-CEF-009-00');

        $GLOBALS['guidanceEvaluationFixtureOrigin'] = $origin;
        $GLOBALS['guidanceEvaluationFixtureCurrentUrl'] = $currentUrl;
        $sections = (new GuidanceServiceEvaluationsFixtureView())->render($fixture);
        echo json_encode($sections, JSON_THROW_ON_ERROR);
    } catch (\Throwable $error) {
        // Diagnostics never echo fixture values; stdout is reserved for sections.
        fwrite(STDERR, 'Synthetic evaluation fixture rejected: ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
