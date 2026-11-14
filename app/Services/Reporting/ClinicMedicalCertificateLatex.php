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

        $template = <<<'TEX'
\documentclass[11pt,a4paper]{article}
\pdfminorversion=4
\usepackage[top=20mm,bottom=20mm,left=20mm,right=20mm]{geometry}
\usepackage[T1]{fontenc}
\IfFileExists{t1ptm.fd}{\renewcommand{\rmdefault}{ptm}}{\renewcommand{\rmdefault}{utm}}
\DeclareSymbolFont{operators}{T1}{\rmdefault}{m}{n}
\SetSymbolFont{operators}{bold}{T1}{\rmdefault}{b}{n}
\usepackage{graphicx,xcolor,tabularx}
\definecolor{Maroon}{HTML}{800000}
\definecolor{Ink}{HTML}{293241}
\definecolor{Muted}{HTML}{606975}
\color{Ink}
\setlength{\parindent}{0pt}
\setlength{\parskip}{6pt}
\pdfinfo{/Title (University Clinic Medical Slip) /Author (Foundation University)}
\begin{document}
\begin{minipage}[c]{14mm}
  \includegraphics[height=13mm]{university-seal.png}
\end{minipage}
\begin{minipage}[c]{\dimexpr\linewidth-18mm\relax}
  {\bfseries\large\color{Maroon}FOUNDATION UNIVERSITY}\par
  {\small\bfseries UNIVERSITY HEALTH CLINIC \textperiodcentered{} MEDICAL CONSULTATION CERTIFICATE}
\end{minipage}
\par\vspace{2mm}{\color{Maroon}\hrule height 0.8pt}\par\vspace{6mm}

{\bfseries Reference:} CLINIC-ENC-__ENC_ID__ \hfill {\small\color{Muted}Issued via SYNAPSE}
\par\vspace{4mm}
To Whom It May Concern:
\par
This is to certify that \textbf{__FULL_NAME__} (University ID: \textbf{__MIS_ID__}) was seen and evaluated at the Foundation University Clinic.

\vspace{2mm}
\begin{tabularx}{\textwidth}{@{}l X@{}}
\textbf{Visit Date/Time:} & __VISIT_AT__ \\
\textbf{Chief Complaint:} & __COMPLAINT__ \\
\textbf{Clinical Impression:} & __DIAGNOSIS__ \\
\textbf{Disposition / Recommendation:} & __DISPOSITION__ --- __NOTES__ \\
\end{tabularx}

\vspace{12mm}
\begin{minipage}[t]{0.55\textwidth}
\rule{60mm}{0.5pt}\\
{\bfseries __SIGNER__}\\
{\small University Clinic Personnel / Attending Clinician}
\end{minipage}
\hfill
\begin{minipage}[t]{0.38\textwidth}
{\small\color{Muted}This certificate reflects the clinical encounter record \#__ENC_ID__ and is intended for academic excuse or return-to-class verification.}
\end{minipage}
\end{document}
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

        $template = <<<'TEX'
\documentclass[11pt,a4paper]{article}
\pdfminorversion=4
\usepackage[top=20mm,bottom=20mm,left=20mm,right=20mm]{geometry}
\usepackage[T1]{fontenc}
\IfFileExists{t1ptm.fd}{\renewcommand{\rmdefault}{ptm}}{\renewcommand{\rmdefault}{utm}}
\DeclareSymbolFont{operators}{T1}{\rmdefault}{m}{n}
\SetSymbolFont{operators}{bold}{T1}{\rmdefault}{b}{n}
\usepackage{graphicx,xcolor,tabularx}
\definecolor{Maroon}{HTML}{800000}
\definecolor{Ink}{HTML}{293241}
\definecolor{Muted}{HTML}{606975}
\color{Ink}
\setlength{\parindent}{0pt}
\setlength{\parskip}{6pt}
\pdfinfo{/Title (Clinic Enrollment Medical Clearance) /Author (Foundation University)}
\begin{document}
\begin{minipage}[c]{14mm}
  \includegraphics[height=13mm]{university-seal.png}
\end{minipage}
\begin{minipage}[c]{\dimexpr\linewidth-18mm\relax}
  {\bfseries\large\color{Maroon}FOUNDATION UNIVERSITY}\par
  {\small\bfseries UNIVERSITY HEALTH CLINIC \textperiodcentered{} ANNUAL / ENROLLMENT HEALTH CLEARANCE}
\end{minipage}
\par\vspace{2mm}{\color{Maroon}\hrule height 0.8pt}\par\vspace{6mm}

{\bfseries Clearance Ref:} CLINIC-CLR-__CID__ \hfill {\bfseries School Year:} __SY__ (__TERM__)
\par\vspace{4mm}
This is to certify the University Clinic health examination record of \textbf{__FULL_NAME__} (University ID: \textbf{__MIS_ID__}).

\vspace{2mm}
\begin{tabularx}{\textwidth}{@{}l X@{}}
\textbf{Clearance Status:} & \textbf{__STATUS__} \\
\textbf{Remarks:} & __REMARKS__ \\
\end{tabularx}

\vspace{12mm}
\rule{60mm}{0.5pt}\\
{\bfseries __SIGNER__}\\
{\small University Clinic Health Officer / Attending Nurse}
\end{document}
TEX;

        return strtr($template, $replacements);
    }
}
