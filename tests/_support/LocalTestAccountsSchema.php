<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Database\BaseConnection;

final class LocalTestAccountsSchema
{
    public static function create(BaseConnection $db): void
    {
        // Relevant production columns, nullability, uniqueness, FKs and auth_version default.
        foreach ([
            'CREATE TABLE tenants (id INTEGER PRIMARY KEY, slug TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT)',
            "CREATE TABLE people (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL REFERENCES tenants(id),
                kind TEXT NOT NULL CHECK(kind IN ('student','employee','guest')), mis_id TEXT NOT NULL,
                first_name TEXT NOT NULL, middle_name TEXT, last_name TEXT NOT NULL, email TEXT, phone TEXT,
                gender TEXT, date_of_birth TEXT, address TEXT, mis_synced_at TEXT, mis_payload_hash TEXT,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT,
                UNIQUE(tenant_id, kind, mis_id))",
            "CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL REFERENCES tenants(id),
                person_id INTEGER REFERENCES people(id), username TEXT NOT NULL, email TEXT, password_hash TEXT,
                role TEXT NOT NULL DEFAULT 'student', status TEXT NOT NULL DEFAULT 'active'
                CHECK(status IN ('active','suspended','locked')), auth_version INTEGER NOT NULL DEFAULT 1,
                last_login_at TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT,
                UNIQUE(tenant_id, username), UNIQUE(tenant_id, person_id))",
            'CREATE TABLE student_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL REFERENCES tenants(id),
                person_id INTEGER NOT NULL REFERENCES people(id), program TEXT, year_level INTEGER, section TEXT, department TEXT,
                enrollment_type TEXT, is_graduating INTEGER, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
                UNIQUE(tenant_id, person_id))',
            "CREATE TABLE employee_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL REFERENCES tenants(id),
                person_id INTEGER NOT NULL REFERENCES people(id), department TEXT, position TEXT,
                employment_status TEXT NOT NULL DEFAULT 'active', is_teaching INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE(tenant_id, person_id))",
            'CREATE TABLE permissions (id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, name TEXT, module TEXT)',
            'CREATE TABLE role_permissions (role TEXT NOT NULL, permission_id INTEGER NOT NULL REFERENCES permissions(id),
                PRIMARY KEY(role, permission_id))',
        ] as $sql) {
            $db->query($sql);
        }
        $db->table('tenants')->insert(['id' => 1, 'slug' => 'synthetic', 'name' => 'Test Tenant',
            'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00']);
    }
}
