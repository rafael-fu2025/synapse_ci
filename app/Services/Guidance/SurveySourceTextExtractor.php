<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use CodeIgniter\HTTP\Files\UploadedFile;
use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;
use RuntimeException;
use ZipArchive;

/**
 * Extracts normalized UTF-8 questionnaire text strictly from user-supplied input
 * (pasted text or .txt/.md/.csv/.docx/.pdf uploads). Never touches database records.
 */
final class SurveySourceTextExtractor
{
    public const MAX_FILE_BYTES     = 2097152; // 2 MB
    public const MAX_DOCX_ENTRIES   = 256;
    public const MAX_DOCX_XML_BYTES = 2097152; // 2 MB uncompressed XML limit
    public const MAX_CSV_ROWS       = 500;
    public const MAX_TEXT_BYTES     = 24000;
    public const PDF_TIMEOUT_SEC    = 5;
    public const ALLOWED_EXTENSIONS = ['txt', 'md', 'csv', 'docx', 'pdf'];

    private readonly ?Closure $pdfRunner;

    /**
     * @param null|callable(string): string $pdfRunner Test hook replacing external pdftotext execution.
     */
    public function __construct(?callable $pdfRunner = null)
    {
        $this->pdfRunner = $pdfRunner !== null ? Closure::fromCallable($pdfRunner) : null;
    }

    /**
     * @return array{text: string, source_kind: string, filename: ?string, truncated: bool}
     */
    public function extract(?UploadedFile $file, ?string $rawText, int $maxBytes = self::MAX_TEXT_BYTES): array
    {
        $sections = [];
        $fileKind = null;
        $filename = null;

        if ($file !== null && $file->getError() !== UPLOAD_ERR_NO_FILE) {
            $extractedFile = $this->extractUploadedFile($file);
            $sections[]    = $extractedFile['text'];
            $fileKind      = $extractedFile['kind'];
            $filename      = $extractedFile['filename'];
        }

        $pasted = is_string($rawText) ? trim($rawText) : '';
        if ($pasted !== '') {
            if (str_contains($pasted, "\0") || ! mb_check_encoding($pasted, 'UTF-8')) {
                throw new RuntimeException('Pasted survey text must be valid UTF-8 without binary characters.', 422);
            }
            $sections[] = $pasted;
        }

        if ($sections === []) {
            throw new RuntimeException('Paste survey questions or upload a .txt, .md, .csv, .docx, or .pdf file first.', 422);
        }

        $combined = implode("\n\n---\n\n", $sections);
        [$normalized, $truncated] = $this->normalizeText($combined, max(512, $maxBytes));

        if ($normalized === '') {
            throw new RuntimeException('The supplied survey input contained no readable text.', 422);
        }

        $sourceKind = match (true) {
            $fileKind !== null && $pasted !== '' => 'mixed',
            $fileKind !== null                   => $fileKind,
            default                              => 'paste',
        };

        return [
            'text'        => $normalized,
            'source_kind' => $sourceKind,
            'filename'    => $filename,
            'truncated'   => $truncated,
        ];
    }

    /**
     * @return array{text: string, kind: string, filename: string}
     */
    public function extractUploadedFile(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            $err = $file->getError();
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                throw new RuntimeException('Uploaded survey file exceeds the 2 MB limit.', 422);
            }
            throw new RuntimeException('The uploaded survey file could not be received cleanly.', 422);
        }

        $size = (int) $file->getSize();
        if ($size <= 0) {
            throw new RuntimeException('The uploaded survey file is empty.', 422);
        }
        if ($size > self::MAX_FILE_BYTES) {
            throw new RuntimeException('Uploaded survey file exceeds the 2 MB limit.', 422);
        }

        $originalName = basename((string) $file->getClientName());
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('Unsupported file type. Upload a .txt, .md, .csv, .docx, or .pdf file.', 422);
        }

        $tempPath = $file->getTempName();
        if ($tempPath === '' || ! is_file($tempPath) || is_link($tempPath)) {
            throw new RuntimeException('Uploaded file is unavailable for reading.', 422);
        }

        $text = $this->extractFromPath($tempPath, $originalName);

        return [
            'text'     => $text,
            'kind'     => $ext,
            'filename' => $originalName,
        ];
    }

    public function extractFromPath(string $path, string $originalName): string
    {
        if (! is_file($path) || is_link($path) || ! is_readable($path)) {
            throw new RuntimeException('Survey source file is missing or unreadable.', 422);
        }
        $size = filesize($path);
        if ($size === false || $size <= 0) {
            throw new RuntimeException('The uploaded survey file is empty.', 422);
        }
        if ($size > self::MAX_FILE_BYTES) {
            throw new RuntimeException('Uploaded survey file exceeds the 2 MB limit.', 422);
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('Unsupported file type. Upload a .txt, .md, .csv, .docx, or .pdf file.', 422);
        }

        return match ($ext) {
            'txt', 'md' => $this->readPlainText($path),
            'csv'       => $this->extractCsv($this->readPlainText($path)),
            'docx'      => $this->extractDocx($path),
            'pdf'       => $this->extractPdf($path),
        };
    }

    private function readPlainText(string $path): string
    {
        $raw = @file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException('The uploaded text file is empty.', 422);
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (str_contains($raw, "\0") || ! mb_check_encoding($raw, 'UTF-8')) {
            throw new RuntimeException('The uploaded text file must be valid UTF-8.', 422);
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            throw new RuntimeException('The uploaded text file is empty.', 422);
        }
        return $trimmed;
    }

    private function extractCsv(string $raw): string
    {
        $stream = @fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new RuntimeException('Unable to parse CSV survey file.', 422);
        }
        try {
            fwrite($stream, $raw);
            rewind($stream);
            $lines = [];
            $count = 0;
            while (($row = fgetcsv($stream, 16384, ',', '"', '\\')) !== false) {
                $cells = array_values(array_filter(
                    array_map(static fn ($cell): string => trim((string) $cell), $row),
                    static fn (string $cell): bool => $cell !== ''
                ));
                if ($cells === []) {
                    continue;
                }
                ++$count;
                if ($count > self::MAX_CSV_ROWS) {
                    break;
                }
                $lines[] = implode(' | ', $cells);
            }
        } finally {
            fclose($stream);
        }

        if ($lines === []) {
            throw new RuntimeException('The CSV file did not contain any non-empty rows.', 422);
        }

        return implode("\n", $lines);
    }

    private function extractDocx(string $path): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('DOCX extraction requires the PHP Zip extension.', 503);
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The uploaded .docx file could not be opened as a valid Word archive.', 422);
        }

        try {
            if ($zip->numFiles > self::MAX_DOCX_ENTRIES) {
                throw new RuntimeException('The uploaded .docx archive contains too many internal entries.', 422);
            }

            $targets = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (! is_string($name)) {
                    continue;
                }
                if ($name === 'word/document.xml' || preg_match('#\Aword/(?:header|footer)[0-9]+\.xml\z#D', $name) === 1) {
                    $targets[] = $name;
                }
            }

            if (! in_array('word/document.xml', $targets, true)) {
                throw new RuntimeException('The uploaded .docx file is missing word/document.xml.', 422);
            }

            // Read document.xml first so body content leads, followed by headers/footers if present.
            usort($targets, static fn (string $a, string $b): int => match (true) {
                $a === 'word/document.xml' => -1,
                $b === 'word/document.xml' => 1,
                default => strcmp($a, $b),
            });

            $totalXmlBytes = 0;
            $chunks        = [];
            foreach ($targets as $entryName) {
                $stat = $zip->statName($entryName);
                $reportedSize = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
                if ($reportedSize < 0 || ($totalXmlBytes + $reportedSize) > self::MAX_DOCX_XML_BYTES) {
                    throw new RuntimeException('The uploaded .docx file exceeds the safe XML expansion limit.', 422);
                }

                $xml = $zip->getFromName($entryName);
                if (! is_string($xml) || $xml === '') {
                    continue;
                }
                $totalXmlBytes += strlen($xml);
                if ($totalXmlBytes > self::MAX_DOCX_XML_BYTES) {
                    throw new RuntimeException('The uploaded .docx file exceeds the safe XML expansion limit.', 422);
                }

                $parsed = $this->parseWordXml($xml);
                if ($parsed !== '') {
                    $chunks[] = $parsed;
                }
            }
        } finally {
            $zip->close();
        }

        $joined = trim(implode("\n\n", $chunks));
        if ($joined === '') {
            throw new RuntimeException('No readable survey text was found inside the uploaded .docx file.', 422);
        }

        return $joined;
    }

    private function parseWordXml(string $xml): string
    {
        if (str_contains($xml, '<!DOCTYPE') || str_contains($xml, '<!ENTITY')) {
            throw new RuntimeException('Rejected unsafe XML declarations in uploaded .docx file.', 422);
        }

        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($prev);
        }

        if (! $loaded || $dom->documentElement === null) {
            throw new RuntimeException('The uploaded .docx document XML could not be parsed.', 422);
        }

        $lines = [];
        $body  = $dom->getElementsByTagName('body')->item(0) ?? $dom->documentElement;
        $this->collectWordBlocks($body, $lines);

        return trim(implode("\n", $lines));
    }

    private function collectWordBlocks(DOMNode $node, array &$lines): void
    {
        if (! $node instanceof DOMElement) {
            return;
        }

        $local = $node->localName;
        if ($local === 'tbl') {
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === 'tr') {
                    $rowCells = [];
                    foreach ($child->childNodes as $cell) {
                        if ($cell instanceof DOMElement && $cell->localName === 'tc') {
                            $cellLines = [];
                            $this->collectWordBlocks($cell, $cellLines);
                            $cellText = trim(implode(' / ', $cellLines));
                            if ($cellText !== '') {
                                $rowCells[] = $cellText;
                            }
                        }
                    }
                    if ($rowCells !== []) {
                        $lines[] = implode(' | ', $rowCells);
                    }
                }
            }
            return;
        }

        if ($local === 'p') {
            $text = trim($this->collectParagraphText($node));
            if ($text !== '') {
                $lines[] = $text;
            }
            return;
        }

        foreach ($node->childNodes as $child) {
            $this->collectWordBlocks($child, $lines);
        }
    }

    private function collectParagraphText(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            return (string) $node->nodeValue;
        }
        if (! $node instanceof DOMElement) {
            return '';
        }
        return match ($node->localName) {
            'tab'      => "\t",
            'br', 'cr' => "\n",
            'delText', 'instrText' => '',
            default    => $this->joinChildText($node),
        };
    }

    private function joinChildText(DOMElement $element): string
    {
        $out = '';
        foreach ($element->childNodes as $child) {
            $out .= $this->collectParagraphText($child);
        }
        return $out;
    }

    private function extractPdf(string $path): string
    {
        if ($this->pdfRunner !== null) {
            $output = (string) ($this->pdfRunner)($path);
        } else {
            $binary = $this->findPdfToTextBinary();
            if ($binary === null) {
                throw new RuntimeException(
                    'PDF text extraction tool (pdftotext) is not installed on this server. Paste the survey text directly or upload a .docx or .txt file instead.',
                    422
                );
            }
            $output = $this->runPdfToText($binary, $path);
        }

        if (str_contains($output, "\0") || ! mb_check_encoding($output, 'UTF-8')) {
            throw new RuntimeException('Extracted PDF text had invalid encoding. Copy and paste the text directly instead.', 422);
        }

        $trimmed = trim($output);
        if ($trimmed === '') {
            throw new RuntimeException(
                'No readable text was found inside this PDF (it may be a scanned image PDF). Paste the survey questions directly or upload a .docx/.txt file.',
                422
            );
        }

        return $trimmed;
    }

    private function findPdfToTextBinary(): ?string
    {
        $configured = getenv('SURVEY_PDFTOTEXT_PATH');
        if (is_string($configured) && $configured !== '') {
            $real = realpath($configured);
            return ($real !== false && is_file($real) && ! is_link($real)) ? $real : null;
        }

        $cmd = PHP_OS_FAMILY === 'Windows' ? ['where.exe', 'pdftotext'] : ['which', 'pdftotext'];
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $spec, $pipes, null, null, ['bypass_shell' => true]);
        if (! is_resource($proc)) {
            return null;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1], 1024);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);
        if ($status !== 0 || ! is_string($stdout)) {
            return null;
        }
        foreach (preg_split('/\r?\n/', trim($stdout)) as $line) {
            $candidate = trim((string) $line);
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function runPdfToText(string $binary, string $path): string
    {
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open(
            [$binary, '-enc', 'UTF-8', '-nopgbrk', $path, '-'],
            $spec,
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (! is_resource($proc)) {
            throw new RuntimeException('Unable to launch PDF text extraction process.', 422);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout   = '';
        $stderr   = '';
        $deadline = microtime(true) + self::PDF_TIMEOUT_SEC;

        while (true) {
            $status = proc_get_status($proc);
            $stdoutChunk = stream_get_contents($pipes[1], 65536);
            if (is_string($stdoutChunk) && $stdoutChunk !== '') {
                $stdout .= $stdoutChunk;
                if (strlen($stdout) > 524288) {
                    $stdout = substr($stdout, 0, 524288);
                    proc_terminate($proc);
                    break;
                }
            }
            $stderrChunk = stream_get_contents($pipes[2], 8192);
            if (is_string($stderrChunk) && $stderrChunk !== '') {
                $stderr .= $stderrChunk;
            }

            if (! $status['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($proc);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                throw new RuntimeException('PDF text extraction timed out. Try pasting the survey text or uploading a .docx/.txt file.', 422);
            }
            usleep(20000);
        }

        $tail = stream_get_contents($pipes[1], 65536);
        if (is_string($tail) && strlen($stdout) < 524288) {
            $stdout .= $tail;
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        if ($exit !== 0 && trim($stdout) === '') {
            throw new RuntimeException('The uploaded PDF could not be read. Try pasting the questions or uploading a .docx/.txt file.', 422);
        }

        return $stdout;
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function normalizeText(string $text, int $maxBytes): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? $text;
        $text = preg_replace("/\n{4,}/", "\n\n\n", $text) ?? $text;
        $text = trim($text);
        $truncated = false;
        if (strlen($text) > $maxBytes) {
            $text      = rtrim(mb_strcut($text, 0, $maxBytes, 'UTF-8'));
            $truncated = true;
        }
        return [$text, $truncated];
    }
}
