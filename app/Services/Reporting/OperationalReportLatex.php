<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use DateTimeImmutable;

/** Server-owned template: validated aggregates with all display labels escaped as plain text. */
final class OperationalReportLatex
{
    public static function source(array $data, array $groups, string $range, ?array $clinicInsights = null): string
    {
        $hasClinicInsights = isset($groups['Clinic']) && $clinicInsights !== null;
        $totalPages = count($groups) * 2 + (count($groups) > 1 ? 1 : 0) + ($hasClinicInsights ? 2 : 0);
        $source = <<<'TEX'
\documentclass[10pt,a4paper]{article}
\pdfminorversion=4
\usepackage[top=16mm,bottom=20mm,left=18mm,right=18mm,footskip=10mm]{geometry}
\usepackage{graphicx,xcolor,booktabs,tabularx,pgfplots,microtype}
\pgfplotsset{compat=1.18}
\definecolor{Maroon}{HTML}{800000}
\definecolor{Ink}{HTML}{293241}
\definecolor{Muted}{HTML}{606975}
\definecolor{Panel}{HTML}{F4F4F6}
\definecolor{Clinic}{HTML}{800000}
\definecolor{Guidance}{HTML}{21598C}
\definecolor{Started}{HTML}{296E5C}
\definecolor{Released}{HTML}{8F5C14}
\definecolor{Voided}{HTML}{665C7A}
\color{Ink}
\setlength{\parindent}{0pt}
\setlength{\parskip}{5pt}
\setlength{\emergencystretch}{2em}
\renewcommand{\arraystretch}{1.2}
\renewcommand{\labelitemi}{\textcolor{Maroon}{\rule{0.7mm}{0.7mm}}}
\pdfinfo{/Title (SYNAPSE Operational Report) /Author (Foundation University)}
\newcommand{\ReportHeader}[3]{%
  \begin{minipage}[c]{22mm}\includegraphics[width=20mm,height=20mm,keepaspectratio]{university-seal.png}\end{minipage}%
  \hfill\begin{minipage}[c]{\dimexpr\linewidth-26mm\relax}
    {\sffamily\bfseries\large\color{Maroon}FOUNDATION UNIVERSITY}\par
    {\sffamily\small\color{Muted}SYNAPSE\enspace/\enspace Operational Reporting}
  \end{minipage}\par\vspace{3mm}
  {\color{Maroon}\hrule height 0.8pt}\vspace{4mm}
  {\sffamily\bfseries\scriptsize\color{Maroon}#1}\par\vspace{1mm}
  {\sffamily\bfseries\LARGE #2}\par\vspace{2mm}
  {\small\textbf{Reporting period:} #3\hfill\textcolor{Muted}{Asia/Manila (inclusive)}}\par\vspace{4mm}
}
\newcommand{\Section}[1]{\par\vspace{3mm}{\sffamily\bfseries\small #1}\par\vspace{1mm}}
\newlength{\cardwidth}
\setlength{\fboxsep}{3mm}
\setlength{\cardwidth}{\dimexpr(\textwidth-12mm)/3-6mm\relax}
\newcommand{\MetricCard}[3]{%
  \colorbox{Panel}{\begin{minipage}[t][24mm][t]{\cardwidth}
    {\sffamily\bfseries\small #1}\par\vspace{1mm}
    {\sffamily\bfseries\Large\color{Maroon}#2}\par
    {\sffamily\scriptsize\color{Muted}#3}
  \end{minipage}}%
}
\makeatletter
\def\ps@synapse{%
  \def\@oddhead{}\def\@evenhead{}%
  \def\@oddfoot{\parbox{\textwidth}{\color{Muted}\hrule\vspace{2mm}%
    \sffamily\scriptsize SYNAPSE\enspace/\enspace Authorized aggregate report\hfill Page \thepage{} of TOTALPAGES\par
    No names, diagnoses, or person-level records.}}
  \let\@evenfoot\@oddfoot
}
\makeatother
\pagestyle{synapse}
\begin{document}
TEX;
        $source = str_replace('TOTALPAGES', (string) $totalPages, $source);
        if (count($groups) > 1) {
            $source .= self::header('EXECUTIVE SUMMARY', 'Operational report', $range);
            $source .= "\n\\Section{Department results}\nEach department is reported independently. There is no combined activity total.\n";
            $page = 2;
            foreach ($groups as $unit => $metrics) {
                $departmentPages = $unit === 'Clinic' && $hasClinicInsights ? 4 : 2;
                $source .= '\Section{' . self::escape($unit) . '\hfill\normalfont\scriptsize Pages ' . $page . '--' . ($page + $departmentPages - 1) . "}\n";
                $source .= "\\begin{tabularx}{\\linewidth}{@{}Xr@{}}\\toprule\n\\textbf{Measure} & \\textbf{Period total} \\\\ \\midrule\n";
                foreach ($metrics as $metric => [$label]) $source .= self::escape($label) . ' & ' . number_format(array_sum(array_column($data, $metric))) . " \\\\\n";
                $source .= "\\bottomrule\\end{tabularx}\n\\vspace{4mm}\n";
                $page += $departmentPages;
            }
            if ($hasClinicInsights) {
                $source .= "\n{\\small Clinic also includes a visit profile and medicine insights before its daily register.}\n";
            }
            $source .= <<<'TEX'
\Section{How to use this report}
The following department sections each contain an analysis page and a daily register.
Charts describe independent measures, with exact daily values provided in the tables.
\Section{Scope and interpretation}
Only authorized departments are included. Clinic and Guidance figures count completed
care activities, not unique people. BMG figures describe separate lifecycle events;
they must not be added together or interpreted as a cohort success rate.
\newpage
TEX;
        }
        foreach ($groups as $unit => $metrics) {
            $source .= self::header('DEPARTMENT ANALYSIS', $unit . ' operational results', $range);
            $source .= "\n\\Section{01\\enspace Performance summary}\n";
            $cards = [];
            if (count($metrics) === 1) {
                $metric = array_key_first($metrics);
                $values = array_column($data, $metric);
                $cards = [
                    ['Completed activity', number_format(array_sum($values)), $metrics[$metric][0]],
                    ['Daily average', number_format(array_sum($values) / count($values), 1), 'Across all reporting days'],
                    ['Days with activity', count(array_filter($values)) . ' / ' . count($values), 'At least one matching record'],
                ];
            } else {
                foreach ($metrics as $metric => [$label]) {
                    $values = array_column($data, $metric);
                    $cards[] = [$label, number_format(array_sum($values)), 'Average/day: ' . number_format(array_sum($values) / count($values), 1)];
                }
            }
            foreach ($cards as $index => [$label, $value, $caption]) {
                if ($index > 0) $source .= '\hfill';
                $source .= '\MetricCard{' . self::escape($label) . '}{' . self::escape($value) . '}{' . self::escape($caption) . "}%\n";
            }
            $source .= "\n\\par\\Section{02\\enspace Daily activity}\n{\\small Each measure has its own labelled scale; counts must not be combined.}\\par\n";
            foreach ($metrics as $metric => [$label]) $source .= self::chart($data, $metric, $label, count($metrics) === 1 ? 65 : 27);
            $source .= "\n\\Section{03\\enspace Interpretation and counting basis}\n\\begin{itemize}\\setlength{\\itemsep}{1pt}\\setlength{\\parskip}{0pt}\n";
            if ($unit === 'BMG') {
                $notes = ['Started counts use batch start date; released and voided counts use completion date.',
                    'These are separate lifecycle events, not a cohort or a release-success rate.',
                    'Counts do not certify sensor readings, sanitation, or material quality.'];
            } else {
                $metric = array_key_first($metrics);
                $values = array_column($data, $metric);
                $peak = max($values);
                $peakDate = $peak > 0 ? (new DateTimeImmutable(array_keys($data)[array_search($peak, $values, true)]))->format('d M Y') : null;
                $notes = [$peakDate === null ? 'No completed activity matched this reporting period.'
                    : 'Peak daily activity: ' . number_format($peak) . ' on ' . $peakDate . ' (first date if tied).',
                    $unit === 'Clinic' ? 'Completed encounters are grouped by encounter start date, not unique patients.'
                        : 'Completed counselling sessions are grouped by session start date, not unique people.',
                    'Daily averages include zero-activity dates. Counts reflect current completion status.'];
            }
            foreach ($notes as $note) $source .= '\item {\small ' . self::escape($note) . "}\n";
            $source .= "\\end{itemize}\n{\\scriptsize\\color{Muted}A zero count means no matching records; it does not establish service availability.}\n\\newpage\n";
            if ($unit === 'Clinic' && $hasClinicInsights) {
                $source .= self::clinicPages($clinicInsights, $range);
            }
            $source .= self::header('DEPARTMENT DETAIL', $unit . ' daily register', $range);
            $source .= "\n\\Section{Daily counts and period totals}\n{\\small Every date in the selected period is included, including zero-activity days.}\\par\n\\vspace{2mm}\n";
            $source .= '\begin{tabularx}{\linewidth}{@{}X' . str_repeat('r', count($metrics)) . "@{}}\\toprule\n\\textbf{Date (Manila)}";
            foreach ($metrics as [$label]) $source .= ' & \textbf{' . self::escape($label) . '}';
            $source .= " \\\\ \\midrule\n";
            foreach ($data as $date => $daily) {
                $source .= self::escape((new DateTimeImmutable($date))->format('d M Y'));
                foreach ($metrics as $metric => $_) $source .= ' & ' . number_format($daily[$metric]);
                $source .= " \\\\\n";
            }
            $source .= "\\midrule\n\\textbf{Period total}";
            foreach ($metrics as $metric => $_) $source .= ' & \textbf{' . number_format(array_sum(array_column($data, $metric))) . '}';
            $source .= " \\\\ \\bottomrule\n\\end{tabularx}\n";
            if (count($data) < 20) {
                $source .= "\n\\Section{Daily statistics}\n\\begin{tabularx}{\\linewidth}{@{}Xrrr@{}}\\toprule\n\\textbf{Measure} & \\textbf{Average/day} & \\textbf{Peak/day} & \\textbf{Active days} \\\\ \\midrule\n";
                foreach ($metrics as $metric => [$label]) {
                    $values = array_column($data, $metric);
                    $source .= self::escape($label) . ' & ' . number_format(array_sum($values) / count($values), 1)
                        . ' & ' . number_format(max($values)) . ' & ' . count(array_filter($values)) . ' / ' . count($values) . " \\\\\n";
                }
                $source .= "\\bottomrule\\end{tabularx}\n";
            }
            $source .= "\n\\vfill{\\scriptsize\\color{Muted}Daily values and period totals use the counting basis documented on this department's analysis page.}\\newpage\n";
        }
        return $source . "\n\\end{document}\n";
    }

    private static function header(string $section, string $title, string $range): string
    {
        return '\ReportHeader{' . self::escape($section) . '}{' . self::escape($title) . '}{' . self::escape($range) . "}\n";
    }

    private static function chart(array $data, string $metric, string $label, int $height): string
    {
        $colors = ['completed_clinic_encounters_started' => 'Clinic', 'completed_guidance_sessions_started' => 'Guidance',
            'bmg_batches_started' => 'Started', 'bmg_batches_released' => 'Released', 'bmg_batches_voided' => 'Voided'];
        $values = array_column($data, $metric);
        $count = count($values);
        $maximum = max($values);
        $step = max(1, (int) ceil($maximum / 2));
        $ceiling = $step * 2;
        $indexes = $count <= 7 ? range(1, $count) : array_unique([1, (int) ceil($count / 3), (int) ceil($count * 2 / 3), $count]);
        $dates = array_keys($data);
        $labels = [];
        foreach ($indexes as $index) $labels[] = '{' . self::escape((new DateTimeImmutable($dates[$index - 1]))->format('d M')) . '}';
        $coordinates = [];
        foreach ($values as $index => $value) $coordinates[] = '(' . ($index + 1) . ',' . $value . ')';
        $color = $colors[$metric];
        $barWidth = min(14, 380 / $count * 0.6);
        return '\par{\sffamily\bfseries\small\color{' . $color . '}' . self::escape($label) . "}\\par\n"
            . '\begin{tikzpicture}\begin{axis}[width=\linewidth,height=' . $height . "mm,scale only axis=false,\n"
            . 'ybar,bar width=' . number_format($barWidth, 2, '.', '') . 'pt,xmin=0.3,xmax=' . ($count + 0.7)
            . ',ymin=0,ymax=' . $ceiling . ',ytick={0,' . $step . ',' . $ceiling . "},\n"
            . 'xtick={' . implode(',', $indexes) . '},xticklabels={' . implode(',', $labels) . "},\n"
            . "tick label style={font=\\scriptsize},label style={font=\\scriptsize},ylabel={Count},\n"
            . "scaled y ticks=false,yticklabel style={/pgf/number format/fixed,/pgf/number format/precision=0},\n"
            . "axis line style={draw=Muted!40},axis x line*=bottom,axis y line*=left,\n"
            . "ymajorgrids=true,grid style={draw=Muted!15},tick align=outside,enlarge x limits=false]\n"
            . '\addplot[fill=' . $color . ',draw=' . $color . '] coordinates {' . implode(' ', $coordinates) . "};\n"
            . "\\end{axis}\\end{tikzpicture}\\par\\vspace{1mm}\n";
    }

    /** The PDF wrapper owns contract validation; these helpers only format aggregates. */
    private static function clinicPages(array $insights, string $range): string
    {
        $total = $insights['encounter_total'];
        $source = self::header('CLINIC INSIGHTS', 'Clinic visit profile', $range);
        $source .= self::clinicCohort($total);
        $source .= self::profileSection('01\\enspace Chief complaint', $insights['complaints'], $total);
        $source .= self::profileSection('02\\enspace Disposition', $insights['dispositions'], $total);
        $source .= <<<'TEX'
\Section{Classification and privacy}
{\small Chief complaints use exact vocabulary matching, ignoring case and edge spaces only.
No clinical inference is made. Top five matched complaints are shown; remaining recorded
values are grouped as Other recorded. Missing values remain Not recorded, including zero.
Disposition uses recorded categories. Free-text narratives are not exported.}
\newpage
TEX;
        $source .= self::header('CLINIC INSIGHTS', 'Clinic medicine insights', $range);
        $source .= self::clinicCohort($total);
        if ($insights['medicine_status'] === 'unavailable') {
            $source .= <<<'TEX'
\Section{Medicine reporting setup not ready}
{\small Medicine insights are unavailable because the ledger reporting setup is not ready.
This is not a zero count and does not establish that no medicines were dispensed.
No medicine chart, ranking, or numeric medicine totals are reported.}
\Section{Counting basis when available}
{\small The top five ranking uses distinct encounters per medicine, not units or quantities.
Shares use medicine encounters as their denominator; medicines can overlap within an encounter.
Legacy medicine encounters are excluded from the ledger-based ranking; their count is unavailable.}
TEX;
        } else {
            $medicines = $insights['medicines'];
            // Stable ties retain the validated collector's ordering.
            usort($medicines, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
            $source .= "\n\\Section{01\\enspace Top five medicines by encounters}\n";
            $source .= '{\\small Medicine encounters: \\textbf{' . number_format($insights['medicine_encounters'])
                . '}. Distinct completed encounters with qualifying ledger dispensations.}\\par' . "\n";
            $source .= self::medicineBars($medicines);
            $source .= "\n\\Section{02\\enspace Medicine ranking}\n";
            $source .= self::rankingTable($medicines, $insights['medicine_encounters'], 'Medicine');
            $source .= "\n\\Section{Counting basis and exclusions}\n{\\small\n";
            $source .= 'Top five ranking by distinct encounters per medicine, not units or quantities. '
                . 'Each encounter counts at most once per medicine. Shares use medicine encounters ('
                . number_format($insights['medicine_encounters']) . ') as the denominator, not all completed encounters. '
                . 'Medicines can overlap within an encounter, so shares may sum to more than 100\\%. '
                . 'Legacy medicine encounters: \\textbf{' . number_format($insights['legacy_medicine_encounters'])
                . '}; their unlinked allocations are excluded. These encounters may also have qualifying ledger dispensing. '
                . 'Counts reflect qualifying, non-reversed ledger dispensations in the same clinical cohort. Catalog labels use the current medicine name, strength, and dosage form.}' . "\n";
        }
        return $source . "\n\\vfill{\\scriptsize\\color{Muted}Aggregate catalog labels only; no narratives or person-level records are exported.}\\newpage\n";
    }

    private static function clinicCohort(int $total): string
    {
        return '{\\small \\textbf{Clinical cohort: ' . number_format($total) . ' completed encounters.} '
            . 'Grouped by encounter start date in the selected reporting period (Asia/Manila), '
            . 'using current completion status, exactly as the Clinic daily counts; not unique patients.}\\par' . "\n";
    }

    private static function profileSection(string $title, array $rows, int $total): string
    {
        $source = '\\Section{' . $title . "}\n";
        $source .= "\\noindent\\begin{minipage}[t]{0.32\\linewidth}\\vspace{0pt}\\centering\n";
        $source .= self::donut($rows, $total);
        $source .= "{\\scriptsize\\color{Muted}Legend: keys match the table categories.}\\par\n";
        foreach ($rows as $index => $_) {
            $source .= '{\\scriptsize\\textcolor{' . self::rankColor($index) . '}{\\rule{2mm}{2mm}}\\,' . ($index + 1) . '}\\quad ';
        }
        $source .= "\n\\end{minipage}\\hfill\\begin{minipage}[t]{0.65\\linewidth}\\vspace{0pt}\n";
        $source .= self::rankingTable($rows, $total, 'Category');
        return $source . "\\end{minipage}\\par\\vspace{3mm}\n";
    }

    private static function rankColor(int $index): string
    {
        return ['Maroon', 'Guidance', 'Started', 'Released', 'Voided', 'Muted', 'Muted!45'][$index];
    }

    private static function donut(array $rows, int $total): string
    {
        $source = "\\begin{tikzpicture}[x=1mm,y=1mm]\n\\path[use as bounding box] (-23,-23) rectangle (23,23);\n";
        $sum = array_sum(array_column($rows, 'count'));
        $angle = 90.0;
        if ($sum > 0) {
            foreach ($rows as $index => $row) {
                if ($row['count'] <= 0) continue;
                $end = $angle + 360 * $row['count'] / $sum;
                $startText = number_format($angle, 6, '.', '');
                $endText = number_format($end, 6, '.', '');
                $source .= '\\fill[' . self::rankColor($index) . '] (0,0) -- (' . $startText
                    . ':21) arc[start angle=' . $startText . ',end angle=' . $endText . ",radius=21] -- cycle;\n";
                $angle = $end;
            }
        } else {
            $source .= "\\draw[Muted!35,line width=2mm] (0,0) circle[radius=19];\n";
        }
        $source .= "\\fill[white] (0,0) circle[radius=12];\n";
        $source .= '\\node[align=center,font=\\sffamily\\small,text=Ink] at (0,0) {'
            . ($sum > 0 ? number_format($total) . '\\\\{\\scriptsize encounters}' : 'Empty\\\\{\\scriptsize No encounters}') . "};\n";
        return $source . "\\end{tikzpicture}\\par\n";
    }

    private static function rankingTable(array $rows, int $denominator, string $label): string
    {
        $source = "{\\footnotesize\\setlength{\\tabcolsep}{2pt}\\renewcommand{\\arraystretch}{1.15}\n"
            . '\\begin{tabularx}{\\linewidth}{@{}r>{\\raggedright\\arraybackslash}Xrr@{}}\\toprule' . "\n"
            . '\\textbf{' . ($label === 'Category' ? 'Key' : 'Rank') . '} & \\textbf{' . self::escape($label) . '} & \\textbf{Count} & \\textbf{Share} \\\\ \\midrule' . "\n";
        foreach ($rows as $index => $row) {
            $share = $denominator > 0 ? rtrim(rtrim(number_format(100 * $row['count'] / $denominator, 1, '.', ''), '0'), '.') : '0';
            $source .= '\\textcolor{' . self::rankColor($index) . '}{\\textbf{' . ($index + 1) . '}} & ' . self::escape($row['label']) . ' & ' . number_format($row['count'])
                . ' & ' . $share . "\\% \\\\\n";
        }
        if ($rows === []) $source .= "\\multicolumn{4}{@{}l@{}}{No matching medicine encounters.} \\\\\n";
        return $source . "\\bottomrule\\end{tabularx}}\n";
    }

    private static function medicineBars(array $rows): string
    {
        $maximum = $rows === [] ? 0 : max(array_column($rows, 'count'));
        if ($maximum === 0) return "{\\small\\color{Muted}Empty chart: no matching medicine encounters.}\\par\n";
        // Numeric rank labels avoid putting catalog strings into a plotting expression.
        $source = "\\begin{tikzpicture}[x=1mm,y=1mm]\n";
        $source .= '\\path[use as bounding box] (0,0) rectangle (150,' . (count($rows) * 8 + 5) . ");\n";
        foreach ($rows as $index => $row) {
            $y = (count($rows) - $index) * 8;
            $width = number_format(115 * $row['count'] / $maximum, 3, '.', '');
            $source .= '\\node[anchor=east,font=\\scriptsize] at (7,' . ($y + 2) . ') {' . ($index + 1) . "};\n";
            if ($row['count'] > 0) {
                $source .= '\\fill[' . self::rankColor($index) . '] (10,' . $y . ') rectangle +(' . $width . ",4);\n";
            }
            $source .= '\\node[anchor=west,font=\\scriptsize] at (' . (10 + (float) $width) . ',' . ($y + 2)
                . ') {' . number_format($row['count']) . "};\n";
        }
        return $source . "\\end{tikzpicture}\\par\n{\\scriptsize Bar labels are ranks in the table below; bar lengths count distinct encounters.}\\par\n";
    }

    private static function escape(string $value): string
    {
        // Catalog text is never TeX. Strip Unicode controls before transliteration, then
        // escape the resulting ASCII too (transliteration can introduce TeX metacharacters).
        $value = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $value) ?? $value;
        if (function_exists('iconv')) {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($ascii !== false) $value = $ascii;
        }
        $value = preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
        return strtr($value, ['\\' => '\\textbackslash{}', '&' => '\\&', '%' => '\\%', '$' => '\\$', '#' => '\\#',
            '_' => '\\_', '{' => '\\{', '}' => '\\}', '~' => '\\textasciitilde{}', '^' => '\\textasciicircum{}']);
    }
}
