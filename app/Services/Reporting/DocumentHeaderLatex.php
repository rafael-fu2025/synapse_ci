<?php

declare(strict_types=1);

namespace App\Services\Reporting;

/**
 * Shared institutional PDF header, footer, and color definitions for
 * Foundation University Office of the Guidance Center and University Health Clinic.
 */
final class DocumentHeaderLatex
{
    public static function colorDefinitions(): string
    {
        return <<<'LATEX'
\definecolor{FuMaroon}{HTML}{800000}
\definecolor{DocInk}{HTML}{1F2937}
\definecolor{DocMuted}{HTML}{52606D}
\definecolor{HeaderBg}{HTML}{F8F9FB}
\definecolor{HeaderBorder}{HTML}{B8C2CC}
\definecolor{HeaderRule}{HTML}{D8DFE6}
LATEX;
    }

    public static function fancyHeaderFooter(
        string $officeLabel,
        string $rightHeaderLatex,
        string $leftFooterLatex,
        string $rightFooterLatex = 'Page \\thepage'
    ): string {
        return <<<LATEX
\\pagestyle{fancy}
\\fancyhf{}
\\renewcommand{\\headrulewidth}{0.4pt}
\\renewcommand{\\footrulewidth}{0.4pt}
\\fancyhead[L]{\\small\\textbf{Foundation University} --- {$officeLabel}}
\\fancyhead[R]{\\small {$rightHeaderLatex}}
\\fancyfoot[L]{\\footnotesize {$leftFooterLatex}}
\\fancyfoot[R]{\\footnotesize {$rightFooterLatex}}
LATEX;
    }

    public static function headerContainer(
        string $unit,
        string $officeTitleUpper,
        string $documentTitle,
        string $metaLineLatex,
        ?string $subtitleLatex = null
    ): string {
        $unitLogo = strtolower($unit) === 'clinic' ? 'clinic-logo.png' : 'guidance-logo.png';
        $subtitleBlock = ($subtitleLatex !== null && $subtitleLatex !== '')
            ? "\n    " . $subtitleLatex . "\\par"
            : '';

        return <<<LATEX
{\\setlength{\\fboxsep}{3mm}\\setlength{\\fboxrule}{0.6pt}%
\\noindent\\fcolorbox{HeaderBorder}{HeaderBg}{%
\\begin{minipage}{\\dimexpr\\textwidth-2\\fboxsep-2\\fboxrule\\relax}
  \\begin{minipage}[c]{18mm}
    \\centering\\IfFileExists{university-seal.png}{\\includegraphics[width=16mm,height=16mm,keepaspectratio]{university-seal.png}}{\\vspace{16mm}}
  \\end{minipage}\\hspace{2mm}%
  \\begin{minipage}[c]{\\dimexpr\\linewidth-40mm\\relax}
    \\centering
    {\\normalsize\\bfseries\\color{FuMaroon} FOUNDATION UNIVERSITY}\\par\\vspace{0.15em}
    {\\large\\bfseries\\color{DocInk} {$officeTitleUpper}}\\par\\vspace{0.15em}
    {\\normalsize\\bfseries\\color{DocInk} {$documentTitle}}\\par{$subtitleBlock}
    \\vspace{0.25em}{\\color{HeaderRule}\\hrule height 0.4pt}\\vspace{0.25em}
    {\\footnotesize\\color{DocMuted} {$metaLineLatex}\\par}
  \\end{minipage}\\hspace{2mm}%
  \\begin{minipage}[c]{18mm}
    \\centering\\IfFileExists{{$unitLogo}}{\\includegraphics[width=16mm,height=16mm,keepaspectratio]{{$unitLogo}}}{\\vspace{16mm}}
  \\end{minipage}
\\end{minipage}%
}}\\par\\vspace{0.8em}
LATEX;
    }

    public static function compactQuadrantHeader(
        string $unit,
        string $officeTitleUpper,
        string $documentTitle,
        string $metaLineLatex
    ): string {
        $unitLogo = strtolower($unit) === 'clinic' ? 'clinic-logo.png' : 'guidance-logo.png';

        return <<<LATEX
{\\setlength{\\fboxsep}{1.2mm}\\setlength{\\fboxrule}{0.4pt}%
\\noindent\\fcolorbox{HeaderBorder}{HeaderBg}{%
\\begin{minipage}{\\dimexpr\\linewidth-2\\fboxsep-2\\fboxrule\\relax}
  \\begin{minipage}[c]{8.5mm}
    \\centering\\IfFileExists{university-seal.png}{\\includegraphics[width=8mm,height=8mm,keepaspectratio]{university-seal.png}}{\\vspace{8mm}}
  \\end{minipage}\\hspace{1mm}%
  \\begin{minipage}[c]{\\dimexpr\\linewidth-19mm\\relax}
    \\centering
    {\\bfseries\\scriptsize\\color{FuMaroon} FOUNDATION UNIVERSITY\\par}
    {\\bfseries\\tiny\\color{DocInk} {$officeTitleUpper}\\par}
    {\\bfseries\\footnotesize\\color{DocInk} {$documentTitle}\\par}
    \\vspace{0.5mm}{\\color{HeaderRule}\\hrule height 0.3pt}\\vspace{0.6mm}
    {\\tiny\\color{DocMuted} {$metaLineLatex}\\par}
  \\end{minipage}\\hspace{1mm}%
  \\begin{minipage}[c]{8.5mm}
    \\centering\\IfFileExists{{$unitLogo}}{\\includegraphics[width=8mm,height=8mm,keepaspectratio]{{$unitLogo}}}{\\vspace{8mm}}
  \\end{minipage}
\\end{minipage}%
}}\\par\\vspace{0.8mm}
LATEX;
    }
}
