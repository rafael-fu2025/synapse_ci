"""Bounded restore proof for BMG deployment. Coordinate exclusive 33307 use first."""
import argparse
import ast
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import sys
import time
from typing import Any

ROOT = Path(__file__).resolve().parent.parent
parser = argparse.ArgumentParser()
parser.add_argument('--backup-stem', required=True)
parser.add_argument('--isolated-slot-approved', action='store_true', required=True,
                    help='Parent has coordinated exclusive use with the other isolated agent')
parser.add_argument('--reset-owned-rehearsal', action='store_true',
                    help='Explicitly recreate only this disposable schema after a clean prior owned attempt')
args = parser.parse_args()
stem = (ROOT / args.backup_stem).resolve()
if stem.parent != (ROOT / 'writable/backups').resolve() or not re.fullmatch(r'humus_db_before_bmg_\d{8}_\d{6}_[a-f0-9]{6}', stem.name):
    parser.error('Expected a fixed BMG backup stem directly under writable/backups, without extension')
# Load only the proven Job Object/process declarations, never top-level harness actions.
source = ROOT / 'writable/isolated-inventory-harness.py'
module = ast.parse(source.read_text(encoding='utf-8'))
cut = next(i for i, node in enumerate(module.body) if isinstance(node, ast.Try))
h: dict[str, Any] = {'__file__': str(source), '__name__': 'bmg_development_guards'}
exec(compile(ast.Module(body=module.body[:cut], type_ignores=[]), str(source), 'exec'), h)
h['DEADLINE'] = time.monotonic() + 150
DATA, BIN, PHP = h['DATA'], h['BIN'], h['PHP']
run, sql, verify = h['run'], h['sql'], h['verify']
schema = 'humus_bmg_development_rehearsal'
report: dict[str, Any] = {'success': False, 'schema': schema, 'port': 33307, 'main_database_accessed': False, 'hardware_operations': 0}
server = None
verified = False
slot = None
slot_path = ROOT / 'writable/bmg-isolated-slot.lock'
try:
    slot = slot_path.open('x')
    slot.write('BMG deployment rehearsal owns the isolated slot; do not start another harness.\n')
    slot.flush()
    proof = json.loads((DATA / 'isolated-inventory-result.json').read_text())
    if not all(proof.get(k) for k in ('success', 'graceful_shutdown', 'job_closed', 'owned_children_exited')):
        raise RuntimeError('Existing datadir lacks clean ownership proof')
    meta = json.loads(stem.with_suffix('.json').read_text())
    dump = stem.with_suffix('.sql')
    raw = dump.read_bytes()
    if (meta.get('database') != 'humus_db' or meta.get('native_dump_completed') is not True
            or len(raw) != meta.get('bytes') or hashlib.sha256(raw).hexdigest() != meta.get('sha256')
            or b'-- Dump completed on' not in raw[-2048:]):
        raise RuntimeError('Backup identity/bytes/SHA/completion failed')
    text = raw.decode('utf-8')
    if re.search(r'^\s*(?:/\*![0-9]+\s+)?(?:USE\s|CREATE\s+(?:DATABASE|SCHEMA)\s)', text, re.I | re.M):
        raise RuntimeError('Backup contains database selection')
    tables = re.findall(r'^CREATE TABLE `([a-zA-Z0-9_]+)`', text, re.M)
    if len(set(tables)) != meta.get('tables_and_views'):
        raise RuntimeError('Backup table count mismatch')
    with socket.socket() as probe:
        probe.setsockopt(socket.SOL_SOCKET, socket.SO_EXCLUSIVEADDRUSE, 1)
        probe.bind(('127.0.0.1', 33307))
    argv = [BIN / 'mysqld.exe', '--no-defaults', '--basedir=' + str(h['BASE']), '--datadir=' + str(DATA),
            '--bind-address=127.0.0.1', '--port=33307', '--pid-file=' + str(DATA / 'bmg-development-server.pid'),
            '--log-error=' + str(DATA / 'logs/bmg-development-server-error.log'), '--tmpdir=' + str(DATA / 'tmp'),
            '--innodb-data-home-dir=' + str(DATA), '--innodb-log-group-home-dir=' + str(DATA),
            '--aria-log-dir-path=' + str(DATA), '--innodb-buffer-pool-size=64M', '--key-buffer-size=8M',
            '--innodb-log-file-size=16M', '--max-connections=10', '--event-scheduler=OFF',
            '--skip-log-bin', '--enable-named-pipe=OFF', '--innodb-lock-wait-timeout=5']
    with open(os.devnull, 'rb') as src, open(DATA / 'bmg-development-console.log', 'wb') as out:
        server = h['spawn'](argv, src, out)
        report['owned_server_pid'] = server.pid
    for attempt in range(80):
        if server.poll() is not None:
            raise RuntimeError('Owned server exited before readiness')
        try:
            verify()
            verified = True
            break
        except RuntimeError:
            time.sleep(.25)
    if not verified:
        raise RuntimeError('Owned server readiness timeout')
    verify()
    # Reset is explicit and restricted to a SHA-bound, cleanly stopped prior owned attempt.
    exists = sql("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='" + schema + "'", 'bmg-development-existing')
    if exists:
        prior_owned = False
        for prior_path in DATA.glob('bmg-restore-result-*.json'):
            prior = json.loads(prior_path.read_text(encoding='utf-8'))
            if (prior.get('schema') == schema and prior.get('port') == 33307
                    and prior.get('backup_sha256') == meta['sha256'] and prior.get('restore_verified') is True
                    and all(prior.get(k) is True for k in ('graceful_shutdown', 'job_closed', 'owned_children_exited'))):
                prior_owned = True
                break
        if not args.reset_owned_rehearsal or not prior_owned:
            raise RuntimeError('Existing schema requires explicit reset and clean SHA-bound prior ownership proof')
        verify(schema)
        sql('DROP DATABASE ' + schema, 'bmg-development-reset-owned')
    sql('CREATE DATABASE ' + schema + ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', 'bmg-development-create')
    verify(schema)
    run(h['CLIENT'] + [schema], 'bmg-development-import', 40, dump)
    expected = dict.fromkeys(tables, 0)
    for match in re.finditer(r'^INSERT INTO `([a-zA-Z0-9_]+)` VALUES (.*);$', text, re.M):
        expected[match[1]] += h['row_count'](match[2])
    counts = sql(' UNION ALL '.join("SELECT '" + t + "',COUNT(*) FROM `" + t + '`' for t in tables), 'bmg-development-counts', schema)
    actual = dict((line.split('\t')[0], int(line.split('\t')[1])) for line in counts.splitlines())
    if actual != expected or set(sql('SHOW TABLES', 'bmg-development-tables', schema).splitlines()) != set(tables):
        raise RuntimeError('Restore schema/count verification failed')
    report.update(backup_sha256=meta['sha256'], restore_verified=True, restored_tables=len(tables), restored_rows=sum(actual.values()))
    paths = ['writable/bmg-development-migrate.php', 'writable/bmg-development-rehearse.php', 'writable/bmg-development-rehearse.py',
             'app/Database/Migrations/2026-10-17-000005_CreateBmgRotationOperations.php',
             'app/Database/Migrations/2026-10-17-000006_AddBmgRotationPermissions.php']
    report['sources_sha256'] = {p: hashlib.sha256((ROOT / p).read_bytes()).hexdigest() for p in paths}
    env = os.environ.copy()
    env.update(SYNAPSE_BMG_REHEARSE='1', BMG_HARDWARE_ENABLED='false', FUMIS_ENABLED='false', CI_ENVIRONMENT='testing')
    env['PHP_INI_SCAN_DIR'] = str(DATA / 'php-ini')
    output = run([PHP, ROOT / 'writable/bmg-development-rehearse.php'], 'bmg-development-migration', 65, env=env)
    marker = 'BMG_DEVELOPMENT_REHEARSAL_OK '
    lines = [line[len(marker):] for line in output.splitlines() if line.startswith(marker)]
    if len(lines) != 1:
        raise RuntimeError('Exact migration evidence missing; inspect local log')
    evidence = json.loads(lines[0])
    report.update(before_sha256=evidence['before_sha256'], preservation=evidence['preservation'], migration_passed=True,
                  permission_idempotency=evidence['permission_idempotency'], down_refused=evidence['down_refused'],
                                    column_preservation=evidence['column_preservation'])
    if report['sources_sha256'] != {p: hashlib.sha256((ROOT / p).read_bytes()).hexdigest() for p in paths}:
        raise RuntimeError('Sources changed during rehearsal')
    report['success'] = True
except BaseException as error:
    report['error_type'] = type(error).__name__
    if isinstance(error, RuntimeError):
        report['guard_error'] = str(error)
    print('Rehearsal failed; inspect private local logs. No main migration was attempted.', flush=True)
finally:
    if verified and server is not None and server.poll() is None:
        try:
            verify()
            run([BIN / 'mysqladmin.exe', '--no-defaults', '--protocol=tcp', '--host=127.0.0.1', '--port=33307',
                 '--user=root', '--password=', '--connect-timeout=2', 'shutdown'], 'bmg-development-shutdown', 5)
            server.wait(timeout=7)
            report['graceful_shutdown'] = server.returncode == 0
        except BaseException:
            report['graceful_shutdown'] = False
    h['K'].CloseHandle(h['JOB'])
    report['job_closed'] = True
    report['owned_children_exited'] = True
    for child in h['children']:
        try:
            child.wait(timeout=3)
        except h['subprocess'].TimeoutExpired:
            report['owned_children_exited'] = False
    report['server_exit_code'] = server.poll() if server is not None else None
    report['port_released'] = False
    if report['owned_children_exited']:
        try:
            with socket.socket() as probe:
                probe.setsockopt(socket.SOL_SOCKET, socket.SO_EXCLUSIVEADDRUSE, 1)
                probe.bind(('127.0.0.1', 33307))
            report['port_released'] = True
        except OSError:
            pass
    report['success'] = (report['success'] and report.get('graceful_shutdown', False)
                         and report['owned_children_exited'] and report['port_released'])
    if slot is not None:
        slot.close()
        if report['owned_children_exited']:
            slot_path.unlink()
    (DATA / ('bmg-restore-result-' + str(time.time_ns()) + '.json')).write_text(json.dumps(report, indent=2), encoding='utf-8')
    print(json.dumps(report, indent=2), flush=True)
sys.exit(0 if report['success'] else 1)
