<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Services\Audit\AuditService;
use App\Services\EngagementAccess;
use Config\Database;
use RuntimeException;
use Throwable;

final class QuestionBankService
{
    public function actor(): array
    {
        $actor = EngagementAccess::actor('guidance.surveys.manage', 'guidance');
        if (!in_array($actor['role'], ['superadmin', 'guidance_admin', 'guidance_supervisor'], true)) {
            throw new RuntimeException('Guidance survey administrator access required.', 403);
        }
        return $actor;
    }

    private function query(array $actor)
    {
        return Database::connect()->table('survey_question_bank b')
            ->join('survey_question_bank_revisions r', 'r.tenant_id=b.tenant_id AND r.question_id=b.id AND r.revision=b.current_revision')
            ->where('b.tenant_id', $actor['tenant_id']);
    }

    public function listing(array $input, bool $catalog = false): array
    {
        $actor = $this->actor();
        $search = SurveyQuestion::text($input['q'] ?? '', 100, true);
        $category = SurveyQuestion::text($input['category'] ?? '', 80, true);
        $audience = $input['audience'] ?? '';
        $status = $catalog ? 'active' : ($input['status'] ?? 'active');
        if (!is_string($audience) || !in_array($audience, ['', 'all', 'students', 'employees'], true)
            || !is_string($status) || !in_array($status, ['active', 'archived'], true)) throw new RuntimeException('Invalid bank filter.', 422);
        $page = SurveyQuestion::positive($input['page'] ?? 1);
        $query = $this->query($actor)->where('b.status', $status);
        if ($category !== '') $query->where('r.category', $category);
        if ($audience !== '') $query->whereIn('r.audience', $audience === 'all' ? ['all'] : ['all', $audience]);
        if ($search !== '') {
            $escape = Database::connect()->likeEscapeChar;
            $searchTerm = strtr($search, [$escape => $escape . $escape, '%' => $escape . '%', '_' => $escape . '_']);
            $query->groupStart()->like('r.label', $searchTerm)->orLike('r.category', $searchTerm)->groupEnd();
        }
        $total = $query->countAllResults(false);
        $pages = max(1, (int) ceil($total / 20));
        $page = min($page, $pages);
        $items = $query->select('b.id,b.status,b.version,b.current_revision,r.category,r.audience,r.review_note,r.question_json,r.created_at')
            ->orderBy('b.id', 'DESC')->get(20, ($page - 1) * 20)->getResultArray();
        foreach ($items as &$item) {
            $item['id'] = (int) $item['id'];
            $item['revision'] = (int) $item['current_revision'];
            $item['question'] = json_decode($item['question_json'], true, 512, JSON_THROW_ON_ERROR);
            unset($item['question_json']);
        }
        unset($item);
        return compact('items', 'search', 'category', 'audience', 'status', 'page', 'pages', 'total');
    }

    public function get(int $id, mixed $revision = null): array
    {
        $actor = $this->actor();
        $db = Database::connect();
        $entry = $db->table('survey_question_bank')->where(['tenant_id' => $actor['tenant_id'], 'id' => $id])->get()->getRowArray();
        if ($entry === null) throw new RuntimeException('Question bank entry not found.', 404);
        $number = $revision === null ? (int) $entry['current_revision'] : SurveyQuestion::positive($revision);
        $row = $db->table('survey_question_bank_revisions')->where(['tenant_id' => $actor['tenant_id'], 'question_id' => $id, 'revision' => $number])->get()->getRowArray();
        if ($row === null) throw new RuntimeException('Question bank revision not found.', 404);
        $row['question'] = json_decode($row['question_json'], true, 512, JSON_THROW_ON_ERROR);
        unset($row['question_json']);
        return $entry + $row;
    }

    public function history(int $id): array
    {
        $entry = $this->get($id);
        $revisions = Database::connect()->table('survey_question_bank_revisions')->select('revision,created_at')
            ->where(['tenant_id' => $entry['tenant_id'], 'question_id' => $id])->orderBy('revision', 'DESC')->get()->getResultArray();
        return ['entry' => $entry, 'revisions' => $revisions];
    }

    public function create(array $input): int
    {
        $actor = $this->actor();
        $data = $this->validate($input);
        return $this->transaction(function () use ($actor, $data): int {
            $db = Database::connect();
            $this->must($db->table('survey_question_bank')->insert(['tenant_id' => $actor['tenant_id'], 'status' => 'active',
                'current_revision' => 1, 'version' => 1, 'created_by' => $actor['id'], 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]));
            $id = (int) $db->insertID();
            $this->revision($id, 1, $data, $actor);
            $this->audit('created', $id, 1, $actor);
            return $id;
        });
    }

    public function update(int $id, array $input): void
    {
        $actor = $this->actor();
        $version = SurveyQuestion::positive($input['version'] ?? null);
        unset($input['version']);
        $data = $this->validate($input);
        $this->transaction(function () use ($id, $actor, $version, $data): void {
            $entry = $this->get($id);
            if ($entry['status'] !== 'active' || (int) $entry['version'] !== $version) throw new RuntimeException('Bank entry changed or was archived. Reload before editing.', 409);
            $number = (int) $entry['current_revision'] + 1;
            $this->revision($id, $number, $data, $actor);
            $this->must(Database::connect()->table('survey_question_bank')->where(['tenant_id' => $actor['tenant_id'], 'id' => $id, 'version' => $version])
                ->update(['current_revision' => $number, 'version' => $version + 1, 'updated_at' => gmdate('Y-m-d H:i:s')]));
            $this->audit('revised', $id, $number, $actor);
        });
    }

    public function archive(int $id, mixed $version): void
    {
        $actor = $this->actor();
        $version = SurveyQuestion::positive($version);
        $this->transaction(function () use ($id, $actor, $version): void {
            $entry = $this->get($id);
            if ((int) $entry['version'] !== $version || $entry['status'] !== 'active') throw new RuntimeException('Bank entry changed or was already archived. Reload the list.', 409);
            $this->must(Database::connect()->table('survey_question_bank')->where(['tenant_id' => $actor['tenant_id'], 'id' => $id, 'version' => $version])
                ->update(['status' => 'archived', 'version' => $version + 1, 'updated_at' => gmdate('Y-m-d H:i:s')]));
            $this->audit('archived', $id, (int) $entry['current_revision'], $actor);
        });
    }

    /** Run inside the survey transaction. Never use client-supplied source IDs as proof. */
    public function verifyCopies(array $questions, array $existing = []): array
    {
        $this->actor();
        foreach ($questions as $question) {
            if (!isset($question['bank_id'])) continue;
            $entry = $this->get($question['bank_id'], $question['bank_revision']);
            $retained = false;
            foreach ($existing as $key => $saved) {
                if ($saved === $question) {
                    $retained = true;
                    unset($existing[$key]);
                    break;
                }
            }
            if ($entry['status'] !== 'active' && !$retained) throw new RuntimeException('An archived bank question cannot be added to a new survey. Choose another question or customize a copy.', 409);
            $content = array_diff_key($question, array_flip(['bank_id', 'bank_revision']));
            if ($content !== $entry['question']) throw new RuntimeException('The bank copy was edited. Customize the question to detach its bank reference before saving.', 422);
        }
        return $questions;
    }

    private function validate(array $input): array
    {
        if (array_diff(array_keys($input), ['question', 'category', 'audience', 'review_note']) !== []) throw new RuntimeException('Unexpected bank fields.', 422);
        $audience = $input['audience'] ?? null;
        if (!is_string($audience) || !in_array($audience, ['all', 'students', 'employees'], true)) throw new RuntimeException('Choose a valid intended audience.', 422);
        $question = SurveyQuestion::normalize($input['question'] ?? null, false);
        return ['category' => SurveyQuestion::text($input['category'] ?? null, 80), 'audience' => $audience,
            'review_note' => SurveyQuestion::text($input['review_note'] ?? '', 1000, true),
            'label' => $question['label'], 'question_json' => json_encode($question, JSON_THROW_ON_ERROR)];
    }

    private function revision(int $id, int $number, array $data, array $actor): void
    {
        $this->must(Database::connect()->table('survey_question_bank_revisions')->insert($data + [
            'tenant_id' => $actor['tenant_id'], 'question_id' => $id, 'revision' => $number,
            'created_by' => $actor['id'], 'created_at' => gmdate('Y-m-d H:i:s')]));
    }

    private function audit(string $action, int $id, int $revision, array $actor): void
    {
        (new AuditService())->log('guidance.question_bank.' . $action, 'question_bank', (string) $id, $action,
            ['revision' => $revision], $actor['id'], $actor['role']);
    }

    private function transaction(callable $operation): mixed
    {
        $actor = $this->actor();
        $db = Database::connect();
        if ($db->transDepth !== 0) throw new RuntimeException('Bank changes require an independent transaction.', 409);
        $db->resetTransStatus();
        if ($db->DBDriver === 'MySQLi') $this->must($db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') !== false);
        $this->must($db->transBegin());
        try {
            (new AuditService(null, $db))->lockTenant($actor['tenant_id']);
            $this->actor();
            $result = $operation();
            $this->must($db->transStatus() && $db->transCommit());
            return $result;
        } catch (Throwable $e) {
            $db->transRollback();
            $db->resetTransStatus();
            throw $e;
        }
    }

    private function must(bool $success): void
    {
        if (!$success) throw new RuntimeException('Unable to save the bank entry.', 503);
    }
}
