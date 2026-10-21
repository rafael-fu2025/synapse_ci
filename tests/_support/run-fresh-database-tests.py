"""Run PHPUnit database tests against a fresh disposable local MySQL schema.

The schema is created and dropped by this script. It never uses humus_db or an
existing humus_db_test schema. Example (from the project root):

  python tests/_support/run-fresh-database-tests.py tests/unit/QueueServiceTest.php
"""
import argparse
import os
import secrets
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MYSQL = Path('C:/Users/udtoh_lmtzs7k/xampp/mysql/bin/mysql.exe')
if not MYSQL.is_file():
    found = shutil.which('mysql')
    MYSQL = Path(found) if found else MYSQL


def client(schema: str | None, query: str) -> subprocess.CompletedProcess:
    args = [str(MYSQL), '--no-defaults', '--protocol=tcp', '--host=127.0.0.1', '--port=3306',
            '--user=root', '--password=', '--batch', '--raw', '--skip-column-names']
    if schema:
        args.append(schema)
    args.append('--execute=' + query)
    return subprocess.run(args, capture_output=True, text=True, timeout=20, check=False)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('files', nargs='+', help='PHPUnit test files')
    args = parser.parse_args()
    if not MYSQL.is_file():
        print('mysql client not found', file=sys.stderr)
        return 2
    files = [(ROOT / name).resolve() for name in args.files]
    for path in files:
        if (ROOT / 'tests') not in path.parents or not path.is_file() or not path.name.endswith('Test.php'):
            print('Refusing file outside tests or without a Test.php suffix: ' + str(path), file=sys.stderr)
            return 2

    schema = 'humus_fresh_' + secrets.token_hex(8)
    owns_schema = False
    try:
        created = client(None, 'CREATE DATABASE `' + schema + '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
        if created.returncode:
            print(created.stdout + created.stderr, file=sys.stderr)
            return created.returncode
        owns_schema = True
        identity = client(None, "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='" + schema + "'")
        if identity.stdout.strip() != schema:
            print('Fresh schema identity check failed', file=sys.stderr)
            return 3
        env = os.environ.copy()
        env.update({
            'FRESH_DATABASE_SCHEMA': schema,
            'FRESH_DATABASE_PORT': '3306',
            'CI_ENVIRONMENT': 'testing',
            'database.defaultGroup': 'tests',
            'database.tests.hostname': '127.0.0.1',
            'database.tests.port': '3306',
            'database.tests.database': schema,
            'database.tests.username': 'root',
            'database.tests.password': '',
            'database.tests.DBDriver': 'MySQLi',
            'database.tests.DBPrefix': '',
            'database.default.hostname': '127.0.0.1',
            'database.default.port': '3306',
            'database.default.database': schema,
            'database.default.username': 'root',
            'database.default.password': '',
            'database.default.DBDriver': 'MySQLi',
            'database.default.DBPrefix': '',
        })
        command = ['php', 'vendor/bin/phpunit', '--configuration', 'phpunit.dist.xml',
                   '--do-not-cache-result', '--no-coverage', '--fail-on-skipped', '--bootstrap',
                   'tests/_support/fresh-database-bootstrap.php', *[str(path) for path in files]]
        result = subprocess.run(command, cwd=ROOT, env=env, capture_output=True, text=True, timeout=180, check=False)
        print(result.stdout, end='')
        print(result.stderr, end='', file=sys.stderr)
        return result.returncode
    finally:
        # Never drop a schema we failed to create, even in the unlikely event of a name collision.
        if owns_schema:
            identity = client(schema, 'SELECT DATABASE()')
            if identity.returncode == 0 and identity.stdout.strip() == schema:
                dropped = client(None, 'DROP DATABASE `' + schema + '`')
                if dropped.returncode:
                    print('Fresh schema cleanup failed: ' + schema, file=sys.stderr)
            else:
                print('Fresh schema cleanup identity check failed; retained ' + schema, file=sys.stderr)


if __name__ == '__main__':
    raise SystemExit(main())
