<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use Config\ReportExport;
use FilesystemIterator;
use RuntimeException;
use Throwable;

/** Compiles trusted, server-authored templates only; TeX is not a security sandbox. */
class LatexReportCompiler
{
    private const MAX_SOURCE = 262_144;
    private const MAX_LOG = 2_097_152;
    private const MAX_PDF = 2_097_152;
    private const MAX_TOTAL = 20_971_520;
    private const FAILURE = 'PDF report compilation is unavailable.';

    public static function compile(string $source): string
    {
        $directory = null;
        $process = null;
        $hardDeadline = hrtime(true) + 45_000_000_000;
        $deadline = $hardDeadline - 500_000_000;
        $failed = false;
        $pdf = null;

        try {
            if ($source === '' || strlen($source) > self::MAX_SOURCE || str_contains($source, "\0")) {
                throw new RuntimeException();
            }
            /** @var ReportExport $config */
            $config = config(ReportExport::class);
            $deadline = min($deadline, hrtime(true) + max(1, min(45, $config->timeoutSeconds)) * 1_000_000_000);
            if ($config->pdflatex === '' || str_contains($config->pdflatex, "\0")) {
                throw new RuntimeException();
            }

            $directory = self::createWorkspace();
            if (@file_put_contents($directory . '/report.tex', $source) !== strlen($source)) {
                throw new RuntimeException();
            }
            $seal = defined('ROOTPATH') ? ROOTPATH . 'public/assets/img/brand/FoundationU.png' : '';
            if ($seal !== '' && is_file($seal)) {
                if (filesize($seal) > self::MAX_LOG || !@copy($seal, $directory . '/university-seal.png')) {
                    throw new RuntimeException();
                }
            }

            $command = [$config->pdflatex, '-no-shell-escape', '-interaction=nonstopmode',
                '-halt-on-error', '-file-line-error'];
            if ($config->miktex) {
                $command[] = '-disable-installer';
                $command[] = '-dont-parse-first-line';
            }
            $command[] = 'report.tex';

            // Explicit runtime/discovery allowlist: never inherit application secrets.
            $environment = [];
            foreach ([
                'PATH', 'SystemRoot', 'WINDIR', 'SystemDrive', 'PATHEXT',
                'USERPROFILE', 'APPDATA', 'LOCALAPPDATA', 'PROGRAMDATA',
                'ProgramFiles', 'ProgramFiles(x86)', 'CommonProgramFiles', 'CommonProgramFiles(x86)',
                'HOME', 'LANG', 'LC_ALL', 'LC_CTYPE', 'TZ',
                'MIKTEX_USERCONFIG', 'MIKTEX_USERDATA', 'MIKTEX_USERINSTALL',
                'MIKTEX_COMMONCONFIG', 'MIKTEX_COMMONDATA', 'MIKTEX_COMMONINSTALL',
                'TEXMF', 'TEXMFCNF', 'TEXMFROOT', 'TEXMFDIST', 'TEXMFHOME', 'TEXMFLOCAL',
                'TEXMFCONFIG', 'TEXMFVAR', 'TEXMFSYSCONFIG', 'TEXMFSYSVAR',
                'TEXINPUTS', 'TFMFONTS', 'VFFONTS', 'T1FONTS', 'TTFONTS', 'OPENTYPEFONTS',
                'OSFONTDIR', 'FONTCONFIG_FILE', 'FONTCONFIG_PATH',
            ] as $name) {
                $value = getenv($name);
                if ($value !== false) {
                    $environment[$name] = $value;
                }
            }
            $environment['openin_any'] = 'p';
            $environment['openout_any'] = 'p';
            $environment['TEXMFOUTPUT'] = $directory;
            $environment['TMP'] = $directory;
            $environment['TEMP'] = $directory;
            $environment['TMPDIR'] = $directory;
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException();
            }
            $process = static::startProcess($command, $directory, $environment);
            if (!is_resource($process)) {
                throw new RuntimeException();
            }

            do {
                $status = proc_get_status($process);
                self::checkWorkspace($directory);
                if (hrtime(true) >= $deadline) {
                    throw new RuntimeException();
                }
                if (!$status['running']) {
                    break;
                }
                usleep(20_000);
            } while (true);

            // PHP versions before 8.3 may lose the exit code on proc_close().
            $exitCode = $status['exitcode'];
            $closedCode = proc_close($process);
            $process = null;
            if (($exitCode === -1 ? $closedCode : $exitCode) !== 0) {
                throw new RuntimeException();
            }
            $pdfPath = $directory . '/report.pdf';
            if (!is_file($pdfPath) || is_link($pdfPath)) {
                throw new RuntimeException();
            }
            $pdf = @file_get_contents($pdfPath, false, null, 0, self::MAX_PDF + 1);
            if (!is_string($pdf) || strlen($pdf) > self::MAX_PDF || !str_starts_with($pdf, '%PDF-')) {
                throw new RuntimeException();
            }
        } catch (Throwable) {
            $failed = true;
        } finally {
            if (is_resource($process)) {
                @proc_terminate($process, 9);
                @proc_close($process);
            }
            if ($directory !== null) {
                // Windows may briefly retain file/directory handles after child exit.
                $cleanupDeadline = min($hardDeadline, hrtime(true) + 250_000_000);
                do {
                    try {
                        self::removeWorkspace($directory);
                        break;
                    } catch (Throwable) {
                        if (hrtime(true) >= $cleanupDeadline) {
                            $failed = true;
                            break;
                        }
                        usleep(10_000);
                    }
                } while (true);
            }
        }
        // Throw only after cleanup: throwing in finally can implicitly chain the
        // original exception, potentially revealing source or private paths.
        if ($failed || !is_string($pdf)) {
            throw new RuntimeException(self::FAILURE, 503);
        }
        return $pdf;
    }

    /** Protected seam lets DB-free tests run a deterministic fake child, not TeX. */
    protected static function startProcess(array $command, string $directory, array $environment): mixed
    {
        // File-backed output avoids Windows' blocking anonymous-pipe behaviour.
        return @proc_open($command, [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['file', $directory . '/stdout.log', 'w'],
            2 => ['file', $directory . '/stderr.log', 'w'],
        ], $pipes, $directory, $environment, ['bypass_shell' => true, 'suppress_errors' => true]);
    }

    private static function createWorkspace(): string
    {
        $base = rtrim(defined('WRITEPATH') ? WRITEPATH : sys_get_temp_dir(), '/\\') . '/report-temp';
        if ((!is_dir($base) && !@mkdir($base, 0700, true)) || is_link($base)) {
            throw new RuntimeException();
        }
        $base = realpath($base);
        $public = defined('ROOTPATH') ? realpath(ROOTPATH . 'public') : false;
        if ($base === false || !is_writable($base)) {
            throw new RuntimeException();
        }
        if ($public !== false) {
            $normalizedBase = strtolower(str_replace('\\', '/', $base)) . '/';
            $normalizedPublic = strtolower(str_replace('\\', '/', $public)) . '/';
            if (str_starts_with($normalizedBase, $normalizedPublic)) {
                throw new RuntimeException();
            }
        }
        $directory = $base . '/job-' . bin2hex(random_bytes(16));
        if (!@mkdir($directory, 0700)) {
            throw new RuntimeException();
        }
        return $directory;
    }

    private static function checkWorkspace(string $directory): void
    {
        clearstatcache();
        $total = 0;
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
            // The fixed report needs no subdirectories or links.
            if ($file->isLink() || !$file->isFile()) {
                throw new RuntimeException();
            }
            $size = $file->getSize();
            $limit = $file->getFilename() === 'report.pdf' ? self::MAX_PDF : self::MAX_LOG;
            $total += $size;
            if ($size > $limit || $total > self::MAX_TOTAL) {
                throw new RuntimeException();
            }
        }
    }

    private static function removeWorkspace(string $directory): void
    {
        // Never follow links, including on error paths.
        if (is_link($directory) || !is_dir($directory)) {
            @unlink($directory);
            clearstatcache(true, $directory);
            if (file_exists($directory) || is_link($directory)) {
                throw new RuntimeException(self::FAILURE, 503);
            }
            return;
        }
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file->isDir() && !$file->isLink()) {
                try {
                    self::removeWorkspace($file->getPathname());
                } catch (Throwable) {
                    // Still attempt all other removals; the final check detects leftovers.
                }
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($directory);
        clearstatcache(true, $directory);
        if (file_exists($directory) || is_link($directory)) {
            throw new RuntimeException(self::FAILURE, 503);
        }
    }
}
