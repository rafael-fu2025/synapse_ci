<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Run during a maintenance window: MySQL DDL is not transactionally reversible. */
final class AddClinicInventoryLedger extends Migration
{
    private function table(string $name): string
    {
        return $this->db->protectIdentifiers($this->db->prefixTable($name));
    }

    private function sql(string $sql): void
    {
        $sql = preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => $this->table($m[1]), $sql);
        if ($this->db->query($sql) === false) {
            throw new RuntimeException('Inventory migration SQL failed: ' . $this->db->error()['message']);
        }
    }

    /** Database-free preflight; package suffixes must never supply the server version. */
    public static function assertSupportedServerVersion(string $version): void
    {
        $maria = stripos($version, 'MariaDB') !== false;
        // Some MariaDB handshakes retain MySQL's 5.5.5 compatibility prefix.
        $server = $maria && str_starts_with($version, '5.5.5-') ? substr($version, 6) : $version;
        if (!preg_match('/\A([0-9]+\.[0-9]+\.[0-9]+)(?=\z|[-+~\s])/', $server, $matches)) {
            throw new RuntimeException('Inventory ledger cannot parse server version: ' . $version);
        }
        if (version_compare($matches[1], $maria ? '10.2.1' : '8.0.16', '<')) {
            throw new RuntimeException('Inventory ledger needs enforced CHECK constraints; unsupported server: ' . $version);
        }
    }

    public function up(): void
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            throw new RuntimeException('Inventory ledger requires MySQL 8.0.16+ or MariaDB 10.2.1+ (InnoDB and enforced CHECK constraints).');
        }
        $version = (string) $this->db->query('SELECT VERSION() AS v')->getRow()->v;
        self::assertSupportedServerVersion($version);
        foreach (['clinic_medicines', 'clinic_medicine_batches', 'clinic_dispensations', 'clinic_encounters', 'users', 'people'] as $table) {
            $row = $this->db->query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$this->db->prefixTable($table)])->getRowArray();
            if (strtoupper($row['ENGINE'] ?? '') !== 'INNODB') {
                throw new RuntimeException('Inventory migration requires InnoDB: ' . $table);
            }
        }
        $checks = [
            'duplicate lot numbers' => 'SELECT MIN(id) AS id FROM {clinic_medicine_batches} GROUP BY tenant_id, medicine_id, batch_number HAVING COUNT(*) > 1',
            'invalid medicine tenant' => 'SELECT m.id FROM {clinic_medicines} m LEFT JOIN {tenants} t ON t.id=m.tenant_id WHERE t.id IS NULL',
            'invalid batch medicine/tenant' => 'SELECT b.id FROM {clinic_medicine_batches} b LEFT JOIN {clinic_medicines} m ON m.id=b.medicine_id AND m.tenant_id=b.tenant_id WHERE m.id IS NULL',
            'invalid batch quantities or dates' => "SELECT id FROM {clinic_medicine_batches} WHERE initial_quantity <= 0 OR remaining_quantity < 0 OR remaining_quantity > initial_quantity OR initial_quantity > 4294967295 OR expiration_date IS NULL OR expiration_date < '1000-01-01' OR TRIM(batch_number) = ''",
            'invalid encounter patient/actor tenant' => 'SELECT e.id FROM {clinic_encounters} e LEFT JOIN {people} p ON p.id=e.person_id AND p.tenant_id=e.tenant_id LEFT JOIN {users} u ON u.id=e.attending_user_id AND u.tenant_id=e.tenant_id WHERE p.id IS NULL OR u.id IS NULL',
            'invalid allocation tenant, encounter, batch, medicine or actor' => 'SELECT d.id FROM {clinic_dispensations} d LEFT JOIN {clinic_encounters} e ON e.id=d.encounter_id AND e.tenant_id=d.tenant_id LEFT JOIN {clinic_medicine_batches} b ON b.id=d.batch_id AND b.tenant_id=d.tenant_id AND b.medicine_id=d.medicine_id LEFT JOIN {users} u ON u.id=d.dispensed_by_user_id AND u.tenant_id=d.tenant_id WHERE e.id IS NULL OR b.id IS NULL OR u.id IS NULL OR d.quantity <= 0',
            'medicine stock cache differs from physical batch total' => 'SELECT m.id FROM {clinic_medicines} m LEFT JOIN {clinic_medicine_batches} b ON b.medicine_id=m.id AND b.tenant_id=m.tenant_id GROUP BY m.id, m.total_stock HAVING m.total_stock <> COALESCE(SUM(b.remaining_quantity),0) OR COALESCE(SUM(b.remaining_quantity),0) > 4294967295',
        ];
        foreach ($checks as $label => $query) {
            $query = preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => $this->table($m[1]), $query);
            $row = $this->db->query($query . ' LIMIT 1')->getRowArray();
            if ($row !== null) {
                throw new RuntimeException('Inventory preflight STOP: ' . $label . '; offending id ' . $row['id'] . '. No automatic repairs performed.');
            }
        }

        $this->sql('ALTER TABLE {clinic_medicines} ADD is_active TINYINT UNSIGNED NOT NULL DEFAULT 1, ADD version INT UNSIGNED NOT NULL DEFAULT 1, ADD UNIQUE KEY inv_medicine_tenant (tenant_id,id), ADD CONSTRAINT inv_medicine_check CHECK (is_active IN (0,1) AND version > 0)');
        $this->sql("ALTER TABLE {clinic_medicine_batches} ADD status ENUM('active','quarantined') NOT NULL DEFAULT 'active', ADD UNIQUE KEY inv_lot (tenant_id,medicine_id,batch_number), ADD UNIQUE KEY inv_batch_identity (tenant_id,medicine_id,id), ADD CONSTRAINT inv_batch_quantity CHECK (initial_quantity > 0 AND remaining_quantity <= initial_quantity)");
        $this->sql('ALTER TABLE {users} ADD UNIQUE KEY inv_user_tenant (tenant_id,id)');
        $this->sql('ALTER TABLE {people} ADD UNIQUE KEY inv_person_tenant (tenant_id,id)');
        $this->sql('ALTER TABLE {clinic_encounters} ADD UNIQUE KEY inv_encounter_tenant (tenant_id,id)');
        $this->replaceForeignKey('clinic_medicine_batches', 'medicine_id');
        $this->replaceForeignKey('clinic_encounters', 'person_id');
        $this->replaceForeignKey('clinic_dispensations', 'encounter_id');
        $this->sql('ALTER TABLE {clinic_medicine_batches} ADD CONSTRAINT inv_batch_medicine FOREIGN KEY (tenant_id,medicine_id) REFERENCES {clinic_medicines}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT');
        $this->sql('ALTER TABLE {clinic_encounters} ADD CONSTRAINT inv_enc_person FOREIGN KEY (tenant_id,person_id) REFERENCES {people}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT, ADD CONSTRAINT inv_enc_actor FOREIGN KEY (tenant_id,attending_user_id) REFERENCES {users}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT');

        $this->sql("CREATE TABLE {inventory_operations} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL, medicine_id INT UNSIGNED NOT NULL,
            encounter_id BIGINT UNSIGNED NULL, actor_id BIGINT UNSIGNED NOT NULL,
            idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            type ENUM('receipt','dispense','adjustment','quarantine','reversal','catalog') NOT NULL,
            reason VARCHAR(1000) NOT NULL, supplier VARCHAR(150) NULL, reference VARCHAR(150) NULL,
            reversal_of BIGINT UNSIGNED NULL, reversal_mode ENUM('record_only','restore_unissued') NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY inv_key (tenant_id,idempotency_key), UNIQUE KEY inv_reversal (reversal_of),
            UNIQUE KEY inv_operation_identity (tenant_id,medicine_id,id),
            KEY inv_operation_encounter (tenant_id,encounter_id),
            CONSTRAINT inv_op_medicine FOREIGN KEY (tenant_id,medicine_id) REFERENCES {clinic_medicines}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT inv_op_actor FOREIGN KEY (tenant_id,actor_id) REFERENCES {users}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT inv_op_encounter FOREIGN KEY (tenant_id,encounter_id) REFERENCES {clinic_encounters}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT inv_op_original FOREIGN KEY (tenant_id,medicine_id,reversal_of) REFERENCES {inventory_operations}(tenant_id,medicine_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT inv_op_correction CHECK ((type='reversal' AND reversal_of IS NOT NULL AND reversal_mode IS NOT NULL) OR (type<>'reversal' AND reversal_of IS NULL AND reversal_mode IS NULL))
        ) ENGINE=InnoDB");
        $this->sql("CREATE TABLE {stock_movements} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL, medicine_id INT UNSIGNED NOT NULL, batch_id BIGINT UNSIGNED NOT NULL,
            operation_id BIGINT UNSIGNED NULL, actor_id BIGINT UNSIGNED NULL,
            delta BIGINT NOT NULL, reason VARCHAR(1000) NOT NULL,
            original_movement_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL,
            UNIQUE KEY inv_movement_identity (tenant_id,medicine_id,batch_id,id),
            KEY inv_movement_history (tenant_id,medicine_id,id),
            CONSTRAINT inv_move_batch FOREIGN KEY (tenant_id,medicine_id,batch_id) REFERENCES {clinic_medicine_batches}(tenant_id,medicine_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT inv_move_operation FOREIGN KEY (tenant_id,medicine_id,operation_id) REFERENCES {inventory_operations}(tenant_id,medicine_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT inv_move_actor FOREIGN KEY (tenant_id,actor_id) REFERENCES {users}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT inv_move_original FOREIGN KEY (tenant_id,medicine_id,batch_id,original_movement_id) REFERENCES {stock_movements}(tenant_id,medicine_id,batch_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT inv_move_actor_required CHECK ((operation_id IS NULL AND actor_id IS NULL AND reason='legacy_cutover_opening_balance' AND delta>=0 AND original_movement_id IS NULL) OR (operation_id IS NOT NULL AND actor_id IS NOT NULL))
        ) ENGINE=InnoDB");
        $this->sql('ALTER TABLE {clinic_dispensations}
            ADD operation_id BIGINT UNSIGNED NULL,
            ADD snapshot_name VARCHAR(150) NULL, ADD snapshot_generic_name VARCHAR(150) NULL,
            ADD snapshot_strength VARCHAR(50) NULL, ADD snapshot_dosage_form VARCHAR(50) NULL,
            ADD snapshot_unit VARCHAR(20) NULL, ADD snapshot_batch_number VARCHAR(50) NULL,
            ADD snapshot_expiration_date DATE NULL,
            ADD snapshot_reconstructed TINYINT UNSIGNED NOT NULL DEFAULT 0,
            ADD CONSTRAINT inv_disp_quantity CHECK (quantity > 0),
            ADD CONSTRAINT inv_disp_encounter FOREIGN KEY (tenant_id,encounter_id) REFERENCES {clinic_encounters}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            ADD CONSTRAINT inv_disp_batch FOREIGN KEY (tenant_id,medicine_id,batch_id) REFERENCES {clinic_medicine_batches}(tenant_id,medicine_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            ADD CONSTRAINT inv_disp_actor FOREIGN KEY (tenant_id,dispensed_by_user_id) REFERENCES {users}(tenant_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
            ADD CONSTRAINT inv_disp_operation FOREIGN KEY (tenant_id,medicine_id,operation_id) REFERENCES {inventory_operations}(tenant_id,medicine_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT');
        $this->sql('UPDATE {clinic_dispensations} d JOIN {clinic_medicines} m ON m.id=d.medicine_id JOIN {clinic_medicine_batches} b ON b.id=d.batch_id SET d.snapshot_name=m.name,d.snapshot_generic_name=m.generic_name,d.snapshot_strength=m.strength,d.snapshot_dosage_form=m.dosage_form,d.snapshot_unit=m.unit_of_measure,d.snapshot_batch_number=b.batch_number,d.snapshot_expiration_date=b.expiration_date,d.snapshot_reconstructed=1');
        // This is remaining stock at cutover, never a fabricated historical receipt.
        $this->sql("INSERT INTO {stock_movements} (tenant_id,medicine_id,batch_id,delta,reason,created_at) SELECT tenant_id,medicine_id,id,remaining_quantity,'legacy_cutover_opening_balance',NOW() FROM {clinic_medicine_batches}");
        foreach (['inventory_operations', 'stock_movements', 'clinic_dispensations'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $verb) {
                $trigger = $this->db->protectIdentifiers($this->db->prefixTable('inv_' . $table . '_' . strtolower($verb)));
                $this->sql("CREATE TRIGGER {$trigger} BEFORE {$verb} ON {{$table}} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Inventory history is append-only; use a correction operation'");
            }
        }
    }

    private function dropCheck(string $table, string $constraint): void
    {
        $maria = stripos((string) $this->db->query('SELECT VERSION() AS v')->getRow()->v, 'MariaDB') !== false;
        $this->sql('ALTER TABLE {' . $table . '} DROP ' . ($maria ? 'CONSTRAINT ' : 'CHECK ') . $constraint);
    }

    private function replaceForeignKey(string $table, string $column): void
    {
        foreach ($this->db->getForeignKeyData($table) as $fk) {
            if ($fk->column_name === $column || $fk->column_name === [$column]) {
                $this->forge->dropForeignKey($table, $fk->constraint_name);
            }
        }
    }

    public function down(): void
    {
        // Even opening balances and reconstructed allocations are historical data.
        foreach (['inventory_operations', 'stock_movements', 'clinic_dispensations'] as $table) {
            if ($this->db->tableExists($table) && $this->db->table($table)->countAllResults() > 0) {
                throw new RuntimeException('Refusing inventory downgrade: live inventory history exists. Restore a verified pre-cutover backup instead.');
            }
        }
        foreach (['inventory_operations', 'stock_movements', 'clinic_dispensations'] as $table) {
            foreach (['update', 'delete'] as $verb) {
                $this->db->query('DROP TRIGGER IF EXISTS ' . $this->db->protectIdentifiers($this->db->prefixTable('inv_' . $table . '_' . $verb)));
            }
        }
        foreach (['inv_disp_encounter', 'inv_disp_batch', 'inv_disp_actor', 'inv_disp_operation'] as $fk) {
            $this->forge->dropForeignKey('clinic_dispensations', $fk);
        }
        $this->dropCheck('clinic_dispensations', 'inv_disp_quantity');
        $this->forge->dropColumn('clinic_dispensations', ['operation_id','snapshot_name','snapshot_generic_name','snapshot_strength','snapshot_dosage_form','snapshot_unit','snapshot_batch_number','snapshot_expiration_date','snapshot_reconstructed']);
        $this->forge->dropTable('stock_movements');
        $this->forge->dropTable('inventory_operations');
        $this->forge->dropForeignKey('clinic_medicine_batches', 'inv_batch_medicine');
        $this->forge->dropForeignKey('clinic_encounters', 'inv_enc_person');
        $this->forge->dropForeignKey('clinic_encounters', 'inv_enc_actor');
        $this->dropCheck('clinic_medicine_batches', 'inv_batch_quantity');
        $this->sql('ALTER TABLE {clinic_medicine_batches} DROP INDEX inv_lot, DROP INDEX inv_batch_identity');
        $this->forge->dropColumn('clinic_medicine_batches', 'status');
        $this->dropCheck('clinic_medicines', 'inv_medicine_check');
        $this->sql('ALTER TABLE {clinic_medicines} DROP INDEX inv_medicine_tenant');
        $this->forge->dropColumn('clinic_medicines', ['is_active','version']);
        foreach (['users' => 'inv_user_tenant', 'people' => 'inv_person_tenant', 'clinic_encounters' => 'inv_encounter_tenant'] as $table => $index) {
            $this->sql("ALTER TABLE {{$table}} DROP INDEX {$index}");
        }
        $this->sql('ALTER TABLE {clinic_medicine_batches} ADD FOREIGN KEY (medicine_id) REFERENCES {clinic_medicines}(id) ON DELETE CASCADE ON UPDATE CASCADE');
        $this->sql('ALTER TABLE {clinic_encounters} ADD FOREIGN KEY (person_id) REFERENCES {people}(id) ON DELETE CASCADE ON UPDATE CASCADE');
        $this->sql('ALTER TABLE {clinic_dispensations} ADD FOREIGN KEY (encounter_id) REFERENCES {clinic_encounters}(id) ON DELETE CASCADE ON UPDATE CASCADE');
    }
}
