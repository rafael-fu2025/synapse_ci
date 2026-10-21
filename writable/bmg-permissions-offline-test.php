<?php

declare(strict_types=1);

// Offline behavioral test: PDO SQLite adapter, no application bootstrap or main connection.
namespace CodeIgniter\Database {
    abstract class Migration
    {
        public function __construct(protected $db) {}
        abstract public function up(): void;
        abstract public function down(): void;
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    final class OfflineDatabase
    {
        public \PDO $pdo;
        public function __construct()
        {
            $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $this->pdo->exec('CREATE TABLE permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, name TEXT, module TEXT)');
            $this->pdo->exec('CREATE TABLE role_permissions (role TEXT, permission_id INTEGER, PRIMARY KEY(role,permission_id))');
        }
        public function table(string $table): OfflineBuilder { return new OfflineBuilder($this->pdo, $table); }
        public function transBegin(): bool { return $this->pdo->beginTransaction(); }
        public function transStatus(): bool { return true; }
        public function transCommit(): bool { return $this->pdo->commit(); }
        public function transRollback(): bool { return !$this->pdo->inTransaction() || $this->pdo->rollBack(); }
        public function insertID(): string { return $this->pdo->lastInsertId(); }
        public function snapshot(): array
        {
            return [$this->pdo->query('SELECT * FROM permissions ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC),
                $this->pdo->query('SELECT * FROM role_permissions ORDER BY role,permission_id')->fetchAll(\PDO::FETCH_ASSOC)];
        }
    }
    final class OfflineBuilder
    {
        private array $where = [];
        private array $rows = [];
        public function __construct(private \PDO $pdo, private string $table) {}
        public function where($key, $value = null): self
        {
            $this->where = array_merge($this->where, is_array($key) ? $key : [$key => $value]);
            return $this;
        }
        public function get(): self
        {
            $sql = 'SELECT * FROM ' . $this->table;
            if ($this->where) { $sql .= ' WHERE ' . implode(' AND ', array_map(static fn ($k) => $k . '=?', array_keys($this->where))); }
            $q = $this->pdo->prepare($sql); $q->execute(array_values($this->where));
            $this->rows = $q->fetchAll(\PDO::FETCH_ASSOC);
            return $this;
        }
        public function getResultArray(): array { return $this->rows; }
        public function countAllResults(): int { return count($this->get()->getResultArray()); }
        public function insert(array $row): bool
        {
            $q = $this->pdo->prepare('INSERT INTO ' . $this->table . ' (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')');
            return $q->execute(array_values($row));
        }
    }
    require dirname(__DIR__) . '/app/Database/Migrations/2026-10-17-000006_AddBmgRotationPermissions.php';
    $check = static function (bool $condition, string $message): void { if (!$condition) { throw new \LogicException($message); } };
    foreach ([false, true] as $existing) {
        $db = new OfflineDatabase();
        $db->table('permissions')->insert(['id' => 50, 'code' => 'unrelated', 'name' => 'Keep me', 'module' => 'other']);
        $db->table('role_permissions')->insert(['role' => 'student', 'permission_id' => 50]);
        if ($existing) {
            foreach (\App\Database\Migrations\AddBmgRotationPermissions::catalog() as $code => $name) {
                $db->table('permissions')->insert(['code' => $code, 'name' => $name, 'module' => 'facilities']);
                // Existing extra configure and explicit commissioning grants must survive unchanged.
                $db->table('role_permissions')->insert(['role' => 'custom_role', 'permission_id' => $db->insertID()]);
            }
        }
        $before = $db->snapshot();
        $m = new \App\Database\Migrations\AddBmgRotationPermissions($db);
        $m->up();
        $after = $db->snapshot();
        foreach ([0, 1] as $i) {
            foreach ($before[$i] as $row) { $check(in_array($row, $after[$i], true), 'Original row changed'); }
        }
        $check(count($after[0]) === 3, 'Wrong catalog count');
        $check(count($after[1]) === count($before[1]) + 2, 'Only two configure grants may be added');
        foreach ($after[0] as $permission) {
            if ($permission['code'] !== 'facilities.configure') { continue; }
            foreach (['superadmin', 'bmg_admin'] as $role) {
                $check($db->table('role_permissions')->where(['role' => $role, 'permission_id' => $permission['id']])->countAllResults() === 1, 'Missing configure grant');
            }
        }
        $m->up();
        $check($after === $db->snapshot(), 'Idempotency failed');
        $refused = false;
        try { $m->down(); } catch (\RuntimeException) { $refused = true; }
        $check($refused && $after === $db->snapshot(), 'Rollback must refuse without mutation');
    }
    $db = new OfflineDatabase();
    $db->table('permissions')->insert(['code' => 'facilities.hardware.manage', 'name' => 'Unexpected metadata', 'module' => 'facilities']);
    $before = $db->snapshot();
    $refused = false;
    try { (new \App\Database\Migrations\AddBmgRotationPermissions($db))->up(); } catch (\RuntimeException) { $refused = true; }
    $check($refused && $before === $db->snapshot(), 'Conflicting metadata must roll back all additions');
    echo "PASS: additive configure grants, no automatic hardware grants, preserved original rows/grants, strict idempotency, metadata conflict rollback, forward-only refusal; no main access\n";
}
