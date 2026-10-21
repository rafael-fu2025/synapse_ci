<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Reporting\LatexReportCompiler;
use CodeIgniter\Test\CIUnitTestCase;
use Config\ReportExport;
use org\bovigo\vfs\vfsStream;
use ReflectionMethod;
use RuntimeException;

final class LatexReportCompilerTest extends CIUnitTestCase
{
    private ReportExport $settings;
    private array $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = config(ReportExport::class);
        $this->original = [$this->settings->pdflatex, $this->settings->miktex, $this->settings->timeoutSeconds];
        $this->settings->pdflatex = 'pdflatex';
        $this->settings->miktex = true;
        $this->settings->timeoutSeconds = 3;
        FakeLatexReportCompiler::$script = 'file_put_contents("report.pdf", "%PDF-1.4\nfake\n%%EOF\n");';
        FakeLatexReportCompiler::$jobs = [];
        FakeLatexReportCompiler::$commands = [];
        FakeLatexReportCompiler::$environments = [];
        FakeLatexReportCompiler::$inputs = [];
        FakeLatexReportCompiler::$files = [];
    }

    protected function tearDown(): void
    {
        [$this->settings->pdflatex, $this->settings->miktex, $this->settings->timeoutSeconds] = $this->original;
        try {
            foreach (FakeLatexReportCompiler::$jobs as $directory) {
                self::assertDirectoryDoesNotExist($directory, 'Every private workspace must be removed.');
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testReturnsPdfAndUsesIsolatedJobsWithOnlyTheFixedAsset(): void
    {
        $source = "\\documentclass{article}\n\\begin{document}Counts 12\\end{document}";
        self::assertStringStartsWith('%PDF-', FakeLatexReportCompiler::compile($source));
        self::assertStringStartsWith('%PDF-', FakeLatexReportCompiler::compile($source));
        self::assertNotSame(FakeLatexReportCompiler::$jobs[0], FakeLatexReportCompiler::$jobs[1]);
        foreach (FakeLatexReportCompiler::$jobs as $directory) {
            self::assertStringStartsWith(realpath(WRITEPATH) . DIRECTORY_SEPARATOR . 'report-temp', $directory);
            self::assertStringNotContainsString(DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR, $directory);
            self::assertMatchesRegularExpression('/job-[a-f0-9]{32}$/', $directory);
        }
        self::assertSame([$source, $source], FakeLatexReportCompiler::$inputs);
        $expectedFiles = ['report.tex'];
        $seal = ROOTPATH . 'public/assets/img/brand/FoundationU.png';
        if (is_file($seal)) {
            $expectedFiles[] = 'university-seal.png';
            self::assertSame(hash_file('sha256', $seal), FakeLatexReportCompiler::$sealHash);
        }
        self::assertSame($expectedFiles, FakeLatexReportCompiler::$files[0]);
    }

    public function testCommandIsAnArgumentArrayAndSourceNeverBecomesAnArgument(): void
    {
        $this->settings->pdflatex = 'C:\\Program Files\\MiKTeX\\pdflatex.EXE';
        $source = 'literal & | > $(anything) " source';
        FakeLatexReportCompiler::compile($source);
        self::assertSame([
            $this->settings->pdflatex, '-no-shell-escape', '-interaction=nonstopmode',
            '-halt-on-error', '-file-line-error', '-disable-installer', '-dont-parse-first-line', 'report.tex',
        ], FakeLatexReportCompiler::$commands[0]);
        $environment = FakeLatexReportCompiler::$environments[0];
        self::assertSame('p', $environment['openin_any']);
        self::assertSame('p', $environment['openout_any']);
        self::assertSame(FakeLatexReportCompiler::$jobs[0], $environment['TMPDIR']);
        self::assertArrayHasKey('path', array_change_key_case($environment, CASE_LOWER));
        foreach (['PATH', 'SystemRoot'] as $name) {
            if (getenv($name) !== false) {
                self::assertSame(getenv($name), $environment[$name]);
            }
        }
    }

    public function testOnlyAllowlistedRuntimeEnvironmentReachesChild(): void
    {
        $values = [
            'LATEX_TEST_CI_SECRET' => 'must-not-reach-child',
            'database.default.password' => 'must-not-reach-child',
            'MIKTEX_TEST_SECRET' => 'must-not-reach-child',
            'TEXMF_TEST_SECRET' => 'must-not-reach-child',
            'TEXMFHOME' => 'runtime-texmf-home',
            'MIKTEX_USERDATA' => 'runtime-miktex-data',
            'OSFONTDIR' => 'runtime-font-directory',
        ];
        $original = [];
        foreach ($values as $name => $value) {
            $original[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
        try {
            // Verify the child receives the restricted environment, not just our recorded array.
            FakeLatexReportCompiler::$script = 'if (getenv("LATEX_TEST_CI_SECRET") !== false'
                . ' || getenv("MIKTEX_TEST_SECRET") !== false'
                . ' || getenv("TEXMF_TEST_SECRET") !== false'
                . ' || getenv("database.default.password") !== false) { exit(1); }'
                . 'file_put_contents("report.pdf", "%PDF-1.4");';
            self::assertStringStartsWith('%PDF-', FakeLatexReportCompiler::compile('trusted'));
            $environment = FakeLatexReportCompiler::$environments[0];
            foreach ($values as $name => $value) {
                if (str_starts_with($value, 'runtime-')) {
                    self::assertSame($value, $environment[$name]);
                } else {
                    self::assertArrayNotHasKey($name, $environment);
                }
            }
        } finally {
            foreach ($original as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }

    public function testCleanupRejectsFailedUnlink(): void
    {
        $root = vfsStream::setup('latex-cleanup');
        $job = vfsStream::newDirectory('job', 0500)->at($root);
        vfsStream::newFile('report.tex')->withContent('private source')->at($job);
        $cleanup = new ReflectionMethod(LatexReportCompiler::class, 'removeWorkspace');
        $this->assertUnavailable(static fn () => $cleanup->invoke(null, $job->url()));
        self::assertFileExists($job->url() . '/report.tex');
    }

    public function testCleanupRejectsFailedRmdir(): void
    {
        $root = vfsStream::setup('latex-cleanup', 0500);
        $job = vfsStream::newDirectory('job', 0700)->at($root);
        $cleanup = new ReflectionMethod(LatexReportCompiler::class, 'removeWorkspace');
        $this->assertUnavailable(static fn () => $cleanup->invoke(null, $job->url()));
        self::assertDirectoryExists($job->url());
    }

    public function testCleanupFailureAfterSuccessOrCompilerFailureIsGeneric(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Uses Windows read-only file deletion semantics.');
        }
        foreach ([0, 1] as $exitCode) {
            FakeLatexReportCompiler::$script = 'file_put_contents("report.pdf", "%PDF-1.4");'
                . 'chmod("report.pdf", 0444); exit(' . $exitCode . ');';
            try {
                $this->assertUnavailable(static fn () => FakeLatexReportCompiler::compile('trusted'));
                self::assertDirectoryExists(FakeLatexReportCompiler::$jobs[array_key_last(FakeLatexReportCompiler::$jobs)]);
            } finally {
                foreach (FakeLatexReportCompiler::$jobs as $directory) {
                    if (is_dir($directory)) {
                        chmod($directory . '/report.pdf', 0666);
                        (new ReflectionMethod(LatexReportCompiler::class, 'removeWorkspace'))->invoke(null, $directory);
                    }
                }
            }
        }
    }

    public function testTexLiveCanOmitMiktexOnlyOptions(): void
    {
        $this->settings->miktex = false;
        FakeLatexReportCompiler::compile('trusted');
        self::assertNotContains('-disable-installer', FakeLatexReportCompiler::$commands[0]);
        self::assertContains('-no-shell-escape', FakeLatexReportCompiler::$commands[0]);
    }

    public function testRejectsEmptyNullAndOversizeSourcesBeforeLaunching(): void
    {
        foreach (['', "a\0b", str_repeat('x', 262_145)] as $source) {
            $this->assertUnavailable(static fn () => FakeLatexReportCompiler::compile($source));
        }
        self::assertSame([], FakeLatexReportCompiler::$jobs);
    }

    public function testAcceptsSourceAtSizeLimit(): void
    {
        self::assertStringStartsWith('%PDF-', FakeLatexReportCompiler::compile(str_repeat('x', 262_144)));
    }

    public function testFailureDoesNotExposeSourceCompilerLogsOrPreviousException(): void
    {
        FakeLatexReportCompiler::$script = 'fwrite(STDERR, "SECRET compiler diagnostic"); exit(1);';
        $this->assertUnavailable(static fn () => FakeLatexReportCompiler::compile('SECRET source'));
    }

    public function testRejectsMissingInvalidAndOversizePdf(): void
    {
        foreach ([
            '',
            'file_put_contents("report.pdf", "not a PDF");',
            'file_put_contents("report.pdf", "%PDF-" . str_repeat("x", 2097148));',
        ] as $script) {
            FakeLatexReportCompiler::$script = $script;
            $this->assertUnavailable(static fn () => FakeLatexReportCompiler::compile('trusted'));
        }
    }

    public function testAcceptsPdfAtOutputLimit(): void
    {
        FakeLatexReportCompiler::$script = 'file_put_contents("report.pdf", "%PDF-" . str_repeat("x", 2097147));';
        self::assertSame(2_097_152, strlen(FakeLatexReportCompiler::compile('trusted')));
    }

    public function testRejectsAggregateOutputEvenWhenIndividualFilesAreWithinBounds(): void
    {
        FakeLatexReportCompiler::$script = 'for ($i = 0; $i < 11; $i++) {'
            . 'file_put_contents("aux-" . $i, str_repeat("x", 2097152)); }';
        $this->assertUnavailable(static fn () => FakeLatexReportCompiler::compile('trusted'));
    }

    public function testRejectsOversizeStdoutStderrAndTexLog(): void
    {
        foreach (['stdout.log', 'stderr.log', 'report.log'] as $filename) {
            FakeLatexReportCompiler::$script = 'file_put_contents(' . var_export($filename, true)
                . ', str_repeat("x", 2097153));';
            $this->assertUnavailable(static fn () => FakeLatexReportCompiler::compile('trusted'));
        }
    }

    public function testTimeoutTerminatesChildAndCleansWorkspace(): void
    {
        $this->settings->timeoutSeconds = 1;
        FakeLatexReportCompiler::$script = 'sleep(10);';
        $start = microtime(true);
        $this->assertUnavailable(static fn () => FakeLatexReportCompiler::compile('trusted'));
        self::assertLessThan(5, microtime(true) - $start);
    }

    public function testMissingBinaryIsGenericAndCleansWorkspaceWithoutShellInterpretation(): void
    {
        $before = glob(WRITEPATH . 'report-temp/job-*') ?: [];
        // If interpreted by a shell, the second command would create a marker.
        $marker = WRITEPATH . 'latex-shell-marker-' . bin2hex(random_bytes(8));
        $this->settings->pdflatex = 'missing-pdflatex-' . bin2hex(random_bytes(8)) . ' & echo unsafe > "' . $marker . '"';
        try {
            $this->assertUnavailable(static fn () => LatexReportCompiler::compile('trusted'));
            self::assertFileDoesNotExist($marker);
            self::assertSame($before, glob(WRITEPATH . 'report-temp/job-*') ?: []);
        } finally {
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }

    public function testNestedUnexpectedOutputIsRejectedAndRemoved(): void
    {
        FakeLatexReportCompiler::$script = 'mkdir("unexpected"); file_put_contents("unexpected/file", "x");';
        $this->assertUnavailable(static fn () => FakeLatexReportCompiler::compile('trusted'));
    }

    private function assertUnavailable(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected a generic compilation failure.');
        } catch (RuntimeException $exception) {
            self::assertSame(503, $exception->getCode());
            self::assertSame('PDF report compilation is unavailable.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }
}

/** Exercises real process polling/termination without depending on TeX or a database. */
final class FakeLatexReportCompiler extends LatexReportCompiler
{
    public static string $script = '';
    public static array $jobs = [];
    public static array $commands = [];
    public static array $environments = [];
    public static array $inputs = [];
    public static array $files = [];
    public static string|false $sealHash = false;

    protected static function startProcess(array $command, string $directory, array $environment): mixed
    {
        self::$jobs[] = $directory;
        self::$commands[] = $command;
        self::$environments[] = $environment;
        self::$inputs[] = file_get_contents($directory . '/report.tex');
        self::$files[] = array_values(array_diff(scandir($directory), ['.', '..']));
        self::$sealHash = is_file($directory . '/university-seal.png')
            ? hash_file('sha256', $directory . '/university-seal.png') : false;
        return parent::startProcess([PHP_BINARY, '-r', self::$script], $directory, $environment);
    }
}
