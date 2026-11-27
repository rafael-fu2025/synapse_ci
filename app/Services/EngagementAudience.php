<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseBuilder;
use Config\Database;
use RuntimeException;

/** Audience membership comes from current same-tenant profiles, never operational roles. */
final class EngagementAudience
{
    public static function validate(mixed $audience): string
    {
        if (!is_string($audience) || !in_array($audience, ['all', 'students', 'employees'], true)) {
            throw new RuntimeException('Choose all, students, or employees as the audience.', 422);
        }
        return $audience;
    }

    public static function audiences(array $actor): array
    {
        if (empty($actor['person_id']) || empty($actor['tenant_id'])) { return []; }
        $db = Database::connect();
        $person = $db->table('people')->where('tenant_id', $actor['tenant_id'])->where('id', $actor['person_id'])
            ->where('deleted_at', null)->get()->getRowArray();
        if ($person === null) { return []; }
        $audiences = [];
        foreach (['students' => 'student_profiles', 'employees' => 'employee_profiles'] as $audience => $table) {
            if ($db->table($table)->where('tenant_id', $actor['tenant_id'])->where('person_id', $actor['person_id'])->countAllResults() > 0) {
                $audiences[] = $audience;
            }
        }
        return $audiences === [] ? [] : ['all', ...$audiences];
    }

    /** Normalize form filters; unrestricted groups are stored as NULL, never guessed. */
    public static function targeting(array $input, string $audience): array
    {
        self::validate($audience);
        $out = [];
        foreach (['target_year_levels' => range(1, 6), 'target_student_categories' => ['new', 'continuing', 'transferee', 'graduating']] as $field => $allowed) {
            $values = $input[$field] ?? [];
            if (!is_array($values) || !array_is_list($values) || count($values) > count($allowed)) {
                throw new RuntimeException('Choose valid student targeting options.', 422);
            }
            $normalized = [];
            foreach ($values as $value) {
                if ($field === 'target_year_levels' && (is_int($value) || is_string($value))
                    && preg_match('/\A[1-6]\z/D', (string) $value)) { $value = (int) $value; }
                if (!in_array($value, $allowed, true) || in_array($value, $normalized, true)) {
                    throw new RuntimeException('Choose distinct, valid student targeting options.', 422);
                }
                $normalized[] = $value;
            }
            if ($audience !== 'students' && $normalized !== []) {
                throw new RuntimeException('Year levels and student categories require the Students audience.', 422);
            }
            // Stable order makes edit/retry behavior independent of checkbox order.
            $normalized = array_values(array_filter($allowed, static fn ($value) => in_array($value, $normalized, true)));
            $out[$field] = $normalized === [] ? null : json_encode($normalized, JSON_THROW_ON_ERROR);
        }
        return $out;
    }

    public static function eligible(array $actor, string $audience, array $target = []): bool
    {
        if (!in_array($audience, self::audiences($actor), true)) { return false; }
        $filters = self::storedTargets($target, $audience);
        if ($audience !== 'students') { return true; }
        $profile = self::student($actor);
        if ($profile === null) { return false; }
        if ($filters['target_year_levels'] !== [] && !in_array((int) $profile['year_level'], $filters['target_year_levels'], true)) { return false; }
        $categories = self::categories($profile);
        return $filters['target_student_categories'] === [] || array_intersect($categories, $filters['target_student_categories']) !== [];
    }

    private static function student(array $actor): ?array
    {
        if (empty($actor['person_id']) || empty($actor['tenant_id'])) { return null; }
        return Database::connect()->table('student_profiles')->where('tenant_id', $actor['tenant_id'])
            ->where('person_id', $actor['person_id'])->get()->getRowArray();
    }

    private static function categories(array $profile): array
    {
        $categories = in_array($profile['enrollment_type'] ?? null, ['new', 'continuing', 'transferee'], true) ? [$profile['enrollment_type']] : [];
        if ((int) ($profile['is_graduating'] ?? 0) === 1) { $categories[] = 'graduating'; }
        return $categories;
    }

    private static function storedTargets(array $row, string $audience): array
    {
        $child = self::loadChildTargetValues($row);
        $fromChild = $child !== null;
        $input = $fromChild ? $child : self::decodeRowTargetValues($row);
        try { $normalized = self::targeting($input, $audience); }
        catch (RuntimeException $e) { throw new RuntimeException('Invalid stored student targeting.', 503, $e); }
        if (! $fromChild) {
            foreach ($normalized as $field => $canonical) {
                if (($row[$field] ?? null) !== $canonical) {
                    throw new RuntimeException('Noncanonical stored student targeting.', 503);
                }
            }
        }
        return array_map(static fn ($value) => $value === null ? [] : json_decode($value, true, 512, JSON_THROW_ON_ERROR), $normalized);
    }

    /**
     * Synchronize normalized 1NF student targeting child rows for an announcement or survey.
     *
     * @param array<string, mixed> $storedData
     */
    public static function syncTargets(\CodeIgniter\Database\BaseConnection $db, string $parentTable, int $tenantId, int $recordId, array $storedData): void
    {
        $map = [
            'announcements' => ['announcement_id', 'announcement_target_year_levels', 'announcement_target_categories'],
            'surveys'       => ['survey_id', 'survey_target_year_levels', 'survey_target_categories'],
        ];
        if (! isset($map[$parentTable]) || $tenantId <= 0 || $recordId <= 0) {
            return;
        }
        [$fkColumn, $yearTable, $categoryTable] = $map[$parentTable];

        if ($db->tableExists($yearTable)) {
            $db->table($yearTable)->where('tenant_id', $tenantId)->where($fkColumn, $recordId)->delete();
            $years = json_decode((string) ($storedData['target_year_levels'] ?? '[]'), true);
            if (is_array($years) && $years !== []) {
                $batch = [];
                foreach ($years as $yr) {
                    $batch[] = ['tenant_id' => $tenantId, $fkColumn => $recordId, 'year_level' => (int) $yr];
                }
                $db->table($yearTable)->insertBatch($batch);
            }
        }

        if ($db->tableExists($categoryTable)) {
            $db->table($categoryTable)->where('tenant_id', $tenantId)->where($fkColumn, $recordId)->delete();
            $cats = json_decode((string) ($storedData['target_student_categories'] ?? '[]'), true);
            if (is_array($cats) && $cats !== []) {
                $batch = [];
                foreach ($cats as $cat) {
                    $batch[] = ['tenant_id' => $tenantId, $fkColumn => $recordId, 'student_category' => (string) $cat];
                }
                $db->table($categoryTable)->insertBatch($batch);
            }
        }
    }

    /** @return array{target_year_levels: list<int>, target_student_categories: list<string>}|null */
    private static function loadChildTargetValues(array $row): ?array
    {
        if (empty($row['id']) || empty($row['tenant_id'])) {
            return null;
        }
        $db = Database::connect();
        $tenantId = (int) $row['tenant_id'];
        $recordId = (int) $row['id'];
        if (array_key_exists('unit', $row)) {
            $fkColumn = 'announcement_id';
            $yearTable = 'announcement_target_year_levels';
            $catTable = 'announcement_target_categories';
        } elseif (array_key_exists('questions_json', $row) || array_key_exists('survey_mode', $row) || array_key_exists('closed_at', $row)) {
            $fkColumn = 'survey_id';
            $yearTable = 'survey_target_year_levels';
            $catTable = 'survey_target_categories';
        } else {
            return null;
        }

        if (! $db->tableExists($yearTable) || ! $db->tableExists($catTable)) {
            return null;
        }

        $years = array_values(array_map(
            static fn (array $r): int => (int) $r['year_level'],
            $db->table($yearTable)->select('year_level')->where('tenant_id', $tenantId)->where($fkColumn, $recordId)->orderBy('year_level', 'ASC')->get()->getResultArray()
        ));
        $cats = array_values(array_map(
            static fn (array $r): string => (string) $r['student_category'],
            $db->table($catTable)->select('student_category')->where('tenant_id', $tenantId)->where($fkColumn, $recordId)->get()->getResultArray()
        ));

        if ($years === [] && $cats === []) {
            return null;
        }

        return ['target_year_levels' => $years, 'target_student_categories' => $cats];
    }

    /** @return array{target_year_levels: list<mixed>, target_student_categories: list<mixed>} */
    private static function decodeRowTargetValues(array $row): array
    {
        $input = [];
        foreach (['target_year_levels', 'target_student_categories'] as $field) {
            $value = $row[$field] ?? null;
            if ($value === null) { $input[$field] = []; continue; }
            if (!is_string($value)) { throw new RuntimeException('Invalid stored student targeting.', 503); }
            try { $input[$field] = json_decode($value, false, 512, JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { throw new RuntimeException('Invalid stored student targeting.', 503, $e); }
            if (!is_array($input[$field]) || !array_is_list($input[$field])) { throw new RuntimeException('Invalid stored student targeting.', 503); }
        }
        return $input;
    }

    /** Apply the same predicate to joined student profiles when publication fans out. */
    public static function recipients(BaseBuilder $builder, array $row, string $alias = 'sp'): BaseBuilder
    {
        if (!preg_match('/\A[a-z][a-z0-9_]*\z/D', $alias)) { throw new RuntimeException('Invalid student scope.'); }
        $filters = self::storedTargets($row, $row['audience']);
        if ($row['audience'] !== 'students') { return $builder; }
        if ($filters['target_year_levels'] !== []) { $builder->whereIn($alias . '.year_level', $filters['target_year_levels']); }
        $categories = $filters['target_student_categories'];
        if ($categories !== []) {
            $builder->groupStart();
            $types = array_values(array_intersect(['new', 'continuing', 'transferee'], $categories));
            if ($types !== []) { $builder->whereIn($alias . '.enrollment_type', $types); }
            if (in_array('graduating', $categories, true)) {
                $types === [] ? $builder->where($alias . '.is_graduating', 1) : $builder->orWhere($alias . '.is_graduating', 1);
            }
            $builder->groupEnd();
        }
        return $builder;
    }

    public static function apply(BaseBuilder $builder, array $actor, string $alias = ''): BaseBuilder
    {
        if ($alias !== '' && !preg_match('/\A[a-z][a-z0-9_]*\z/D', $alias)) { throw new RuntimeException('Invalid audience scope.'); }
        $audiences = self::audiences($actor);
        if ($audiences === []) { return $builder->where('1=0', null, false); }
        $prefix = $alias === '' ? '' : $alias . '.';
        $builder->whereIn($prefix . 'audience', $audiences);
        $profile = self::student($actor) ?? [];
        $db = Database::connect();
        foreach (['target_year_levels' => isset($profile['year_level']) ? [(int) $profile['year_level']] : [],
            'target_student_categories' => self::categories($profile)] as $field => $matches) {
            $column = $db->protectIdentifiers($prefix . $field);
            // The bounded option set lets SQL admit only the exact same canonical
            // representations as direct access/fanout, including on MariaDB 10.4.
            $allowed = $field === 'target_year_levels' ? range(1, 6) : ['new', 'continuing', 'transferee', 'graduating'];
            $selections = [];
            for ($mask = 1; $mask < (1 << count($allowed)); $mask++) {
                $selection = [];
                foreach ($allowed as $index => $value) { if ($mask & (1 << $index)) { $selection[] = $value; } }
                if (array_intersect($selection, $matches) !== []) { $selections[] = $db->escape(json_encode($selection, JSON_THROW_ON_ERROR)); }
            }
            $predicate = $selections === [] ? '0=1' : 'BINARY ' . $column . ' IN (' . implode(',', $selections) . ')';
            $builder->groupStart()->where($prefix . $field, null)
                ->orGroupStart()->where($prefix . 'audience', 'students')->where($predicate, null, false)->groupEnd()->groupEnd();
        }
        return $builder;
    }
}
