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
        $headerColors = DocumentHeaderLatex::colorDefinitions();
        $fancy = DocumentHeaderLatex::fancyHeaderFooter(
            'Operational Reporting',
            '\\textbf{Report:} Operational Export',
            '\\textbf{Issued via SYNAPSE} \\quad \\textit{Foundation University --- Aggregate operational data only}',
            'Page \\thepage{} of ' . $totalPages
        );
        $source = <<<TEX
\\documentclass[10pt,a4paper]{article}
\\pdfminorversion=4
\\usepackage[top=20mm,bottom=22mm,left=18mm,right=18mm,headheight=14pt,headsep=5mm]{geometry}
\\usepackage[T1]{fontenc}
\\IfFileExists{t1ptm.fd}{\\renewcommand{\\rmdefault}{ptm}}{\\renewcommand{\\rmdefault}{utm}}
\\DeclareSymbolFont{operators}{T1}{\\rmdefault}{m}{n}
\\SetSymbolFont{operators}{bold}{T1}{\\rmdefault}{b}{n}
\\PassOptionsToPackage{framemethod=tikz}{mdframed}
\\usepackage{graphicx,xcolor,tabularx,pgfplots,microtype,fancyhdr,mdframed}
\\pgfplotsset{compat=1.18}
\\definecolor{Maroon}{HTML}{800000}
\\definecolor{Ink}{HTML}{293241}
\\definecolor{Muted}{HTML}{606975}
{$headerColors}
\\definecolor{Panel}{HTML}{F8F9FB}
\\definecolor{Clinic}{HTML}{800000}
\\definecolor{Guidance}{HTML}{21598C}
\\definecolor{Started}{HTML}{296E5C}
\\definecolor{Released}{HTML}{8F5C14}
\\definecolor{Voided}{HTML}{665C7A}
\\color{DocInk}
\\setlength{\\parindent}{0pt}
\\setlength{\\parskip}{4pt}
\\setlength{\\emergencystretch}{2em}
\\renewcommand{\\arraystretch}{1.25}
\\renewcommand{\\labelitemi}{\\textcolor{FuMaroon}{\\rule{0.7mm}{0.7mm}}}
\\mdfsetup{backgroundcolor=gray!2,linecolor=HeaderBorder,linewidth=0.5pt,innertopmargin=2mm,innerbottommargin=2mm,innerleftmargin=3mm,innerrightmargin=3mm}
\\pdfinfo{/Title (SYNAPSE Operational Report) /Author (Foundation University)}
\\newcommand{\\ReportHeader}[4]{%
  {\\setlength{\\fboxsep}{3mm}\\setlength{\\fboxrule}{0.6pt}%
  \\noindent\\fcolorbox{HeaderBorder}{HeaderBg}{%
  \\begin{minipage}{\\dimexpr\\textwidth-2\\fboxsep-2\\fboxrule\\relax}
    \\begin{minipage}[c]{18mm}
      \\centering\\IfFileExists{university-seal.png}{\\includegraphics[width=16mm,height=16mm,keepaspectratio]{university-seal.png}}{\\vspace{16mm}}
    \\end{minipage}\\hspace{2mm}%
    \\begin{minipage}[c]{\\dimexpr\\linewidth-40mm\\relax}
      \\centering
      {\\normalsize\\bfseries\\color{FuMaroon} FOUNDATION UNIVERSITY}\\par\\vspace{0.15em}
      {\\large\\bfseries\\color{DocInk} #1}\\par\\vspace{0.15em}
      {\\normalsize\\bfseries\\color{DocInk} #2}\\par
      \\vspace{0.25em}{\\color{HeaderRule}\\hrule height 0.4pt}\\vspace{0.25em}
      {\\footnotesize\\color{DocMuted} \\textbf{Reporting period:} #3 \\quad \\textit{Asia/Manila (inclusive)}\\par}
    \\end{minipage}\\hspace{2mm}%
    \\begin{minipage}[c]{18mm}
      \\centering\\if\\relax\\detokenize{#4}\\relax\\vspace{16mm}\\else\\IfFileExists{#4}{\\includegraphics[width=16mm,height=16mm,keepaspectratio]{#4}}{\\vspace{16mm}}\\fi
    \\end{minipage}
  \\end{minipage}%
  }}\\par\\vspace{0.6em}
}
\\newcommand{\\Section}[1]{\\par\\addvspace{2.5mm}\\noindent{\\rmfamily\\bfseries\\small\\color{FuMaroon}#1}\\par\\nobreak\\vspace{1mm}}
\\newlength{\\cardwidth}
\\setlength{\\fboxsep}{3mm}
\\setlength{\\fboxrule}{0.6pt}
\\setlength{\\cardwidth}{\\dimexpr(\\textwidth-12mm)/3-6mm-2\\fboxrule\\relax}
\\newcommand{\\MetricCard}[3]{%
  \\fcolorbox{HeaderBorder}{HeaderBg}{\\begin{minipage}[t][21mm][t]{\\cardwidth}
    {\\rmfamily\\bfseries\\small\\color{DocInk} #1}\\par\\vspace{1mm}
    {\\rmfamily\\bfseries\\Large\\color{FuMaroon}#2}\\par
    {\\rmfamily\\scriptsize\\color{DocMuted}#3}
  \\end{minipage}}%
}
{$fancy}
\\begin{document}
TEX;
        if (count($groups) > 1) {
            $source .= self::header('EXECUTIVE SUMMARY', 'Operational report', $range);
            $source .= "\n\\Section{Department results}\n{\\small\\color{DocMuted}\\textit{Each department is reported independently. There is no combined activity total.}}\\par\\vspace{1mm}\n";
            $page = 2;
            foreach ($groups as $unit => $metrics) {
                $departmentPages = $unit === 'Clinic' && $hasClinicInsights ? 4 : 2;
                $source .= '\Section{' . self::escape($unit) . '\hfill\normalfont\scriptsize Pages ' . $page . '--' . ($page + $departmentPages - 1) . "}\n";
                $source .= "\\begin{tabularx}{\\textwidth}{|X|r|}\n\\hline\n\\textcolor{FuMaroon}{\\textbf{Measure}} & \\textcolor{FuMaroon}{\\textbf{Period total}} \\\\\n\\hline\\hline\n";
                foreach ($metrics as $metric => [$label]) $source .= self::escape($label) . ' & ' . number_format(array_sum(array_column($data, $metric))) . " \\\\\n\\hline\n";
                $source .= "\\end{tabularx}\\par\\vspace{1.5mm}\n";
                $page += $departmentPages;
            }
            if ($hasClinicInsights) {
                $source .= "\n{\\small\\color{DocMuted}\\textit{Clinic also includes a visit profile and medicine insights before its daily register.}}\\par\\vspace{1mm}\n";
            }
            $source .= <<<'TEX'
\Section{How to use this report}
\begin{mdframed}
{\small The following department sections each contain an analysis page and a daily register.
Charts describe independent measures, with exact daily values provided in the tables.}
\end{mdframed}
\Section{Scope and interpretation}
\begin{mdframed}
{\small Only authorized departments are included. Clinic and Guidance figures count completed
care activities, not unique people. BMG figures describe separate lifecycle events;
they must not be added together or interpreted as a cohort success rate.}
\end{mdframed}
\newpage
TEX;
        }
        foreach ($groups as $unit => $metrics) {
            $unitLogo = match ($unit) {
                'Clinic' => 'clinic-logo.png',
                'Guidance' => 'guidance-logo.png',
                default => '',
            };
            $source .= self::header('DEPARTMENT ANALYSIS', $unit . ' operational results', $range, $unitLogo);
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
            $source .= "\n\\par\\Section{02\\enspace Daily activity}\n{\\small\\color{DocMuted}\\textit{Each measure has its own labelled scale; counts must not be combined.}}\\par\n";
            foreach ($metrics as $metric => [$label]) $source .= self::chart($data, $metric, $label, count($metrics) === 1 ? 60 : 25);
            $source .= "\n\\Section{03\\enspace Interpretation and counting basis}\n\\begin{mdframed}\n\\begin{itemize}\\setlength{\\itemsep}{1pt}\\setlength{\\parskip}{0pt}\n";
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
            $source .= "\\end{itemize}\n{\\scriptsize\\color{DocMuted}A zero count means no matching records; it does not establish service availability.}\n\\end{mdframed}\n\\newpage\n";
            if ($unit === 'Clinic' && $hasClinicInsights) {
                $source .= self::clinicPages($clinicInsights, $range);
            }
            $source .= self::header('DEPARTMENT DETAIL', $unit . ' daily register', $range, $unitLogo);
            $source .= "\n\\Section{Daily counts and period totals}\n{\\small\\color{DocMuted}\\textit{Every date in the selected period is included, including zero-activity days.}}\\par\n\\vspace{1.5mm}\n";
            $rowStretch = count($data) >= 20 ? '{\footnotesize\renewcommand{\arraystretch}{1.08}' : '{\small\renewcommand{\arraystretch}{1.18}';
            $source .= $rowStretch . "\n" . '\begin{tabularx}{\textwidth}{|X' . str_repeat('|r', count($metrics)) . "|}\n\\hline\n\\textcolor{FuMaroon}{\\textbf{Date (Manila)}}";
            foreach ($metrics as [$label]) $source .= ' & \textcolor{FuMaroon}{\textbf{' . self::escape($label) . '}}';
            $source .= " \\\\\n\\hline\\hline\n";
            foreach ($data as $date => $daily) {
                $source .= self::escape((new DateTimeImmutable($date))->format('d M Y'));
                foreach ($metrics as $metric => $_) $source .= ' & ' . number_format($daily[$metric]);
                $source .= " \\\\\n\\hline\n";
            }
            $source .= "\\textbf{Period total}";
            foreach ($metrics as $metric => $_) $source .= ' & \textbf{' . number_format(array_sum(array_column($data, $metric))) . '}';
            $source .= " \\\\\n\\hline\n\\end{tabularx}}\n";
            if (count($data) < 20) {
                $source .= "\n\\Section{Daily statistics}\n\\begin{tabularx}{\\textwidth}{|X|r|r|r|}\n\\hline\n\\textcolor{FuMaroon}{\\textbf{Measure}} & \\textcolor{FuMaroon}{\\textbf{Average/day}} & \\textcolor{FuMaroon}{\\textbf{Peak/day}} & \\textcolor{FuMaroon}{\\textbf{Active days}} \\\\\n\\hline\\hline\n";
                foreach ($metrics as $metric => [$label]) {
                    $values = array_column($data, $metric);
                    $source .= self::escape($label) . ' & ' . number_format(array_sum($values) / count($values), 1)
                        . ' & ' . number_format(max($values)) . ' & ' . count(array_filter($values)) . ' / ' . count($values) . " \\\\\n\\hline\n";
                }
                $source .= "\\end{tabularx}\n";
            }
            $source .= "\n\\vfill{\\scriptsize\\color{DocMuted}Daily values and period totals use the counting basis documented on this department's analysis page.}\\newpage\n";
        }
        return $source . "\n\\end{document}\n";
    }

    private static function header(string $section, string $title, string $range, string $unitLogo = ''): string
    {
        return '\ReportHeader{' . self::escape($section) . '}{' . self::escape($title) . '}{' . self::escape($range) . '}{' . self::escape($unitLogo) . "}\n";
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
        return '\par{\rmfamily\bfseries\small\color{' . $color . '}' . self::escape($label) . "}\\par\n"
            . '\begin{tikzpicture}\begin{axis}[width=\linewidth,height=' . $height . "mm,scale only axis=false,\n"
            . 'ybar,bar width=' . number_format($barWidth, 2, '.', '') . 'pt,xmin=0.3,xmax=' . ($count + 0.7)
            . ',ymin=0,ymax=' . $ceiling . ',ytick={0,' . $step . ',' . $ceiling . "},\n"
            . 'xtick={' . implode(',', $indexes) . '},xticklabels={' . implode(',', $labels) . "},\n"
            . "tick label style={font=\\scriptsize},label style={font=\\scriptsize},ylabel={Count},\n"
            . "scaled y ticks=false,yticklabel style={/pgf/number format/fixed,/pgf/number format/precision=0,/pgf/number format/assume math mode=true},\n"
            . "axis line style={draw=Muted!40},axis x line*=bottom,axis y line*=left,\n"
            . "ymajorgrids=true,grid style={draw=Muted!15},tick align=outside,enlarge x limits=false]\n"
            . '\addplot[fill=' . $color . ',draw=' . $color . '] coordinates {' . implode(' ', $coordinates) . "};\n"
            . "\\end{axis}\\end{tikzpicture}\\par\\vspace{1mm}\n";
    }

    /** The PDF wrapper owns contract validation; these helpers only format aggregates. */
    private static function clinicPages(array $insights, string $range): string
    {
        $total = $insights['encounter_total'];
        $source = self::header('CLINIC INSIGHTS', 'Clinic visit profile', $range, 'clinic-logo.png');
        $source .= self::clinicCohort($total);
        $source .= self::profileSection('01\\enspace Chief complaint', $insights['complaints'], $total);
        $source .= self::profileSection('02\\enspace Disposition', $insights['dispositions'], $total);
        $source .= <<<'TEX'
\Section{Classification and privacy}
\begin{mdframed}
{\small Chief complaints use exact vocabulary matching, ignoring case and edge spaces only.
No clinical inference is made. Top five matched complaints are shown; remaining recorded
values are grouped as Other recorded. Missing values remain Not recorded, including zero.
Disposition uses recorded categories. Free-text narratives are not exported.}
\end{mdframed}
\newpage
TEX;
        $source .= self::header('CLINIC INSIGHTS', 'Clinic medicine insights', $range, 'clinic-logo.png');
        $source .= self::clinicCohort($total);
        if ($insights['medicine_status'] === 'unavailable') {
            $source .= <<<'TEX'
\Section{Medicine reporting setup not ready}
\begin{mdframed}
{\small Medicine insights are unavailable because the ledger reporting setup is not ready.
This is not a zero count and does not establish that no medicines were dispensed.
No medicine chart, ranking, or numeric medicine totals are reported.}
\end{mdframed}
\Section{Counting basis when available}
\begin{mdframed}
{\small The top five ranking uses distinct encounters per medicine, not units or quantities.
Shares use medicine encounters as their denominator; medicines can overlap within an encounter.
Legacy medicine encounters are excluded from the ledger-based ranking; their count is unavailable.}
\end{mdframed}
TEX;
        } else {
            $medicines = $insights['medicines'];
            // Stable ties retain the validated collector's ordering.
            usort($medicines, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
            $source .= "\n\\Section{01\\enspace Top five medicines by encounters}\n";
            $source .= '{\\small\\color{DocMuted} Medicine encounters: \\textbf{' . number_format($insights['medicine_encounters'])
                . '}. Distinct completed encounters with qualifying ledger dispensations.}\\par' . "\n";
            $source .= self::medicineBars($medicines);
            $source .= "\n\\Section{02\\enspace Medicine ranking}\n";
            $source .= self::rankingTable($medicines, $insights['medicine_encounters'], 'Medicine');
            $source .= "\n\\Section{Counting basis and exclusions}\n\\begin{mdframed}\n{\\small\n";
            $source .= 'Top five ranking by distinct encounters per medicine, not units or quantities. '
                . 'Each encounter counts at most once per medicine. Shares use medicine encounters ('
                . number_format($insights['medicine_encounters']) . ') as the denominator, not all completed encounters. '
                . 'Medicines can overlap within an encounter, so shares may sum to more than 100\\%. '
                . 'Legacy medicine encounters: \\textbf{' . number_format($insights['legacy_medicine_encounters'])
                . '}; their unlinked allocations are excluded. These encounters may also have qualifying ledger dispensing. '
                . 'Counts reflect qualifying, non-reversed ledger dispensations in the same clinical cohort. Catalog labels use the current medicine name, strength, and dosage form.}' . "\n\\end{mdframed}\n";
        }
        return $source . "\n\\vfill{\\scriptsize\\color{DocMuted}Aggregate catalog labels only; no narratives or person-level records are exported.}\\newpage\n";
    }

    private static function clinicCohort(int $total): string
    {
        return "\\begin{mdframed}[backgroundcolor=HeaderBg]\n"
            . '{\\small \\textbf{Clinical cohort: ' . number_format($total) . ' completed encounters.} '
            . 'Grouped by encounter start date in the selected reporting period (Asia/Manila), '
            . 'using current completion status, exactly as the Clinic daily counts; not unique patients.}' . "\n\\end{mdframed}\\par\\vspace{1mm}\n";
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
        $source .= '\\node[align=center,font=\\rmfamily\\small,text=Ink] at (0,0) {'
            . ($sum > 0 ? number_format($total) . '\\\\{\\scriptsize encounters}' : 'Empty\\\\{\\scriptsize No encounters}') . "};\n";
        return $source . "\\end{tikzpicture}\\par\n";
    }

    private static function rankingTable(array $rows, int $denominator, string $label): string
    {
        $source = "{\\footnotesize\\setlength{\\tabcolsep}{3pt}\\renewcommand{\\arraystretch}{1.2}\n"
            . '\\begin{tabularx}{\\linewidth}{|r|>{\\raggedright\\arraybackslash}X|r|r|}' . "\n\\hline\n"
            . '\\textcolor{FuMaroon}{\\textbf{' . ($label === 'Category' ? 'Key' : 'Rank') . '}} & \\textcolor{FuMaroon}{\\textbf{' . self::escape($label) . '}} & \\textcolor{FuMaroon}{\\textbf{Count}} & \\textcolor{FuMaroon}{\\textbf{Share}} \\\\' . "\n\\hline\\hline\n";
        foreach ($rows as $index => $row) {
            $share = $denominator > 0 ? rtrim(rtrim(number_format(100 * $row['count'] / $denominator, 1, '.', ''), '0'), '.') : '0';
            $source .= '\\textcolor{' . self::rankColor($index) . '}{\\textbf{' . ($index + 1) . '}} & ' . self::escape($row['label']) . ' & ' . number_format($row['count'])
                . ' & ' . $share . "\\% \\\\\n\\hline\n";
        }
        if ($rows === []) $source .= "\\multicolumn{4}{|l|}{No matching medicine encounters.} \\\\\n\\hline\n";
        return $source . "\\end{tabularx}}\n";
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
