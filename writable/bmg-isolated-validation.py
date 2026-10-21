"""Bounded BMG-only rehearsal. Reuses harness job/process guards, not its top-level actions."""
import argparse
import ast
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import sys
import tempfile
import time
import xml.etree.ElementTree as ET
from typing import Any

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / 'writable/isolated-inventory-harness.py'
# The existing harness is executable at import time. Load only its declarations and
# Job Object setup, stopping BEFORE its top-level restore/test try block.
module = ast.parse(SOURCE.read_text(encoding='utf-8'))
cut = next(i for i, node in enumerate(module.body) if isinstance(node, ast.Try))
h: dict[str, Any] = {'__file__': str(SOURCE), '__name__': 'bmg_harness_guards'}
exec(compile(ast.Module(body=module.body[:cut], type_ignores=[]), str(SOURCE), 'exec'), h)
h['DEADLINE'] = time.monotonic() + 160
DATA, BIN, PHP = h['DATA'], h['BIN'], h['PHP']
run, sql, verify = h['run'], h['sql'], h['verify']
report: dict[str, Any] = {'success': False, 'main_database_accessed': False, 'hardware_enabled': False}
server = None
verified = False
config_path = None
schema = 'humus_bmg_isolated_test'
try:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--resume-cleaned', action='store_true',
                        help='Accept only the approved, fully cleaned prior 100s PHPUnit timeout')
    options = parser.parse_args()
    proof = json.loads((DATA / 'isolated-inventory-result.json').read_text())
    report['prior_test_success'] = proof.get('success')
    if not all(proof.get(k) is True for k in ('graceful_shutdown', 'job_closed', 'owned_children_exited')):
        raise RuntimeError('Existing datadir lacks clean owned-harness proof')
    if proof.get('success') is not True:
        # Exact approved failure only; never treat cleanup as evidence that tests passed.
        expected_error = str(h['subprocess'].TimeoutExpired([
            PHP, str(ROOT / 'vendor/phpunit/phpunit/phpunit'), '--configuration',
            str(ROOT / 'writable/isolated-security-5gztrg16.xml'),
            '--no-coverage', '--stop-on-error'], 100))
        stages = proof.get('stages', [])
        expected_stages = ['preflight_ok', 'initialized', 'isolated_server_identity_verified',
                           'restore_verified', 'restore_inventory_migration_passed']
        if (not options.resume_cleaned or proof.get('success') is not False
                or proof.get('error') != expected_error
                or [stage.get('name') for stage in stages] != expected_stages
                or stages[2].get('port') != 33307 or stages[2].get('owned_pid') != 30172
                or stages[0].get('backup_sha256') != '8d6dbc433a331d7d68c0b98a7609bece3f4640c4b9fcad4e4437af0b2a5f606b'
                or stages[3].get('all_table_counts_match') is not True
                or stages[4].get('readiness') is not True):
            raise RuntimeError('Existing datadir lacks successful proof or approved --resume-cleaned timeout proof')
        report['resumed_cleaned_timeout'] = True
    stem = ROOT / 'writable/backups/humus_db_before_audience_20261016_201958_258b4d'
    meta = json.loads(stem.with_suffix('.json').read_text())
    dump = stem.with_suffix('.sql')
    raw = dump.read_bytes()
    if (meta['database'] != 'humus_db' or meta['native_dump_completed'] is not True
            or len(raw) != meta['bytes'] or hashlib.sha256(raw).hexdigest() != meta['sha256']
            or b'-- Dump completed on' not in raw[-2048:]):
        raise RuntimeError('Native backup verification failed')
    text = raw.decode('utf-8')
    if re.search(r'^\s*(?:/\*![0-9]+\s+)?(?:USE\s|CREATE\s+(?:DATABASE|SCHEMA)\s)', text, re.I | re.M):
        raise RuntimeError('Backup contains database selection')
    tables = re.findall(r'^CREATE TABLE `([a-zA-Z0-9_]+)`', text, re.M)
    if len(set(tables)) != meta['tables_and_views']:
        raise RuntimeError('Backup table count mismatch')
    with socket.socket() as probe:
        probe.setsockopt(socket.SOL_SOCKET, socket.SO_EXCLUSIVEADDRUSE, 1)
        probe.bind(('127.0.0.1', 33307))
    args = [BIN / 'mysqld.exe', '--no-defaults', '--basedir=' + str(h['BASE']), '--datadir=' + str(DATA),
            '--bind-address=127.0.0.1', '--port=33307', '--pid-file=' + str(DATA / 'bmg-server.pid'),
            '--log-error=' + str(DATA / 'logs/bmg-server-error.log'), '--tmpdir=' + str(DATA / 'tmp'),
            '--innodb-data-home-dir=' + str(DATA), '--innodb-log-group-home-dir=' + str(DATA),
            '--aria-log-dir-path=' + str(DATA), '--innodb-buffer-pool-size=64M', '--key-buffer-size=8M',
            '--innodb-log-file-size=16M', '--max-connections=30', '--event-scheduler=OFF',
            '--skip-log-bin', '--enable-named-pipe=OFF', '--innodb-lock-wait-timeout=10']
    with open(os.devnull, 'rb') as src, open(DATA / 'bmg-server-console.log', 'wb') as out:
        server = h['spawn'](args, src, out)
    for attempt in range(80):
        if server.poll() is not None:
            raise RuntimeError('Owned MariaDB exited before readiness')
        try:
            verify()
            verified = True
            break
        except RuntimeError:
            time.sleep(.25)
    if not verified:
        raise RuntimeError('Owned MariaDB did not become ready')
    # Never drops or alters the original restore/service schemas used by the parent.
    verify()
    sql('DROP DATABASE IF EXISTS ' + schema, 'bmg-drop-prior')
    sql('CREATE DATABASE ' + schema + ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', 'bmg-create')
    verify(schema)
    run(h['CLIENT'] + [schema], 'bmg-restore-import', 35, dump)
    expected = dict.fromkeys(tables, 0)
    for match in re.finditer(r'^INSERT INTO `([a-zA-Z0-9_]+)` VALUES (.*);$', text, re.M):
        expected[match[1]] += h['row_count'](match[2])
    counts = sql(' UNION ALL '.join("SELECT '" + t + "',COUNT(*) FROM `" + t + '`' for t in tables), 'bmg-restore-counts', schema)
    actual = dict((line.split('\t')[0], int(line.split('\t')[1])) for line in counts.splitlines())
    if actual != expected or set(sql('SHOW TABLES', 'bmg-restore-tables', schema).splitlines()) != set(tables):
        raise RuntimeError('Restored native schema/count verification failed')
    report.update(backup_sha256=meta['sha256'], restored_tables=len(tables), restored_rows=sum(actual.values()), owned_pid=server.pid)
    env = os.environ.copy()
    env.update(SYNAPSE_BMG_ISOLATED='1', SYNAPSE_BMG_MIGRATE='1', BMG_HARDWARE_ENABLED='false', FUMIS_ENABLED='false', CI_ENVIRONMENT='testing')
    env['PHP_INI_SCAN_DIR'] = str(DATA / 'php-ini')
    output = run([PHP, ROOT / 'writable/bmg-isolated-validation.php'], 'bmg-migration-evidence', 40, env=env)
    if 'BMG_RESTORE_MIGRATION_OK ' not in output:
        raise RuntimeError('Migration evidence marker missing')
    print(output, flush=True)
    report['migration_passed'] = True
    env['SYNAPSE_BMG_MIGRATE'] = '0'
    root = ET.Element('phpunit', {'bootstrap': str(ROOT / 'writable/bmg-isolated-validation.php'), 'colors': 'false'})
    suite = ET.SubElement(ET.SubElement(root, 'testsuites'), 'testsuite', {'name': 'Isolated BMG routes'})
    for name in ('BmgDeviceApiTest.php', 'BmgRoutesTest.php', 'BmgUsbRoutesTest.php'):
        ET.SubElement(suite, 'file').text = str(ROOT / 'tests/feature' / name)
    with tempfile.NamedTemporaryFile(prefix='bmg-', suffix='.xml', dir=DATA, delete=False) as file:
        config_path = Path(file.name)
    ET.ElementTree(root).write(config_path, encoding='utf-8', xml_declaration=True)
    print(run([PHP, ROOT / 'vendor/phpunit/phpunit/phpunit', '--configuration', config_path,
               '--no-coverage', '--do-not-cache-result', '--testdox'], 'bmg-route-tests', 90, env=env), flush=True)
    report['success'] = True
except BaseException as error:
    report['error'] = str(error)
    print('STOP:', error, flush=True)
finally:
    if verified and server is not None and server.poll() is None:
        try:
            verify()
            run([BIN / 'mysqladmin.exe', '--no-defaults', '--protocol=tcp', '--host=127.0.0.1', '--port=33307',
                 '--user=root', '--password=', '--connect-timeout=2', 'shutdown'], 'bmg-shutdown', 5)
            server.wait(timeout=7)
            report['graceful_shutdown'] = server.returncode == 0
        except BaseException as error:
            report['shutdown_error'] = str(error)
    h['K'].CloseHandle(h['JOB'])
    report['job_closed'] = True
    report['owned_children_exited'] = True
    for child in h['children']:
        try:
            child.wait(timeout=3)
        except h['subprocess'].TimeoutExpired:
            report['owned_children_exited'] = False
    if config_path is not None:
        config_path.unlink(missing_ok=True)
    if server is not None:
        report['server_exit_code'] = server.poll()
        try:
            with socket.socket() as probe:
                probe.setsockopt(socket.SOL_SOCKET, socket.SO_EXCLUSIVEADDRUSE, 1)
                probe.bind(('127.0.0.1', 33307))
            report['port_released'] = True
        except OSError:
            report['port_released'] = False
    report['success'] = (report['success'] and report.get('graceful_shutdown', False)
                         and report['owned_children_exited'] and report.get('port_released', False))
    (DATA / 'bmg-validation-result.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
    print(json.dumps(report, indent=2), flush=True)
sys.exit(0 if report['success'] else 1)
