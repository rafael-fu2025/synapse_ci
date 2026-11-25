<?php

declare(strict_types=1);

namespace App\Services\Reporting;

/** Clinic Medical Certificate / Excuse & Enrollment Clearance Slip PDF. */
final class ClinicMedicalCertificateLatex
{
    public static function forEncounter(array $details, string $issuedBy): string
    {
        $e = static fn (string $v): string => LatexReportCompiler::escapeLatex($v);
        $p = $details['patient'];
        $enc = $details['encounter'];
        $treat = $details['treatment'] ?? [];

        $replacements = [
            '__ENC_ID__'     => (string) (int) $enc['id'],
            '__FULL_NAME__'  => $e(trim(($p['last_name'] ?? '') . ', ' . ($p['first_name'] ?? '') . ' ' . ($p['middle_name'] ?? ''))),
            '__MIS_ID__'     => $e((string) ($p['mis_id'] ?? '--')),
            '__VISIT_AT__'   => $e((string) ($enc['visited_at'] ?? $enc['started_at'] ?? '--') . ' UTC'),
            '__COMPLAINT__'  => $e((string) ($enc['chief_complaint'] ?? 'Consultation')),
            '__DIAGNOSIS__'  => $e((string) ($treat['diagnosis'] ?? 'Clinically evaluated at University Clinic')),
            '__DISPOSITION__' => $e(ucwords(str_replace('_', ' ', (string) ($enc['disposition'] ?? 'discharged')))),
            '__NOTES__'      => $e((string) ($enc['disposition_notes'] ?? 'Advised rest / medical follow-up as indicated.')),
            '__SIGNER__'     => $e((string) ($details['attending_user_name'] ?? $issuedBy)),
        ];

        $colors = DocumentHeaderLatex::colorDefinitions();
        $fancy = DocumentHeaderLatex::fancyHeaderFooter(
            'University Health Clinic',
            '\\textbf{Document Ref:} CLINIC-ENC-__ENC_ID__',
            '\\textbf{Issued via SYNAPSE} \\quad \\textit{Foundation University Health Clinic}',
            'Page \\thepage'
        );
        $header = DocumentHeaderLatex::headerContainer(
            'clinic',
            'UNIVERSITY HEALTH CLINIC',
            'Medical Consultation Certificate',
            '\\textbf{Reference:} CLINIC-ENC-__ENC_ID__ \\quad \\textbf{Issued via:} SYNAPSE'
        );

        $template = <<<TEX
\\documentclass[10pt,a4paper]{article}
\\pdfminorversion=4
\\usepackage[margin=18mm,top=20mm,bottom=22mm,headheight=14pt,headsep=6mm]{geometry}
\\usepackage[T1]{fontenc}
\\IfFileExists{t1ptm.fd}{\\renewcommand{\\rmdefault}{ptm}}{\\renewcommand{\\rmdefault}{utm}}
\\DeclareSymbolFont{operators}{T1}{\\rmdefault}{m}{n}
\\SetSymbolFont{operators}{bold}{T1}{\\rmdefault}{b}{n}
\\usepackage{graphicx,xcolor,tabularx,fancyhdr}
\\definecolor{Maroon}{HTML}{800000}
\\definecolor{Ink}{HTML}{293241}
\\definecolor{Muted}{HTML}{606975}
{$colors}
\\color{Ink}
\\setlength{\\parindent}{0pt}
\\setlength{\\parskip}{6pt}
\\renewcommand{\\arraystretch}{1.25}
\\pdfinfo{/Title (University Clinic Medical Slip) /Author (Foundation University)}
{$fancy}
\\begin{document}
{$header}

To Whom It May Concern:
\\par
This is to certify that \\textbf{__FULL_NAME__} (University ID: \\textbf{__MIS_ID__}) was seen and evaluated at the Foundation University Clinic.

\\vspace{2mm}
\\noindent\\begin{tabularx}{\\textwidth}{|l|X|}
\\hline
\\textbf{Reference No:} & CLINIC-ENC-__ENC_ID__ \\\\ \\hline
\\textbf{Visit Date/Time:} & __VISIT_AT__ \\\\ \\hline
\\textbf{Chief Complaint:} & __COMPLAINT__ \\\\ \\hline
\\textbf{Clinical Impression:} & __DIAGNOSIS__ \\\\ \\hline
\\textbf{Disposition / Recommendation:} & __DISPOSITION__ --- __NOTES__ \\\\ \\hline
\\end{tabularx}

\\vspace{12mm}
\\noindent\\begin{minipage}[t]{0.55\\textwidth}
\\rule{60mm}{0.5pt}\\\\
{\\bfseries __SIGNER__}\\\\
{\\small University Clinic Personnel / Attending Clinician}
\\end{minipage}
\\hfill
\\begin{minipage}[t]{0.38\\textwidth}
{\\small\\color{Muted}This certificate reflects clinical encounter record \\#__ENC_ID__ and is intended for academic excuse or return-to-class verification.}
\\end{minipage}

\\vfill
\\begin{center}
\\footnotesize\\textit{CONFIDENTIALITY NOTICE: This medical record is protected under RA 10173 (Data Privacy Act of 2012). Unauthorized disclosure or reproduction is strictly prohibited.}
\\end{center}
\\end{document}
TEX;

        return strtr($template, $replacements);
    }

    public static function forClearance(array $person, array $clearance, string $issuedBy): string
    {
        $e = static fn (string $v): string => LatexReportCompiler::escapeLatex($v);

        $replacements = [
            '__CID__'       => (string) (int) ($clearance['id'] ?? 0),
            '__FULL_NAME__' => $e(trim(($person['last_name'] ?? '') . ', ' . ($person['first_name'] ?? '') . ' ' . ($person['middle_name'] ?? ''))),
            '__MIS_ID__'    => $e((string) ($person['mis_id'] ?? '--')),
            '__SY__'        => $e((string) ($clearance['school_year'] ?? '')),
            '__TERM__'      => $e((string) ($clearance['semester'] ?? '')),
            '__STATUS__'    => $e(strtoupper(str_replace('_', ' ', (string) ($clearance['clearance_status'] ?? 'pending')))),
            '__REMARKS__'   => $e((string) ($clearance['remarks'] ?? 'Required enrollment health examination and laboratories reviewed.')),
            '__SIGNER__'    => $e((string) ($clearance['cleared_by_name'] ?? $issuedBy)),
        ];

        $colors = DocumentHeaderLatex::colorDefinitions();
        $fancy = DocumentHeaderLatex::fancyHeaderFooter(
            'University Health Clinic',
            '\\textbf{Clearance Ref:} CLINIC-CLR-__CID__',
            '\\textbf{Issued via SYNAPSE} \\quad \\textit{Foundation University Health Clinic}',
            'Page \\thepage'
        );
        $header = DocumentHeaderLatex::headerContainer(
            'clinic',
            'UNIVERSITY HEALTH CLINIC',
            'Annual / Enrollment Health Clearance',
            '\\textbf{Clearance Ref:} CLINIC-CLR-__CID__ \\quad \\textbf{School Year:} __SY__ (__TERM__)'
        );

        $template = <<<TEX
\\documentclass[10pt,a4paper]{article}
\\pdfminorversion=4
\\usepackage[margin=18mm,top=20mm,bottom=22mm,headheight=14pt,headsep=6mm]{geometry}
\\usepackage[T1]{fontenc}
\\IfFileExists{t1ptm.fd}{\\renewcommand{\\rmdefault}{ptm}}{\\renewcommand{\\rmdefault}{utm}}
\\DeclareSymbolFont{operators}{T1}{\\rmdefault}{m}{n}
\\SetSymbolFont{operators}{bold}{T1}{\\rmdefault}{b}{n}
\\usepackage{graphicx,xcolor,tabularx,fancyhdr}
\\definecolor{Maroon}{HTML}{800000}
\\definecolor{Ink}{HTML}{293241}
\\definecolor{Muted}{HTML}{606975}
{$colors}
\\color{Ink}
\\setlength{\\parindent}{0pt}
\\setlength{\\parskip}{6pt}
\\renewcommand{\\arraystretch}{1.25}
\\pdfinfo{/Title (Clinic Enrollment Medical Clearance) /Author (Foundation University)}
{$fancy}
\\begin{document}
{$header}

This is to certify the University Clinic health examination record of \\textbf{__FULL_NAME__} (University ID: \\textbf{__MIS_ID__}).

\\vspace{2mm}
\\noindent\\begin{tabularx}{\\textwidth}{|l|X|}
\\hline
\\textbf{Clearance Ref:} & CLINIC-CLR-__CID__ \\\\ \\hline
\\textbf{School Year / Term:} & __SY__ (__TERM__) \\\\ \\hline
\\textbf{Clearance Status:} & \\textbf{__STATUS__} \\\\ \\hline
\\textbf{Remarks:} & __REMARKS__ \\\\ \\hline
\\end{tabularx}

\\vspace{12mm}
\\rule{60mm}{0.5pt}\\\\
{\\bfseries __SIGNER__}\\\\
{\\small University Clinic Health Officer / Attending Nurse}

\\vfill
\\begin{center}
\\footnotesize\\textit{CONFIDENTIALITY NOTICE: This health clearance record is protected under RA 10173 (Data Privacy Act of 2012). Unauthorized disclosure or reproduction is strictly prohibited.}
\\end{center}
\\end{document}
TEX;

        return strtr($template, $replacements);
    }
}
