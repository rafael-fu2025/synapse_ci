<?php

declare(strict_types=1);

namespace App\Services\Scheduling {
    // Local stand-in only: never load the application clock or its dependencies.
    final class SchedulingClock
    {
        public static function now(): \DateTimeImmutable
        {
            return new \DateTimeImmutable('2031-11-24 09:00:00', new \DateTimeZone('Asia/Manila'));
        }

        public static function formatManila(?string $utc, string $format = 'Y-m-d H:i:s'): string
        {
            if ($utc === null || $utc === '') {
                return 'Not recorded';
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new \DateTimeZone('UTC'));
            if ($date === false || $date->format('Y-m-d H:i:s') !== $utc) {
                throw new \RuntimeException('fixture-time');
            }
            return $date->setTimezone(new \DateTimeZone('Asia/Manila'))->format($format);
        }
    }
}

namespace {
    // Required STDIN keys: origin, url, kind, csrfHash. Defaults: variant=full, long=false.
    // Optional dashboardSeries: dashboard only, exactly seven created-batch integers (0..1000).
    // stdout contains only content/page_actions/styles/scripts JSON, never a layout.
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    error_reporting(E_ALL);
    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new \RuntimeException('fixture-warning');
    });

    function esc(mixed $value, string $context = 'html'): string
    {
        if (!in_array($context, ['html', 'attr'], true) || ($value !== null && !is_scalar($value))) {
            throw new \RuntimeException('fixture-escape');
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    function site_url(string $path = ''): string
    {
        if (preg_match('/[\x00-\x20\\\\]/', $path) || str_starts_with($path, '//') || str_contains($path, '://')) {
            throw new \RuntimeException('fixture-link');
        }
        return $GLOBALS['bmgWorkstationOrigin'] . '/' . ltrim($path, '/');
    }

    function base_url(string $path = ''): string
    {
        return site_url($path);
    }

    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_test_name" value="'
            . esc($GLOBALS['bmgWorkstationCsrfHash'], 'attr') . '">';
    }

    function view(string $name, array $data = []): string
    {
        $root = dirname(__DIR__, 2) . '/app/Views/';
        $allowed = [
            'partials/table_pagination' => $root . 'partials/table_pagination.php',
            'partials/schedule_time' => $root . 'partials/schedule_time.php',
        ];
        if (!isset($allowed[$name])) {
            throw new \RuntimeException('fixture-helper');
        }
        $level = ob_get_level();
        ob_start();
        (static function (string $file, array $variables): void {
            extract($variables, EXTR_SKIP);
            require $file;
        })($allowed[$name], $data);
        if (ob_get_level() !== $level + 1) {
            throw new \RuntimeException('fixture-buffer');
        }
        return (string) ob_get_clean();
    }

    // The current pagination helper needs no config(), request, session or service shim.
    final class BmgWorkstationSections
    {
        private array $sections = ['content' => '', 'page_actions' => '', 'styles' => '', 'scripts' => ''];
        private ?string $active = null;
        private int $sectionLevel = 0;

        public function extend(string $layout): string
        {
            if ($layout !== 'layouts/app') {
                throw new \RuntimeException('fixture-layout');
            }
            return '';
        }

        public function section(string $name): string
        {
            if ($this->active !== null || !array_key_exists($name, $this->sections)) {
                throw new \RuntimeException('fixture-section');
            }
            $this->active = $name;
            ob_start();
            $this->sectionLevel = ob_get_level();
            return '';
        }

        public function endSection(): string
        {
            if ($this->active === null || ob_get_level() !== $this->sectionLevel) {
                throw new \RuntimeException('fixture-section');
            }
            $this->sections[$this->active] .= (string) ob_get_clean();
            $this->active = null;
            return '';
        }

        public function render(string $kind, array $data): array
        {
            $allowed = [
                'index' => dirname(__DIR__, 2) . '/app/Views/facilities/index.php',
                'dashboard' => dirname(__DIR__, 2) . '/app/Views/facilities/dashboard.php',
                'unit_detail' => dirname(__DIR__, 2) . '/app/Views/facilities/unit_detail.php',
            ];
            if (!isset($allowed[$kind])) {
                throw new \RuntimeException('fixture-view');
            }
            $file = $allowed[$kind];
            extract($data, EXTR_SKIP);
            // These real templates include their own fixed __DIR__ partials.
            require $file;
            if ($this->active !== null) {
                throw new \RuntimeException('fixture-section');
            }
            return $this->sections;
        }
    }

    function bmgWorkstationQuery(string $origin, string $url, string $kind): array
    {
        if (strlen($url) > 2048) {
            throw new \InvalidArgumentException('fixture-url');
        }
        // Both a canonical same-origin URL and an origin-relative request target are safe.
        $target = str_starts_with($url, $origin . '/') ? substr($url, strlen($origin)) : $url;
        if (!preg_match('~\A(/facilities(?:/dashboard|/unit/98001)?)(?:\?([^#]*))?\z~', $target, $parts)) {
            throw new \InvalidArgumentException('fixture-url');
        }
        $routes = ['index' => '/facilities', 'dashboard' => '/facilities/dashboard', 'unit_detail' => '/facilities/unit/98001'];
        if (!isset($routes[$kind]) || $parts[1] !== $routes[$kind]) {
            throw new \InvalidArgumentException('fixture-route');
        }
        $query = [];
        $raw = $parts[2] ?? '';
        if ($raw === '') {
            return $query;
        }
        if (!preg_match('/\A[a-z_]+=[a-z0-9]+(?:&[a-z_]+=[a-z0-9]+)*\z/', $raw)) {
            throw new \InvalidArgumentException('fixture-query');
        }
        $allowed = match ($kind) {
            'index' => ['page', 'per_page', 'view', 'create'],
            'unit_detail' => ['commands_page', 'commands_per_page', 'runs_page', 'runs_per_page', 'batches_page', 'batches_per_page'],
            'dashboard' => [],
        };
        foreach (explode('&', $raw) as $pair) {
            [$key, $value] = explode('=', $pair, 2);
            if (!in_array($key, $allowed, true) || array_key_exists($key, $query)) {
                throw new \InvalidArgumentException('fixture-query');
            }
            if ($key === 'view') {
                $valid = in_array($value, ['current', 'archived'], true);
            } elseif ($key === 'create') {
                $valid = in_array($value, ['0', '1'], true);
            } elseif ($key === 'per_page' || str_ends_with($key, '_per_page')) {
                $valid = in_array($value, ['20', '25', '50', '100'], true);
            } else {
                $valid = preg_match('/\A[1-9][0-9]{0,3}\z/', $value) === 1 && (int) $value <= 1000;
            }
            if (!$valid) {
                throw new \InvalidArgumentException('fixture-query');
            }
            $query[$key] = $value;
        }
        return $query;
    }

    function bmgWorkstationPage(array $rows, array $query, string $prefix = '', int $defaultSize = 25): array
    {
        $pageKey = $prefix === '' ? 'page' : $prefix . '_page';
        $sizeKey = $prefix === '' ? 'per_page' : $prefix . '_per_page';
        $size = (int) ($query[$sizeKey] ?? $defaultSize);
        $total = count($rows);
        $pages = max(1, (int) ceil($total / $size));
        $page = min($pages, (int) ($query[$pageKey] ?? 1));
        $offset = ($page - 1) * $size;
        return [array_slice($rows, $offset, $size), [
            'page' => $page, 'per_page' => $size, 'total' => $total, 'filtered' => $total,
            'pages' => $pages, 'from' => $total === 0 ? 0 : $offset + 1,
            'to' => min($offset + $size, $total),
        ]];
    }

    $baseLevel = ob_get_level();
    $finished = false;
    $phase = 'fixture-input';
    ob_start();
    register_shutdown_function(static function () use (&$finished, $baseLevel): void {
        if (!$finished) {
            while (ob_get_level() > $baseLevel) {
                ob_end_clean();
            }
            fwrite(STDERR, "fixture-aborted\n");
        }
    });

    try {
        $raw = stream_get_contents(STDIN, 32769);
        if ($raw === false || strlen($raw) > 32768) {
            throw new \InvalidArgumentException('fixture-size');
        }
        $object = json_decode($raw, false, 4, JSON_THROW_ON_ERROR);
        if (!$object instanceof \stdClass) {
            throw new \InvalidArgumentException('fixture-object');
        }
        $input = get_object_vars($object);
        if (array_diff(array_keys($input), ['origin', 'url', 'kind', 'variant', 'long', 'csrfHash', 'dashboardSeries']) !== []
            || preg_match_all('/"(?:[^"\\\\]|\\\\.)*"\s*:/s', $raw) !== count($input)) {
            throw new \InvalidArgumentException('fixture-keys');
        }
        foreach (['origin', 'url', 'kind', 'csrfHash'] as $key) {
            if (!isset($input[$key]) || !is_string($input[$key])) {
                throw new \InvalidArgumentException('fixture-type');
            }
        }
        $origin = $input['origin'];
        if (!preg_match('~\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z~', $origin, $port) || (int) $port[1] > 65535) {
            throw new \InvalidArgumentException('fixture-origin');
        }
        $kind = $input['kind'];
        $variant = $input['variant'] ?? 'full';
        $long = $input['long'] ?? false;
        if (!in_array($kind, ['index', 'dashboard', 'unit_detail'], true)
            || !is_string($variant)
            || !in_array($variant, ['full', 'reader', 'operator', 'config-only', 'gate-off', 'unbound', 'empty', 'active-batch', 'zero-version', 'stale', 'archived', 'zero-batches'], true)
            || ($variant === 'zero-batches' && $kind !== 'dashboard')
            || !is_bool($long)
            || (array_key_exists('variant', $input) && $input['variant'] === null)
            || (array_key_exists('long', $input) && $input['long'] === null)) {
            throw new \InvalidArgumentException('fixture-options');
        }
        $dashboardSeries = in_array($variant, ['empty', 'zero-batches'], true) ? array_fill(0, 7, 0) : [3, 0, 8, 4, 2, 9, 5];
        if (array_key_exists('dashboardSeries', $input)) {
            if ($kind !== 'dashboard' || !is_array($input['dashboardSeries']) || count($input['dashboardSeries']) !== 7) {
                throw new \InvalidArgumentException('fixture-series');
            }
            foreach ($input['dashboardSeries'] as $value) {
                if (!is_int($value) || $value < 0 || $value > 1000
                    || (in_array($variant, ['empty', 'zero-batches'], true) && $value !== 0)) {
                    throw new \InvalidArgumentException('fixture-series');
                }
            }
            $dashboardSeries = $input['dashboardSeries'];
        }
        $hash = $input['csrfHash'];
        if (!preg_match('/\A[0-9a-fA-F]{32,128}\z/', $hash) || strlen($hash) % 2 !== 0) {
            throw new \InvalidArgumentException('fixture-csrf');
        }
        $query = bmgWorkstationQuery($origin, $input['url'], $kind);
        $GLOBALS['bmgWorkstationOrigin'] = $origin;
        $GLOBALS['bmgWorkstationCsrfHash'] = $hash;

        $archived = $variant === 'archived' || ($query['view'] ?? '') === 'archived';
        $gate = $variant !== 'gate-off';
        $notes = $long ? str_repeat("Synthetic notes only; invented layout data.\n", 40) : 'Synthetic notes only.';
        $activeBatch = $variant === 'active-batch' && !$archived ? [
            'id' => 99501, 'unit_id' => 98001, 'batch_code' => 'SYN_BATCH001', 'status' => 'processing',
            'started_at' => '2031-11-23 08:00:00', 'completed_at' => null,
            'total_input_weight_kg' => '25.00', 'output_weight_kg' => null, 'notes' => $notes, 'release_notes' => null,
        ] : null;
        $completeUnits = [];
        foreach (range(1, 30) as $number) {
            $name = sprintf('Synthetic drum %03d', $number);
            $unit = [
                'id' => 98000 + $number, 'unit_code' => sprintf('SYN_D%03d', $number),
                'name' => $long ? str_pad($name . ' ', 100, 'Synthetic') : $name,
                'status' => $archived ? 'offline' : ['active', 'maintenance', 'offline'][($number - 1) % 3],
                'drum_capacity_kg' => '100.00', 'deleted_at' => $archived ? '2031-11-23 11:00:00' : null,
            ];
            $schedule = [
                'version' => $variant === 'zero-version' ? 0 : 3, 'enabled' => in_array($variant, ['config-only', 'gate-off'], true) && !$archived,
                'timezone' => 'Asia/Manila', 'times' => ['06:00', '18:00'],
                'rotations' => 7, 'pwm' => 120, 'direction' => 'forward',
            ];
            $device = $archived || $variant === 'unbound' || $number % 3 === 0 ? null : [
                'id' => 99000 + $number, 'unit_id' => $unit['id'],
                'chip_mac' => sprintf('02:00:00:00:00:%02X', $number), 'status' => 'active',
                'firmware_version' => 'Synthetic1.0', 'rtc_valid' => true,
                'applied_schedule_version' => $schedule['version'], 'last_seen_at' => '2031-11-23 10:00:00',
                'firmware_reported_at' => '2031-11-23 10:00:00', 'rtc_reported_at' => '2031-11-23 10:00:00',
                'schedule_reported_at' => '2031-11-23 10:00:00',
            ];
            if ($device !== null && $variant === 'stale') {
                $device['firmware_reported_at'] = '2031-01-01 00:00:00';
                $device['rtc_valid'] = null;
                $device['rtc_reported_at'] = null;
                $device['applied_schedule_version'] = 2;
                $device['schedule_reported_at'] = '2031-01-01 00:00:00';
            }
            $completeUnits[] = [
                'unit' => $unit, 'schedule' => $schedule, 'device' => $device, 'hardware_enabled' => $gate,
                'activeBatch' => $number === 1 ? $activeBatch : null,
            ];
        }
        $data = [
            'title' => 'Synthetic BMG workstation', 'canManage' => $variant !== 'reader',
            'canConfigure' => !in_array($variant, ['reader', 'operator'], true),
            'canHardware' => !in_array($variant, ['reader', 'config-only'], true),
            'hardwareEnabled' => $gate, 'hardware_enabled' => $gate,
            'canUsb' => $kind === 'dashboard' && !in_array($variant, ['reader', 'config-only'], true),
            'archivedView' => $archived,
        ];
        if ($kind === 'dashboard') {
            $records = $variant === 'empty' ? [] : $completeUnits;
            if ($long) {
                foreach ($records as &$record) {
                    $record['unit']['unit_code'] .= '_<&\"\'_SYN_' . str_repeat('Synthetic', 9);
                    $record['unit']['name'] = 'Synthetic <svg data-bmg-fixture-injection onload="window.fixtureEscapeRan=1"></svg> & "layout" \'only\' ' . str_repeat('SyntheticLongDrum', 22);
                    if ($record['device'] !== null) {
                        $record['device']['chip_mac'] .= ' <synthetic> & "layout" \'only\' ' . str_repeat('SyntheticBinding', 10);
                    }
                }
                unset($record);
            }
            $data['user'] = [
                'name' => 'Synthetic <operator> & "layout" \'only\'' . ($long ? ' ' . str_repeat('SyntheticOperator', 20) : ''),
                'role' => 'facilities_operator & <synthetic>',
            ];
            $data['units'] = $records;
            $data['totalUnits'] = count($records);
            $data['activeUnits'] = $data['maintenanceUnits'] = $data['boundDevices'] = 0;
            foreach ($records as $record) {
                $data['activeUnits'] += $record['unit']['status'] === 'active' ? 1 : 0;
                $data['maintenanceUnits'] += $record['unit']['status'] === 'maintenance' ? 1 : 0;
                $data['boundDevices'] += $record['device'] === null ? 0 : 1;
            }
            $zeroBatches = in_array($variant, ['empty', 'zero-batches'], true);
            $data['totalBatches'] = $zeroBatches ? 0 : 73;
            $data['activeBatches'] = $zeroBatches ? 0 : 4;
            $data['totalTreatedKg'] = $zeroBatches ? 0.0 : 1325.0;
            $data['chartDays'] = [];
            $today = \App\Services\Scheduling\SchedulingClock::now();
            for ($day = 6; $day >= 0; --$day) {
                $data['chartDays'][] = $today->modify('-' . $day . ' days')->format('M j');
            }
            $data['chartSeries'] = $dashboardSeries;
        } elseif ($kind === 'index') {
            $records = $variant === 'empty' ? [] : $completeUnits;
            $stats = ['total' => count($records), 'active' => 0, 'maintenance' => 0, 'bound' => 0];
            foreach ($records as $record) {
                if (isset($stats[$record['unit']['status']])) {
                    ++$stats[$record['unit']['status']];
                }
                $stats['bound'] += $record['device'] === null ? 0 : 1;
            }
            [$data['units'], $data['pagination']] = bmgWorkstationPage($records, $query);
            $data['stats'] = $stats;
            $data['openCreate'] = ($query['create'] ?? '') === '1' && $data['canConfigure'];
        } else {
            $commands = $runs = $pastBatches = [];
            $clock = new \DateTimeImmutable('2031-11-23 09:00:00', new \DateTimeZone('UTC'));
            if ($variant !== 'empty') {
                for ($i = 0; $i < 55; ++$i) {
                    $time = $clock->modify('-' . $i . ' minutes');
                    $commands[] = [
                        'id' => 99755 - $i, 'unit_id' => 98001, 'created_at' => $time->format('Y-m-d H:i:s'),
                        'command' => 'run_now', 'rotations' => 7, 'status' => 'acknowledged',
                        'ack_status' => 'accepted', 'ack_message' => 'Synthetic acknowledgement ' . (55 - $i)
                            . ($long ? ' ' . str_repeat('Synthetic layout text. ', 12) : ''),
                        'acknowledged_at' => $time->modify('+1 second')->format('Y-m-d H:i:s'),
                        'expires_at' => $time->modify('+60 seconds')->format('Y-m-d H:i:s'),
                    ];
                }
                for ($i = 0; $i < 57; ++$i) {
                    $time = $clock->modify('-' . $i . ' minutes');
                    $runs[] = [
                        'id' => 99857 - $i, 'unit_id' => 98001, 'occurred_at' => $time->format('Y-m-d H:i:s'),
                        'motor_state' => 'turned_normal', 'rotations' => 7,
                        'created_at' => $time->modify('+1 second')->format('Y-m-d H:i:s'),
                        'raw_payload' => '{"synthetic":true,"motor_state":"turned_normal","rotations":7}',
                    ];
                }
                for ($i = 0; $i < 53; ++$i) {
                    $time = $clock->modify('-' . ($i + 2) . ' hours');
                    $pastBatches[] = [
                        'id' => 99653 - $i, 'unit_id' => 98001, 'batch_code' => sprintf('SYN_BATCH%03d', 54 - $i),
                        'status' => 'released', 'started_at' => $time->format('Y-m-d H:i:s'),
                        'completed_at' => $time->modify('+1 hour')->format('Y-m-d H:i:s'),
                        'total_input_weight_kg' => '25.00', 'output_weight_kg' => '20.00',
                        'notes' => $notes, 'release_notes' => 'Synthetic completion notes only.',
                    ];
                }
            }
            $data += $completeUnits[0];
            [$data['commands'], $data['commandsPagination']] = bmgWorkstationPage($commands, $query, 'commands', 20);
            [$data['runs'], $data['runsPagination']] = bmgWorkstationPage($runs, $query, 'runs', 20);
            [$data['pastBatches'], $data['batchesPagination']] = bmgWorkstationPage($pastBatches, $query, 'batches', 50);
        }

        $phase = 'fixture-render';
        $sections = (new BmgWorkstationSections())->render($kind, $data);
        if (ob_get_level() !== $baseLevel + 1 || trim((string) ob_get_contents()) !== '') {
            throw new \RuntimeException('fixture-output');
        }
        $phase = 'fixture-json';
        $json = json_encode($sections, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        ob_end_clean();
        fwrite(STDOUT, $json . "\n");
        $finished = true;
    } catch (\Throwable $error) {
        while (ob_get_level() > $baseLevel) {
            ob_end_clean();
        }
        $finished = true;
        fwrite(STDERR, $phase . "\n");
        exit(1);
    }
}
