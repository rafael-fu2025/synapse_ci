<?php

declare(strict_types=1);

namespace App\Services\Reporting;

/** Server-owned CHED & School-Year University Clinic Summary PDF template. */
final class ClinicSummaryReportLatex
{
    public static function source(array $summary): string
    {
        $e = static fn (string $v): string => LatexReportCompiler::escapeLatex($v);
        $period = $summary['period'];
        $totals = $summary['totals'];
        $periodLabel = $e($period['label'] . ' (' . $period['from'] . ' to ' . $period['to'] . ')');

        $monthlyRows = '';
        foreach ($summary['monthly_series'] as $m) {
            $monthlyRows .= $e($m['label']) . ' & ' . (int) $m['count'] . " \\\\\n";
        }
        if ($monthlyRows === '') {
            $monthlyRows = "No encounters in selected period & 0 \\\\\n";
        }

        $complaintRows = '';
        foreach (array_slice($summary['complaints'], 0, 12, true) as $label => $cnt) {
            $complaintRows .= $e((string) $label) . ' & ' . (int) $cnt . " \\\\\n";
        }
        if ($complaintRows === '') {
            $complaintRows = "No chief complaints recorded & 0 \\\\\n";
        }

        $dispositionRows = '';
        foreach ($summary['dispositions'] as $label => $cnt) {
            $dispositionRows .= $e((string) $label) . ' & ' . (int) $cnt . " \\\\\n";
        }
        if ($dispositionRows === '') {
            $dispositionRows = "No dispositions recorded & 0 \\\\\n";
        }

        $medicineRows = '';
        foreach ($summary['top_medicines'] as $med) {
            $medicineRows .= $e($med['name']) . ' & ' . (int) $med['encounter_count'] . ' & ' . (int) $med['total_qty'] . ' ' . $e($med['unit']) . " \\\\\n";
        }
        if ($medicineRows === '') {
            $medicineRows = "No FEFO dispensations in selected period & 0 & 0 \\\\\n";
        }

        $isolationRows = '';
        foreach ($summary['isolation_summary'] as $iso) {
            $isolationRows .= $e($iso['label']) . ' & ' . (int) $iso['count'] . " \\\\\n";
        }
        if ($isolationRows === '') {
            $isolationRows = "No communicable isolation cases logged & 0 \\\\\n";
        }

        $replacements = [
            '__PERIOD_LABEL__'     => $periodLabel,
            '__SY__'               => $e($summary['school_year']),
            '__ENCOUNTERS__'       => (string) (int) $totals['encounters'],
            '__COMPLETED__'        => (string) (int) $totals['completed'],
            '__STUDENT__'          => (string) (int) $totals['student'],
            '__EMPLOYEE__'         => (string) (int) $totals['employee'],
            '__REFERRED__'         => (string) (int) $totals['referred'],
            '__ISOLATION__'        => (string) (int) $totals['isolation'],
            '__MONTHLY_ROWS__'     => $monthlyRows,
            '__COMPLAINT_ROWS__'   => $complaintRows,
            '__DISPOSITION_ROWS__' => $dispositionRows,
            '__MEDICINE_ROWS__'    => $medicineRows,
            '__ISOLATION_ROWS__'   => $isolationRows,
            '__CLEARED__'          => (string) (int) $summary['clearances']['cleared'],
            '__PENDING_CLR__'      => (string) (int) $summary['clearances']['pending'],
            '__HOLD_CLR__'         => (string) (int) $summary['clearances']['hold_followup'],
        ];

        $template = <<<'TEX'
\documentclass[10pt,a4paper]{article}
\pdfminorversion=4
\usepackage[top=16mm,bottom=18mm,left=18mm,right=18mm]{geometry}
\usepackage[T1]{fontenc}
\IfFileExists{t1ptm.fd}{\renewcommand{\rmdefault}{ptm}}{\renewcommand{\rmdefault}{utm}}
\DeclareSymbolFont{operators}{T1}{\rmdefault}{m}{n}
\SetSymbolFont{operators}{bold}{T1}{\rmdefault}{b}{n}
\usepackage{graphicx,xcolor,booktabs,tabularx}
\definecolor{Maroon}{HTML}{800000}
\definecolor{Ink}{HTML}{293241}
\definecolor{Muted}{HTML}{606975}
\color{Ink}
\setlength{\parindent}{0pt}
\setlength{\parskip}{4pt}
\renewcommand{\arraystretch}{1.2}
\pdfinfo{/Title (University Clinic Summary Report) /Author (Foundation University)}
\begin{document}
\begin{minipage}[c]{14mm}
  \includegraphics[height=12mm]{university-seal.png}
\end{minipage}
\begin{minipage}[c]{\dimexpr\linewidth-18mm\relax}
  {\bfseries\large\color{Maroon}FOUNDATION UNIVERSITY --- UNIVERSITY CLINIC}\par
  {\small\color{Muted}SYNAPSE Health Services \textperiodcentered{} Periodic \& CHED Spot-Check Summary Report}
\end{minipage}
\par\vspace{2mm}{\color{Maroon}\hrule height 0.8pt}\par\vspace{3mm}
{\bfseries Period:} __PERIOD_LABEL__ \hfill {\small\color{Muted}SY __SY__ \textperiodcentered{} Asia/Manila}
\par\vspace{3mm}
\begin{tabularx}{\textwidth}{@{}l r l r l r@{}}
\toprule
Total Visits & \textbf{__ENCOUNTERS__} & Completed & \textbf{__COMPLETED__} & Students & \textbf{__STUDENT__} \\
Employees & \textbf{__EMPLOYEE__} & Referred & \textbf{__REFERRED__} & Communicable Cases & \textbf{__ISOLATION__} \\
\bottomrule
\end{tabularx}

\vspace{4mm}
{\bfseries\color{Maroon}Monthly Encounter Breakdown}
\begin{tabularx}{\textwidth}{@{}X r@{}}
\toprule
Month & Encounters \\
\midrule
__MONTHLY_ROWS__
\bottomrule
\end{tabularx}

\vspace{4mm}
{\bfseries\color{Maroon}Chief Complaints \& Clinical Dispositions}
\begin{tabularx}{\textwidth}{@{}X r | X r@{}}
\toprule
Complaint Category & Count & Clinical Disposition & Count \\
\midrule
\multicolumn{4}{@{}l@{}}{\small\textit{(Aggregated independently below for readability)}} \\
\bottomrule
\end{tabularx}

\begin{minipage}[t]{0.48\textwidth}
\begin{tabularx}{\linewidth}{@{}X r@{}}
\toprule
Chief Complaint & Visits \\
\midrule
__COMPLAINT_ROWS__
\bottomrule
\end{tabularx}
\end{minipage}
\hfill
\begin{minipage}[t]{0.48\textwidth}
\begin{tabularx}{\linewidth}{@{}X r@{}}
\toprule
Disposition & Visits \\
\midrule
__DISPOSITION_ROWS__
\bottomrule
\end{tabularx}
\end{minipage}

\vspace{4mm}
{\bfseries\color{Maroon}Top Dispensed Medicines (FEFO Ledger)}
\begin{tabularx}{\textwidth}{@{}X r r@{}}
\toprule
Medicine & Encounters & Quantity Dispensed \\
\midrule
__MEDICINE_ROWS__
\bottomrule
\end{tabularx}

\vspace{4mm}
{\bfseries\color{Maroon}Communicable Disease \& Enrollment Health Clearance Summary (SY __SY__)}
\begin{tabularx}{\textwidth}{@{}X r@{}}
\toprule
Reported Condition (Selected Period) & Cases \\
\midrule
__ISOLATION_ROWS__
\bottomrule
\end{tabularx}
\par\vspace{2mm}
{\small\textbf{SY __SY__ Enrollment Clearances:} Cleared: __CLEARED__ \quad Pending: __PENDING_CLR__ \quad Hold for Follow-Up: __HOLD_CLR__}
\vfill
{\scriptsize\color{Muted}Generated by SYNAPSE \textperiodcentered{} Aggregate operational data only \textperiodcentered{} No individual patient identifiers.}
\end{document}
TEX;

        return strtr($template, $replacements);
    }
}
