<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Libraries\LikePattern;
use App\Services\Audit\AuditService;
use App\Services\EngagementAccess;
use App\Services\EngagementAudience;
use App\Services\EngagementNotifications;
use App\Services\NotificationInvalidation;
use App\Services\PortalContent;
use App\Services\Push\PushPublisher;
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

    public function publish(int $id, mixed $version = null): void
    {
        $actor = $this->staff();
        $this->transaction(function (array &$notifications) use ($id, $actor, $version): void {
            $row = $this->record($id, $actor, true);
            $this->transitionVersion($row, $version);
            if ($row['status'] === 'published') { return; }
            if ($row['status'] !== 'draft') { throw new RuntimeException('This survey cannot be published.', 409); }
            if (!empty($row['external_url'])) {
                $this->normalizeExternalUrl($row['external_url'], true);
            } else {
                $this->questions(json_decode($row['questions_json'], true, 512, JSON_THROW_ON_ERROR));
            }
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
            $db = Database::connect();
            $this->must($db->table('surveys')->where('tenant_id', $actor['tenant_id'])->where('id', $id)
                ->where('version', $version)
                ->update(['status' => 'published', 'version' => (int) $row['version'] + 1,
                    'published_at' => $this->now(), 'updated_at' => $this->now()]));
            if ($db->affectedRows() !== 1) {
                throw new RuntimeException('Survey changed. Reload before changing its state.', 409);
            }
            $notifications[(int) $actor['tenant_id']] = EngagementNotifications::publish('survey', $id, $actor);
            $this->audit('published', $id, $actor);
        });
    }

    public function close(int $id, mixed $version = null): void
    {
        $actor = $this->staff();
        $this->transaction(function () use ($id, $actor, $version): void {
            $row = $this->record($id, $actor, true);
            $this->transitionVersion($row, $version);
            if ($row['status'] === 'closed') { return; }
            if ($row['status'] !== 'published') { throw new RuntimeException('Only published surveys can be closed.', 409); }
            $db = Database::connect();
            $this->must($db->table('surveys')->where('tenant_id', $actor['tenant_id'])->where('id', $id)
                ->where('version', $version)
                ->update(['status' => 'closed', 'version' => (int) $row['version'] + 1,
                    'closed_at' => $this->now(), 'updated_at' => $this->now()]));
            if ($db->affectedRows() !== 1) {
                throw new RuntimeException('Survey changed. Reload before changing its state.', 409);
            }
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
        if ($filters['q'] !== '') LikePattern::applyGroup($builder, ['s.title', 's.description'], $filters['q']);
        $table = $db->protectIdentifiers($db->prefixTable('survey_responses'));
        $person = (int) $actor['person_id'];
        $submitted = "EXISTS(SELECT 1 FROM {$table} r WHERE r.tenant_id=s.tenant_id AND r.survey_id=s.id AND r.person_id={$person})";
        $open = "(s.status='published' AND (s.ends_at IS NULL OR s.ends_at > " . $db->escape($now) . '))';
        $conditions = ['available' => $open . ' AND NOT ' . $submitted, 'submitted' => $submitted, 'closed' => 'NOT ' . $open];
        $counts['all'] = (clone $builder)->countAllResults();
        foreach ($conditions as $state => $condition) $counts[$state] = (clone $builder)->where($condition, null, false)->countAllResults();
        if ($filters['status'] !== 'all') $builder->where($conditions[$filters['status']], null, false);
        $externalSelect = self::hasExternalUrlColumn($db) ? ',s.external_url' : ',NULL AS external_url';
        $builder->select('s.id,s.title,s.description,s.starts_at,s.ends_at,s.published_at,s.status' . $externalSelect)
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
        $responseRow = Database::connect()->table('survey_responses')->where([
            'tenant_id' => $actor['tenant_id'], 'survey_id' => $id, 'person_id' => $actor['person_id'],
        ])->select('id, tenant_id, survey_id, person_id, submitted_at, payload_cipher, payload_nonce, payload_tag, payload_key_version')
          ->get()->getRowArray();
        $row['submitted'] = $responseRow !== null;
        $row['submitted_at'] = $responseRow['submitted_at'] ?? null;
        $row['submitted_answers'] = null;
        if ($responseRow !== null) {
            try {
                $decoded = $this->decryptResponse($responseRow);
                if (is_array($decoded) && array_is_list($decoded)) {
                    $row['submitted_answers'] = $decoded;
                }
            } catch (Throwable) {
                $row['submitted_answers'] = null;
            }
        }
        unset($row['questions_json']);
        return $row;
    }

    /** The survey row serializes publish/close/submit and duplicate requests. */
    public function submit(int $id, mixed $answers, mixed $otherAnswers = []): int
    {
        $actor = EngagementAccess::actor();
        $this->person($actor);
        return $this->transaction(function () use ($id, $answers, $otherAnswers, $actor): int {
            $row = $this->record($id, $actor, true);
            $this->eligible($row, $actor);
            $db = Database::connect();
            $scope = ['tenant_id' => $actor['tenant_id'], 'survey_id' => $id, 'person_id' => $actor['person_id']];
            if ($db->table('survey_responses')->where($scope)->countAllResults() > 0) {
                throw new RuntimeException('You have already submitted this survey.', 409);
            }
            if (!empty($row['external_url'])) {
                if (!is_array($answers) || $answers !== [] || !is_array($otherAnswers) || $otherAnswers !== []) {
                    throw new RuntimeException('External link surveys do not accept in-app question answers.', 422);
                }
                $normalized = ['_external_link_completed' => true, 'external_url' => (string) $row['external_url']];
            } else {
                $questions = json_decode($row['questions_json'], true, 512, JSON_THROW_ON_ERROR);
                $normalized = $this->answers($questions, $answers, $otherAnswers);
            }
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
        $db = Database::connect();
        $hasInterviewCols = self::hasInterviewColumns($db);
        $select = 'r.id,r.submitted_at,p.first_name,p.last_name'
            . ($hasInterviewCols ? ',r.interviewed_by_user_id,r.interviewed_at' : '');
        $builder = $db->table('survey_responses r')
            ->join('people p', 'p.tenant_id=r.tenant_id AND p.id=r.person_id')
            ->where('r.tenant_id', $actor['tenant_id'])->where('r.survey_id', $id)
            ->select($select);
        $result = $this->page($builder, $query, 'r.id');
        if ($hasInterviewCols && !empty($result['items'])) {
            $directory = (new GuidancePractitionerService(db: $db))->getPractitionerDirectory((int) $actor['tenant_id']);
            foreach ($result['items'] as &$item) {
                $iid = (int) ($item['interviewed_by_user_id'] ?? 0);
                $info = $iid > 0 ? ($directory[$iid] ?? null) : null;
                $item['interviewer_name'] = $info['name'] ?? ($iid > 0 ? 'Staff #' . $iid : null);
                $item['interviewer_tier'] = $info['tier_label'] ?? null;
            }
            unset($item);
        }
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
        $otherBuckets = [];
        foreach ($questions as $idx => $q) {
            $questionStats[$idx] = [
                'type'            => $q['type'],
                'label'           => $q['label'],
                'options'         => $q['options'] ?? [],
                'allow_other'     => !empty($q['allow_other']),
                'scale_min_label' => $q['scale_min_label'] ?? '',
                'scale_max_label' => $q['scale_max_label'] ?? '',
                'counts'          => [],
                'total_answers'   => 0,
                'avg_rating'      => null,
                'text_samples'    => [],
                'other_samples'   => [],
            ];
            $otherBuckets[$idx] = null;
            if (in_array($q['type'], ['single', 'multiple'], true)) {
                $otherOptIdx = SurveyQuestion::otherOptionIndex($q);
                if ($otherOptIdx !== null) {
                    $otherBuckets[$idx] = $q['options'][$otherOptIdx];
                } elseif (!empty($q['allow_other'])) {
                    $otherBuckets[$idx] = 'Other (please specify)';
                    $questionStats[$idx]['options'][] = $otherBuckets[$idx];
                }
                foreach ($questionStats[$idx]['options'] as $opt) {
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
                    } elseif ($otherBuckets[$idx] !== null && preg_match('/\AOther:\s*(.+)\z/su', (string) $val, $otherMatch)) {
                        $questionStats[$idx]['counts'][$otherBuckets[$idx]]++;
                        if (count($questionStats[$idx]['other_samples']) < 25) {
                            $questionStats[$idx]['other_samples'][] = [
                                'text' => trim($otherMatch[1]),
                                'date' => $day,
                            ];
                        }
                    }
                } elseif ($type === 'multiple' && is_array($val)) {
                    foreach ($val as $choice) {
                        if (isset($questionStats[$idx]['counts'][$choice])) {
                            $questionStats[$idx]['counts'][$choice]++;
                        } elseif ($otherBuckets[$idx] !== null && preg_match('/\AOther:\s*(.+)\z/su', (string) $choice, $otherMatch)) {
                            $questionStats[$idx]['counts'][$otherBuckets[$idx]]++;
                            if (count($questionStats[$idx]['other_samples']) < 25) {
                                $questionStats[$idx]['other_samples'][] = [
                                    'text' => trim($otherMatch[1]),
                                    'date' => $day,
                                ];
                            }
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
        $db = Database::connect();
        $row = $db->table('survey_responses r')
            ->join('people p', 'p.tenant_id=r.tenant_id AND p.id=r.person_id')
            ->where('r.tenant_id', $actor['tenant_id'])->where('r.survey_id', $id)->where('r.id', $responseId)
            ->select('r.*,p.first_name,p.last_name')->get()->getRowArray();
        if ($row === null) { throw new RuntimeException('Response not found.', 404); }
        $answers = $this->decryptResponse($row);
        $staffRemarks = $this->decryptStaffRemarks($row);
        $interviewerId = (int) ($row['interviewed_by_user_id'] ?? 0);
        $interviewerName = null;
        $interviewerTier = null;
        if ($interviewerId > 0) {
            $directory = (new GuidancePractitionerService(db: $db))->getPractitionerDirectory((int) $actor['tenant_id']);
            $info = $directory[$interviewerId] ?? null;
            $interviewerName = $info['name'] ?? ('Staff #' . $interviewerId);
            $interviewerTier = $info['tier_label'] ?? null;
        }
        $this->audit('response_read', $id, $actor, $responseId);
        return [
            'survey' => $survey,
            'response' => array_intersect_key($row, array_flip(['id', 'person_id', 'first_name', 'last_name', 'submitted_at', 'interviewed_by_user_id', 'interviewed_at'])) + [
                'interviewer_name' => $interviewerName,
                'interviewer_tier' => $interviewerTier,
                'staff_remarks'    => $staffRemarks,
            ],
            'answers' => $answers,
        ];
    }

    public static function hasInterviewColumns(\CodeIgniter\Database\BaseConnection $db): bool
    {
        if ($db->DBDriver === 'MySQLi') {
            return $db->query(
                'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
                [$db->prefixTable('survey_responses'), 'interviewed_by_user_id']
            )->getRowArray() !== null;
        }

        return $db->fieldExists('interviewed_by_user_id', 'survey_responses');
    }

    public static function hasExternalUrlColumn(?\CodeIgniter\Database\BaseConnection $db = null): bool
    {
        $db ??= Database::connect();
        if ($db->DBDriver === 'MySQLi') {
            return $db->query(
                'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
                [$db->prefixTable('surveys'), 'external_url']
            )->getRowArray() !== null;
        }

        return $db->fieldExists('external_url', 'surveys');
    }

    /**
     * Records staff-only "Interviewed by / date" attribution and encrypted staff remarks on an identified response.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function recordInterview(int $id, int $responseId, array $input): array
    {
        $actor = $this->staff('guidance.responses.read');
        $db = Database::connect();
        if (!self::hasInterviewColumns($db)) {
            throw new RuntimeException('Survey interview fields are not installed. Run migrations first.', 503);
        }

        $remarks = null;
        if (array_key_exists('staff_remarks', $input) && $input['staff_remarks'] !== null && trim((string) $input['staff_remarks']) !== '') {
            $remarks = $this->text($input['staff_remarks'], 2000);
        }

        $interviewDateRaw = trim((string) ($input['interview_date'] ?? ''));
        if ($interviewDateRaw === '') {
            $interviewedAtUtc = $this->now();
        } else {
            if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $interviewDateRaw)) {
                $interviewDateRaw .= 'T09:00';
            }
            $interviewedAtUtc = $this->date($interviewDateRaw) ?? $this->now();
        }

        return $this->transaction(function () use ($id, $responseId, $actor, $db, $remarks, $interviewedAtUtc): array {
            $this->record($id, $actor);
            $row = $db->table('survey_responses')
                ->where('tenant_id', $actor['tenant_id'])
                ->where('survey_id', $id)
                ->where('id', $responseId)
                ->get()
                ->getRowArray();
            if ($row === null) {
                throw new RuntimeException('Response not found.', 404);
            }

            $update = [
                'interviewed_by_user_id'    => (int) $actor['id'],
                'interviewed_at'            => $interviewedAtUtc,
                'staff_remarks_cipher'      => null,
                'staff_remarks_nonce'       => null,
                'staff_remarks_tag'         => null,
                'staff_remarks_key_version' => null,
            ];

            if ($remarks !== null) {
                [$version, $key] = $this->activeKey();
                $nonce = random_bytes(12);
                $tag = '';
                $cipher = openssl_encrypt(
                    $remarks,
                    'aes-256-gcm',
                    $key,
                    OPENSSL_RAW_DATA,
                    $nonce,
                    $tag,
                    $this->staffRemarksAad($row),
                    16
                );
                if ($cipher === false || $tag === '') {
                    throw new RuntimeException('Unable to encrypt staff interview remarks.', 503);
                }
                $update['staff_remarks_cipher']      = $cipher;
                $update['staff_remarks_nonce']       = $nonce;
                $update['staff_remarks_tag']         = $tag;
                $update['staff_remarks_key_version'] = $version;
            }

            $this->must($db->table('survey_responses')
                ->where('tenant_id', $actor['tenant_id'])
                ->where('survey_id', $id)
                ->where('id', $responseId)
                ->update($update));

            $this->audit('survey_interview_recorded', $id, $actor, $responseId);

            return [
                'response_id'    => $responseId,
                'interviewed_at' => $interviewedAtUtc,
            ];
        });
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
        $externalUrl = $survey['external_url'] ?? null;
        if (!empty($externalUrl) && $questions === []) {
            $header = ['response_id', 'first_name', 'last_name', 'submitted_at_manila', 'delivery_mode', 'external_url', 'completion_status'];
            $csvRows = (function () use ($header, $rows, $externalUrl): iterable {
                yield $header;
                foreach ($rows as $row) {
                    $this->decryptResponse($row);
                    $manilaAt = (new DateTimeImmutable($row['submitted_at'], new DateTimeZone('UTC')))
                        ->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s');
                    yield [$row['id'], $row['first_name'], $row['last_name'], $manilaAt, 'External Link', (string) $externalUrl, 'Completed via external form'];
                }
            })();
            return ExportCsv::encode($csvRows);
        }
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
        $row['external_url'] = $row['external_url'] ?? null;
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
        $this->shape($input, ['title', 'description', 'audience', 'target_year_levels', 'target_student_categories', 'starts_at', 'ends_at', 'survey_mode', 'external_url', 'questions']);
        $audience = EngagementAudience::validate($input['audience'] ?? null);
        $target = EngagementAudience::targeting($input, $audience);
        $start = $this->date($input['starts_at'] ?? null);
        $end = $this->date($input['ends_at'] ?? null);
        if ($start !== null && $end !== null && $end <= $start) {
            throw new RuntimeException('End time must be after start time.', 422);
        }
        $rawMode = $input['survey_mode'] ?? null;
        if ($rawMode !== null && !in_array($rawMode, ['internal', 'external'], true)) {
            throw new RuntimeException('Invalid survey delivery mode.', 422);
        }
        $hasExternalCol = self::hasExternalUrlColumn();
        $rawUrl = $input['external_url'] ?? null;
        $isExternal = $rawMode === 'external'
            || ($rawMode === null && is_string($rawUrl) && trim($rawUrl) !== '');

        $fields = [
            'title' => $this->text($input['title'] ?? null, 200),
            'description' => $this->text($input['description'] ?? '', 2000, true),
            'audience' => $audience,
            'starts_at' => $start,
            'ends_at' => $end,
        ];

        if ($isExternal) {
            if (!$hasExternalCol) {
                throw new RuntimeException('External survey links are not installed. Run migrations first.', 503);
            }
            $fields['external_url'] = $this->normalizeExternalUrl($rawUrl, true);
            $fields['questions_json'] = json_encode([], JSON_THROW_ON_ERROR);
        } else {
            $questions = $this->questions($input['questions'] ?? null);
            if (array_filter($questions, static fn (array $question): bool => isset($question['bank_id'])) !== []) {
                $questions = (new QuestionBankService())->verifyCopies($questions, $existing);
            }
            $fields['questions_json'] = json_encode($questions, JSON_THROW_ON_ERROR);
            if ($hasExternalCol) {
                $fields['external_url'] = null;
            }
        }

        return $fields + $target;
    }

    private function normalizeExternalUrl(mixed $value, bool $required = false): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($required) {
                throw new RuntimeException('Enter a valid Google Form or external survey URL starting with https:// or http://.', 422);
            }
            return null;
        }
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException('Expected valid external survey URL.', 422);
        }
        $url = trim($value);
        if (mb_strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new RuntimeException('Enter a valid Google Form or external survey URL starting with https:// or http://.', 422);
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Enter a valid Google Form or external survey URL starting with https:// or http://.', 422);
        }
        return $url;
    }

    private function questions(mixed $questions): array
    {
        if (!is_array($questions) || !array_is_list($questions) || count($questions) < 1 || count($questions) > 20) {
            throw new RuntimeException('Provide between 1 and 20 questions.', 422);
        }
        return array_map(static fn ($question): array => SurveyQuestion::normalize($question), $questions);
    }

    /** Answers are a keyed map of zero-based question index to scalar/list value. */
    private function answers(array $questions, mixed $answers, mixed $otherAnswers = []): array
    {
        if (!is_array($answers) || !is_array($otherAnswers) || count($answers) > count($questions) || count($otherAnswers) > count($questions)) {
            throw new RuntimeException('Invalid answers.', 422);
        }
        foreach (array_keys($answers) as $index) {
            if (!is_int($index) || !array_key_exists($index, $questions)) { throw new RuntimeException('Unknown question in answers.', 422); }
        }
        foreach (array_keys($otherAnswers) as $index) {
            if (!is_int($index) || !array_key_exists($index, $questions)) { throw new RuntimeException('Unknown question in answers.', 422); }
        }
        $out = [];
        foreach ($questions as $index => $question) {
            $value = $answers[$index] ?? null;
            $otherText = $otherAnswers[$index] ?? null;
            if ($value === null || $value === '' || $value === []) {
                if ($question['required']) { throw new RuntimeException('Please answer all required questions.', 422); }
                $out[] = ['question' => $question['label'], 'type' => $question['type'], 'value' => null];
                continue;
            }
            if ($question['type'] === 'text') { $value = $this->text($value, 2000); }
            elseif ($question['type'] === 'rating') { $value = $this->integer($value, 1, 5); }
            elseif ($question['type'] === 'single') {
                $value = $this->resolveChoiceAnswer($question, $value, $otherText);
            } else {
                if (!is_array($value) || !array_is_list($value) || count($value) > 11) { throw new RuntimeException('Invalid multiple choice answer.', 422); }
                $tokens = array_map(static fn ($v) => is_string($v) || is_int($v) ? (string) $v : '', $value);
                if (in_array('', $tokens, true) || count(array_unique($tokens)) !== count($tokens)) {
                    throw new RuntimeException('Duplicate choices are not allowed.', 422);
                }
                $value = array_map(fn ($v) => $this->resolveChoiceAnswer($question, $v, $otherText), $value);
            }
            $out[] = ['question' => $question['label'], 'type' => $question['type'], 'value' => $value];
        }
        return $out;
    }

    private function resolveChoiceAnswer(array $question, mixed $rawChoice, mixed $rawOtherText): string
    {
        $allowExplicitOther = !empty($question['allow_other']);
        $otherOptionIndex = SurveyQuestion::otherOptionIndex($question);

        if ($rawChoice === '__other__') {
            if (!$allowExplicitOther) {
                throw new RuntimeException('Invalid choice answer.', 422);
            }
            return 'Other: ' . $this->requireOtherText($rawOtherText);
        }

        $choiceIndex = $this->integer($rawChoice, 0, count($question['options']) - 1);
        if ($otherOptionIndex !== null && $choiceIndex === $otherOptionIndex) {
            return 'Other: ' . $this->requireOtherText($rawOtherText);
        }

        return $question['options'][$choiceIndex];
    }

    private function requireOtherText(mixed $value): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || trim($value) === '') {
            throw new RuntimeException('Please specify your "Other" answer.', 422);
        }
        return $this->text($value, 500);
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

    private function staffRemarksAad(array $row): string
    {
        return $this->aad($row) . ':staff_remarks:' . (int) $row['id'];
    }

    private function decryptStaffRemarks(array $row): ?string
    {
        if (empty($row['staff_remarks_cipher']) || empty($row['staff_remarks_nonce']) || empty($row['staff_remarks_tag']) || empty($row['staff_remarks_key_version'])) {
            return null;
        }
        $plaintext = openssl_decrypt(
            $row['staff_remarks_cipher'],
            'aes-256-gcm',
            $this->key((int) $row['staff_remarks_key_version']),
            OPENSSL_RAW_DATA,
            $row['staff_remarks_nonce'],
            $row['staff_remarks_tag'],
            $this->staffRemarksAad($row)
        );
        if ($plaintext === false) {
            throw new RuntimeException('Staff interview remarks could not be authenticated.', 503);
        }
        return $plaintext;
    }

    private function audit(string $action, int $id, array $actor, ?int $response = null, array $metadata = []): void
    {
        (new AuditService())->log('guidance.survey.' . $action, 'surveys', (string) $id, $action,
            $response === null ? $metadata : ['response_id' => $response], (int) $actor['id'], $actor['role']);
    }

    private function transaction(callable $callback, ?NotificationInvalidation $invalidation = null, ?PushPublisher $push = null): mixed
    {
        $db = Database::connect();
        if ($db->transDepth !== 0) { throw new RuntimeException('Survey operations require an independent transaction.', 409); }
        $actor = EngagementAccess::actor();
        $db->resetTransStatus();
        if (($db->DBDriver === 'MySQLi' && !$db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')) || !$db->transBegin()) {
            throw new RuntimeException('Survey transaction could not begin.', 503);
        }
        $notifications = [];
        try {
            // Keep the tenant-first lock order shared by all audit writers, including BMG.
            (new AuditService(null, $db))->lockTenant((int) $actor['tenant_id']);
            $result = $callback($notifications);
            if (!$db->transStatus() || !$db->transCommit()) { throw new RuntimeException('Unable to save survey.', 503); }
        } catch (Throwable $e) {
            try { $db->transRollback(); }
            finally { $db->resetTransStatus(); }
            throw $e;
        }
        // IDs are local to this successful outer commit, never retained for a later operation.
        try {
            if ($notifications !== []) {
                $invalidation ??= new NotificationInvalidation($db);
                foreach ($notifications as $tenant => $ids) $invalidation->publishCommitted((int) $tenant, $ids);
            }
        } catch (Throwable) {
            // Optional realtime setup cannot fail or roll back a committed write.
        }
        // Push is independently best effort, even if realtime setup or publication failed.
        try {
            if ($notifications !== []) {
                $push ??= new PushPublisher($db);
                foreach ($notifications as $tenant => $ids) $push->publishCommitted((int) $tenant, $ids);
            }
        } catch (Throwable) {
            // A missed push cannot fail or roll back the committed survey and inbox rows.
        }
        return $result;
    }

    /** Check under the record lock, even for no-ops: old approval cannot approve a newer revision. */
    private function transitionVersion(array $row, mixed $version): void
    {
        if ((!is_string($version) && !is_int($version)) || !preg_match('/\A[1-9][0-9]*\z/D', (string) $version)
            || (string) $row['version'] !== (string) $version) {
            throw new RuntimeException('Survey changed. Reload before changing its state.', 409);
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
