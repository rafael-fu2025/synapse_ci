"""Disposable MariaDB runner for queue (default), audit or guidance tests. Default is read-only dry feasibility.

From the project root:
  python tests/_support/queue-isolated-harness.py --dry-run
  python tests/_support/queue-isolated-harness.py --run
  python tests/_support/queue-isolated-harness.py --suite audit --run
Never imports the inventory harness, reads .env/backups, or reuses a datadir.
Run artifacts are retained under writable/queue-isolated-* for diagnosis.
"""
import argparse
import ctypes as C
import json
import os
import secrets
import socket
import subprocess
import sys
import tempfile
import threading
import time
from ctypes import wintypes as W
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BASE = Path('C:/Users/udtoh_lmtzs7k/xampp/mysql')
PHP = Path('C:/Users/udtoh_lmtzs7k/xampp/php/php.exe')
SUPPORT = ROOT / 'tests/_support'
TEST = ROOT / 'tests/unit/QueueTransitionTest.php'
TESTS = {'queue': TEST, 'audit': ROOT / 'tests/unit/AuditLedgerIsolationTest.php',
         'guidance': ROOT / 'tests/unit/GuidanceIsolationTest.php'}
LIMIT = 180


def feasibility(suite='queue'):
    required = [BASE / 'bin' / name for name in
                ('mysql_install_db.exe', 'mysqld.exe', 'mysql.exe')]
    required += [PHP, ROOT / 'vendor/phpunit/phpunit/phpunit',
                 ROOT / 'vendor/codeigniter4/framework/system/Test/bootstrap.php',
                 SUPPORT / 'queue-isolated-bootstrap.php',
                 SUPPORT / (suite + '-isolated-phpunit.xml')]
    required += [PHP.parent / 'ext' / ('php_' + name + '.dll')
                 for name in (('mysqli', 'mbstring', 'intl', 'openssl') if suite == 'guidance'
                                               else ('mysqli', 'mbstring', 'intl'))]
    missing = [str(path) for path in required if not path.is_file()]
    result = {'platform_ok': os.name == 'nt', 'missing': missing,
              'writable_exists': (ROOT / 'writable').is_dir(),
              'test_exists': TESTS[suite].is_file(), 'server_started': False,
              'database_connected': False}
    # An exclusive ephemeral bind checks local port allocation, not a DB endpoint.
    with socket.socket() as probe:
        if os.name == 'nt':
            probe.setsockopt(socket.SOL_SOCKET, socket.SO_EXCLUSIVEADDRUSE, 1)
        probe.bind(('127.0.0.1', 0))
        result['available_port_sample'] = probe.getsockname()[1]
    result['infrastructure_feasible'] = (result['platform_ok'] and not missing
                                         and result['writable_exists'])
    print(json.dumps(result, indent=2), flush=True)
    return result['infrastructure_feasible']


class BasicLimit(C.Structure):
    _fields_ = [('process_time', C.c_int64), ('job_time', C.c_int64), ('flags', W.DWORD),
                ('min_ws', C.c_size_t), ('max_ws', C.c_size_t), ('active', W.DWORD),
                ('affinity', C.c_size_t), ('priority', W.DWORD), ('scheduling', W.DWORD)]


class IOCounters(C.Structure):
    _fields_ = [(name, C.c_uint64) for name in
                ('read_ops', 'write_ops', 'other_ops', 'read_bytes', 'write_bytes', 'other_bytes')]


class ExtendedLimit(C.Structure):
    _fields_ = [('basic', BasicLimit), ('io', IOCounters), ('process_memory', C.c_size_t),
                ('job_memory', C.c_size_t), ('peak_process_memory', C.c_size_t),
                ('peak_job_memory', C.c_size_t)]


class OwnedJob:
    def __init__(self):
        self.kernel = C.WinDLL('kernel32', use_last_error=True)
        self.nt = C.WinDLL('ntdll')
        self.kernel.CreateJobObjectW.argtypes = [C.c_void_p, W.LPCWSTR]
        self.kernel.CreateJobObjectW.restype = W.HANDLE
        self.kernel.SetInformationJobObject.argtypes = [W.HANDLE, C.c_int, C.c_void_p, W.DWORD]
        self.kernel.AssignProcessToJobObject.argtypes = [W.HANDLE, W.HANDLE]
        self.kernel.TerminateJobObject.argtypes = [W.HANDLE, W.UINT]
        self.kernel.CloseHandle.argtypes = [W.HANDLE]
        self.nt.NtResumeProcess.argtypes = [W.HANDLE]
        self.nt.NtResumeProcess.restype = C.c_long
        self.handle = self.kernel.CreateJobObjectW(None, None)
        if not self.handle:
            raise C.WinError(C.get_last_error())
        limits = ExtendedLimit()
        limits.basic.flags = 0x2000  # KILL_ON_JOB_CLOSE, non-inherited handle.
        if not self.kernel.SetInformationJobObject(self.handle, 9, C.byref(limits), C.sizeof(limits)):
            self.kernel.CloseHandle(self.handle)
            raise C.WinError(C.get_last_error())
        self.children = []
        self.deadline = time.monotonic() + LIMIT
        self.done = threading.Event()
        threading.Thread(target=self.watchdog, daemon=True).start()

    def watchdog(self):
        if not self.done.wait(LIMIT + 5):
            self.kernel.TerminateJobObject(self.handle, 124)
            self.kernel.CloseHandle(self.handle)
            os._exit(124)

    def spawn(self, args, out, cwd, env):
        # Suspend before assignment so even initialization cannot escape ownership.
        p = subprocess.Popen([str(arg) for arg in args], cwd=str(cwd), env=env,
                             stdin=subprocess.DEVNULL, stdout=out, stderr=subprocess.STDOUT,
                             creationflags=0x00000004 | 0x08000000, close_fds=True)
        try:
            raw_handle = getattr(p, '_handle', None)
            if raw_handle is None:
                raise RuntimeError('Windows subprocess handle unavailable')
            try:
                handle_value = int(raw_handle)
            except (TypeError, ValueError, OverflowError) as exc:
                raise RuntimeError('Invalid Windows subprocess handle') from exc
            if handle_value <= 0:
                raise RuntimeError('Invalid Windows subprocess handle')
            handle = W.HANDLE(handle_value)
            if not self.kernel.AssignProcessToJobObject(self.handle, handle):
                raise C.WinError(C.get_last_error())
            if self.nt.NtResumeProcess(handle) != 0:
                raise RuntimeError('Could not resume owned child')
        except BaseException:
            p.kill()
            p.wait(timeout=3)
            raise
        self.children.append(p)
        return p

    def run(self, args, log, cwd, env, cap=20):
        remaining = min(cap, self.deadline - time.monotonic())
        if remaining <= 0:
            raise TimeoutError('Queue harness deadline exceeded')
        with log.open('wb') as out:
            p = self.spawn(args, out, cwd, env)
            code = p.wait(timeout=remaining)
        if code:
            raise RuntimeError(f'Child exited {code}; inspect {log}')
        return log.read_text(encoding='utf-8', errors='replace').strip()

    def close(self):
        # No SQL shutdown or cleanup connection: kill only owned processes.
        if not self.kernel.TerminateJobObject(self.handle, 0):
            raise C.WinError(C.get_last_error())
        for child in self.children:
            child.wait(timeout=3)
        self.kernel.CloseHandle(self.handle)
        self.done.set()


def execute(suite='queue'):
    test = TESTS[suite]
    if not test.is_file():
        raise RuntimeError(test.name + ' is absent; refusing initialization')
    job = OwnedJob()
    try:
        attempt = Path(tempfile.mkdtemp(prefix='queue-isolated-', dir=ROOT / 'writable')).resolve()
        data = attempt / 'data'
        data.mkdir()
        (attempt / 'tmp').mkdir()
        print('Isolated artifacts: ' + str(attempt), flush=True)
        with socket.socket() as probe:
            probe.setsockopt(socket.SOL_SOCKET, socket.SO_EXCLUSIVEADDRUSE, 1)
            probe.bind(('127.0.0.1', 0))
            port = probe.getsockname()[1]
        schema = 'queue_isolated_' + secrets.token_hex(8)
        # Discard inherited database/client credentials and previous harness switches.
        env = {key: value for key, value in os.environ.items()
               if not key.lower().startswith(('database.', 'database_', 'mysql', 'mariadb', 'synapse_'))}
        env.update({'CI_ENVIRONMENT': 'testing', 'FUMIS_ENABLED': 'false',
                    'QUEUE_ISOLATED_DATADIR': str(data), 'QUEUE_ISOLATED_PORT': str(port),
                    'QUEUE_ISOLATED_SCHEMA': schema, 'database.defaultGroup': 'tests'})
        fields = {'DSN': '', 'hostname': '127.0.0.1', 'port': str(port), 'username': 'root',
                  'password': '', 'database': schema, 'DBDriver': 'MySQLi', 'DBPrefix': '',
                  'pConnect': 'false', 'DBDebug': 'true', 'charset': 'utf8mb4',
                  'DBCollat': 'utf8mb4_unicode_ci', 'swapPre': '', 'encrypt': 'false',
                  'compress': 'false', 'strictOn': 'true', 'failover': '[]'}
        for group in ('default', 'tests'):
            env.update({'database.' + group + '.' + key: value for key, value in fields.items()})
        # This Windows installer rejects --no-defaults/--config. Its built-in
        # mysqld bootstrap command already uses --no-defaults and --datadir=.
        # It writes my.ini only into the fresh datadir; our server ignores it.
        job.run([BASE / 'bin/mysql_install_db.exe', '--datadir=' + str(data),
                 '--port=' + str(port)], attempt / 'initialize.log', attempt, env, 55)
        args = [BASE / 'bin/mysqld.exe', '--no-defaults', '--basedir=' + str(BASE),
                '--datadir=' + str(data), '--bind-address=127.0.0.1', '--port=' + str(port),
                '--pid-file=' + str(attempt / 'server.pid'), '--log-error=' + str(attempt / 'server-error.log'),
                '--tmpdir=' + str(attempt / 'tmp'), '--innodb-data-home-dir=' + str(data),
                '--innodb-log-group-home-dir=' + str(data), '--aria-log-dir-path=' + str(data),
                '--innodb-buffer-pool-size=64M', '--innodb-log-file-size=16M',
                '--max-connections=15', '--event-scheduler=OFF', '--skip-log-bin',
                # --enable-named-pipe=OFF still enables pipes on MariaDB 10.4 Windows.
                # Disable via the verified boolean option and isolate the name defensively.
                '--general-log=OFF', '--slow-query-log=OFF', '--skip-named-pipe',
                '--socket=synapse-' + schema,
                '--local-infile=0', '--innodb-lock-wait-timeout=5']
        with (attempt / 'server-console.log').open('wb') as out:
            server = job.spawn(args, out, attempt, env)
        client = [BASE / 'bin/mysql.exe', '--no-defaults', '--protocol=tcp',
                  '--host=127.0.0.1', '--port=' + str(port), '--user=root', '--password=',
                  '--connect-timeout=2', '--batch', '--raw', '--skip-column-names']

        def sql(query):
            return job.run(client + ['--execute=' + query], attempt / 'sql.log', attempt, env, 10)

        ready = time.monotonic() + 60
        while True:
            if server.poll() is not None:
                raise RuntimeError('Owned server exited; inspect isolated logs')
            try:
                identity = sql('SELECT @@datadir, @@port')
                break
            except RuntimeError:
                if time.monotonic() >= ready:
                    raise
                time.sleep(.25)
        directory, actual_port = identity.split('\t')
        if Path(directory).resolve() != data or actual_port != str(port):
            raise RuntimeError('Identity mismatch: refusing ALL schema writes')
        if server.poll() is not None:
            raise RuntimeError('Owned server is no longer running')
        if sql('SELECT @@named_pipe, @@socket') != '0\tsynapse-' + schema:
            raise RuntimeError('Named pipe isolation mismatch: refusing ALL schema writes')
        sql('CREATE DATABASE `' + schema + '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
        if sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='" + schema + "'") != '0':
            raise RuntimeError('Synthetic schema is not empty')
        # Ignore installed php.ini entirely, loading only required local extensions.
        php = [PHP, '-n', '-d', 'extension_dir=' + str(PHP.parent / 'ext')]
        for extension in (('mysqli', 'mbstring', 'intl', 'openssl') if suite == 'guidance'
                          else ('mysqli', 'mbstring', 'intl')):
            php += ['-d', 'extension=' + extension]
        job.run(php + [ROOT / 'vendor/phpunit/phpunit/phpunit', '--configuration',
                       SUPPORT / (suite + '-isolated-phpunit.xml'), '--do-not-cache-result', '--no-coverage'],
                attempt / 'tests.log', ROOT, env, 80)
        print(suite.upper() + '_ISOLATED_TESTS_OK; output: ' + str(attempt / 'tests.log'), flush=True)
    finally:
        job.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--suite', choices=('queue', 'audit', 'guidance'), default='queue',
                        help='Test suite; all use the fresh queue-isolated identity guards')
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--dry-run', action='store_true')
    mode.add_argument('--run', action='store_true')
    args = parser.parse_args()
    if not feasibility(args.suite):
        return 2
    if args.run:
        execute(args.suite)
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (OSError, RuntimeError, ValueError, subprocess.SubprocessError) as exc:
        print(str(exc), file=sys.stderr)
        sys.exit(1)
