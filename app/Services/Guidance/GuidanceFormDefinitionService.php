<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Models\GuidanceFormDefinitionModel;
use App\Models\GuidanceFormDefinitionRevisionModel;
use App\Services\CurrentTenant;
use InvalidArgumentException;
use RuntimeException;

/**
 * Manages controlled guidance form definitions and versioned schemas.
 * Authoritative schemas originate from reviewed manifest and database records.
 */
class GuidanceFormDefinitionService
{
    private GuidanceFormDefinitionModel $definitionModel;
    private GuidanceFormDefinitionRevisionModel $revisionModel;
    private string $manifestPath;

    public function __construct(
        ?GuidanceFormDefinitionModel $definitionModel = null,
        ?GuidanceFormDefinitionRevisionModel $revisionModel = null,
        ?string $manifestPath = null
    ) {
        $this->definitionModel = $definitionModel ?? new GuidanceFormDefinitionModel();
        $this->revisionModel = $revisionModel ?? new GuidanceFormDefinitionRevisionModel();
        $this->manifestPath = $manifestPath ?? ROOTPATH . 'docs/guidance/forms/manifest.json';
    }

    /**
     * Reads the reviewed manifest and synchronizes definitions and revisions for the tenant.
     *
     * @return array<string, int> Count of synchronized definitions and revisions
     */
    public function syncManifestToDatabase(?int $tenantId = null): array
    {
        $tid = $tenantId ?? CurrentTenant::id();
        if (!file_exists($this->manifestPath)) {
            throw new RuntimeException('Guidance forms manifest not found at ' . $this->manifestPath);
        }

        $manifestContent = file_get_contents($this->manifestPath);
        if ($manifestContent === false) {
            throw new RuntimeException('Unable to read guidance forms manifest.');
        }

        $data = json_decode($manifestContent, true, 512, JSON_THROW_ON_ERROR);
        $forms = $data['forms'] ?? [];
        if (!is_array($forms) || empty($forms)) {
            throw new RuntimeException('Guidance forms manifest contains no form definitions.');
        }

        $syncedDefinitions = 0;
        $syncedRevisions = 0;

        foreach ($forms as $form) {
            $internalKey = (string) $form['internal_key'];
            $formCode = (string) $form['form_code'];
            $title = (string) $form['canonical_title'];
            $owner = (string) ($form['owner'] ?? 'Office of the Guidance Center (OGC)');
            $revisionNumber = (string) ($form['revision'] ?? '00');
            $schemaVersion = (string) ($form['schema_version'] ?? '1.0.0');
            $rendererVersion = (string) ($form['renderer_version'] ?? '1.0.0');
            $effectivityDate = (string) ($form['effectivity_date'] ?? date('Y-m-d'));
            $approvalState = (string) ($form['approval_state'] ?? 'provisional_gated');

            $schemaJson = json_encode($form['fields'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $schemaHash = hash('sha256', $schemaJson);

            // Upsert definition
            $existingDef = $this->definitionModel->scoped($tid)
                ->where('internal_key', $internalKey)
                ->first();

            if ($existingDef === null) {
                $defId = (int) $this->definitionModel->insert([
                    'tenant_id' => $tid,
                    'internal_key' => $internalKey,
                    'form_code' => $formCode,
                    'canonical_title' => $title,
                    'owner' => $owner,
                    'is_active' => 1,
                ]);
                $syncedDefinitions++;
            } else {
                $defId = (int) $existingDef['id'];
                $this->definitionModel->update($defId, [
                    'form_code' => $formCode,
                    'canonical_title' => $title,
                    'owner' => $owner,
                    'is_active' => 1,
                ]);
            }

            // Check if revision exists
            $existingRev = $this->revisionModel->scoped($tid)
                ->where('form_definition_id', $defId)
                ->where('revision_number', $revisionNumber)
                ->first();

            if ($existingRev === null) {
                $this->revisionModel->insert([
                    'tenant_id' => $tid,
                    'form_definition_id' => $defId,
                    'revision_number' => $revisionNumber,
                    'schema_version' => $schemaVersion,
                    'renderer_version' => $rendererVersion,
                    'schema_json' => $schemaJson,
                    'schema_hash' => $schemaHash,
                    'effectivity_date' => $effectivityDate,
                    'approval_state' => $approvalState,
                ]);
                $syncedRevisions++;
            }
        }

        return [
            'definitions' => $syncedDefinitions,
            'revisions' => $syncedRevisions,
        ];
    }

    /**
     * Resolves an active definition and its latest revision by internal key or ID.
     *
     * @return array{definition: array<string, mixed>, revision: array<string, mixed>}
     */
    public function getActiveDefinition(string|int $keyOrId, ?int $tenantId = null): array
    {
        $tid = $tenantId ?? CurrentTenant::id();
        $builder = $this->definitionModel->scoped($tid);
        if (is_numeric($keyOrId)) {
            $builder->where('id', (int) $keyOrId);
        } else {
            $builder->where('internal_key', (string) $keyOrId);
        }
        $def = $builder->where('is_active', 1)->first();

        if ($def === null) {
            throw new InvalidArgumentException("Controlled guidance form definition not found: {$keyOrId}", 404);
        }

        $rev = $this->revisionModel->scoped($tid)
            ->where('form_definition_id', (int) $def['id'])
            ->orderBy('id', 'DESC')
            ->first();

        if ($rev === null) {
            throw new RuntimeException("No revision configured for form definition: {$keyOrId}", 500);
        }

        return [
            'definition' => $def,
            'revision' => $rev,
        ];
    }

    /**
     * Validates input fields against the canonical schema.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $data
     * @return array<string, string> Errors indexed by field name
     */
    public function validateFormData(array $schema, array $data): array
    {
        $errors = [];

        foreach ($schema as $fieldName => $rules) {
            $value = $data[$fieldName] ?? null;
            $isRequired = !empty($rules['required']);

            if ($isRequired && ($value === null || $value === '' || $value === [])) {
                $errors[$fieldName] = "Field {$fieldName} is required.";
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $type = $rules['type'] ?? 'string';
            switch ($type) {
                case 'string':
                    if (!is_string($value)) {
                        $errors[$fieldName] = "Field {$fieldName} must be a string.";
                    } elseif (isset($rules['max_length']) && mb_strlen($value) > $rules['max_length']) {
                        $errors[$fieldName] = "Field {$fieldName} exceeds max length of {$rules['max_length']}.";
                    }
                    break;

                case 'integer':
                    if (!is_int($value) && !ctype_digit((string) $value)) {
                        $errors[$fieldName] = "Field {$fieldName} must be an integer.";
                    } else {
                        $num = (int) $value;
                        if (isset($rules['minimum']) && $num < $rules['minimum']) {
                            $errors[$fieldName] = "Field {$fieldName} must be at least {$rules['minimum']}.";
                        }
                        if (isset($rules['maximum']) && $num > $rules['maximum']) {
                            $errors[$fieldName] = "Field {$fieldName} must not exceed {$rules['maximum']}.";
                        }
                    }
                    break;

                case 'boolean':
                    if (!is_bool($value) && $value !== 0 && $value !== 1 && $value !== '0' && $value !== '1') {
                        $errors[$fieldName] = "Field {$fieldName} must be a boolean.";
                    }
                    break;

                case 'enum':
                    $allowed = $rules['values'] ?? [];
                    if (!in_array($value, $allowed, true)) {
                        $errors[$fieldName] = "Field {$fieldName} must be one of: " . implode(', ', $allowed);
                    }
                    break;

                case 'array':
                    if (!is_array($value)) {
                        $errors[$fieldName] = "Field {$fieldName} must be an array.";
                    } elseif (!empty($rules['allowed'])) {
                        foreach ($value as $item) {
                            if (!in_array($item, $rules['allowed'], true)) {
                                $errors[$fieldName] = "Value {$item} is not permitted in {$fieldName}.";
                                break;
                            }
                        }
                    }
                    break;
            }
        }

        return $errors;
    }
}
