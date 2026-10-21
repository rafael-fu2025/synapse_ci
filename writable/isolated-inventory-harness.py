"""One-shot Windows-only rehearsal; owns a kill-on-close Job Object, never a service."""
import ctypes as C
from ctypes import wintypes as W
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET
import threading
import time

ROOT = Path(__file__).resolve().parent.parent
WRITABLE = ROOT / 'writable'
DATA = WRITABLE / 'isolated-inventory-db'
BASE = Path('C:/Users/udtoh_lmtzs7k/xampp/mysql')
BIN = BASE / 'bin'
PHP = 'C:/Users/udtoh_lmtzs7k/xampp/php/php.exe'
STEM = WRITABLE / 'backups/humus_db_before_inventory_20261015_214239_aeb44e'
START = time.monotonic()
DEADLINE = START + 220
REPORT = {'stages': [], 'success': False}
K = C.WinDLL('kernel32', use_last_error=True)
NT = C.WinDLL('ntdll')

class BasicLimit(C.Structure):
    _fields_ = [('process_time', C.c_int64), ('job_time', C.c_int64), ('flags', W.DWORD),
                ('min_ws', C.c_size_t), ('max_ws', C.c_size_t), ('active', W.DWORD),
                ('affinity', C.c_size_t), ('priority', W.DWORD), ('scheduling', W.DWORD)]
class IOCounters(C.Structure):
    _fields_ = [(n, C.c_uint64) for n in ('read_ops','write_ops','other_ops','read_bytes','write_bytes','other_bytes')]
class ExtendedLimit(C.Structure):
    _fields_ = [('basic', BasicLimit), ('io', IOCounters), ('process_memory', C.c_size_t),
                ('job_memory', C.c_size_t), ('peak_process_memory', C.c_size_t), ('peak_job_memory', C.c_size_t)]
K.CreateJobObjectW.argtypes = [C.c_void_p, W.LPCWSTR]
K.CreateJobObjectW.restype = W.HANDLE
K.SetInformationJobObject.argtypes = [W.HANDLE, C.c_int, C.c_void_p, W.DWORD]
K.AssignProcessToJobObject.argtypes = [W.HANDLE, W.HANDLE]
K.CloseHandle.argtypes = [W.HANDLE]
NT.NtResumeProcess.argtypes = [W.HANDLE]
NT.NtResumeProcess.restype = C.c_long
JOB = K.CreateJobObjectW(None, None)
if not JOB:
    raise C.WinError(C.get_last_error())
limits = ExtendedLimit()
limits.basic.flags = 0x2000  # JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE; handle is not inherited.
if not K.SetInformationJobObject(JOB, 9, C.byref(limits), C.sizeof(limits)):
    K.CloseHandle(JOB)
    raise C.WinError(C.get_last_error())

# Independent watchdog closes the job even if a Python wait/cleanup stalls.
def watchdog():
    time.sleep(238)
    K.CloseHandle(JOB)
    os._exit(124)
threading.Thread(target=watchdog, daemon=True).start()
children = []
server = None
identity_verified = False
temporary_test_config = None

def remaining(cap):
    value = min(cap, DEADLINE - time.monotonic())
    if value <= 0:
        raise TimeoutError('Internal rehearsal deadline exceeded')
    return value

def spawn(args, stdin, stdout, env=None):
    p = subprocess.Popen([str(a) for a in args], cwd=str(ROOT if str(args[0]) == PHP else (DATA if DATA.exists() else WRITABLE)),
                         stdin=stdin, stdout=stdout, stderr=subprocess.STDOUT, env=env,
                         creationflags=0x00000004 | 0x08000000, close_fds=True)
    try:
        raw_handle = getattr(p, '_handle', None)
        if raw_handle is None:
            raise RuntimeError('Windows subprocess handle unavailable')
        try:
            handle = int(raw_handle)
        except (TypeError, ValueError, OverflowError) as exc:
            raise RuntimeError('Invalid Windows subprocess handle') from exc
        if handle <= 0:
            raise RuntimeError('Invalid Windows subprocess handle')
        if not K.AssignProcessToJobObject(JOB, W.HANDLE(handle)):
            raise C.WinError(C.get_last_error())
        if NT.NtResumeProcess(W.HANDLE(handle)) != 0:
            raise RuntimeError('NtResumeProcess failed')
    except BaseException:
        p.kill()
        p.wait(timeout=5)
        raise
    children.append(p)
    return p

def run(args, name, cap=35, input_path=None, env=None):
    path = (DATA if DATA.exists() else WRITABLE) / (name + '.log')
    with open(input_path or os.devnull, 'rb') as src, open(path, 'wb') as out:
        p = spawn(args, src, out, env)
        try:
            code = p.wait(timeout=remaining(cap))
        except BaseException:
            p.kill()
            p.wait(timeout=3)
            raise
    if code:
        raise RuntimeError(f'{name} exited {code}; inspect {path.name}')
    return path.read_text(encoding='utf-8', errors='replace')

CLIENT = [BIN/'mysql.exe', '--no-defaults', '--protocol=tcp', '--host=127.0.0.1', '--port=33307', '--user=root', '--password=', '--connect-timeout=2', '--batch', '--raw', '--skip-column-names']
def sql(query, name, schema=None):
    return run(CLIENT + ([schema] if schema else []) + ['--execute=' + query], name, 12).strip()

def verify(schema=None):
    line = sql('SELECT @@datadir,@@port,COALESCE(DATABASE(),\'NONE\')', 'identity', schema)
    directory, port, actual = line.split('\t')
    if Path(directory).resolve() != DATA.resolve() or port != '33307' or actual != (schema or 'NONE'):
        raise RuntimeError('Server identity mismatch; refusing writes')

def stage(name, **extra):
    REPORT['stages'].append({'name': name, 'elapsed_seconds': round(time.monotonic()-START, 2), **extra})
    print(name, json.dumps(extra), flush=True)

def row_count(values):
    quoted = False
    escaped = False
    depth = 0
    count = 0
    for ch in values:
        if escaped:
            escaped = False
        elif quoted and ch == '\\':
            escaped = True
        elif ch == "'":
            quoted = not quoted
        elif not quoted:
            if ch == '(':
                if depth == 0:
                    count += 1
                depth += 1
            elif ch == ')':
                depth -= 1
    if quoted or depth:
        raise RuntimeError('Could not safely count dump INSERT rows')
    return count

def pagination_test_tree(test_config):
    tree = ET.parse(test_config)
    suite = tree.getroot().find('testsuites/testsuite')
    if suite is None:
        raise RuntimeError('Pagination test configuration requires testsuites/testsuite')
    for path in ('unit/TablePageTest.php', 'unit/DirectoryPaginationQueryTest.php',
                 'database/DirectoryPaginationDatabaseTest.php', 'feature/DirectoryPaginationHttpTest.php',
                 'feature/DirectoryPaginationRoutesTest.php'):
        ET.SubElement(suite, 'file').text = '../tests/' + path
    return tree

def security_test_tree(validate_security, validate_engagement_tests, validate_audience_tests):
    tree = ET.parse(ROOT/'phpunit.dist.xml')
    root = tree.getroot()
    root.set('bootstrap', str(WRITABLE/'isolated-inventory-bootstrap.php'))
    suites = root.find('testsuites')
    if suites is None:
        raise RuntimeError('Security test configuration requires testsuites')
    for child in list(suites): suites.remove(child)
    suite = ET.SubElement(suites, 'testsuite', {'name': 'Local security'})
    security_paths = ('unit/SessionAuthorizationTest.php', 'feature/SessionHardeningTest.php',
                 'feature/LookupReferralSecurityTest.php', 'unit/ReferralGuardTest.php',
                 'feature/WebSecurityTest.php', 'unit/AuthServiceTest.php', 'unit/ReferralServiceTest.php',
                                      'unit/DepartmentStaffAvailabilityTest.php', 'unit/DepartmentStaffMigrationTest.php',
                                                           'unit/StaffAssignmentSchedulingTest.php',
                                                           'feature/DepartmentStaffAvailabilityRoutesTest.php',
                                      'feature/AuthRoutesTest.php', 'feature/PortalSeparationTest.php',
                 'unit/HttpMisClientTest.php', 'unit/MisProfileMapperTest.php',
                 'feature/MisIdentityLoginTest.php', 'feature/AppointmentNotificationTest.php',
                                      'feature/ReferralWorkflowTest.php', 'feature/ReferralRoutesTest.php',
                                      'feature/ReferralNotificationTest.php', 'unit/ReferralWorkflowMigrationTest.php')
    engagement_paths = ('feature/AnnouncementWorkflowTest.php', 'feature/AnnouncementRoutesTest.php',
                        'feature/SurveyWorkflowTest.php', 'feature/SurveyRoutesTest.php')
    audience_paths = ('feature/StudentAudienceTargetingTest.php',)
    selected_paths = audience_paths if validate_audience_tests else (() if validate_engagement_tests else security_paths) + engagement_paths + (audience_paths if validate_security else ())
    for path in selected_paths:
        if not (ROOT/'tests'/path).is_file():
            raise RuntimeError('Required test is not ready: ' + path)
        ET.SubElement(suite, 'file').text = str(ROOT/'tests'/path)
    return tree

class RestoreRehearsalComplete(Exception):
    pass

try:
    validate_staff = '--validate-staff' in sys.argv
    validate_notifications = '--validate-notifications' in sys.argv
    validate_referrals = '--validate-referrals' in sys.argv
    validate_audience = '--validate-audience' in sys.argv
    validate_audience_tests = '--validate-audience-tests' in sys.argv
    validate_engagement = '--validate-engagement' in sys.argv
    validate_engagement_tests = '--validate-engagement-tests' in sys.argv
    validate_current_backup = validate_staff or validate_notifications or validate_referrals or validate_engagement or validate_audience
    if validate_audience and not (ROOT/'app/Database/Migrations/2026-10-17-000004_AddStudentAudienceTargeting.php').is_file():
        raise RuntimeError('Exact audience migration is not ready')
    if validate_engagement:
        # Refuse before touching prior ownership proof or starting the disposable server.
        for version, name in (('2026-10-17-000001', 'CreateAnnouncements'),
                              ('2026-10-17-000002', 'CreateSurveys'),
                              ('2026-10-17-000003', 'AddEngagementNotifications')):
            migration = ROOT/'app/Database/Migrations'/f'{version}_{name}.php'
            if not migration.is_file():
                raise RuntimeError('Exact engagement migration is not ready: ' + migration.name)
    stem_args = [arg.split('=', 1)[1] for arg in sys.argv[1:] if arg.startswith('--backup-stem=')]
    if validate_current_backup:
        mode_flag = '--validate-audience' if validate_audience else '--validate-engagement' if validate_engagement else '--validate-referrals' if validate_referrals else ('--validate-notifications' if validate_notifications else '--validate-staff')
        if len(stem_args) != 1 or any(arg != mode_flag and not arg.startswith('--backup-stem=') for arg in sys.argv[1:]):
            raise RuntimeError('Restore mode requires only ' + mode_flag + ' --backup-stem=<literal project backup stem>')
        supplied = Path(stem_args[0])
        candidate = supplied if supplied.is_absolute() else ROOT / supplied
        STEM = candidate.resolve(strict=True) if candidate.exists() else candidate.resolve()
        backups = (WRITABLE/'backups').resolve(strict=True)
        label_pattern = 'audience' if validate_audience else 'engagement' if validate_engagement else 'referrals' if validate_referrals else (r'[a-z_]{1,40}' if validate_notifications else 'staff_assignments')
        if STEM.parent != backups or not re.fullmatch(r'humus_db_before_' + label_pattern + r'_[0-9]{8}_[0-9]{6}_[0-9a-f]{6}', STEM.name):
            raise RuntimeError('Backup stem must be a literal project backup stem under writable/backups')
        for suffix in ('.json', '.sql'):
            path = STEM.with_suffix(suffix)
            if path.resolve(strict=True).parent != backups or not path.is_file():
                raise RuntimeError('Backup files must resolve under writable/backups')
    elif stem_args:
        raise RuntimeError('--backup-stem requires --validate-staff, --validate-notifications, --validate-referrals or --validate-engagement or --validate-audience')
    diagnose_routes = '--diagnose-routes' in sys.argv
    validate_security = '--validate-security' in sys.argv
    validate_pagination = '--validate-pagination' in sys.argv
    validate_inventory = '--validate-inventory' in sys.argv or validate_pagination
    resume_rehearsal = '--resume-owned-rehearsal' in sys.argv or diagnose_routes or validate_inventory or validate_security or validate_engagement_tests or validate_audience_tests or validate_current_backup
    resume_restore = '--resume-owned-restore' in sys.argv or resume_rehearsal
    resume = '--resume-owned-initialization' in sys.argv or resume_restore
    if DATA.exists():
        proof_path = DATA/'isolated-inventory-result.json'
        proof = json.loads(proof_path.read_text()) if proof_path.exists() else {}
        stages = [s['name'] for s in proof.get('stages', [])]
        restore_stages = ['preflight_ok', 'initialized', 'isolated_server_identity_verified', 'restore_verified']
        current_backup_proof = (stages in (restore_stages + ['staff_restore_migration_passed'],
                                           restore_stages + ['notifications_restore_migration_passed'],
                                           restore_stages + ['referrals_restore_migration_passed'],
                                           restore_stages + ['engagement_restore_migration_passed'],
                                           restore_stages + ['audience_restore_migration_passed'])
                       and proof.get('success') and proof.get('graceful_shutdown')
                       and re.fullmatch(r'[0-9a-f]{64}', str(proof['stages'][-1].get('backup_sha256', ''))) is not None
                       and proof['stages'][-1]['backup_sha256'] == proof['stages'][0].get('backup_sha256'))
        permitted = (resume_rehearsal and current_backup_proof) or (stages == ['preflight_ok', 'initialized'] and not resume_restore) or (resume_restore and stages == restore_stages and proof.get('graceful_shutdown')) or (resume_rehearsal and stages[:5] == restore_stages + ['restore_inventory_migration_passed'] and proof.get('graceful_shutdown'))
        if not resume or not proof.get('job_closed') or not proof.get('owned_children_exited') or not permitted:
            raise RuntimeError('Disposable datadir already exists without resumable owned-attempt proof')
        proof_path.rename(DATA/('prior-attempt-' + str(time.time_ns()) + '.json'))
        for log_name in ('restore-inventory-migration', 'staff-restore-migration', 'notifications-restore-migration', 'referrals-restore-migration', 'engagement-restore-migration', 'audience-restore-migration', 'focused-tests', 'pagination-tests', 'full-inventory-tests'):
            log_path = DATA/(log_name + '.log')
            if log_path.exists():
                log_path.rename(DATA/(log_name + '-prior-' + str(time.time_ns()) + '.log'))
    elif resume:
        raise RuntimeError('Cannot resume absent datadir')
    meta = json.loads(STEM.with_suffix('.json').read_text())
    dump = STEM.with_suffix('.sql')
    raw = dump.read_bytes()
    if hashlib.sha256(raw).hexdigest() != meta['sha256'] or len(raw) != meta['bytes']:
        raise RuntimeError('Backup hash/size mismatch')
    if (validate_engagement or validate_audience) and (meta.get('database') != 'humus_db'
            or meta.get('native_dump_completed') is not True
            or Path(meta.get('path', '')).resolve(strict=True) != dump.resolve(strict=True)
            or b'-- Dump completed on' not in raw[-2048:]):
        raise RuntimeError('Completed native humus_db engagement backup metadata required')
    text = raw.decode('utf-8')
    if re.search(r'^\s*(?:/\*![0-9]+\s+)?(?:USE\s|CREATE\s+(?:DATABASE|SCHEMA)\s)', text, re.I | re.M):
        raise RuntimeError('Dump unexpectedly selects/creates database')
    tables = re.findall(r'^CREATE TABLE `([a-zA-Z0-9_]+)`', text, re.M)
    table_count = meta['tables_and_views'] if validate_current_backup else 35
    if type(table_count) is not int or table_count <= 0 or len(tables) != table_count or len(set(tables)) != table_count:
        raise RuntimeError('Unexpected dump table count')
    expected = dict.fromkeys(tables, 0)
    for match in re.finditer(r'^INSERT INTO `([a-zA-Z0-9_]+)` VALUES (.*);$', text, re.M):
        expected[match[1]] += row_count(match[2])
    with socket.socket() as probe:
        probe.setsockopt(socket.SOL_SOCKET, socket.SO_EXCLUSIVEADDRUSE, 1)
        probe.bind(('127.0.0.1',33307))
    stage('preflight_ok', backup_sha256=meta['sha256'], tables=table_count)
    if not resume:
        run([BIN/'mysql_install_db.exe', '--datadir='+str(DATA), '--port=33307'], 'isolated-inventory-initialize', 55)
    stage('initialized')
    for sub in ('tmp', 'logs'):
        (DATA/sub).mkdir(exist_ok=True)
    args = [BIN/'mysqld.exe', '--no-defaults', '--basedir='+str(BASE), '--datadir='+str(DATA),
            '--bind-address=127.0.0.1', '--port=33307', '--pid-file='+str(DATA/'server.pid'),
            '--log-error='+str(DATA/'logs/server-error.log'), '--tmpdir='+str(DATA/'tmp'),
            '--innodb-data-home-dir='+str(DATA), '--innodb-log-group-home-dir='+str(DATA),
            '--aria-log-dir-path='+str(DATA), '--innodb-buffer-pool-size=64M', '--key-buffer-size=8M',
            '--innodb-log-file-size=16M', '--max-connections=30', '--event-scheduler=OFF',
            '--skip-log-bin', '--general-log=OFF', '--slow-query-log=OFF',
            '--enable-named-pipe=OFF', '--innodb-lock-wait-timeout=10']
    with open(os.devnull, 'rb') as src, open(DATA/'logs/server-console.log', 'wb') as out:
        server = spawn(args, src, out)
    ready_end = time.monotonic()+30
    while True:
        if server.poll() is not None:
            raise RuntimeError(f'Server exited {server.returncode}; inspect server logs')
        try:
            verify()
            identity_verified = True
            break
        except RuntimeError:
            if time.monotonic() > ready_end:
                raise
            time.sleep(.25)
    stage('isolated_server_identity_verified', port=33307, owned_pid=server.pid)
    restored = 'humus_inventory_restore_test'
    verify()
    if resume_restore:
        sql('DROP DATABASE '+restored, 'drop-owned-partial-restore')
    sql('CREATE DATABASE '+restored+' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', 'create-restore')
    verify(restored)
    run(CLIENT+[restored], 'restore-import', 35, dump)
    actual_tables = sql('SHOW TABLES', 'restore-table-list', restored).splitlines()
    if set(actual_tables) != set(tables):
        raise RuntimeError('Restored table set mismatch')
    counts_query = ' UNION ALL '.join("SELECT '"+t+"',COUNT(*) FROM `"+t+'`' for t in tables)
    counts = dict((line.split('\t')[0], int(line.split('\t')[1])) for line in sql(counts_query, 'restore-counts', restored).splitlines())
    if counts != expected:
        raise RuntimeError('Restored row counts differ from dump INSERT counts')
    stage('restore_verified', tables=table_count, rows=sum(counts.values()), all_table_counts_match=True)
    ini = DATA/'php-ini'
    ini.mkdir(exist_ok=True)
    (ini/'isolated.ini').write_text('extension=C:/Users/udtoh_lmtzs7k/xampp/php/ext/php_intl.dll\n', encoding='ascii')
    env = os.environ.copy()
    env['PHP_INI_SCAN_DIR'] = str(ini)
    env['SYNAPSE_ISOLATED_MODE'] = 'restore'
    # Set both groups in the process environment before framework bootstrap; no production credentials.
    for group in ('default','tests'):
        for key,value in {'DSN':'','hostname':'127.0.0.1','port':'33307','username':'root','password':'','database':restored,'DBDriver':'MySQLi','DBPrefix':'','pConnect':'false'}.items():
            env['database.'+group+'.'+key] = value
    env['CI_ENVIRONMENT'] = 'testing'
    env.pop('SYNAPSE_RESTORE_STAFF', None)
    env.pop('SYNAPSE_RESTORE_NOTIFICATIONS', None)
    env.pop('SYNAPSE_RESTORE_REFERRALS', None)
    env.pop('SYNAPSE_RESTORE_ENGAGEMENT', None)
    env.pop('SYNAPSE_RESTORE_AUDIENCE', None)
    env['FUMIS_ENABLED'] = 'false'
    if validate_current_backup:
        rehearsal = 'audience' if validate_audience else 'engagement' if validate_engagement else 'referrals' if validate_referrals else ('notifications' if validate_notifications else 'staff')
        env['SYNAPSE_RESTORE_' + rehearsal.upper()] = '1'
        output = run([PHP, WRITABLE/'isolated-inventory-bootstrap.php'], rehearsal + '-restore-migration', 65, env=env)
        if not any(line.startswith(rehearsal.upper() + '_RESTORE_MIGRATION_OK ') for line in output.splitlines()):
            raise RuntimeError(rehearsal + ' restore verification marker missing')
        stage(rehearsal + '_restore_migration_passed', backup_sha256=meta['sha256'], proof=output.strip())
        REPORT['success'] = True
        raise RestoreRehearsalComplete()
    run([PHP, WRITABLE/'isolated-inventory-bootstrap.php'], 'restore-inventory-migration', 45, env=env)
    stage('restore_inventory_migration_passed', readiness=True, triggers=6)
    verify()
    if resume_rehearsal:
        sql('DROP DATABASE IF EXISTS humus_inventory_service_test', 'drop-owned-service')
    sql('CREATE DATABASE humus_inventory_service_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', 'create-service')
    env['SYNAPSE_ISOLATED_MODE'] = 'tests'
    for group in ('default','tests'):
        env['database.'+group+'.database'] = 'humus_inventory_unused_test' if group == 'default' else 'humus_inventory_service_test'
    test_config = WRITABLE/'isolated-inventory-phpunit.xml'
    if validate_pagination:
        tree = pagination_test_tree(test_config)
        with tempfile.NamedTemporaryFile(prefix='isolated-pagination-', suffix='.xml', dir=WRITABLE, delete=False) as config_file:
            temporary_test_config = Path(config_file.name)
        tree.write(temporary_test_config, encoding='utf-8', xml_declaration=True)
        test_config = temporary_test_config
    if validate_security or validate_engagement_tests or validate_audience_tests:
        tree = security_test_tree(validate_security, validate_engagement_tests, validate_audience_tests)
        env['FUMIS_ENABLED'] = 'false'
        with tempfile.NamedTemporaryFile(prefix='isolated-security-', suffix='.xml', dir=WRITABLE, delete=False) as config_file:
            temporary_test_config = Path(config_file.name)
        tree.write(temporary_test_config, encoding='utf-8', xml_declaration=True)
        test_config = temporary_test_config
    test_command = [PHP, ROOT/'vendor/phpunit/phpunit/phpunit', '--configuration', test_config, '--no-coverage', '--stop-on-error']
    if diagnose_routes:
        env['SYNAPSE_ISOLATED_DIAGNOSE'] = '1'
    run(test_command + ([] if validate_security or validate_engagement_tests or validate_audience_tests else ['--filter', 'InventoryRoutesTest']), 'focused-tests', 100 if validate_security or validate_engagement_tests or validate_audience_tests else 70, env=env)
    stage('audience_tests_passed' if validate_audience_tests else 'engagement_tests_passed' if validate_engagement_tests else 'security_tests_passed' if validate_security else 'route_tests_passed')
    if validate_pagination:
        verify('humus_inventory_service_test')
        run(test_command + ['--filter', 'DirectoryPagination|TablePage'], 'pagination-tests', 70, env=env)
        stage('pagination_tests_passed')
    if validate_inventory:
        verify()
        sql('DROP DATABASE humus_inventory_service_test', 'drop-owned-service-before-full')
        sql('CREATE DATABASE humus_inventory_service_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', 'create-service-before-full')
        verify('humus_inventory_service_test')
        run(test_command, 'full-inventory-tests', 100, env=env)
        stage('full_inventory_tests_passed')
    REPORT['success'] = True
except RestoreRehearsalComplete:
    pass
except BaseException as exc:
    REPORT['error'] = str(exc)
    print('STOP:', str(exc), flush=True)
finally:
    if identity_verified and server is not None and server.poll() is None:
        try:
            # Explicit port/protocol; never a service stop or name-based kill.
            path = DATA/'shutdown.log'
            with open(os.devnull,'rb') as src, open(path,'wb') as out:
                p = spawn([BIN/'mysqladmin.exe','--no-defaults','--protocol=tcp','--host=127.0.0.1','--port=33307','--user=root','--password=','--connect-timeout=2','shutdown'], src, out)
                p.wait(timeout=5)
            server.wait(timeout=7)
            REPORT['graceful_shutdown'] = server.returncode == 0
        except BaseException:
            REPORT['graceful_shutdown'] = False
    K.CloseHandle(JOB)
    if temporary_test_config is not None:
        temporary_test_config.unlink(missing_ok=True)
    REPORT['job_closed'] = True
    REPORT['owned_children_exited'] = True
    for child in children:
        try:
            child.wait(timeout=3)
        except subprocess.TimeoutExpired:
            REPORT['owned_children_exited'] = False
    if any(flag in sys.argv for flag in ('--validate-staff', '--validate-notifications', '--validate-referrals', '--validate-engagement', '--validate-audience')) and REPORT['success'] and not (REPORT.get('graceful_shutdown') and REPORT['owned_children_exited']):
        REPORT['success'] = False
        REPORT['error'] = 'Restore rehearsal did not shut down cleanly'
    REPORT['elapsed_seconds'] = round(time.monotonic()-START,2)
    destination = (DATA if DATA.exists() else WRITABLE)/'isolated-inventory-result.json'
    # A refused resume must not destroy the existing ownership proof.
    if not destination.exists():
        destination.write_text(json.dumps(REPORT,indent=2), encoding='utf-8')
    for flag, name in (('--validate-staff', 'staff'), ('--validate-notifications', 'notifications'), ('--validate-referrals', 'referrals'), ('--validate-engagement', 'engagement'), ('--validate-audience', 'audience')):
        if flag in sys.argv and DATA.exists():
            (DATA/(name + '-restore-result-' + str(time.time_ns()) + '.json')).write_text(json.dumps(REPORT,indent=2), encoding='utf-8')
    print(json.dumps(REPORT,indent=2),flush=True)
sys.exit(0 if REPORT['success'] else 1)
