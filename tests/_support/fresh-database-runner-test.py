"""No database connections: exercise runner refusal and cleanup with mocked processes."""
import contextlib
import importlib.util
import io
import subprocess
import unittest
from pathlib import Path
from unittest import mock

SPEC = importlib.util.spec_from_file_location('fresh_runner', Path(__file__).with_name('run-fresh-database-tests.py'))
if SPEC is None or SPEC.loader is None:
    raise RuntimeError('Cannot load the fresh-database runner for safety tests.')
RUNNER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RUNNER)


class FreshDatabaseRunnerTest(unittest.TestCase):
    def invoke(self, query, test_result=None, filename='tests/unit/QueueServiceTest.php'):
        with mock.patch.object(RUNNER, 'MYSQL', mock.Mock(is_file=lambda: True)), \
             mock.patch.object(RUNNER, 'client', side_effect=query) as client, \
             mock.patch.object(RUNNER.subprocess, 'run', return_value=test_result) as php, \
             mock.patch('sys.argv', ['runner', filename]), \
             contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
            result = RUNNER.main()
        return result, client, php

    def testCreationFailureNeverDropsAnExistingSchema(self):
        result, client, php = self.invoke(lambda schema, sql: subprocess.CompletedProcess([], 1, '', 'creation refused'))
        self.assertEqual(1, result)
        self.assertEqual(1, client.call_count)
        php.assert_not_called()
        self.assertTrue(client.call_args.args[1].startswith('CREATE DATABASE '))

    def testNonTestInputIsRefusedBeforeDatabaseAccess(self):
        result, client, php = self.invoke(lambda *_: self.fail('Unexpected DB access'), filename='composer.json')
        self.assertEqual(2, result)
        client.assert_not_called()
        php.assert_not_called()

    def testFreshSchemaOverridesInheritedDatabaseAndIsCleanedAfterTestFailure(self):
        schema_name = []
        queries = []

        def client(schema, sql):
            queries.append(sql)
            if sql.startswith('CREATE DATABASE '):
                schema_name.append(sql.split('`')[1])
                return subprocess.CompletedProcess([], 0, '', '')
            if sql.startswith('SELECT SCHEMA_NAME ') or sql == 'SELECT DATABASE()':
                return subprocess.CompletedProcess([], 0, schema_name[0] + '\n', '')
            self.assertEqual('DROP DATABASE `' + schema_name[0] + '`', sql)
            return subprocess.CompletedProcess([], 0, '', '')

        failure = subprocess.CompletedProcess([], 2, 'synthetic test failure', '')
        with mock.patch.dict(RUNNER.os.environ, {'database.tests.database': 'humus_db_test'}):
            result, _, php = self.invoke(client, failure)
        self.assertEqual(2, result)
        self.assertRegex(schema_name[0], r'^humus_fresh_[0-9a-f]{16}$')
        command = php.call_args.args[0]
        environment = php.call_args.kwargs['env']
        self.assertIn('tests/_support/fresh-database-bootstrap.php', command)
        self.assertIn('phpunit.dist.xml', command)
        self.assertIn('--fail-on-skipped', command)
        for group in ['default', 'tests']:
            self.assertEqual(schema_name[0], environment['database.' + group + '.database'])
        self.assertEqual(schema_name[0], environment['FRESH_DATABASE_SCHEMA'])
        self.assertTrue(queries[-1].startswith('DROP DATABASE '))
        self.assertNotIn('DROP DATABASE `humus_db_test`', queries)

    def testCleanupIdentityFailureRetainsSchema(self):
        created = []
        queries = []

        def client(schema, sql):
            queries.append(sql)
            if sql.startswith('CREATE DATABASE '):
                created.append(sql.split('`')[1])
                return subprocess.CompletedProcess([], 0, '', '')
            if sql.startswith('SELECT SCHEMA_NAME '):
                return subprocess.CompletedProcess([], 0, created[0] + '\n', '')
            self.assertEqual('SELECT DATABASE()', sql)
            return subprocess.CompletedProcess([], 0, 'humus_db\n', '')

        result, _, _ = self.invoke(client, subprocess.CompletedProcess([], 0, '', ''))
        self.assertEqual(0, result)
        self.assertFalse(any(sql.startswith('DROP DATABASE ') for sql in queries))


if __name__ == '__main__':
    unittest.main()
