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

        $formatRows = static function (array $rows): string {
            $out = '';
            foreach ($rows as $cells) {
                $out .= implode(' & ', $cells) . " \\\\ \\hline\n";
            }
            return rtrim($out, "\n");
        };

        $monthlyData = [];
        foreach ($summary['monthly_series'] as $m) {
            $monthlyData[] = [$e($m['label']), (int) $m['count']];
        }
        $monthlyRows = $formatRows($monthlyData ?: [['No encounters in selected period', 0]]);

        $complaintData = [];
        foreach (array_slice($summary['complaints'], 0, 12, true) as $label => $cnt) {
            $complaintData[] = [$e((string) $label), (int) $cnt];
        }
        $complaintRows = $formatRows($complaintData ?: [['No chief complaints recorded', 0]]);

        $dispositionData = [];
        foreach ($summary['dispositions'] as $label => $cnt) {
            $dispositionData[] = [$e((string) $label), (int) $cnt];
        }
        $dispositionRows = $formatRows($dispositionData ?: [['No dispositions recorded', 0]]);

        $medicineData = [];
        foreach ($summary['top_medicines'] as $med) {
            $medicineData[] = [$e($med['name']), (int) $med['encounter_count'], (int) $med['total_qty'] . ' ' . $e($med['unit'])];
        }
        $medicineRows = $formatRows($medicineData ?: [['No FEFO dispensations in selected period', 0, 0]]);

        $isolationData = [];
        foreach ($summary['isolation_summary'] as $iso) {
            $isolationData[] = [$e($iso['label']), (int) $iso['count']];
        }
        $isolationRows = $formatRows($isolationData ?: [['No communicable isolation cases logged', 0]]);

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
\usepackage{graphicx,xcolor,tabularx}
\definecolor{Maroon}{HTML}{800000}
\definecolor{Ink}{HTML}{293241}
\definecolor{Muted}{HTML}{606975}
\color{Ink}
\setlength{\parindent}{0pt}
\setlength{\parskip}{4pt}
\renewcommand{\arraystretch}{1.35}
\pdfinfo{/Title (University Clinic Summary Report) /Author (Foundation University)}
\newcommand{\SectionHeading}[1]{\par\addvspace{4mm}\noindent{\bfseries\color{Maroon}#1}\par\nobreak\vspace{1.5mm}}
\begin{document}
\noindent\begin{minipage}[c]{14mm}
  \includegraphics[height=12mm]{university-seal.png}
\end{minipage}\hspace{4mm}%
\begin{minipage}[c]{\dimexpr\linewidth-18mm\relax}
  {\bfseries\large\color{Maroon}FOUNDATION UNIVERSITY --- UNIVERSITY CLINIC}\par
  {\small\color{Muted}SYNAPSE Health Services \textperiodcentered{} Periodic \& CHED Spot-Check Summary Report}
\end{minipage}
\par\vspace{2mm}{\color{Maroon}\hrule height 0.8pt}\par\vspace{3mm}
{\bfseries Period:} __PERIOD_LABEL__ \hfill {\small\color{Muted}SY __SY__ \textperiodcentered{} Asia/Manila}
\par\vspace{3mm}
\begin{tabularx}{\textwidth}{|X|X|X|}
\hline
Total Visits \hfill \textbf{__ENCOUNTERS__} & Completed \hfill \textbf{__COMPLETED__} & Students \hfill \textbf{__STUDENT__} \\
\hline
Employees \hfill \textbf{__EMPLOYEE__} & Referred \hfill \textbf{__REFERRED__} & Communicable Cases \hfill \textbf{__ISOLATION__} \\
\hline
\end{tabularx}

\SectionHeading{Monthly Encounter Breakdown}
\begin{tabularx}{\textwidth}{|X|r|}
\hline
\textcolor{Maroon}{\textbf{Month}} & \textcolor{Maroon}{\textbf{Encounters}} \\
\hline\hline
__MONTHLY_ROWS__
\end{tabularx}

\SectionHeading{Chief Complaints \& Clinical Dispositions}
{\small\color{Muted}\textit{Aggregated independently below for readability.}}\par\vspace{2mm}
\noindent\begin{minipage}[t]{0.48\textwidth}
\begin{tabularx}{\linewidth}[t]{|X|r|}
\hline
\textcolor{Maroon}{\textbf{Chief Complaint}} & \textcolor{Maroon}{\textbf{Visits}} \\
\hline\hline
__COMPLAINT_ROWS__
\end{tabularx}
\end{minipage}\hfill%
\begin{minipage}[t]{0.48\textwidth}
\begin{tabularx}{\linewidth}[t]{|X|r|}
\hline
\textcolor{Maroon}{\textbf{Disposition}} & \textcolor{Maroon}{\textbf{Visits}} \\
\hline\hline
__DISPOSITION_ROWS__
\end{tabularx}
\end{minipage}

\SectionHeading{Top Dispensed Medicines (FEFO Ledger)}
\begin{tabularx}{\textwidth}{|X|r|r|}
\hline
\textcolor{Maroon}{\textbf{Medicine}} & \textcolor{Maroon}{\textbf{Encounters}} & \textcolor{Maroon}{\textbf{Quantity Dispensed}} \\
\hline\hline
__MEDICINE_ROWS__
\end{tabularx}

\SectionHeading{Communicable Disease \& Enrollment Health Clearance Summary (SY __SY__)}
\begin{tabularx}{\textwidth}{|X|r|}
\hline
\textcolor{Maroon}{\textbf{Reported Condition (Selected Period)}} & \textcolor{Maroon}{\textbf{Cases}} \\
\hline\hline
__ISOLATION_ROWS__
\end{tabularx}
\par\vspace{2.5mm}
{\small\bfseries\color{Ink}SY __SY__ Enrollment Clearances}\par\nobreak\vspace{1mm}
\begin{tabularx}{\textwidth}{|>{\centering\arraybackslash}X|>{\centering\arraybackslash}X|>{\centering\arraybackslash}X|}
\hline
\textcolor{Maroon}{\textbf{Cleared}} & \textcolor{Maroon}{\textbf{Pending}} & \textcolor{Maroon}{\textbf{Hold for Follow-Up}} \\
\hline\hline
\textbf{__CLEARED__} & \textbf{__PENDING_CLR__} & \textbf{__HOLD_CLR__} \\
\hline
\end{tabularx}
\par\vfill
{\scriptsize\color{Muted}Generated by SYNAPSE \textperiodcentered{} Aggregate operational data only \textperiodcentered{} No individual patient identifiers.}
\end{document}
TEX;

        return strtr($template, $replacements);
    }
}
