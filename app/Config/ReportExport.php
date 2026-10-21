<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

final class ReportExport extends BaseConfig
{
    // CI4 environment overrides: reportExport.pdflatex, .miktex, .timeoutSeconds.
    public string $pdflatex = 'pdflatex';
    public bool $miktex = PHP_OS_FAMILY === 'Windows';
    public int $timeoutSeconds = 30;
}
