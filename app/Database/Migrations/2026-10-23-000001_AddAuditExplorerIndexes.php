<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Reader-only indexes: never rewrites ledger rows or changes append-only triggers. */
final class AddAuditExplorerIndexes extends Migration
{
    private const INDEXES = [
        'idx_audit_tenant_id' => ['tenant_id', 'id'],
        'idx_audit_tenant_event_id' => ['tenant_id', 'event_type', 'id'],
        'idx_audit_tenant_actor_id' => ['tenant_id', 'actor_user_id', 'id'],
    ];

    private function existing(): array
    {
        $indexes = $this->db->getIndexData('audit_events');
        foreach (self::INDEXES as $name => $columns) {
            if (isset($indexes[$name]) && ($indexes[$name]->fields !== $columns
                || strtoupper($indexes[$name]->type) !== 'INDEX')) {
                throw new RuntimeException('Unexpected audit reader index definition: ' . $name);
            }
        }
        return $indexes;
    }

    public function up(): void
    {
        $existing = $this->existing();
        foreach (self::INDEXES as $name => $columns) {
            if (!isset($existing[$name])) {
                $this->forge->addKey($columns, false, false, $name);
            }
        }
        if (count(array_intersect_key($existing, self::INDEXES)) !== count(self::INDEXES)
            && !$this->forge->processIndexes('audit_events')) {
            throw new RuntimeException('Unable to install audit reader indexes. Review the schema before retrying.');
        }
    }

    public function down(): void
    {
        $existing = $this->existing();
        foreach (self::INDEXES as $name => $columns) {
            if (isset($existing[$name]) && !$this->forge->dropKey('audit_events', $name, false)) {
                throw new RuntimeException('Unable to remove audit reader index: ' . $name);
            }
        }
    }
}
