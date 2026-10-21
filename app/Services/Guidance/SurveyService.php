<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Services\Audit\AuditService;
use App\Services\EngagementAccess;
use App\Services\EngagementAudience;
use App\Services\EngagementNotifications;
use App\Services\PortalContent;
use App\Services\Reporting\ExportAccess;
use App\Services\Reporting\ExportCsv;
use App\Services\Reporting\ExportRange;
use CodeIgniter\Database\BaseBuilder;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class SurveyService
{
    private function staff(string $permission = 'guidance.surveys.manage'): array
    {
        $actor = EngagementAccess::actor($permission, 'guidance');
        if (!in_array($actor['role'], ['superadmin', 'guidance_admin', 'guidance_supervisor'], true)) {
            throw new RuntimeException('Guidance survey administrator access required.', 403);
        }
        return $actor;
    }

    public function listing(array $query = []): array
    {
        $actor = $this->staff();
        $builder = Database::connect()->table('surveys s')->where('s.tenant_id', $actor['tenant_id']);
        return $this->page($builder->select('s.*'), $query, 's.id');
    }

    public function get(int $id): array
    {
        $actor = $this->staff();
        return $this->hydrate($this->record($id, $actor));
    }

    public function create(array $input): array
    {
        $actor = $this->staff();
        return $this->transaction(function () use ($actor, $input): array {
            $fields = $this->validate($input);
            $db = Database::connect();
            $this->must($db->table('surveys')->insert($fields + [
                'tenant_id' => $actor['tenant_id'], 'status' => 'draft', 'created_by' => $actor['id'],
                'created_at' => $this->now(), 'updated_at' => $this->now(),
            ]));
            $id = (int) $db->insertID();
            $this->audit('created', $id, $actor);
            return $this->hydrate($this->record($id, $actor));
        });
    }

    public function update(int $id, array $input): array
    {
        $actor = $this->staff();
        $version = $input['version'] ?? null;
        unset($input['version']);
        return $this->transaction(function () use ($id, $actor, $input, $version): array {
            $row = $this->record($id, $actor, true);
            if ($row['status'] !== 'draft') {
                throw new RuntimeException('Published surveys are immutable. Create a new survey for corrections.', 409);
            }
            if ((!is_string($version) && !is_int($version)) || !preg_match('/\A[1-9][0-9]*\z/D', (string) $version)
                || (string) $row['version'] !== (string) $version) {
                throw new RuntimeException('Survey draft changed. Reload before saving your changes.', 409);
            }
            $fields = $this->validate($input, json_decode($row['questions_json'], true, 512, JSON_THROW_ON_ERROR));
            $this->must(Database::connect()->table('surveys')->where('tenant_id', $actor['tenant_id'])
                ->where('id', $id)->where('version', $row['version'])
                ->update($fields + ['version' => (int) $row['version'] + 1, 'updated_at' => $this->now()]));
            $this->audit('updated', $id, $actor);
            return $this->hydrate($this->record($id, $actor));
        });
    }

    public function publish(int $id): void
    {
        $actor = $this->staff();
        $this->transaction(function () use ($id, $actor): void {
            $row = $this->record($id, $actor, true);
            if ($row['status'] === 'published') { return; }
            if ($row['status'] !== 'draft') { throw new RuntimeException('This survey cannot be published.', 409); }
            $this->questions(json_decode($row['questions_json'], true, 512, JSON_THROW_ON_ERROR));
            $target = [];
            foreach (['target_year_levels', 'target_student_categories'] as $field) {
                $value = $row[$field] ?? null;
                if ($value === null) { continue; }
                if (!is_string($value)) { throw new RuntimeException('Invalid stored student targeting.', 422); }
                try { $decoded = json_decode($value, false, 512, JSON_THROW_ON_ERROR); }
                catch (\JsonException $e) { throw new RuntimeException('Invalid stored student targeting.', 422, $e); }
                if (!is_array($decoded)) { throw new RuntimeException('Invalid stored student targeting.', 422); }
                $target[$field] = $decoded;
            }
            EngagementAudience::targeting($target, EngagementAudience::validate($row['audience']));
            $this->activeKey();
            if ($row['ends_at'] !== null && $row['ends_at'] <= $this->now()) {
                throw new RuntimeException('The survey closing time must be in the future.', 422);
            }
            $this->must(Database::connect()->table('surveys')->where('tenant_id', $actor['tenant_id'])->where('id', $id)
                ->update(['status' => 'published', 'published_at' => $this->now(), 'updated_at' => $this->now()]));
            EngagementNotifications::publish('survey', $id, $actor);
            $this->audit('published', $id, $actor);
        });
    }

    public function close(int $id): void
    {
        $actor = $this->staff();
        $this->transaction(function () use ($id, $actor): void {
            $row = $this->record($id, $actor, true);
            if ($row['status'] === 'closed') { return; }
            if ($row['status'] !== 'published') { throw new RuntimeException('Only published surveys can be closed.', 409); }
            $this->must(Database::connect()->table('surveys')->where('tenant_id', $actor['tenant_id'])->where('id', $id)
                ->update(['status' => 'closed', 'closed_at' => $this->now(), 'updated_at' => $this->now()]));
            $this->audit('closed', $id, $actor);
        });
    }

    public function available(array $query = []): array
    {
        $actor = EngagementAccess::actor();
        $filters = PortalContent::filters($query, ['all', 'available', 'submitted', 'closed']);
        $counts = array_fill_keys(['all', 'available', 'submitted', 'closed'], 0);
        $now = $this->now();
        if (empty($actor['person_id'])) {
            return ['items' => [], 'filters' => $filters, 'counts' => $counts, 'hasProfile' => false, 'nowUtc' => $now,
                'pagination' => ['page' => 1, 'per_page' => $filters['per_page'], 'total' => 0, 'filtered' => 0, 'pages' => 1]];
        }
        $db = Database::connect();
        $builder = EngagementAudience::apply($db->table('surveys s')
            ->where('s.tenant_id', $actor['tenant_id'])->whereIn('s.status', ['published', 'closed'])
            ->where('s.published_at IS NOT NULL', null, false), $actor, 's');
        // Closed history still obeys today's profile targeting; drafts and future starts stay private.
        $builder->groupStart()->where('s.starts_at', null)->orWhere('s.starts_at <=', $now)->groupEnd();
        if ($filters['q'] !== '') $builder->groupStart()->like('s.title', $filters['q'])->orLike('s.description', $filters['q'])->groupEnd();
        $table = $db->protectIdentifiers($db->prefixTable('survey_responses'));
        $person = (int) $actor['person_id'];
        $submitted = "EXISTS(SELECT 1 FROM {$table} r WHERE r.tenant_id=s.tenant_id AND r.survey_id=s.id AND r.person_id={$person})";
        $open = "(s.status='published' AND (s.ends_at IS NULL OR s.ends_at > " . $db->escape($now) . '))';
        $conditions = ['available' => $open . ' AND NOT ' . $submitted, 'submitted' => $submitted, 'closed' => 'NOT ' . $open];
        $counts['all'] = (clone $builder)->countAllResults();
        foreach ($conditions as $state => $condition) $counts[$state] = (clone $builder)->where($condition, null, false)->countAllResults();
        if ($filters['status'] !== 'all') $builder->where($conditions[$filters['status']], null, false);
        $builder->select('s.id,s.title,s.description,s.starts_at,s.ends_at,s.published_at,s.status')
            ->select($submitted . ' AS submitted', false)->select('CASE WHEN ' . $open . ' THEN 0 ELSE 1 END AS closed', false);
        return PortalContent::page($builder, $filters, 's.id') + ['counts' => $counts, 'hasProfile' => true, 'nowUtc' => $now];
    }

    public function form(int $id): array
    {
        $actor = EngagementAccess::actor();
        $this->person($actor);
        $row = $this->record($id, $actor);
        $this->eligible($row, $actor);
        $row = $this->hydrate($row);
        $row['submitted'] = Database::connect()->table('survey_responses')->where([
            'tenant_id' => $actor['tenant_id'], 'survey_id' => $id, 'person_id' => $actor['person_id'],
        ])->countAllResults() > 0;
        unset($row['questions_json']);
        return $row;
    }

    /** The survey row serializes publish/close/submit and duplicate requests. */
    public function submit(int $id, mixed $answers): int
    {
        $actor = EngagementAccess::actor();
        $this->person($actor);
        return $this->transaction(function () use ($id, $answers, $actor): int {
            $row = $this->record($id, $actor, true);
            $this->eligible($row, $actor);
            $db = Database::connect();
            $scope = ['tenant_id' => $actor['tenant_id'], 'survey_id' => $id, 'person_id' => $actor['person_id']];
            if ($db->table('survey_responses')->where($scope)->countAllResults() > 0) {
                throw new RuntimeException('You have already submitted this survey.', 409);
            }
            $questions = json_decode($row['questions_json'], true, 512, JSON_THROW_ON_ERROR);
            $normalized = $this->answers($questions, $answers);
            [$version, $key] = $this->activeKey();
            $nonce = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt(json_encode($normalized, JSON_THROW_ON_ERROR), 'aes-256-gcm', $key,
                OPENSSL_RAW_DATA, $nonce, $tag, $this->aad($scope), 16);
            if ($cipher === false) { throw new RuntimeException('Survey encryption unavailable.', 503); }
            $this->must($db->table('survey_responses')->insert($scope + [
                'submitted_by' => $actor['id'], 'payload_cipher' => $cipher, 'payload_nonce' => $nonce,
                'payload_tag' => $tag, 'payload_key_version' => $version, 'submitted_at' => $this->now(),
            ]));
            $responseId = (int) $db->insertID();
            $this->audit('submitted', $id, $actor, $responseId);
            return $responseId;
        });
    }

    public function responses(int $id, array $query = []): array
    {
        $actor = $this->staff('guidance.responses.read');
        $survey = $this->hydrate($this->record($id, $actor));
        $builder = Database::connect()->table('survey_responses r')
            ->join('people p', 'p.tenant_id=r.tenant_id AND p.id=r.person_id')
            ->where('r.tenant_id', $actor['tenant_id'])->where('r.survey_id', $id)
            ->select('r.id,r.submitted_at,p.first_name,p.last_name');
        $result = $this->page($builder, $query, 'r.id');
        $this->audit('responses_listed', $id, $actor);
        $analytics = $this->aggregateAnalytics($id, $actor, $survey);
        try { ExportAccess::assertAllowed($actor, true); $canExport = true; }
                catch (RuntimeException $e) { $canExport = false; }
                return $result + ['survey' => $survey, 'analytics' => $analytics, 'canExportResponses' => $canExport];
    }

    private function aggregateAnalytics(int $id, array $actor, array $survey): array
    {
        $db = Database::connect();
        $responses = $db->table('survey_responses')
            ->where('tenant_id', $actor['tenant_id'])
            ->where('survey_id', $id)
            ->select('id, person_id, tenant_id, survey_id, payload_cipher, payload_nonce, payload_tag, payload_key_version, submitted_at')
            ->get()->getResultArray();

        $totalSubmissions = count($responses);
        $questions = $survey['questions'] ?? [];

        $questionStats = [];
        foreach ($questions as $idx => $q) {
            $questionStats[$idx] = [
                'type'          => $q['type'],
                'label'         => $q['label'],
                'options'       => $q['options'] ?? [],
                'counts'        => [],
                'total_answers' => 0,
                'avg_rating'    => null,
                'text_samples'  => [],
            ];
            if (in_array($q['type'], ['single', 'multiple'], true)) {
                foreach ($q['options'] as $opt) {
                    $questionStats[$idx]['counts'][$opt] = 0;
                }
            } elseif ($q['type'] === 'rating') {
                for ($r = 1; $r <= 5; $r++) {
                    $questionStats[$idx]['counts'][$r] = 0;
                }
            }
        }

        $dailyTrend = [];
        $overallRatingSum = 0;
        $overallRatingCount = 0;

        foreach ($responses as $row) {
            $day = 'Recent';
            try {
                $day = (new \DateTimeImmutable($row['submitted_at'], new \DateTimeZone('UTC')))
                    ->setTimezone(new \DateTimeZone('Asia/Manila'))->format('M j');
                $dailyTrend[$day] = ($dailyTrend[$day] ?? 0) + 1;
            } catch (\Throwable $e) {
                // Ignore date parsing fallback
            }

            try {
                $plaintext = openssl_decrypt(
                    $row['payload_cipher'],
                    'aes-256-gcm',
                    $this->key((int) $row['payload_key_version']),
                    OPENSSL_RAW_DATA,
                    $row['payload_nonce'],
                    $row['payload_tag'],
                    $this->aad($row)
                );
                if ($plaintext === false) { continue; }
                $answers = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                continue;
            }

            if (!is_array($answers)) { continue; }

            foreach ($answers as $idx => $ans) {
                if (!isset($questionStats[$idx])) { continue; }
                $type = $questionStats[$idx]['type'];
                $val = $ans['value'] ?? null;
                if ($val === null || $val === '') { continue; }

                $questionStats[$idx]['total_answers']++;

                if ($type === 'single') {
                    if (isset($questionStats[$idx]['counts'][$val])) {
                        $questionStats[$idx]['counts'][$val]++;
                    }
                } elseif ($type === 'multiple' && is_array($val)) {
                    foreach ($val as $choice) {
                        if (isset($questionStats[$idx]['counts'][$choice])) {
                            $questionStats[$idx]['counts'][$choice]++;
                        }
                    }
                } elseif ($type === 'rating') {
                    $num = (int) $val;
                    if ($num >= 1 && $num <= 5) {
                        $questionStats[$idx]['counts'][$num]++;
                        $overallRatingSum += $num;
                        $overallRatingCount++;
                    }
                } elseif ($type === 'text') {
                    if (count($questionStats[$idx]['text_samples']) < 25) {
                        $questionStats[$idx]['text_samples'][] = [
                            'text' => (string) $val,
                            'date' => $day,
                        ];
                    }
                }
            }
        }

        foreach ($questionStats as $idx => &$stat) {
            if ($stat['type'] === 'rating' && $stat['total_answers'] > 0) {
                $sum = 0;
                foreach ($stat['counts'] as $r => $cnt) {
                    $sum += ($r * $cnt);
                }
                $stat['avg_rating'] = round($sum / $stat['total_answers'], 1);
            }
        }
        unset($stat);

        return [
            'total_submissions'  => $totalSubmissions,
            'overall_avg_rating' => $overallRatingCount > 0 ? round($overallRatingSum / $overallRatingCount, 1) : null,
            'questions'          => $questionStats,
            'daily_trend'        => $dailyTrend,
        ];
    }

    public function responseDetail(int $id, int $responseId): array
    {
        $actor = $this->staff('guidance.responses.read');
        $survey = $this->record($id, $actor);
        $row = Database::connect()->table('survey_responses r')
            ->join('people p', 'p.tenant_id=r.tenant_id AND p.id=r.person_id')
            ->where('r.tenant_id', $actor['tenant_id'])->where('r.survey_id', $id)->where('r.id', $responseId)
            ->select('r.*,p.first_name,p.last_name')->get()->getRowArray();
        if ($row === null) { throw new RuntimeException('Response not found.', 404); }
        $answers = $this->decryptResponse($row);
        $this->audit('response_read', $id, $actor, $responseId);
        return ['survey' => $survey, 'response' => array_intersect_key($row,
            array_flip(['id', 'first_name', 'last_name', 'submitted_at'])), 'answers' => $answers];
    }

    /** Explicit identified download, never part of the aggregate operational report. */
    public function exportResponses(int $id, array $input): array
    {
        $actor = ExportAccess::actor(true);
        if (($input['confirm_identified'] ?? null) !== '1') {
            throw new RuntimeException('Confirm that this download contains identified survey answers.', 422);
        }
        $range = ExportRange::fromInput($input);
        $survey = $this->hydrate($this->record($id, $actor));
        [$from, $until] = $range->bounds();
        $rows = Database::connect()->table('survey_responses r')
            ->join('people p', 'p.tenant_id=r.tenant_id AND p.id=r.person_id')
            ->where('r.tenant_id', $actor['tenant_id'])->where('r.survey_id', $id)
            ->where('r.submitted_at >=', $from)->where('r.submitted_at <', $until)
            ->select('r.id,r.tenant_id,r.survey_id,r.person_id,r.submitted_at,r.payload_cipher,r.payload_nonce,r.payload_tag,r.payload_key_version,p.first_name,p.last_name')
            ->orderBy('r.id', 'ASC')->limit(501)->get()->getResultArray();
        $csv = $this->responseCsv($survey, $rows);
        $this->audit('responses_exported', $id, $actor, null,
            $range->metadata() + ['row_count' => count($rows), 'format' => 'csv', 'identified' => true]);
        return ['csv' => $csv, 'filename' => 'survey_' . $id . '_identified_' . $range->start->format('Y-m-d') . '_' . $range->end->format('Y-m-d') . '.csv'];
    }

    private function decryptResponse(array $row): array
    {
        $plaintext = openssl_decrypt($row['payload_cipher'], 'aes-256-gcm', $this->key((int) $row['payload_key_version']),
            OPENSSL_RAW_DATA, $row['payload_nonce'], $row['payload_tag'], $this->aad($row));
        if ($plaintext === false) { throw new RuntimeException('Survey response could not be authenticated.', 503); }
        $answers = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($answers)) { throw new RuntimeException('Invalid survey response.', 503); }
        return $answers;
    }

    private function responseCsv(array $survey, array $rows): string
    {
        if (count($rows) > 500) {
            throw new RuntimeException('Export exceeds 500 responses. Select a smaller date range; no rows were exported.', 422);
        }
        // Published survey copies are immutable; do not consult live/archived question-bank revisions.
        $questions = $survey['questions'];
        $header = ['response_id', 'first_name', 'last_name', 'submitted_at_manila'];
        foreach ($questions as $index => $question) { $header[] = 'Q' . ($index + 1) . ': ' . $question['label']; }
        $csvRows = (function () use ($header, $rows, $questions): iterable {
            yield $header;
            foreach ($rows as $row) {
                $answers = $this->decryptResponse($row);
                if (!array_is_list($answers) || count($answers) !== count($questions)) {
                    throw new RuntimeException('Survey response does not match its retained instrument.', 503);
                }
                $cells = [$row['id'], $row['first_name'], $row['last_name'],
                    (new DateTimeImmutable($row['submitted_at'], new DateTimeZone('UTC')))
                        ->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s')];
                foreach ($questions as $index => $question) {
                    if (!is_array($answers[$index]) || !array_key_exists('value', $answers[$index])) {
                        throw new RuntimeException('Invalid retained survey answer.', 503);
                    }
                    $value = $answers[$index]['value'];
                    $cells[] = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : $value;
                }
                yield $cells;
            }
        })();
        return ExportCsv::encode($csvRows);
    }

    private function record(int $id, array $actor, bool $lock = false): array
    {
        $db = Database::connect();
        $table = $db->protectIdentifiers($db->prefixTable('surveys'));
        $row = $db->query("SELECT * FROM {$table} WHERE tenant_id=? AND id=?" . ($lock && $db->DBDriver === 'MySQLi' ? ' FOR UPDATE' : ''),
            [$actor['tenant_id'], $id])->getRowArray();
        if ($row === null) { throw new RuntimeException('Survey not found.', 404); }
        return $row;
    }

    private function hydrate(array $row): array
    {
        $row['questions'] = json_decode($row['questions_json'], true, 512, JSON_THROW_ON_ERROR);
        return $row;
    }

    private function eligible(array $row, array $actor): void
    {
        $now = $this->now();
        if ($row['status'] !== 'published' || !EngagementAudience::eligible($actor, $row['audience'], $row)
            || ($row['starts_at'] !== null && $row['starts_at'] > $now)
            || ($row['ends_at'] !== null && $row['ends_at'] <= $now)) {
            throw new RuntimeException('Survey not available.', 404);
        }
    }

    private function person(array $actor): void
    {
        if (empty($actor['person_id'])) { throw new RuntimeException('An identified person profile is required.', 403); }
    }

    private function validate(array $input, array $existing = []): array
    {
        $this->shape($input, ['title', 'description', 'audience', 'target_year_levels', 'target_student_categories', 'starts_at', 'ends_at', 'questions']);
                $audience = EngagementAudience::validate($input['audience'] ?? null);
                $target = EngagementAudience::targeting($input, $audience);
        $start = $this->date($input['starts_at'] ?? null);
        $end = $this->date($input['ends_at'] ?? null);
        if ($start !== null && $end !== null && $end <= $start) {
            throw new RuntimeException('End time must be after start time.', 422);
        }
        $questions = $this->questions($input['questions'] ?? null);
        if (array_filter($questions, static fn (array $question): bool => isset($question['bank_id'])) !== []) {
            $questions = (new QuestionBankService())->verifyCopies($questions, $existing);
        }
        return ['title' => $this->text($input['title'] ?? null, 200),
            'description' => $this->text($input['description'] ?? '', 2000, true),
            'audience' => $audience,
            'starts_at' => $start, 'ends_at' => $end,
            'questions_json' => json_encode($questions, JSON_THROW_ON_ERROR)] + $target;
    }

    private function questions(mixed $questions): array
    {
        if (!is_array($questions) || !array_is_list($questions) || count($questions) < 1 || count($questions) > 20) {
            throw new RuntimeException('Provide between 1 and 20 questions.', 422);
        }
        return array_map(static fn ($question): array => SurveyQuestion::normalize($question), $questions);
    }

    /** Answers are a keyed map of zero-based question index to scalar/list value. */
    private function answers(array $questions, mixed $answers): array
    {
        if (!is_array($answers) || count($answers) > count($questions)) { throw new RuntimeException('Invalid answers.', 422); }
        foreach (array_keys($answers) as $index) {
            if (!is_int($index) || !array_key_exists($index, $questions)) { throw new RuntimeException('Unknown question in answers.', 422); }
        }
        $out = [];
        foreach ($questions as $index => $question) {
            $value = $answers[$index] ?? null;
            if ($value === null || $value === '' || $value === []) {
                if ($question['required']) { throw new RuntimeException('Please answer all required questions.', 422); }
                $out[] = ['question' => $question['label'], 'type' => $question['type'], 'value' => null];
                continue;
            }
            if ($question['type'] === 'text') { $value = $this->text($value, 2000); }
            elseif ($question['type'] === 'rating') { $value = $this->integer($value, 1, 5); }
            elseif ($question['type'] === 'single') {
                $value = $question['options'][$this->integer($value, 0, count($question['options']) - 1)];
            } else {
                if (!is_array($value) || !array_is_list($value) || count($value) > 10) { throw new RuntimeException('Invalid multiple choice answer.', 422); }
                $choices = array_map(fn ($v) => $this->integer($v, 0, count($question['options']) - 1), $value);
                if (count(array_unique($choices)) !== count($choices)) { throw new RuntimeException('Duplicate choices are not allowed.', 422); }
                $value = array_map(fn ($v) => $question['options'][$v], $choices);
            }
            $out[] = ['question' => $question['label'], 'type' => $question['type'], 'value' => $value];
        }
        return $out;
    }

    private function integer(mixed $value, int $min, int $max): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A(?:0|[1-9][0-9]*)\z/D', (string) $value)
            || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $min || (int) $value > $max) {
            throw new RuntimeException('Invalid numeric answer or page.', 422);
        }
        return (int) $value;
    }

    private function text(mixed $value, int $max, bool $empty = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) { throw new RuntimeException('Expected valid text.', 422); }
        $value = trim($value);
        if ((!$empty && $value === '') || mb_strlen($value) > $max) { throw new RuntimeException('Text is empty or too long.', 422); }
        return $value;
    }

    private function shape(array $input, array $keys): void
    {
        if (array_diff(array_keys($input), $keys) !== []) { throw new RuntimeException('Unexpected survey fields.', 422); }
    }

    /** Form times are explicit Manila wall times, stored as UTC instants. */
    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') { return null; }
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\z/D', $value)) {
            throw new RuntimeException('Use a valid date and time (Asia/Manila).', 422);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('Asia/Manila'));
        if ($date === false || $date->format('Y-m-d\TH:i') !== $value || (int) $date->format('Y') < 1970) {
            throw new RuntimeException('Invalid survey date.', 422);
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function page(BaseBuilder $builder, array $query, string $order): array
    {
        $page = $this->integer($query['page'] ?? 1, 1, 1000000);
        $perPage = $this->integer($query['per_page'] ?? 25, 1, 100);
        if (!in_array($perPage, [25, 50, 100], true)) { throw new RuntimeException('Invalid page size.', 422); }
        $total = (clone $builder)->countAllResults();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        return ['items' => $builder->orderBy($order, 'DESC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray(),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'filtered' => $total, 'pages' => $pages]];
    }

    /** SURVEY_KEY is 64 hex characters. Rotation uses SURVEY_KEY_VERSION=N and SURVEY_KEY_VN; retain old keys. */
    private function activeKey(): array
    {
        try { $version = $this->integer(env('SURVEY_KEY_VERSION', '1'), 1, 65535); }
        catch (RuntimeException $e) { throw new RuntimeException('Survey key version is not configured correctly.', 503); }
        return [$version, $this->key($version)];
    }

    private function key(int $version): string
    {
        $hex = env($version === 1 ? 'SURVEY_KEY' : 'SURVEY_KEY_V' . $version);
        if (!is_string($hex) || !preg_match('/\A[a-fA-F0-9]{64}\z/D', $hex)) {
            throw new RuntimeException('Survey encryption is not configured. Contact the administrator.', 503);
        }
        return hex2bin($hex);
    }

    private function aad(array $row): string
    {
        return 'humus:survey:v1:' . (int) $row['tenant_id'] . ':' . (int) $row['survey_id'] . ':' . (int) $row['person_id'];
    }

    private function audit(string $action, int $id, array $actor, ?int $response = null, array $metadata = []): void
    {
        (new AuditService())->log('guidance.survey.' . $action, 'surveys', (string) $id, $action,
            $response === null ? $metadata : ['response_id' => $response], (int) $actor['id'], $actor['role']);
    }

    private function transaction(callable $callback): mixed
    {
        $db = Database::connect();
        if ($db->transDepth !== 0) { throw new RuntimeException('Survey operations require an independent transaction.', 409); }
        $actor = EngagementAccess::actor();
        $db->resetTransStatus();
        if (($db->DBDriver === 'MySQLi' && !$db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')) || !$db->transBegin()) {
            throw new RuntimeException('Survey transaction could not begin.', 503);
        }
        try {
            // Keep the tenant-first lock order shared by all audit writers, including BMG.
            (new AuditService(null, $db))->lockTenant((int) $actor['tenant_id']);
            $result = $callback();
            if (!$db->transStatus() || !$db->transCommit()) { throw new RuntimeException('Unable to save survey.', 503); }
            return $result;
        } catch (Throwable $e) {
            try { $db->transRollback(); }
            finally { $db->resetTransStatus(); }
            throw $e;
        }
    }

    private function must(bool $success): void
    {
        if (!$success) { throw new RuntimeException('Unable to save survey.', 503); }
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
