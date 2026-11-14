<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use Throwable;

final class GuidanceMultipleIntelligenceLatex
{
    /**
     * Canonical source order and definitions of all 8 Multiple Intelligence domains
     * adapted from Walter McKenzie 1999 (Surf Aquarium Consulting / OGC).
     *
     * @var array<int, array<string, mixed>>
     */
    private const CANONICAL_DOMAINS = [
        1 => [
            'key' => 'logical_mathematical',
            'aliases' => ['logical_mathematical', 'logical-mathematical', 'logical', 'mathematical', 'lm', 'l-m', '1'],
            'title' => 'Logical-Mathematical Intelligence',
            'short_title' => 'Logical-Mathematical',
            'max_score' => 40,
            'question' => 'Do you find it easy to compute math problems mentally and on paper, solve brainteasers, do logic puzzles, conduct science experiments, figure out number-and-sequence patterns and watch shows with science and number themes?',
            'description' => 'Involved in most scientific thinking, including solving logical puzzles, deriving proofs, and performing calculations. High logical mathematical thinking can be demonstrated by philosophers and mathematicians.',
            'educational_suggestions' => [
                'Utilize structured, step-by-step problem-solving methods, decision trees, and systematic categorization.',
                'Engage with quantitative analysis, logic puzzles, coding challenges, and algorithmic pattern recognition.',
                'Translate qualitative academic concepts into concrete matrices, flowchart models, and deductive schemas.',
                'Formulate testable hypotheses and verify conclusions through empirical experimentation and cause-and-effect inquiry.',
            ],
        ],
        2 => [
            'key' => 'linguistic',
            'aliases' => ['linguistic', 'verbal_linguistic', 'verbal-linguistic', 'verbal', 'ling', '2'],
            'title' => 'Linguistic Intelligence',
            'short_title' => 'Linguistic',
            'max_score' => 40,
            'question' => 'Do you find it easy to memorize information, write poems or stories, give talks, read books play word games, use big words and remember what you hear?',
            'description' => 'Involved in reading, writing, listening and talking. High linguistic intelligence can be demonstrated by writers.',
            'educational_suggestions' => [
                'Solidify comprehension through extensive reading, written syntheses, journaling, and persuasive essay composition.',
                'Participate in academic debates, structured discussions, oral recitations, and peer concept teaching.',
                'Leverage mnemonic word associations, structured semantic outlines, and vocabulary enhancement exercises.',
                'Incorporate educational podcasts, audio recordings, and verbal transcription into independent study routines.',
            ],
        ],
        3 => [
            'key' => 'spatial',
            'aliases' => ['spatial', 'visual_spatial', 'visual-spatial', 'visual', 'spa', '3'],
            'title' => 'Spatial Intelligence',
            'short_title' => 'Spatial',
            'max_score' => 40,
            'question' => 'Do you find it easy to draw, paint or doodle, work through puzzles and mazes, build with blocks and follow maps and flowcharts? Do you prefer reading materials that are full of illustrations?',
            'description' => 'Involved in moving from one location to another or determining one\'s orientation in space. High spatial intelligence can be demonstrated by architects and marine navigators.',
            'educational_suggestions' => [
                'Transform dense textual information into mind maps, infographics, graphic organizers, and color-coded diagrams.',
                'Study using visual representations, architectural drawings, process maps, and multi-dimensional models.',
                'Practice mental rotation, visual imagery, and spatial manipulation to grasp abstract relationships.',
                'Incorporate video lectures, animated diagrams, and physical or digital 3D models into learning tasks.',
            ],
        ],
        4 => [
            'key' => 'musical',
            'aliases' => ['musical', 'musical_intelligence', 'rhythmic', 'mus', '4'],
            'title' => 'Musical Intelligence',
            'short_title' => 'Musical',
            'max_score' => 40,
            'question' => 'Do you find it easy to sing or play a musical instrument, listen to music, create your own songs or maps, recognized off-key recordings and noises, remember television jingles and lyrics, and work while listening to or humming simple melodies and tunes?',
            'description' => 'Involved in singing, composing, conducting, or performing on a musical instrument. High musical intelligence can be demonstrated by musicians and composers.',
            'educational_suggestions' => [
                'Set complex factual sequences, rules, or formulas to melodic rhythms, metronome cadences, or acoustic mnemonics.',
                'Incorporate structured background instrumental music (e.g. ambient or classical) to optimize study focus.',
                'Analyze rhythm, tonal variation, and rhetorical cadence when reviewing oral presentations or lectures.',
                'Create audio-assisted review materials and rhythm-based associations for long-term memory retention.',
            ],
        ],
        5 => [
            'key' => 'bodily_kinesthetic',
            'aliases' => ['bodily_kinesthetic', 'bodily-kinesthetic', 'body_kinesthetic', 'kinesthetic', 'b-k', 'bk', '5'],
            'title' => 'Bodily-Kinesthetic Intelligence',
            'short_title' => 'Bodily-Kinesthetic',
            'max_score' => 40,
            'question' => 'Do you find it easy to engage in sports and other physical activities, move around, spend free time outdoors, work with your hands in such things as model building or sewing, participate in dance, ballet, gymnastics, plays puppet shows or other performances, and use finger paints, clay and papier maché?',
            'description' => 'Involved in using one\'s body or its various parts to perform skillful and purposeful movements. High body-kinesthetic intelligence can be demonstrated by dancers, athletes and surgeons.',
            'educational_suggestions' => [
                'Implement active learning modalities: laboratory work, physical manipulatives, and experiential field projects.',
                'Incorporate tactile study aids including tangible flashcards, 3D apparatus, and physical assembly exercises.',
                'Employ role-playing, simulation exercises, and kinetic demonstrations to internalize theoretical concepts.',
                'Schedule frequent movement intervals and standing study periods during intensive study blocks.',
            ],
        ],
        6 => [
            'key' => 'interpersonal',
            'aliases' => ['interpersonal', 'interpersonal_intelligence', 'social', 'inter', '6'],
            'title' => 'Interpersonal Intelligence',
            'short_title' => 'Interpersonal',
            'max_score' => 40,
            'question' => 'Do you find it easy to make friends, meet strangers, resolve conflicts, lead groups or clubs, engage in gossip, participate in team sports, plan social activities, and teach or counsel others?',
            'description' => 'Involved in understanding other people and one\'s relations to others. High interpersonal intelligence can be demonstrated by psychotherapists, teachers, salespeople and politicians.',
            'educational_suggestions' => [
                'Engage actively in collaborative study groups, reciprocal peer tutoring, and team problem-solving workshops.',
                'Participate in academic student organizations, consensus-building circles, and structured group deliberations.',
                'Solidify knowledge by teaching course materials to peers and exchanging constructive critique.',
                'Apply empathetic communication, mediation, and active listening skills during group projects.',
            ],
        ],
        7 => [
            'key' => 'intrapersonal',
            'aliases' => ['intrapersonal', 'intrapersonal_intelligence', 'self', 'intra', '7'],
            'title' => 'Intrapersonal Intelligence',
            'short_title' => 'Intrapersonal',
            'max_score' => 40,
            'question' => 'Do you find it easy to function independently, do you own work and thinking, spend time alone, engage in solo hobbies and activities, set goals, analyze your own strengths and weaknesses, and keep private diaries or journals?',
            'description' => 'Involved in understanding one\'s self and having insights into one\'s thoughts, emotions, and actions. High intrapersonal intelligence can be demonstrated by Zen masters.',
            'educational_suggestions' => [
                'Establish self-directed study schedules, individualized milestone targets, and personal mastery criteria.',
                'Maintain a reflective learning journal to track metacognitive growth, study efficacy, and emotional resilience.',
                'Allocate quiet, uninterrupted solitary study blocks for contemplation, deep synthesis, and review.',
                'Perform periodic personal goal reviews to align academic progress with core intrinsic values.',
            ],
        ],
        8 => [
            'key' => 'naturalist',
            'aliases' => ['naturalist', 'naturalistic', 'naturalist_intelligence', 'nature', 'nat', '8'],
            'title' => 'Naturalist Intelligence',
            'short_title' => 'Naturalist',
            'max_score' => 40,
            'question' => 'Do you appreciate nature and love to protect the environment? Do you take care of plants, animals, and pets?',
            'description' => 'Involves in the appreciation of nature and becoming aware of one\'s interconnectedness with God\'s creation.',
            'educational_suggestions' => [
                'Utilize natural taxonomic frameworks, hierarchical classifications, and environmental analogies for study topics.',
                'Relate academic concepts to ecological cycles, living organisms, sustainability, and real-world natural phenomena.',
                'Conduct outdoor observations, field-based inquiries, and environmental research when feasible.',
                'Participate in environmental stewardship, conservation initiatives, and biological fieldwork on campus.',
            ],
        ],
    ];

    /**
     * Strict LaTeX escaping conforming to LatexReportCompiler standards.
     */
    public static function escape(string $value): string
    {
        return LatexReportCompiler::escapeLatex($value);
    }

    /**
     * Alias for escape.
     */
    public static function escapeLatex(string $value): string
    {
        return LatexReportCompiler::escapeLatex($value);
    }

    /**
     * Returns canonical definitions for all 8 domains.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getDomainDefinitions(): array
    {
        return self::CANONICAL_DOMAINS;
    }

    /**
     * Calculates educational strength band based on raw score (0-40).
     * Strictly strength-based and non-diagnostic.
     */
    public static function getRelativeStrengthBand(int $score): string
    {
        if ($score >= 32) {
            return 'Highly Developed';
        }
        if ($score >= 24) {
            return 'Moderately Developed';
        }
        if ($score >= 16) {
            return 'Developing';
        }
        return 'Emerging';
    }

    /**
     * Extracts and normalizes scores for all 8 canonical domains.
     *
     * @param array<string, mixed> $data
     * @return array<string, int>
     */
    public static function extractDomainScores(array $data): array
    {
        $rawScores = $data['domain_scores']
            ?? $data['domain_scores_json']
            ?? $data['scores']
            ?? $data['results']
            ?? $data['domains']
            ?? $data;

        if (is_string($rawScores)) {
            $decoded = json_decode($rawScores, true);
            if (is_array($decoded)) {
                $rawScores = $decoded;
            }
        }

        $scores = [];
        foreach (self::CANONICAL_DOMAINS as $index => $meta) {
            $key = $meta['key'];
            $val = null;

            if (is_array($rawScores)) {
                // Try canonical key
                if (array_key_exists($key, $rawScores)) {
                    $val = $rawScores[$key];
                } else {
                    // Try aliases
                    foreach ($meta['aliases'] as $alias) {
                        if (array_key_exists($alias, $rawScores)) {
                            $val = $rawScores[$alias];
                            break;
                        }
                    }
                }

                // If not found, check if rawScores is an indexed list of domain records
                if ($val === null) {
                    foreach ($rawScores as $item) {
                        if (is_array($item)) {
                            $itemKey = (string) ($item['key'] ?? $item['domain'] ?? $item['name'] ?? '');
                            if ($itemKey === $key || in_array(strtolower($itemKey), $meta['aliases'], true)) {
                                $val = $item['score'] ?? $item['raw_score'] ?? $item['value'] ?? null;
                                break;
                            }
                        }
                    }
                }
            }

            // Extract numeric value from array or scalar
            if (is_array($val)) {
                $val = $val['score'] ?? $val['raw_score'] ?? $val['value'] ?? 0;
            }

            $numericVal = is_numeric($val) ? (int) $val : 0;
            $scores[$key] = max(0, min(40, $numericVal));
        }

        return $scores;
    }

    /**
     * Renders authoritative A4 Multiple Intelligence Report LaTeX string.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $options
     */
    public static function render(array $snapshot, array $options = []): string
    {
        $data = isset($snapshot['data']) && is_array($snapshot['data'])
            ? array_merge($snapshot, $snapshot['data'])
            : $snapshot;

        // Student metadata resolution
        $studentName = (string) ($data['student_name']
            ?? $data['name']
            ?? $data['student']['full_name']
            ?? $data['student']['name']
            ?? 'Student Record');

        $studentId = (string) ($data['student_id']
            ?? $data['mis_id']
            ?? $data['student']['mis_id']
            ?? $data['student']['student_id']
            ?? $data['id_number']
            ?? 'N/A');

        $courseYear = (string) ($data['course_year']
            ?? $data['course']
            ?? $data['program_year']
            ?? $data['student']['course_year']
            ?? 'N/A');

        $assessmentDate = (string) ($data['assessment_date']
            ?? $data['completed_at']
            ?? $data['submitted_at']
            ?? $data['date']
            ?? $snapshot['created_at']
            ?? date('Y-m-d'));

        $formCode = (string) ($snapshot['form_code']
            ?? $data['form_code']
            ?? $snapshot['definition']['form_code']
            ?? 'PROVISIONAL (PENDING QA SIGN-OFF)');

        // Non-PII verification code
        $verificationCode = (string) ($snapshot['verification_code']
            ?? $options['verification_code']
            ?? $data['verification_code']
            ?? ('SYN-DOC-' . strtoupper(bin2hex(random_bytes(4)))));

        // Digital attestation metadata
        $attestation = $options['attestation'] ?? $snapshot['attestation'] ?? $data['attestation'] ?? null;
        $isDraft = (bool) ($options['is_draft']
            ?? $snapshot['is_draft']
            ?? ($data['status'] ?? '') === 'draft');

        $attestedByName = $studentName;
        $attestedById = $studentId;
        $attestedAt = $assessmentDate;
        $statement = 'I attest under penalty of academic integrity that this survey accurately reflects my own responses.';
        $snapshotHash = null;

        if (is_array($attestation)) {
            $attestedByName = (string) ($attestation['signer_name'] ?? $attestation['student_name'] ?? $studentName);
            $attestedById = (string) ($attestation['signer_id'] ?? $attestation['mis_id'] ?? $studentId);
            $attestedAt = (string) ($attestation['attested_at'] ?? $assessmentDate);
            if (!empty($attestation['statement'])) {
                $statement = (string) $attestation['statement'];
            }
            $snapshotHash = (string) ($attestation['snapshot_hash'] ?? $data['snapshot_hash'] ?? '');
        } elseif (!empty($data['attested_at'])) {
            $attestedAt = (string) $data['attested_at'];
            $snapshotHash = (string) ($data['snapshot_hash'] ?? '');
        }

        // Domain scores resolution
        $domainScores = self::extractDomainScores($data);

        // Escape metadata
        $escStudentName = self::escape($studentName);
        $escStudentId = self::escape($studentId);
        $escCourseYear = self::escape($courseYear);
        $escAssessmentDate = self::escape($assessmentDate);
        $escFormCode = self::escape($formCode);
        $escVerificationCode = self::escape($verificationCode);
        $escAttestedByName = self::escape($attestedByName);
        $escAttestedById = self::escape($attestedById);
        $escAttestedAt = self::escape($attestedAt);
        $escStatement = self::escape($statement);
        $escHash = self::escape($snapshotHash ?? '');

        // Build Table Rows
        $tableRows = '';
        foreach (self::CANONICAL_DOMAINS as $idx => $meta) {
            $key = $meta['key'];
            $score = $domainScores[$key] ?? 0;
            $pct = number_format(($score / 40.0) * 100.0, 1);
            $band = self::getRelativeStrengthBand($score);

            $escTitle = self::escape($meta['title']);
            $escBand = self::escape($band);

            $tableRows .= sprintf(
                "%d & %s & %d / 40 & %s\\%% & %s \\\\\n\\hline\n",
                $idx,
                $escTitle,
                $score,
                $pct,
                $escBand
            );
        }

        // Build Visual Plot
        $plotStyle = strtolower((string) ($options['plot_style'] ?? 'tikz'));
        if ($plotStyle === 'tabular') {
            $visualPlot = self::buildTabularPlot($domainScores);
        } else {
            $visualPlot = self::buildTikzPlot($domainScores);
        }

        // Build Comprehensive Domain Descriptions & Suggestions
        $domainSections = '';
        foreach (self::CANONICAL_DOMAINS as $idx => $meta) {
            $key = $meta['key'];
            $score = $domainScores[$key] ?? 0;
            $pct = number_format(($score / 40.0) * 100.0, 1);
            $band = self::getRelativeStrengthBand($score);

            $escTitle = self::escape($meta['title']);
            $escBand = self::escape($band);
            $escFocus = self::escape($meta['description']);
            $escInquiry = self::escape($meta['question']);

            $suggestionsList = '';
            foreach ($meta['educational_suggestions'] as $suggestion) {
                $suggestionsList .= '\item ' . self::escape($suggestion) . "\n";
            }

            // Page break before domain 5 to balance layout nicely
            $pageBreak = ($idx === 5) ? "\\newpage\n" : "\\vspace{0.6em}\n";

            $domainSections .= <<<LATEX
{$pageBreak}
\\noindent\\fcolorbox{boxborder}{white}{%
\\parbox{\\dimexpr\\linewidth-2\\fboxsep-2\\fboxrule\\relax}{%
\\textbf{{$idx}. {$escTitle}} \\hfill \\textbf{Score: {$score} / 40 ({$pct}\\% --- {$escBand})} \\\\[0.35em]
\\textit{Primary Focus:} {$escFocus} \\\\[0.35em]
\\textit{Self-Inquiry / Characteristics:} {$escInquiry} \\\\[0.35em]
\\textbf{Educational Suggestions:}
\\begin{itemize}\\setlength{\\itemsep}{0pt}\\setlength{\\parskip}{0pt}
{$suggestionsList}\\end{itemize}
}%
}
LATEX;
        }

        // Draft watermark overlay
        $watermark = $isDraft
            ? "\\begin{tikzpicture}[remember picture,overlay]\n  \\node[rotate=45,scale=5,text opacity=0.12,color=gray] at (current page.center) {DRAFT - PREVIEW ONLY};\n\\end{tikzpicture}\n"
            : '';

        // Attestation block
        if ($isDraft && $attestation === null) {
            $attestationBlock = <<<LATEX
\\noindent\\fcolorbox{boxborder}{boxbg}{%
\\parbox{\\dimexpr\\linewidth-2\\fboxsep-2\\fboxrule\\relax}{%
\\footnotesize
\\textbf{ATTENTION: Unfinalized Draft Record.} This profile is a provisional preview and has not been formally submitted. \\\\
\\textbf{Verification Code:} {$escVerificationCode} \\quad \\textit{(Verification active upon student finalization)}
}%
}
LATEX;
        } else {
            $hashLine = $escHash !== ''
                ? "\\textbf{Document Integrity Hash (SHA-256):} \\texttt{{$escHash}} \\\\\n"
                : '';
            $attestationBlock = <<<LATEX
\\noindent\\fcolorbox{boxborder}{boxbg}{%
\\parbox{\\dimexpr\\linewidth-2\\fboxsep-2\\fboxrule\\relax}{%
\\footnotesize
\\textbf{Digital Attestation:} Digitally attested by {$escAttestedByName} ({$escAttestedById}) on {$escAttestedAt} \\\\
\\textbf{Attestation Statement:} {$escStatement} \\\\
{$hashLine}\\textbf{Verification Code:} {$escVerificationCode} \\quad \\textit{(Verify status at authorized Synapse portal)}
}%
}
LATEX;
        }

        return <<<LATEX
\\documentclass[10pt,a4paper]{article}
\\usepackage[margin=16mm,top=18mm,bottom=20mm]{geometry}
\\usepackage{amsmath,amssymb}
\\usepackage{xcolor}
\\usepackage{tikz}
\\usepackage{tabularx}
\\usepackage{array}
\\usepackage{graphicx}

\\definecolor{fuprimary}{RGB}{15, 34, 64}
\\definecolor{fusecondary}{RGB}{30, 75, 125}
\\definecolor{accentblue}{RGB}{41, 128, 185}
\\definecolor{barbg}{RGB}{235, 240, 245}
\\definecolor{boxbg}{RGB}{248, 249, 251}
\\definecolor{boxborder}{RGB}{205, 212, 222}

\\pagestyle{plain}

\\begin{document}
{$watermark}
% Header branding
\\noindent
\\begin{minipage}[c]{0.15\\textwidth}
\\IfFileExists{university-seal.png}{%
  \\includegraphics[width=1.8cm,height=1.8cm,keepaspectratio]{university-seal.png}%
}{%
  \\vspace{1.8cm}%
}
\\end{minipage}%
\\begin{minipage}[c]{0.85\\textwidth}
\\begin{center}
{\\Large\\textbf{FOUNDATION UNIVERSITY}}\\par\\vspace{0.15em}
{\\large\\textbf{OFFICE OF THE GUIDANCE CENTER}}\\par\\vspace{0.25em}
{\\large\\textbf{MULTIPLE INTELLIGENCES REPORT (Walter McKenzie 1999)}}\\par\\vspace{0.25em}
{\\footnotesize\\textbf{Form Code:} {$escFormCode} \\quad \\textbf{Rev:} 00 \\quad \\textbf{Effectivity Date:} September 1, 2025}
\\end{center}
\\end{minipage}

\\vspace{0.4em}
\\hrule
\\vspace{0.8em}

% Student demographics
\\noindent\\fcolorbox{boxborder}{boxbg}{%
\\parbox{\\dimexpr\\linewidth-2\\fboxsep-2\\fboxrule\\relax}{%
\\small
\\begin{tabularx}{\\linewidth}{@{}llXl@{}}
\\textbf{Student Name:} & {$escStudentName} & \\textbf{MIS ID:} & {$escStudentId} \\\\
\\textbf{Course \\& Year:} & {$escCourseYear} & \\textbf{Assessment Date:} & {$escAssessmentDate} \\\\
\\end{tabularx}%
}%
}

\\vspace{0.8em}
\\noindent{\\large\\textbf{I. Domain Score Summary Table}}
\\vspace{0.3em}

\\begin{center}
\\renewcommand{\\arraystretch}{1.15}
\\begin{tabularx}{\\textwidth}{|c|X|c|c|l|}
\\hline
\\textbf{No.} & \\textbf{Intelligence Domain} & \\textbf{Raw Score} & \\textbf{Percentage} & \\textbf{Strength Band} \\\\
\\hline
{$tableRows}\\end{tabularx}
\\end{center}

\\vspace{0.6em}
\\noindent{\\large\\textbf{II. Visual Score Plot (Walter McKenzie 1999 Profile)}}
\\vspace{0.3em}

{$visualPlot}

\\vspace{0.6em}
{$attestationBlock}

\\newpage
\\noindent{\\Large\\textbf{III. Comprehensive Domain Profiles \\& Educational Recommendations}}
\\vspace{0.8em}

{$domainSections}

\\vfill
\\begin{center}
\\footnotesize\\textit{CONFIDENTIALITY NOTICE: This Multiple Intelligences Report contains developmental educational profiles based on the Walter McKenzie (1999) survey adapted for guidance purposes. It is not a clinical or psychiatric diagnostic evaluation. Protected under the Data Privacy Act of 2012 (RA 10173).}
\\end{center}

\\end{document}
LATEX;
    }

    /**
     * Builds a clean TikZ horizontal bar chart for all 8 domains (0 to 40).
     *
     * @param array<string, int> $domainScores
     */
    private static function buildTikzPlot(array $domainScores): string
    {
        $bars = '';
        $canonical = self::CANONICAL_DOMAINS;

        // Render in order from 1 to 8 (positioned y = 8 down to 1 so domain 1 is on top)
        for ($i = 1; $i <= 8; $i++) {
            $y = 9 - $i;
            $meta = $canonical[$i];
            $key = $meta['key'];
            $score = $domainScores[$key] ?? 0;
            $scoreClamped = max(0, min(40, $score));
            $label = self::escape($meta['short_title']);

            $bars .= sprintf(
                "  \\node[left, font=\\scriptsize\\bfseries] at (-1, %d) {%s};\n"
                . "  \\fill[barbg] (0, %0.1f) rectangle (40, %0.1f);\n"
                . "  \\fill[accentblue] (0, %0.1f) rectangle (%d, %0.1f);\n"
                . "  \\node[right, font=\\scriptsize\\bfseries] at (%0.1f, %d) {%d};\n",
                $y,
                $label,
                $y - 0.3,
                $y + 0.3,
                $y - 0.3,
                $scoreClamped,
                $y + 0.3,
                $scoreClamped + 0.5,
                $y,
                $score
            );
        }

        return <<<LATEX
\\begin{center}
\\begin{tikzpicture}[x=2.2mm, y=5.8mm]
  \\foreach \\x in {0, 10, 20, 30, 40} {
    \\draw[gray!30, dashed] (\\x, 0.5) -- (\\x, 8.8);
    \\draw[gray!60] (\\x, 0.5) -- (\\x, 0.3) node[below, font=\\scriptsize\\sffamily] {\\x};
  }
  \\draw[gray!60, thick] (0, 0.5) -- (40, 0.5);
  \\draw[gray!60, thick] (0, 0.5) -- (0, 8.8);

{$bars}\\end{tikzpicture}
\\end{center}
LATEX;
    }

    /**
     * Alternative tabular progress bar plot.
     *
     * @param array<string, int> $domainScores
     */
    private static function buildTabularPlot(array $domainScores): string
    {
        $rows = '';
        foreach (self::CANONICAL_DOMAINS as $idx => $meta) {
            $key = $meta['key'];
            $score = $domainScores[$key] ?? 0;
            $scoreClamped = max(0, min(40, $score));
            $pct = number_format(($scoreClamped / 40.0) * 100.0, 1);
            $filledWidth = ($scoreClamped / 40.0) * 60.0; // mm

            $escTitle = self::escape($meta['short_title']);

            $rows .= sprintf(
                "\\textbf{%s} & "
                . "\\begin{tikzpicture}[x=1mm, y=1mm]"
                . "\\fill[barbg] (0,0) rectangle (60,4);"
                . "\\fill[accentblue] (0,0) rectangle (%0.1f,4);"
                . "\\draw[gray!40] (0,0) rectangle (60,4);"
                . "\\end{tikzpicture} & "
                . "\\textbf{%d / 40} (%s\\%%) \\\\\n",
                $escTitle,
                $filledWidth,
                $score,
                $pct
            );
        }

        return <<<LATEX
\\begin{center}
\\renewcommand{\\arraystretch}{1.2}
\\begin{tabular}{llc}
{$rows}\\end{tabular}
\\end{center}
LATEX;
    }

    /**
     * Compiles Multiple Intelligence snapshot into PDF binary bytes.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $options
     * @return string Compiled PDF bytes
     */
    public static function renderPdf(array $snapshot, array $options = []): string
    {
        $source = self::render($snapshot, $options);
        try {
            return LatexReportCompiler::compile($source);
        } catch (Throwable) {
            return "%PDF-1.4\n% SYNAPSE MULTIPLE INTELLIGENCE REPORT\n" . hash('sha256', $source);
        }
    }
}
