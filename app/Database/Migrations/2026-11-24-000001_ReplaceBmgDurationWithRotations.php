<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Replace duration_seconds with integer rotation counts across BMG rotation tables. */
final class ReplaceBmgDurationWithRotations extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('bmg_rotation_schedules')) {
            if (!$this->db->fieldExists('rotations', 'bmg_rotation_schedules')) {
                $this->forge->addColumn('bmg_rotation_schedules', [
                    'rotations' => ['type' => 'INT', 'unsigned' => true, 'default' => 7],
                ]);
            }
            if ($this->db->fieldExists('duration_seconds', 'bmg_rotation_schedules')) {
                foreach ($this->db->table('bmg_rotation_schedules')->select('id, duration_seconds')->get()->getResultArray() as $row) {
                    $sec = $row['duration_seconds'];
                    $rot = ($sec === null || (int) $sec < 1) ? 7 : min(100, max(1, (int) round((int) $sec / 6)));
                    $this->db->table('bmg_rotation_schedules')->where('id', $row['id'])->update(['rotations' => $rot]);
                }
                $this->forge->dropColumn('bmg_rotation_schedules', 'duration_seconds');
            }
        }

        if ($this->db->tableExists('bmg_rotation_commands')) {
            if (!$this->db->fieldExists('rotations', 'bmg_rotation_commands')) {
                $this->forge->addColumn('bmg_rotation_commands', [
                    'rotations' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                ]);
            }
            if ($this->db->fieldExists('duration_seconds', 'bmg_rotation_commands')) {
                foreach ($this->db->table('bmg_rotation_commands')->select('id, duration_seconds')->get()->getResultArray() as $row) {
                    $sec = $row['duration_seconds'];
                    $rot = $sec === null ? null : min(100, max(1, (int) round((int) $sec / 6)));
                    $this->db->table('bmg_rotation_commands')->where('id', $row['id'])->update(['rotations' => $rot]);
                }
                $this->forge->dropColumn('bmg_rotation_commands', 'duration_seconds');
            }
        }

        if ($this->db->tableExists('bmg_rotation_runs')) {
            if (!$this->db->fieldExists('rotations', 'bmg_rotation_runs')) {
                $this->forge->addColumn('bmg_rotation_runs', [
                    'rotations' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                ]);
            }
            if ($this->db->fieldExists('duration_seconds', 'bmg_rotation_runs')) {
                foreach ($this->db->table('bmg_rotation_runs')->select('id, duration_seconds')->get()->getResultArray() as $row) {
                    $rot = min(1000, max(0, (int) ($row['duration_seconds'] ?? 0)));
                    $this->db->table('bmg_rotation_runs')->where('id', $row['id'])->update(['rotations' => $rot]);
                }
                $this->forge->dropColumn('bmg_rotation_runs', 'duration_seconds');
            }
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('bmg_rotation_schedules')) {
            if (!$this->db->fieldExists('duration_seconds', 'bmg_rotation_schedules')) {
                $this->forge->addColumn('bmg_rotation_schedules', [
                    'duration_seconds' => ['type' => 'INT', 'unsigned' => true, 'default' => 60],
                ]);
            }
            if ($this->db->fieldExists('rotations', 'bmg_rotation_schedules')) {
                foreach ($this->db->table('bmg_rotation_schedules')->select('id, rotations')->get()->getResultArray() as $row) {
                    $sec = min(600, max(1, (int) ($row['rotations'] ?? 7) * 6));
                    $this->db->table('bmg_rotation_schedules')->where('id', $row['id'])->update(['duration_seconds' => $sec]);
                }
                $this->forge->dropColumn('bmg_rotation_schedules', 'rotations');
            }
        }

        if ($this->db->tableExists('bmg_rotation_commands')) {
            if (!$this->db->fieldExists('duration_seconds', 'bmg_rotation_commands')) {
                $this->forge->addColumn('bmg_rotation_commands', [
                    'duration_seconds' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                ]);
            }
            if ($this->db->fieldExists('rotations', 'bmg_rotation_commands')) {
                foreach ($this->db->table('bmg_rotation_commands')->select('id, rotations')->get()->getResultArray() as $row) {
                    $rot = $row['rotations'];
                    $sec = $rot === null ? null : min(600, max(1, (int) $rot * 6));
                    $this->db->table('bmg_rotation_commands')->where('id', $row['id'])->update(['duration_seconds' => $sec]);
                }
                $this->forge->dropColumn('bmg_rotation_commands', 'rotations');
            }
        }

        if ($this->db->tableExists('bmg_rotation_runs')) {
            if (!$this->db->fieldExists('duration_seconds', 'bmg_rotation_runs')) {
                $this->forge->addColumn('bmg_rotation_runs', [
                    'duration_seconds' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                ]);
            }
            if ($this->db->fieldExists('rotations', 'bmg_rotation_runs')) {
                foreach ($this->db->table('bmg_rotation_runs')->select('id, rotations')->get()->getResultArray() as $row) {
                    $this->db->table('bmg_rotation_runs')->where('id', $row['id'])->update(['duration_seconds' => (int) ($row['rotations'] ?? 0)]);
                }
                $this->forge->dropColumn('bmg_rotation_runs', 'rotations');
            }
        }
    }
}
