<?php

declare(strict_types=1);

namespace App\Services\Reporting;

final class GuidanceFormLatex
{
    public static function escape(string $value): string
    {
        return LatexReportCompiler::escapeLatex($value);
    }

    public static function escapeLatex(string $value): string
    {
        return LatexReportCompiler::escapeLatex($value);
    }

    public static function check(bool $condition): string
    {
        return $condition ? '$\\boxtimes$' : '$\\square$';
    }

    /**
     * Renders A4 LaTeX document for a guidance form record.
     *
     * @param array<string, mixed> $formInstance
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $revision
     * @param array<string, mixed> $formData Decrypted form values
     * @param array<string, mixed>|null $attestation Digital attestation details
     * @param string|null $verificationCode Non-PII verification code
     * @param bool $isDraft
     */
    public static function render(
        array $formInstance,
        array $definition,
        array $revision,
        array $formData,
        ?array $attestation = null,
        ?string $verificationCode = null,
        bool $isDraft = false
    ): string {
        $key = (string) ($definition['internal_key'] ?? '');
        $code = (string) ($definition['form_code'] ?? '');
        if ($key === 'guidance_appointment_form' || str_contains($code, 'GAP-006')) {
            return self::renderAppointmentSlip([
                'instance' => $formInstance,
                'definition' => $definition,
                'revision' => $revision,
                'data' => $formData,
                'attestation' => $attestation,
                'verification_code' => $verificationCode,
                'is_draft' => $isDraft,
            ]);
        }
        if ($key === 'guidance_call_slip' || str_contains($code, 'CSP-007')) {
            return self::renderCallSlip([
                'instance' => $formInstance,
                'definition' => $definition,
                'revision' => $revision,
                'data' => $formData,
                'attestation' => $attestation,
                'verification_code' => $verificationCode,
                'is_draft' => $isDraft,
            ]);
        }
        if ($key === 'psychosocial_support_interview' || str_contains($code, 'PIF-008')) {
            return self::renderPsychosocialInterview([
                'instance' => $formInstance,
                'definition' => $definition,
                'revision' => $revision,
                'data' => $formData,
                'attestation' => $attestation,
                'verification_code' => $verificationCode,
                'is_draft' => $isDraft,
            ]);
        }
        if ($key === 'psychosocial_support_contact' || str_contains($code, 'PCF-008')) {
            return self::renderPsychosocialContact([
                'instance' => $formInstance,
                'definition' => $definition,
                'revision' => $revision,
                'data' => $formData,
                'attestation' => $attestation,
                'verification_code' => $verificationCode,
                'is_draft' => $isDraft,
            ]);
        }
        if ($key === 'counseling_contact_sheet' || str_contains($code, 'CCF-009')) {
            return self::renderCounselingContact([
                'instance' => $formInstance,
                'definition' => $definition,
                'revision' => $revision,
                'data' => $formData,
                'attestation' => $attestation,
                'verification_code' => $verificationCode,
                'is_draft' => $isDraft,
            ]);
        }
        if ($key === 'psychosocial_support_evaluation' || str_contains($code, 'PEF-008')) {
            return self::renderPsychosocialEvaluation([
                'instance' => $formInstance,
                'definition' => $definition,
                'revision' => $revision,
                'data' => $formData,
                'attestation' => $attestation,
                'verification_code' => $verificationCode,
                'is_draft' => $isDraft,
            ]);
        }
        if ($key === 'counseling_evaluation' || str_contains($code, 'CEF-009')) {
            return self::renderCounselingEvaluation([
                'instance' => $formInstance,
                'definition' => $definition,
                'revision' => $revision,
                'data' => $formData,
                'attestation' => $attestation,
                'verification_code' => $verificationCode,
                'is_draft' => $isDraft,
            ]);
        }

        $title = self::escape((string) ($definition['canonical_title'] ?? 'Controlled Guidance Record'));
        $code = self::escape((string) ($definition['form_code'] ?? 'F-OGC-000-00'));
        $rev = self::escape((string) ($revision['revision_number'] ?? '00'));
        $effectivity = self::escape((string) ($revision['effectivity_date'] ?? 'September 1, 2025'));
        $instanceNum = self::escape((string) ($formInstance['instance_number'] ?? ''));

        $watermark = $isDraft
            ? "\\usepackage{draftwatermark}\n\\SetWatermarkText{DRAFT - PREVIEW ONLY}\n\\SetWatermarkScale{0.6}\n\\SetWatermarkColor[gray]{0.85}\n"
            : '';

        $verificationLine = $verificationCode !== null
            ? '\\textbf{Verification Code:} ' . self::escape($verificationCode) . ' \\quad \\textit{(Verify status at authorized Synapse portal)}'
            : '\\textbf{Status:} Provisional Draft';

        $attestationBanner = '';
        if ($attestation !== null) {
            $signer = self::escape((string) ($attestation['signer_role'] ?? 'Counselor'));
            $date = self::escape((string) ($attestation['attested_at'] ?? ''));
            $hash = self::escape(substr((string) ($attestation['snapshot_hash'] ?? ''), 0, 16) . '...');
            $attestationBanner = "\\begin{mdframed}[backgroundcolor=gray!10]\n"
                . "\\small \\textbf{Digitally Finalized by:} {$signer} on {$date} \\\\\n"
                . "\\textbf{Record Hash (SHA-256):} \\texttt{{$hash}} \\quad \\textbf{Method:} Authenticated Account Attestation\n"
                . "\\end{mdframed}\\par\\vspace{0.8em}\n";
        } elseif ($isDraft) {
            $attestationBanner = "\\begin{mdframed}[backgroundcolor=orange!10]\n"
                . "\\small \\textbf{ATTENTION:} Unfinalized draft record. Not official without authenticated attestation.\n"
                . "\\end{mdframed}\\par\\vspace{0.8em}\n";
        }

        // Build dynamic content based on internal key
        $body = self::renderBody($key, $formData);
        $metaLine = "\\textbf{Rev:} {$rev} \\quad \\textbf{Effectivity Date:} {$effectivity}"
            . ($instanceNum !== '' ? " \\quad \\textbf{Instance:} {$instanceNum}" : '');
        $headerColors = DocumentHeaderLatex::colorDefinitions();
        $fancy = DocumentHeaderLatex::fancyHeaderFooter(
            'Office of the Guidance Center',
            "\\textbf{Form Code:} {$code}",
            $verificationLine,
            'Page \\thepage\\ of 1'
        );
        $headerContainer = DocumentHeaderLatex::headerContainer(
            'guidance',
            'OFFICE OF THE GUIDANCE CENTER',
            $title,
            $metaLine
        );

        return <<<LATEX
\\documentclass[10pt,a4paper]{article}
\\pdfminorversion=4
\\usepackage[margin=18mm,top=20mm,bottom=22mm,headheight=14pt,headsep=6mm]{geometry}
\\usepackage{amsmath,amssymb}
\\usepackage{graphicx}
\\usepackage{xcolor}
\\usepackage[framemethod=tikz]{mdframed}
\\usepackage{array}
\\usepackage{tabularx}
\\usepackage{fancyhdr}
{$headerColors}
\\setlength{\\parindent}{0pt}
{$fancy}
{$watermark}

\\begin{document}

{$headerContainer}

{$attestationBanner}

{$body}

\\vfill
\\begin{center}
\\footnotesize\\textit{CONFIDENTIALITY NOTICE: This document contains strictly privileged psychological/guidance information protected under RA 10173 and professional ethics standards. Unauthorized disclosure is prohibited.}
\\end{center}

\\end{document}
LATEX;
    }

    /**
     * Renders Appointment Slip document.
     * Canonical code: F-OGC-GAP-006-00 (in-doc: F-OGC-GRE-GAP-006-00), rev 00, eff 2025-09-01.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $options Supports layout => 'single' | 'quad' | '4up'
     */
    public static function renderAppointmentSlip(array $snapshot, array $options = []): string
    {
        $data = isset($snapshot['data']) && is_array($snapshot['data'])
            ? array_merge($snapshot, $snapshot['data'])
            : $snapshot;

        $layout = strtolower((string) ($options['layout'] ?? $snapshot['layout'] ?? 'single'));
        $isQuad = in_array($layout, ['quad', '4up', '4-up'], true);

        $instance = (array) ($snapshot['instance'] ?? []);
        $attestation = $options['attestation'] ?? $snapshot['attestation'] ?? null;
        $verificationCode = $options['verification_code'] ?? $snapshot['verification_code'] ?? ($snapshot['verification']['verification_code'] ?? null);
        $isDraft = (bool) ($options['is_draft'] ?? $snapshot['is_draft'] ?? (($instance['status'] ?? '') === 'draft'));

        $num = self::escape((string) ($data['appointment_number'] ?? $instance['instance_number'] ?? $snapshot['instance_number'] ?? ''));
        $name = self::escape((string) ($data['student_name'] ?? ''));
        $id = self::escape((string) ($data['student_id'] ?? ''));
        $course = self::escape((string) ($data['course_year'] ?? ''));
        $contact = self::escape((string) ($data['contact_number'] ?? $data['phone'] ?? ''));
        $date = self::escape((string) ($data['scheduled_date'] ?? $data['appointment_date'] ?? ''));
        $rawTime = (string) ($data['scheduled_time'] ?? $data['appointment_time'] ?? '');
        $time = self::escape($rawTime);
        $counselor = self::escape((string) ($data['counselor_name'] ?? $data['assigned_counselor'] ?? ''));

        $purposeRaw = (string) ($data['purpose'] ?? '');
        $purposeOtherRaw = (string) ($data['purpose_other'] ?? $data['other_purpose'] ?? '');

        $normPurpose = strtolower(str_replace([' ', '-', '/'], '_', trim($purposeRaw)));
        $isPersonalSocial = ($normPurpose === 'personal_social' || $normPurpose === 'counseling');
        $isAcademic = ($normPurpose === 'academic');
        $isCareer = ($normPurpose === 'career');
        $isConsultation = ($normPurpose === 'consultation' || $normPurpose === 'inquiry');
        $isOthers = ($normPurpose === 'others'
            || $normPurpose === 'other'
            || $normPurpose === 'routine_interview'
            || $normPurpose === 'follow_up'
            || $normPurpose === 'intake'
            || (!$isPersonalSocial && !$isAcademic && !$isCareer && !$isConsultation && $normPurpose !== ''));

        $checkPersonalSocial = self::check($isPersonalSocial);
        $checkAcademic = self::check($isAcademic);
        $checkCareer = self::check($isCareer);
        $checkOthers = self::check($isOthers);
        $checkConsultation = self::check($isConsultation);

        $legacyOtherLabel = match ($normPurpose) {
            'routine_interview' => 'Routine Interview',
            'follow_up'         => 'Follow-up',
            'intake'            => 'Intake',
            default             => $purposeRaw,
        };
        $otherText = $purposeOtherRaw !== ''
            ? $purposeOtherRaw
            : ($isOthers && $normPurpose !== 'others' && $normPurpose !== 'other' ? $legacyOtherLabel : '');
        $othersSpecify = ($isOthers && $otherText !== '') ? self::escape($otherText) : '';

        // Preferred schedule slots matching official OGC form
        $schedRaw = trim((string) ($data['preferred_schedule'] ?? ''));
        $normSched = strtolower(str_replace(' ', '', $schedRaw));
        if ($normSched === '' && $rawTime !== '') {
            $ts = strtotime('1970-01-01 ' . trim($rawTime));
            if ($ts !== false) {
                $mins = ((int) date('G', $ts)) * 60 + (int) date('i', $ts);
                if ($mins >= 540 && $mins <= 639) {
                    $normSched = '9:00-10:40am';
                } elseif ($mins >= 640 && $mins <= 700) {
                    $normSched = '10:40-11:40am';
                } elseif ($mins >= 840 && $mins <= 880) {
                    $normSched = '2:00-2:40pm';
                } elseif ($mins >= 900 && $mins <= 940) {
                    $normSched = '3:00-3:40pm';
                }
            }
        }

        $checkSlot1 = self::check($normSched === '9:00-10:40am');
        $checkSlot2 = self::check($normSched === '10:40-11:40am');
        $checkSlot3 = self::check($normSched === '2:00-2:40pm');
        $checkSlot4 = self::check($normSched === '3:00-3:40pm');

        $watermark = $isDraft
            ? "\\usepackage{draftwatermark}\n\\SetWatermarkText{DRAFT - PREVIEW ONLY}\n\\SetWatermarkScale{0.6}\n\\SetWatermarkColor[gray]{0.85}\n"
            : '';

        if ($isQuad) {
            return self::renderAppointmentSlipQuad(
                $num,
                $name,
                $id,
                $course,
                $contact,
                $date,
                $time,
                $counselor,
                $checkPersonalSocial,
                $checkAcademic,
                $checkCareer,
                $checkOthers,
                $othersSpecify,
                $checkConsultation,
                $checkSlot1,
                $checkSlot2,
                $checkSlot3,
                $checkSlot4,
                $verificationCode,
                $attestation,
                $watermark,
                $isDraft
            );
        }

        return self::renderAppointmentSlipSingle(
            $num,
            $name,
            $id,
            $course,
            $contact,
            $date,
            $time,
            $counselor,
            $checkPersonalSocial,
            $checkAcademic,
            $checkCareer,
            $checkOthers,
            $othersSpecify,
            $checkConsultation,
            $checkSlot1,
            $checkSlot2,
            $checkSlot3,
            $checkSlot4,
            $verificationCode,
            $attestation,
            $watermark,
            $isDraft
        );
    }

    private static function renderAppointmentSlipSingle(
        string $num,
        string $name,
        string $id,
        string $course,
        string $contact,
        string $date,
        string $time,
        string $counselor,
        string $checkPersonalSocial,
        string $checkAcademic,
        string $checkCareer,
        string $checkOthers,
        string $othersSpecify,
        string $checkConsultation,
        string $checkSlot1,
        string $checkSlot2,
        string $checkSlot3,
        string $checkSlot4,
        ?string $verificationCode,
        ?array $attestation,
        string $watermark,
        bool $isDraft
    ): string {
        $verificationLine = $verificationCode !== null && $verificationCode !== ''
            ? '\\textbf{Verification Code:} ' . self::escape($verificationCode) . ' \\quad \\textit{(Verify status at authorized Synapse portal)}'
            : '\\textbf{Status:} Provisional Draft';

        $attestationBanner = '';
        if ($attestation !== null) {
            $signer = self::escape((string) ($attestation['signer_role'] ?? $attestation['signer_name'] ?? 'Counselor'));
            $attDate = self::escape((string) ($attestation['attested_at'] ?? ''));
            $hash = self::escape(substr((string) ($attestation['snapshot_hash'] ?? ''), 0, 16) . '...');
            $attestationBanner = "\\begin{mdframed}[backgroundcolor=gray!10]\n"
                . "\\small \\textbf{Digitally Finalized by:} {$signer} on {$attDate} \\\\\n"
                . "\\textbf{Record Hash (SHA-256):} \\texttt{{$hash}} \\quad \\textbf{Method:} Authenticated Account Attestation\n"
                . "\\end{mdframed}\\par\\vspace{0.8em}\n";
        } elseif ($isDraft) {
            $attestationBanner = "\\begin{mdframed}[backgroundcolor=orange!10]\n"
                . "\\small \\textbf{ATTENTION:} Unfinalized draft record. Not official without authenticated attestation.\n"
                . "\\end{mdframed}\\par\\vspace{0.8em}\n";
        }

        $headerColors = DocumentHeaderLatex::colorDefinitions();
        $fancy = DocumentHeaderLatex::fancyHeaderFooter(
            'Office of the Guidance Center',
            '\\textbf{Form Code:} F-OGC-GAP-006-00',
            $verificationLine,
            'Page \\thepage\\ of 1'
        );
        $metaLine = '\\textbf{In-Doc Code:} F-OGC-GRE-GAP-006-00 \\quad \\textbf{Revision Number:} 00 \\quad \\textbf{Effectivity Date:} September 1, 2025'
            . ($num !== '' ? " \\quad \\textbf{Appt No:} {$num}" : '');
        $headerContainer = DocumentHeaderLatex::headerContainer(
            'guidance',
            'OFFICE OF THE GUIDANCE CENTER',
            'GUIDANCE APPOINTMENT FORM',
            $metaLine
        );
        $dateTimeDisplay = trim($date . ($time !== '' ? ' ' . $time : ''));
        $othersLine = $othersSpecify !== '' ? $othersSpecify : '\\underline{\\hspace{4cm}}';

        return <<<LATEX
\\documentclass[10pt,a4paper]{article}
\\pdfminorversion=4
\\usepackage[margin=18mm,top=20mm,bottom=22mm,headheight=14pt,headsep=6mm]{geometry}
\\usepackage{amsmath,amssymb}
\\usepackage{graphicx}
\\usepackage{xcolor}
\\usepackage[framemethod=tikz]{mdframed}
\\usepackage{array}
\\usepackage{tabularx}
\\usepackage{fancyhdr}
{$headerColors}
\\setlength{\\parindent}{0pt}
{$fancy}
{$watermark}

\\begin{document}

{$headerContainer}

{$attestationBanner}

\\noindent\\begin{tabularx}{\\textwidth}{|l|X|l|X|}
\\hline
\\textbf{Date:} & {$dateTimeDisplay} & \\textbf{Appointment No:} & {$num} \\\\ \\hline
\\textbf{Name:} & {$name} & \\textbf{Student ID:} & {$id} \\\\ \\hline
\\textbf{Grade/Course/YR. Level:} & {$course} & \\textbf{Contact Number:} & {$contact} \\\\ \\hline
\\textbf{Assigned Counselor:} & \\multicolumn{3}{l|}{{$counselor}} \\\\ \\hline
\\end{tabularx}\\par\\vspace{1.2em}

\\noindent\\begin{tabularx}{\\textwidth}{|X|X|}
\\hline
\\textbf{Reason:} & \\textbf{Preferred Sched} \\\\ \\hline
\\textbf{A. Counseling/Psychosocial} & {$checkSlot1} 9:00-10:40AM \\\\
\\quad {$checkPersonalSocial} Personal/Social & {$checkSlot2} 10:40-11:40AM \\\\
\\quad {$checkAcademic} Academic & {$checkSlot3} 2:00-2:40PM \\\\
\\quad {$checkCareer} Career & {$checkSlot4} 3:00-3:40PM \\\\
\\quad {$checkOthers} Others please specify: {$othersLine} & \\\\
\\textbf{B.} {$checkConsultation} \\textbf{Consultation} & \\\\ \\hline
\\end{tabularx}\\par\\vspace{1.2em}

\\begin{mdframed}[backgroundcolor=gray!5]
\\small\\textbf{Note:} Please be informed that you will be scheduled 2 days prior to your appointment, except for emergencies.
\\end{mdframed}

\\vfill
\\noindent{\\footnotesize\\textbf{Department Office:} Office of the Guidance Center (OGC) \\hfill \\textbf{Effectivity Date:} September 1, 2025 \\quad \\textbf{Revision Number:} 00}\\par\\vspace{0.3em}
\\begin{center}
\\footnotesize\\textit{CONFIDENTIALITY NOTICE: This document contains strictly privileged psychological/guidance information protected under RA 10173 and professional ethics standards. Unauthorized disclosure or reproduction is strictly prohibited.}
\\end{center}

\\end{document}
LATEX;
    }

    private static function renderAppointmentSlipQuad(
        string $num,
        string $name,
        string $id,
        string $course,
        string $contact,
        string $date,
        string $time,
        string $counselor,
        string $checkPersonalSocial,
        string $checkAcademic,
        string $checkCareer,
        string $checkOthers,
        string $othersSpecify,
        string $checkConsultation,
        string $checkSlot1,
        string $checkSlot2,
        string $checkSlot3,
        string $checkSlot4,
        ?string $verificationCode,
        ?array $attestation,
        string $watermark,
        bool $isDraft
    ): string {
        $verificationLine = $verificationCode !== null && $verificationCode !== ''
            ? '\\textbf{Verif Code:} ' . self::escape($verificationCode)
            : '\\textbf{Status:} Draft';

        $attestationLine = '';
        if ($attestation !== null) {
            $signer = self::escape((string) ($attestation['signer_role'] ?? 'Counselor'));
            $hash = self::escape(substr((string) ($attestation['snapshot_hash'] ?? ''), 0, 10) . '...');
            $attestationLine = "\\textbf{Attested:} {$signer} ({$hash})";
        } elseif ($isDraft) {
            $attestationLine = "\\textbf{Notice:} Draft preview (Unofficial)";
        } else {
            $attestationLine = "\\textbf{Attestation:} Official Record";
        }

        $quadrant = self::renderAppointmentQuadrant(
            $num,
            $name,
            $id,
            $course,
            $contact,
            $date,
            $time,
            $counselor,
            $checkPersonalSocial,
            $checkAcademic,
            $checkCareer,
            $checkOthers,
            $othersSpecify,
            $checkConsultation,
            $checkSlot1,
            $checkSlot2,
            $checkSlot3,
            $checkSlot4,
            $verificationLine,
            $attestationLine
        );

        $headerColors = DocumentHeaderLatex::colorDefinitions();

        return <<<LATEX
\\documentclass[10pt,a4paper]{article}
\\pdfminorversion=4
\\usepackage[margin=8mm,top=8mm,bottom=8mm,left=8mm,right=8mm]{geometry}
\\usepackage{amsmath,amssymb}
\\usepackage{graphicx}
\\usepackage{xcolor}
\\usepackage[framemethod=tikz]{mdframed}
\\usepackage{array}
\\usepackage{tabularx}
{$headerColors}
\\pagestyle{empty}
\\setlength{\\parindent}{0pt}
{$watermark}

\\begin{document}

\\noindent
\\begin{minipage}[t][125mm][t]{93mm}
\\vspace{0pt}
{$quadrant}
\\end{minipage}%
\\hfill%
\\begin{minipage}[t][125mm][t]{6mm}
\\vspace{0pt}
\\centering
\\vbox to 125mm{\\cleaders\\vbox to 4mm{\\vss\\rule{0.5pt}{2mm}\\vss}\\vfill}
\\end{minipage}%
\\hfill%
\\begin{minipage}[t][125mm][t]{93mm}
\\vspace{0pt}
{$quadrant}
\\end{minipage}

\\vspace{2mm}
\\noindent\\cleaders\\hbox to 4mm{\\hss\\rule{2mm}{0.5pt}\\hss}\\hfill%
\\quad\\raisebox{-0.2ex}{\\scriptsize - - - - - - \\textbf{CUT ALONG DASHED LINE} - - - - - -}\\quad%
\\cleaders\\hbox to 4mm{\\hss\\rule{2mm}{0.5pt}\\hss}\\hfill\\kern0pt
\\vspace{2mm}

\\noindent
\\begin{minipage}[t][125mm][t]{93mm}
\\vspace{0pt}
{$quadrant}
\\end{minipage}%
\\hfill%
\\begin{minipage}[t][125mm][t]{6mm}
\\vspace{0pt}
\\centering
\\vbox to 125mm{\\cleaders\\vbox to 4mm{\\vss\\rule{0.5pt}{2mm}\\vss}\\vfill}
\\end{minipage}%
\\hfill%
\\begin{minipage}[t][125mm][t]{93mm}
\\vspace{0pt}
{$quadrant}
\\end{minipage}

\\end{document}
LATEX;
    }

    private static function renderAppointmentQuadrant(
        string $num,
        string $name,
        string $id,
        string $course,
        string $contact,
        string $date,
        string $time,
        string $counselor,
        string $checkPersonalSocial,
        string $checkAcademic,
        string $checkCareer,
        string $checkOthers,
        string $othersSpecify,
        string $checkConsultation,
        string $checkSlot1,
        string $checkSlot2,
        string $checkSlot3,
        string $checkSlot4,
        string $verificationLine,
        string $attestationLine
    ): string {
        $quadHeader = DocumentHeaderLatex::compactQuadrantHeader(
            'guidance',
            'OFFICE OF THE GUIDANCE CENTER',
            'GUIDANCE APPOINTMENT FORM',
            '\\textbf{F-OGC-GAP-006-00} (\\textbf{In-Doc:} F-OGC-GRE-GAP-006-00) \\enspace \\textbf{Rev:} 00 \\enspace \\textbf{Eff:} 2025-09-01'
        );
        $dateTimeDisplay = trim($date . ($time !== '' ? ' ' . $time : ''));
        $othersLine = $othersSpecify !== '' ? $othersSpecify : '\\underline{\\hspace{2.1cm}}';

        return <<<LATEX
\\begin{mdframed}[linewidth=0.5pt,innerleftmargin=2mm,innerrightmargin=2mm,innertopmargin=2mm,innerbottommargin=2mm]
{$quadHeader}
\\vspace{0.5mm}
\\begin{tabularx}{\\linewidth}{|l|X|l|X|}
\\hline
\\textbf{\\tiny Date:} & {\\tiny {$dateTimeDisplay}} & \\textbf{\\tiny Appt No:} & {\\tiny {$num}} \\\\ \\hline
\\textbf{\\tiny Name:} & {\\tiny {$name}} & \\textbf{\\tiny ID:} & {\\tiny {$id}} \\\\ \\hline
\\textbf{\\tiny Course/Yr:} & {\\tiny {$course}} & \\textbf{\\tiny Contact:} & {\\tiny {$contact}} \\\\ \\hline
\\textbf{\\tiny Counselor:} & \\multicolumn{3}{l|}{\\tiny {$counselor}} \\\\ \\hline
\\end{tabularx}\\par\\vspace{1mm}
\\begin{tabularx}{\\linewidth}{|>{\\tiny}X|>{\\tiny}p{31mm}|}
\\hline
\\textbf{Reason:} & \\textbf{Preferred Sched} \\\\ \\hline
\\textbf{A. Counseling/Psychosocial} & {$checkSlot1} 9:00-10:40AM \\\\
\\enspace {$checkPersonalSocial} Personal/Social & {$checkSlot2} 10:40-11:40AM \\\\
\\enspace {$checkAcademic} Academic & {$checkSlot3} 2:00-2:40PM \\\\
\\enspace {$checkCareer} Career & {$checkSlot4} 3:00-3:40PM \\\\
\\enspace {$checkOthers} Others please specify: {$othersLine} & \\\\
\\textbf{B.} {$checkConsultation} \\textbf{Consultation} & \\\\ \\hline
\\end{tabularx}\\par\\vspace{1mm}
{\\tiny\\textbf{Note:} Please be informed that you will be scheduled 2 days prior to your appointment, except for emergencies.}\\par\\vspace{1mm}
{\\tiny {$verificationLine} \\hfill {$attestationLine}}\\par\\vspace{0.5mm}
{\\tiny\\centering\\textit{Department Office: Office of the Guidance Center (OGC) --- RA 10173}\\par}
\\end{mdframed}
LATEX;
    }

    /**
     * Renders Call Slip document.
     * Canonical code: F-OGC-GSP-CSP-007-00, rev 00, eff 2025-09-01.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $options Supports layout => 'single' | 'quad' | '4up'
     */
    public static function renderCallSlip(array $snapshot, array $options = []): string
    {
        $data = isset($snapshot['data']) && is_array($snapshot['data'])
            ? array_merge($snapshot, $snapshot['data'])
            : $snapshot;

        $layout = strtolower((string) ($options['layout'] ?? $snapshot['layout'] ?? 'single'));
        $isQuad = in_array($layout, ['quad', '4up', '4-up'], true);

        $instance = (array) ($snapshot['instance'] ?? []);
        $attestation = $options['attestation'] ?? $snapshot['attestation'] ?? null;
        $verificationCode = $options['verification_code'] ?? $snapshot['verification_code'] ?? ($snapshot['verification']['verification_code'] ?? null);
        $isDraft = (bool) ($options['is_draft'] ?? $snapshot['is_draft'] ?? (($instance['status'] ?? '') === 'draft'));

        $num = self::escape((string) ($data['call_slip_number'] ?? $instance['instance_number'] ?? $snapshot['instance_number'] ?? ''));
        $name = self::escape((string) ($data['student_name'] ?? ''));
        $id = self::escape((string) ($data['student_id'] ?? ''));
        $course = self::escape((string) ($data['course_year'] ?? ''));
        $date = self::escape((string) ($data['call_date'] ?? $data['scheduled_date'] ?? ''));
        $time = self::escape((string) ($data['call_time'] ?? $data['scheduled_time'] ?? ''));
        $counselor = self::escape((string) ($data['issuing_counselor_name'] ?? $data['counselor_name'] ?? ''));
        $note = self::escape((string) ($data['note'] ?? $data['delivery_notes'] ?? ''));

        $purposeRaw = (string) ($data['purpose'] ?? '');
        $purposeOtherRaw = (string) ($data['purpose_other'] ?? $data['other_purpose'] ?? '');

        $normPurpose = strtolower(str_replace([' ', '-', '/'], '_', trim($purposeRaw)));
        $isPersonal = ($normPurpose === 'personal_counseling');
        $isCareer = ($normPurpose === 'career_counseling');
        $isAcademic = ($normPurpose === 'academic_counseling');
        $isInterview = ($normPurpose === 'routine_interview' || $normPurpose === 'interview');
        $isTesting = ($normPurpose === 'testing_interpretation' || $normPurpose === 'testing__test_interpretation' || $normPurpose === 'testing_test_interpretation');
        $isOthers = ($normPurpose === 'others' || $normPurpose === 'other' || (!$isPersonal && !$isCareer && !$isAcademic && !$isInterview && !$isTesting && $normPurpose !== ''));

        $checkPersonal = self::check($isPersonal);
        $checkCareer = self::check($isCareer);
        $checkAcademic = self::check($isAcademic);
        $checkInterview = self::check($isInterview);
        $checkTesting = self::check($isTesting);
        $checkOthers = self::check($isOthers);

        $legacyOther = $normPurpose === 'follow_up' ? 'Follow-up' : $purposeRaw;
        $otherText = $purposeOtherRaw !== '' ? $purposeOtherRaw : ($isOthers && $normPurpose !== 'others' && $normPurpose !== 'other' ? $legacyOther : '');
        $othersSpecify = ($isOthers && $otherText !== '') ? self::escape($otherText) : '';

        $watermark = $isDraft
            ? "\\usepackage{draftwatermark}\n\\SetWatermarkText{DRAFT - PREVIEW ONLY}\n\\SetWatermarkScale{0.6}\n\\SetWatermarkColor[gray]{0.85}\n"
            : '';

        if ($isQuad) {
            return self::renderCallSlipQuad(
                $num,
                $name,
                $id,
                $course,
                $date,
                $time,
                $counselor,
                $note,
                $checkPersonal,
                $checkCareer,
                $checkAcademic,
                $checkInterview,
                $checkTesting,
                $checkOthers,
                $othersSpecify,
                $verificationCode,
                $attestation,
                $watermark,
                $isDraft
            );
        }

        return self::renderCallSlipSingle(
            $num,
            $name,
            $id,
            $course,
            $date,
            $time,
            $counselor,
            $note,
            $checkPersonal,
            $checkCareer,
            $checkAcademic,
            $checkInterview,
            $checkTesting,
            $checkOthers,
            $othersSpecify,
            $verificationCode,
            $attestation,
            $watermark,
            $isDraft
        );
    }

    private static function renderCallSlipSingle(
        string $num,
        string $name,
        string $id,
        string $course,
        string $date,
        string $time,
        string $counselor,
        string $note,
        string $checkPersonal,
        string $checkCareer,
        string $checkAcademic,
        string $checkInterview,
        string $checkTesting,
        string $checkOthers,
        string $othersSpecify,
        ?string $verificationCode,
        ?array $attestation,
        string $watermark,
        bool $isDraft
    ): string {
        $verificationLine = $verificationCode !== null && $verificationCode !== ''
            ? '\\textbf{Verification Code:} ' . self::escape($verificationCode) . ' \\quad \\textit{(Verify status at authorized Synapse portal)}'
            : '\\textbf{Status:} Provisional Draft';

        $attestationBanner = '';
        if ($attestation !== null) {
            $signer = self::escape((string) ($attestation['signer_role'] ?? $attestation['signer_name'] ?? 'Counselor'));
            $attDate = self::escape((string) ($attestation['attested_at'] ?? ''));
            $hash = self::escape(substr((string) ($attestation['snapshot_hash'] ?? ''), 0, 16) . '...');
            $attestationBanner = "\\noindent\\fcolorbox{gray}{gray!10}{\\begin{minipage}{\\dimexpr\\linewidth-2\\fboxsep-2\\fboxrule\\relax}\n"
                . "\\small \\textbf{Digitally Finalized by:} {$signer} on {$attDate} \\\\\n"
                . "\\textbf{Record Hash (SHA-256):} \\texttt{{$hash}} \\quad \\textbf{Method:} Authenticated Account Attestation\n"
                . "\\end{minipage}}\\par\\vspace{0.8em}\n";
        } elseif ($isDraft) {
            $attestationBanner = "\\noindent\\fcolorbox{gray}{orange!10}{\\begin{minipage}{\\dimexpr\\linewidth-2\\fboxsep-2\\fboxrule\\relax}\n"
                . "\\small \\textbf{ATTENTION:} Unfinalized draft record. Not official without authenticated attestation.\n"
                . "\\end{minipage}}\\par\\vspace{0.8em}\n";
        }

        $headerColors = DocumentHeaderLatex::colorDefinitions();
        $fancy = DocumentHeaderLatex::fancyHeaderFooter(
            'Office of the Guidance Center',
            '\\textbf{Form Code:} F-OGC-GSP-CSP-007-00',
            $verificationLine,
            'Page \\thepage\\ of 1'
        );
        $metaLine = '\\textbf{Revision Number:} 00 \\quad \\textbf{Effectivity Date:} September 1, 2025'
            . ($num !== '' ? " \\quad \\textbf{Call Slip No:} {$num}" : '');
        $headerContainer = DocumentHeaderLatex::headerContainer(
            'guidance',
            'OFFICE OF THE GUIDANCE CENTER',
            'GUIDANCE CALL SLIP',
            $metaLine
        );
        $dateTimeDisplay = trim($date . ($time !== '' ? ' ' . $time : ''));
        $othersLine = $othersSpecify !== '' ? $othersSpecify : '\\underline{\\hspace{7cm}}';
        $noteDisplay = $note !== '' ? $note : '\\underline{\\hspace{11.5cm}}';

        return <<<LATEX
\\documentclass[10pt,a4paper]{article}
\\pdfminorversion=4
\\usepackage[margin=18mm,top=20mm,bottom=22mm,headheight=14pt,headsep=6mm]{geometry}
\\usepackage{amsmath,amssymb}
\\usepackage{graphicx}
\\usepackage{xcolor}
\\usepackage{array}
\\usepackage{tabularx}
\\usepackage{fancyhdr}
{$headerColors}
\\setlength{\\parindent}{0pt}
{$fancy}
{$watermark}

\\begin{document}

{$headerContainer}

{$attestationBanner}

\\noindent\\begin{tabularx}{\\textwidth}{|l|X|l|X|}
\\hline
\\textbf{Date:} & {$dateTimeDisplay} & \\textbf{Call Slip No:} & {$num} \\\\ \\hline
\\textbf{Name:} & {$name} & \\textbf{Student ID:} & {$id} \\\\ \\hline
\\textbf{Grade/Strand / Course/Yr. Level:} & \\multicolumn{3}{l|}{{$course}} \\\\ \\hline
\\end{tabularx}\\par\\vspace{1em}

\\noindent\\textbf{to see the Guidance Counselor for the following purpose/reason:}\\par\\vspace{0.5em}
\\noindent\\begin{tabular}{l}
{$checkPersonal} Personal Counseling \\\\[0.35em]
{$checkCareer} Career Counseling \\\\[0.35em]
{$checkAcademic} Academic Counseling (INC or etc.) \\\\[0.35em]
{$checkInterview} Interview (Initial, Routine \\& Exit) \\\\[0.35em]
{$checkTesting} Testing/ Test Interpretation \\\\[0.35em]
{$checkOthers} Others: {$othersLine} \\\\
\\end{tabular}\\par\\vspace{1.2em}

\\noindent\\textbf{Note:} {$noteDisplay}\\par\\vspace{2em}

\\noindent\\hfill\\begin{minipage}{6cm}
\\centering
{$counselor}\\\\[0.2em]
\\rule{5.5cm}{0.4pt}\\\\[0.2em]
\\textbf{Guidance Personnel}
\\end{minipage}

\\vfill
\\noindent{\\footnotesize\\textbf{Department Office:} Office of the Guidance Center (OGC) \\hfill \\textbf{Effectivity Date:} September 1, 2025 \\quad \\textbf{Revision Number:} 00}\\par\\vspace{0.3em}
\\begin{center}
\\footnotesize\\textit{CONFIDENTIALITY NOTICE: This document contains strictly privileged psychological/guidance information protected under RA 10173 and professional ethics standards. Unauthorized disclosure or reproduction is strictly prohibited.}
\\end{center}

\\end{document}
LATEX;
    }

    private static function renderCallSlipQuad(
        string $num,
        string $name,
        string $id,
        string $course,
        string $date,
        string $time,
        string $counselor,
        string $note,
        string $checkPersonal,
        string $checkCareer,
        string $checkAcademic,
        string $checkInterview,
        string $checkTesting,
        string $checkOthers,
        string $othersSpecify,
        ?string $verificationCode,
        ?array $attestation,
        string $watermark,
        bool $isDraft
    ): string {
        $verificationLine = $verificationCode !== null && $verificationCode !== ''
            ? '\\textbf{Verif Code:} ' . self::escape($verificationCode)
            : '\\textbf{Status:} Draft';

        $attestationLine = '';
        if ($attestation !== null) {
            $signer = self::escape((string) ($attestation['signer_role'] ?? 'Counselor'));
            $hash = self::escape(substr((string) ($attestation['snapshot_hash'] ?? ''), 0, 10) . '...');
            $attestationLine = "\\textbf{Attested:} {$signer} ({$hash})";
        } elseif ($isDraft) {
            $attestationLine = "\\textbf{Notice:} Draft preview (Unofficial)";
        } else {
            $attestationLine = "\\textbf{Attestation:} Official Record";
        }

        $quadrant = self::renderCallSlipQuadrant(
            $num,
            $name,
            $id,
            $course,
            $date,
            $time,
            $counselor,
            $note,
            $checkPersonal,
            $checkCareer,
            $checkAcademic,
            $checkInterview,
            $checkTesting,
            $checkOthers,
            $othersSpecify,
            $verificationLine,
            $attestationLine
        );

        $headerColors = DocumentHeaderLatex::colorDefinitions();

        return <<<LATEX
\\documentclass[10pt,a4paper]{article}
\\pdfminorversion=4
\\usepackage[margin=8mm,top=8mm,bottom=8mm,left=8mm,right=8mm]{geometry}
\\usepackage{amsmath,amssymb}
\\usepackage{graphicx}
\\usepackage{xcolor}
\\usepackage{array}
\\usepackage{tabularx}
{$headerColors}
\\pagestyle{empty}
\\setlength{\\parindent}{0pt}
\\setlength{\\fboxsep}{2mm}
\\setlength{\\fboxrule}{0.5pt}
{$watermark}

\\begin{document}
\\noindent\\begin{minipage}{\\textwidth}

\\noindent
\\begin{minipage}[t][125mm][t]{93mm}
\\vspace{0pt}
{$quadrant}
\\end{minipage}%
\\hfill%
\\begin{minipage}[t][125mm][t]{6mm}
\\vspace{0pt}
\\centering
\\vbox to 125mm{\\cleaders\\vbox to 4mm{\\vss\\rule{0.5pt}{2mm}\\vss}\\vfill}
\\end{minipage}%
\\hfill%
\\begin{minipage}[t][125mm][t]{93mm}
\\vspace{0pt}
{$quadrant}
\\end{minipage}

\\vspace{2mm}
\\noindent\\cleaders\\hbox to 4mm{\\hss\\rule{2mm}{0.5pt}\\hss}\\hfill%
\\quad\\raisebox{-0.2ex}{\\scriptsize - - - - - - \\textbf{CUT ALONG DASHED LINE} - - - - - -}\\quad%
\\cleaders\\hbox to 4mm{\\hss\\rule{2mm}{0.5pt}\\hss}\\hfill\\kern0pt
\\vspace{2mm}

\\noindent
\\begin{minipage}[t][125mm][t]{93mm}
\\vspace{0pt}
{$quadrant}
\\end{minipage}%
\\hfill%
\\begin{minipage}[t][125mm][t]{6mm}
\\vspace{0pt}
\\centering
\\vbox to 125mm{\\cleaders\\vbox to 4mm{\\vss\\rule{0.5pt}{2mm}\\vss}\\vfill}
\\end{minipage}%
\\hfill%
\\begin{minipage}[t][125mm][t]{93mm}
\\vspace{0pt}
{$quadrant}
\\end{minipage}
\\end{minipage}

\\end{document}
LATEX;
    }

    private static function renderCallSlipQuadrant(
        string $num,
        string $name,
        string $id,
        string $course,
        string $date,
        string $time,
        string $counselor,
        string $note,
        string $checkPersonal,
        string $checkCareer,
        string $checkAcademic,
        string $checkInterview,
        string $checkTesting,
        string $checkOthers,
        string $othersSpecify,
        string $verificationLine,
        string $attestationLine
    ): string {
        $quadHeader = DocumentHeaderLatex::compactQuadrantHeader(
            'guidance',
            'OFFICE OF THE GUIDANCE CENTER',
            'GUIDANCE CALL SLIP',
            '\\textbf{F-OGC-GSP-CSP-007-00} \\enspace \\textbf{Rev:} 00 \\enspace \\textbf{Eff:} 2025-09-01'
        );
        $dateTimeDisplay = trim($date . ($time !== '' ? ' ' . $time : ''));
        $othersLine = $othersSpecify !== '' ? $othersSpecify : '\\underline{\\hspace{3.2cm}}';
        $noteDisplay = $note !== '' ? $note : '\\underline{\\hspace{5.8cm}}';

        return <<<LATEX
\\noindent\\fbox{\\begin{minipage}[t]{\\dimexpr\\linewidth-2\\fboxsep-2\\fboxrule\\relax}
{$quadHeader}
\\vspace{0.5mm}
\\begin{tabularx}{\\linewidth}{|l|X|l|X|}
\\hline
\\textbf{\\tiny Date:} & {\\tiny {$dateTimeDisplay}} & \\textbf{\\tiny Slip No:} & {\\tiny {$num}} \\\\ \\hline
\\textbf{\\tiny Name:} & {\\tiny {$name}} & \\textbf{\\tiny ID:} & {\\tiny {$id}} \\\\ \\hline
\\textbf{\\tiny Grade/Course:} & \\multicolumn{3}{l|}{\\tiny {$course}} \\\\ \\hline
\\end{tabularx}\\par\\vspace{0.8mm}
{\\tiny\\textbf{to see the Guidance Counselor for the following purpose/reason:}}\\par\\vspace{0.4mm}
{\\tiny
{$checkPersonal} Personal Counseling \\\\
{$checkCareer} Career Counseling \\\\
{$checkAcademic} Academic Counseling (INC or etc.) \\\\
{$checkInterview} Interview (Initial, Routine \\& Exit) \\\\
{$checkTesting} Testing/ Test Interpretation \\\\
{$checkOthers} Others: {$othersLine}
}\\par\\vspace{0.8mm}
{\\tiny\\textbf{Note:} {$noteDisplay}}\\par\\vspace{1.2mm}
\\noindent\\hfill{\\tiny\\begin{tabular}{c} {$counselor} \\\\ \\hline \\textbf{Guidance Personnel} \\end{tabular}}\\par\\vspace{0.8mm}
{\\tiny {$verificationLine} \\hfill {$attestationLine}}\\par\\vspace{0.4mm}
{\\tiny\\centering\\textit{Department Office: Office of the Guidance Center (OGC) --- RA 10173}\\par}
\\end{minipage}}
LATEX;
    }

    /**
     * Renders Psychosocial Support Interview Form.
     * Canonical code: F-OGC-GPS-PIF-008-00, rev 00, eff 2025-09-01.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $options
     */
    public static function renderPsychosocialInterview(array $snapshot, array $options = []): string
    {
        $data = isset($snapshot['data']) && is_array($snapshot['data'])
            ? array_merge($snapshot, $snapshot['data'])
            : $snapshot;

        $instance = (array) ($snapshot['instance'] ?? []);
        $definition = (array) ($snapshot['definition'] ?? []);
        $revision = (array) ($snapshot['revision'] ?? []);

        [$authorAttestation, $cosignerAttestation] = self::resolveAttestations($snapshot, $options);
        $verificationCode = $options['verification_code'] ?? $snapshot['verification_code'] ?? ($snapshot['verification']['verification_code'] ?? null);
        $isDraft = (bool) ($options['is_draft'] ?? $snapshot['is_draft'] ?? (($instance['status'] ?? '') === 'draft'));

        $code = self::escape((string) ($definition['form_code'] ?? 'F-OGC-GPS-PIF-008-00'));
        $title = self::escape((string) ($definition['canonical_title'] ?? 'Psychosocial Support Interview Form'));
        $rev = self::escape((string) ($revision['revision_number'] ?? '00'));

        $effectivityRaw = (string) ($revision['effectivity_date'] ?? 'September 1, 2025');
        if ($effectivityRaw === '2025-09-01' || $effectivityRaw === 'September 1, 2025') {
            $effectivity = 'September 1, 2025 (2025-09-01)';
        } else {
            $effectivity = self::escape($effectivityRaw);
        }

        $instanceNum = self::escape((string) ($instance['instance_number'] ?? $snapshot['instance_number'] ?? $data['instance_number'] ?? ''));

        $name = self::escape((string) ($data['student_name'] ?? $data['name'] ?? ''));
        $id = self::escape((string) ($data['student_id'] ?? $data['id'] ?? ''));
        $course = self::escape((string) ($data['course_year'] ?? $data['course'] ?? $data['grade_strand_course'] ?? ''));
        $date = self::escape((string) ($data['interview_date'] ?? $data['scheduled_date'] ?? $data['date'] ?? ''));
        $time = self::escape((string) ($data['interview_time'] ?? $data['time'] ?? ''));
        $dateTimeDisplay = trim($date . ($time !== '' ? ' ' . $time : ''));

        $clientTypeRaw = strtolower((string) ($data['client_type'] ?? $data['type_of_client'] ?? ''));
        $isWalkIn = str_contains($clientTypeRaw, 'walk');
        $isFollowUp = str_contains($clientTypeRaw, 'follow');
        $isReferral = str_contains($clientTypeRaw, 'refer');

        $checkWalkIn = self::check($isWalkIn);
        $checkFollowUp = self::check($isFollowUp);
        $checkReferral = self::check($isReferral);

        $consentChecked = !empty($data['privacy_consent_acknowledged']);
        $checkConsent = self::check($consentChecked);

        // Q1: Issues / Concerns
        $concernsRaw = (string) ($data['presenting_concerns'] ?? $data['issues_concerns'] ?? $data['concerns'] ?? '');
        $concerns = self::escape($concernsRaw !== '' ? $concernsRaw : 'None recorded.');

        // Q2: Seriousness
        $severityRaw = strtolower(trim((string) ($data['concern_severity'] ?? $data['seriousness'] ?? '')));
        $isVerySerious = ($severityRaw === 'very_serious' || $severityRaw === 'very serious');
        $isSerious = ($severityRaw === 'serious');
        $isNotSoSerious = ($severityRaw === 'not_so_serious' || $severityRaw === 'not so serious');
        $checkVerySerious = self::check($isVerySerious);
        $checkSerious = self::check($isSerious);
        $checkNotSoSerious = self::check($isNotSoSerious);

        // Q3-Q8 from F-OGC-GPS-PIF-008-00
        $durationRaw = (string) ($data['problem_duration'] ?? '');
        $duration = self::escape($durationRaw !== '' ? $durationRaw : '---');

        $causeRaw = (string) ($data['perceived_cause'] ?? '');
        $perceivedCause = self::escape($causeRaw !== '' ? $causeRaw : '---');

        $barriersRaw = (string) ($data['coping_barriers'] ?? '');
        $copingBarriers = self::escape($barriersRaw !== '' ? $barriersRaw : '---');

        $triedRaw = (string) ($data['prior_coping_attempts'] ?? '');
        $priorAttempts = self::escape($triedRaw !== '' ? $triedRaw : '---');

        $helpRaw = (string) ($data['desired_help'] ?? '');
        $desiredHelp = self::escape($helpRaw !== '' ? $helpRaw : '---');

        $changesRaw = (string) ($data['hoped_changes'] ?? '');
        $hopedChanges = self::escape($changesRaw !== '' ? $changesRaw : '---');

        $watermark = $isDraft
            ? "\\usepackage{draftwatermark}\n\\SetWatermarkText{DRAFT - PREVIEW ONLY}\n\\SetWatermarkScale{0.6}\n\\SetWatermarkColor[gray]{0.85}\n"
            : '';

        $verificationLine = $verificationCode !== null && $verificationCode !== ''
            ? '\\textbf{Verification Code:} ' . self::escape($verificationCode) . ' \\quad \\textit{(Verify status at authorized Synapse portal)}'
            : '\\textbf{Status:} Provisional Draft';

        $attestationBanner = self::renderAttestationBanner($authorAttestation, $cosignerAttestation, $verificationCode, $isDraft);
        $attestationStamp = self::renderAttestationStamp($authorAttestation, $cosignerAttestation, $verificationCode, $isDraft);
        $metaLine = "\\textbf{Rev:} {$rev} \\quad \\textbf{Effectivity Date:} {$effectivity}"
            . ($instanceNum !== '' ? " \\quad \\textbf{Instance:} {$instanceNum}" : '');
        $headerColors = DocumentHeaderLatex::colorDefinitions();
        $fancy = DocumentHeaderLatex::fancyHeaderFooter(
            'Office of the Guidance Center',
            "\\textbf{Form Code:} {$code}",
            $verificationLine,
            'Page \\thepage'
        );
        $headerContainer = DocumentHeaderLatex::headerContainer(
            'guidance',
            'OFFICE OF THE GUIDANCE CENTER',
            $title,
            $metaLine
        );

        return <<<LATEX
\\documentclass[10pt,a4paper]{article}
\\pdfminorversion=4
\\usepackage[margin=16mm,top=20mm,bottom=20mm,headheight=14pt,headsep=6mm]{geometry}
\\usepackage{amsmath,amssymb}
\\usepackage{graphicx}
\\usepackage{xcolor}
\\usepackage[framemethod=tikz]{mdframed}
\\usepackage{array}
\\usepackage{tabularx}
\\usepackage{fancyhdr}
{$headerColors}
\\setlength{\\parindent}{0pt}
{$fancy}
{$watermark}

\\begin{document}

{$headerContainer}

{$attestationBanner}

\\begin{mdframed}[backgroundcolor=gray!3]
\\footnotesize
In accordance with the \\textbf{Data Privacy Act of 2012 (RA 10173)}, the University Guidance Center collects and processes your personal information or your son/daughter/ward's personal information and, when necessary, sensitive personal information for the purposes of providing counseling services, conducting assessments or interventions, maintaining records, and coordinating with authorized University officials for student welfare.\\par\\vspace{0.3em}
Processing is based on the University's legitimate educational mandate and/or your consent, particularly when sensitive personal information is involved. The data will be kept confidential and accessed only by authorized personnel. It will be stored securely, retained in accordance with University policies, and disclosed only with your consent or as required by law.\\par\\vspace{0.3em}
You have the right to be informed, access, correct, object to processing, and request the erasure of your personal data or your son/daughter/ward's personal data, subject to applicable legal limitations. For privacy-related concerns, you may contact the University Data Protection Officer (DPO): \\textbf{dpo@foundationu.com}.\\par\\vspace{0.4em}
{$checkConsent} \\textbf{I have read and understood this Privacy Notice and voluntarily give my consent to the processing of my personal or my son/daughter/ward's personal and sensitive personal information for guidance and counseling purposes.}\\\\[0.3em]
\\textbf{Student / Parent / Guardian:} {$name} \\hfill \\textbf{Date:} {$date}
\\end{mdframed}\\par\\vspace{0.6em}

\\begin{tabularx}{\\textwidth}{|l|X|l|X|}
\\hline
\\textbf{Type of Client:} & \\multicolumn{3}{l|}{{$checkWalkIn} Walk-in \\enspace\\quad {$checkFollowUp} Follow-up \\enspace\\quad {$checkReferral} Referral} \\\\ \\hline
\\textbf{Student's Name:} & {$name} & \\textbf{Date and Time:} & {$dateTimeDisplay} \\\\ \\hline
\\textbf{Grade/Strand/Course \\& Year:} & {$course} & \\textbf{Student / MIS ID:} & {$id} \\\\ \\hline
\\end{tabularx}\\par\\vspace{0.7em}

\\noindent\\textbf{1. ISSUES/CONCERNS} \\textit{(How would you describe your current situation? What are you experiencing? What are your main concerns?)}\\par
\\begin{mdframed}[backgroundcolor=gray!2]
{$concerns}
\\end{mdframed}\\par\\vspace{0.45em}

\\noindent\\textbf{2. How serious are these matters for you at this time?}\\quad
{$checkVerySerious} Very Serious \\qquad {$checkSerious} Serious \\qquad {$checkNotSoSerious} Not so Serious\\par\\vspace{0.55em}

\\noindent\\textbf{3. How long have these been your problem?}\\par
\\begin{mdframed}[backgroundcolor=gray!2]
{$duration}
\\end{mdframed}\\par\\vspace{0.45em}

\\noindent\\textbf{4. What do you think originally caused these problem/s?}\\par
\\begin{mdframed}[backgroundcolor=gray!2]
{$perceivedCause}
\\end{mdframed}\\par\\vspace{0.45em}

\\noindent\\textbf{5. What other things currently making it hard to deal with the problem/s?}\\par
\\begin{mdframed}[backgroundcolor=gray!2]
{$copingBarriers}
\\end{mdframed}\\par\\vspace{0.45em}

\\noindent\\textbf{6. What have you already tried in order to deal with the problem/s?}\\par
\\begin{mdframed}[backgroundcolor=gray!2]
{$priorAttempts}
\\end{mdframed}\\par\\vspace{0.45em}

\\noindent\\textbf{7. What do you think would help solve the problem/s? What type of help do you want?}\\par
\\begin{mdframed}[backgroundcolor=gray!2]
{$desiredHelp}
\\end{mdframed}\\par\\vspace{0.45em}

\\noindent\\textbf{8. What changes are you hoping for?}\\par
\\begin{mdframed}[backgroundcolor=gray!2]
{$hopedChanges}
\\end{mdframed}\\par\\vspace{0.8em}

\\noindent\\textbf{Attestation \\& Verification}\\par\\vspace{0.3em}
{$attestationStamp}

\\vfill
\\begin{center}
\\footnotesize\\textit{Department Office: Office of the Guidance Center (OGC) \\quad|\\quad Confidential record protected under RA 10173.}
\\end{center}

\\end{document}
LATEX;
    }

    /**
     * Renders Psychosocial Support Contact Form.
     * Canonical code: F-OGC-GPS-PCF-008-00, rev 00, eff 2025-09-01.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $options
     */
    public static function renderPsychosocialContact(array $snapshot, array $options = []): string
    {
        $data = isset($snapshot['data']) && is_array($snapshot['data'])
            ? array_merge($snapshot, $snapshot['data'])
            : $snapshot;

        $instance = (array) ($snapshot['instance'] ?? []);
        $definition = (array) ($snapshot['definition'] ?? []);
        $revision = (array) ($snapshot['revision'] ?? []);

        [$authorAttestation, $cosignerAttestation] = self::resolveAttestations($snapshot, $options);
        $verificationCode = $options['verification_code'] ?? $snapshot['verification_code'] ?? ($snapshot['verification']['verification_code'] ?? null);
        $isDraft = (bool) ($options['is_draft'] ?? $snapshot['is_draft'] ?? (($instance['status'] ?? '') === 'draft'));

        $code = self::escape((string) ($definition['form_code'] ?? 'F-OGC-GPS-PCF-008-00'));
        $title = self::escape((string) ($definition['canonical_title'] ?? 'Psychosocial Support Contact Form'));
        $rev = self::escape((string) ($revision['revision_number'] ?? '00'));

        $effectivityRaw = (string) ($revision['effectivity_date'] ?? 'September 1, 2025');
        if ($effectivityRaw === '2025-09-01' || $effectivityRaw === 'September 1, 2025') {
            $effectivity = 'September 1, 2025 (2025-09-01)';
        } else {
            $effectivity = self::escape($effectivityRaw);
        }

        $instanceNum = self::escape((string) ($instance['instance_number'] ?? $snapshot['instance_number'] ?? $data['instance_number'] ?? ''));

        $dept = self::escape((string) ($data['department'] ?? '---'));
        $sessionDate = self::escape((string) ($data['session_date'] ?? $data['date'] ?? ''));
        $name = self::escape((string) ($data['student_name'] ?? $data['name'] ?? ''));
        $id = self::escape((string) ($data['student_id'] ?? $data['id'] ?? ''));
        $course = self::escape((string) ($data['course_year'] ?? $data['course'] ?? ''));

        $timeStart = self::escape((string) ($data['time_started'] ?? $data['time_start'] ?? ''));
        $timeEnd = self::escape((string) ($data['time_ended'] ?? $data['time_end'] ?? ''));
        $timeStr = ($timeStart !== '' || $timeEnd !== '') ? trim("{$timeStart} -- {$timeEnd}", ' -') : '---';

        $refRaw = strtolower(trim((string) ($data['referral_source'] ?? $data['client_type'] ?? '')));
        $isWalkIn = ($refRaw === '' || str_contains($refRaw, 'walk'));
        $isAssociateInit = str_contains($refRaw, 'associate') || str_contains($refRaw, 'initiated');
        $isReferral = str_contains($refRaw, 'refer');
        $isFollowUpRef = str_contains($refRaw, 'follow');
        $checkRefWalkIn = self::check($isWalkIn);
        $checkRefAssoc = self::check($isAssociateInit);
        $checkRefReferral = self::check($isReferral);
        $checkRefFollowUp = self::check($isFollowUpRef);

        $sessionTypeRaw = strtolower((string) ($data['session_type'] ?? ''));
        $isTypeIntake = str_contains($sessionTypeRaw, 'intake');
        $isTypeFollowUp = str_contains($sessionTypeRaw, 'follow');
        $isTypeCrisis = str_contains($sessionTypeRaw, 'crisis');
        $isTypeGroup = str_contains($sessionTypeRaw, 'group');
        $isTypeIndividual = str_contains($sessionTypeRaw, 'indiv') || (!$isTypeIntake && !$isTypeFollowUp && !$isTypeCrisis && !$isTypeGroup);

        $checkTypeIntake = self::check($isTypeIntake);
        $checkTypeFollowUp = self::check($isTypeFollowUp);
        $checkTypeCrisis = self::check($isTypeCrisis);
        $checkTypeIndiv = self::check($isTypeIndividual);
        $checkTypeGroup = self::check($isTypeGroup);

        $consentChecked = !empty($data['privacy_consent_acknowledged']);
        $checkConsent = self::check($consentChecked);

        $presentingConcerns = self::escape((string) ($data['presenting_concerns'] ?? $data['client_state'] ?? 'None recorded.'));
        $predisposing = self::escape((string) ($data['predisposing_factors'] ?? '---'));
        $precipitating = self::escape((string) ($data['precipitating_factors'] ?? '---'));
        $perpetuating = self::escape((string) ($data['perpetuating_factors'] ?? '---'));
        $protectiveRisk = self::escape((string) ($data['protective_risk_factors'] ?? '---'));
        $interventionPlan = self::escape((string) ($data['intervention_plan'] ?? $data['interventions_used'] ?? 'None recorded.'));
        $followUpPlan = self::escape((string) ($data['follow_up_plan'] ?? $data['action_items'] ?? 'None recorded.'));
        $nextSchedule = self::escape((string) ($data['next_session_schedule'] ?? $data['next_session_date'] ?? $data['next_schedule'] ?? 'As scheduled / on-call'));

        $watermark = $isDraft
            ? "\\usepackage{draftwatermark}\n\\SetWatermarkText{DRAFT - PREVIEW ONLY}\n\\SetWatermarkScale{0.6}\n\\SetWatermarkColor[gray]{0.85}\n"
            : '';

        $verificationLine = $verificationCode !== null && $verificationCode !== ''
            ? '\\textbf{Verification Code:} ' . self::escape($verificationCode) . ' \\quad \\textit{(Verify status at authorized Synapse portal)}'
            : '\\textbf{Status:} Provisional Draft';

        $attestationBanner = self::renderAttestationBanner($authorAttestation, $cosignerAttestation, $verificationCode, $isDraft);
        $attestationStamp = self::renderAttestationStamp($authorAttestation, $cosignerAttestation, $verificationCode, $isDraft);
        $metaLine = "\\textbf{Rev:} {$rev} \\quad \\textbf{Effectivity Date:} {$effectivity}"
            . ($instanceNum !== '' ? " \\quad \\textbf{Instance:} {$instanceNum}" : '');
        $headerColors = DocumentHeaderLatex::colorDefinitions();
        $fancy = DocumentHeaderLatex::fancyHeaderFooter(
            'Office of the Guidance Center',
            "\\textbf{Form Code:} {$code}",
            $verificationLine,
            'Page \\thepage'
        );
        $headerContainer = DocumentHeaderLatex::headerContainer(
            'guidance',
            'OFFICE OF THE GUIDANCE CENTER',
            $title,
            $metaLine
        );

        return <<<LATEX
\\documentclass[10pt,a4paper]{article}
\\pdfminorversion=4
\\usepackage[margin=16mm,top=20mm,bottom=20mm,headheight=14pt,headsep=6mm]{geometry}
\\usepackage{amsmath,amssymb}
\\usepackage{graphicx}
\\usepackage{xcolor}
\\usepackage[framemethod=tikz]{mdframed}
\\usepackage{array}
\\usepackage{tabularx}
\\usepackage{fancyhdr}
{$headerColors}
\\setlength{\\parindent}{0pt}
{$fancy}
{$watermark}

\\begin{document}

{$headerContainer}

{$attestationBanner}

\\begin{mdframed}[backgroundcolor=gray!3]
\\footnotesize
\\textbf{PRIVACY NOTICE AND CONSENT:} In accordance with the \\textbf{Data Privacy Act of 2012 (RA 10173)}, the University Guidance Center collects and processes your personal information or your son/daughter/ward's personal information and, when necessary, sensitive personal information for the purposes of providing counseling services, conducting assessments or interventions, maintaining records, and coordinating with authorized University officials for student welfare.\\par\\vspace{0.25em}
Processing is based on the University's legitimate educational mandate and/or your consent, particularly when sensitive personal information is involved. The data will be kept confidential and accessed only by authorized personnel. It will be stored securely, retained in accordance with University policies, and disclosed only with your consent or as required by law.\\par\\vspace{0.25em}
You have the right to be informed, access, correct, object to processing, and request the erasure of your personal data or your son/daughter/ward's personal data, subject to applicable legal limitations. For privacy-related concerns, you may contact the University Data Protection Officer (DPO): \\textbf{dpo@foundationu.com}.\\par\\vspace{0.35em}
{$checkConsent} \\textbf{I have read and understood this Privacy Notice and voluntarily give my consent to the processing of my personal or my son/daughter/ward's personal and sensitive personal information for guidance and counseling purposes.}\\\\[0.25em]
\\textbf{Student / Parent / Guardian:} {$name} \\hfill \\textbf{Date:} {$sessionDate}
\\end{mdframed}\\par\\vspace{0.6em}

\\begin{tabularx}{\\textwidth}{|l|X|l|X|}
\\hline
\\textbf{Department:} & {$dept} & \\textbf{Date:} & {$sessionDate} \\\\ \\hline
\\textbf{Name of Student:} & {$name} & \\textbf{Student / MIS ID:} & {$id} \\\\ \\hline
\\textbf{Course / Year / Grade / Section:} & {$course} & \\textbf{Time Started / Ended:} & {$timeStr} \\\\ \\hline
\\textbf{Contact Source:} & \\multicolumn{3}{l|}{{$checkRefWalkIn} Walk in \\enspace {$checkRefAssoc} Counselor Associate-initiated \\enspace {$checkRefReferral} Referral \\enspace {$checkRefFollowUp} Follow-up} \\\\ \\hline
\\textbf{Type of Session:} & \\multicolumn{3}{l|}{{$checkTypeIntake} Intake \\enspace {$checkTypeFollowUp} Follow-up \\enspace {$checkTypeCrisis} Crisis \\enspace {$checkTypeIndiv} Individual \\enspace {$checkTypeGroup} Group} \\\\ \\hline
\\end{tabularx}\\par\\vspace{0.7em}

\\noindent\\textbf{Summary of Psychosocial Support Session}\\par\\vspace{0.35em}
\\noindent\\textbf{Presenting Concern/s:}\\par
\\begin{mdframed}[backgroundcolor=gray!2]
{$presentingConcerns}
\\end{mdframed}\\par\\vspace{0.4em}

\\noindent\\textbf{Predisposing Factors:}\\par
\\begin{mdframed}[backgroundcolor=gray!2]
{$predisposing}
\\end{mdframed}\\par\\vspace{0.4em}

\\noindent\\textbf{Precipitating Factors:}\\par
\\begin{mdframed}[backgroundcolor=gray!2]
{$precipitating}
\\end{mdframed}\\par\\vspace{0.4em}

\\noindent\\textbf{Perpetuating Factors:}\\par
\\begin{mdframed}[backgroundcolor=gray!2]
{$perpetuating}
\\end{mdframed}\\par\\vspace{0.4em}

\\noindent\\textbf{Protective / Risk Factors:}\\par
\\begin{mdframed}[backgroundcolor=gray!2]
{$protectiveRisk}
\\end{mdframed}\\par\\vspace{0.6em}

\\noindent\\textbf{Plans:}\\par\\vspace{0.35em}
\\noindent\\textbf{Intervention:}\\par
\\begin{mdframed}[backgroundcolor=gray!2]
{$interventionPlan}
\\end{mdframed}\\par\\vspace{0.4em}

\\noindent\\textbf{Follow up:}\\par
\\begin{mdframed}[backgroundcolor=gray!2]
{$followUpPlan}
\\end{mdframed}\\par\\vspace{0.5em}

\\noindent\\textbf{Next Session / Follow-Up}\\\\[0.2em]
\\noindent\\textbf{Schedule for Next Session (Date and Time):} {$nextSchedule}\\par\\vspace{0.8em}

\\noindent\\textbf{Counselor Associate's Signature / Attestation}\\par\\vspace{0.3em}
{$attestationStamp}

\\vfill
\\begin{center}
\\footnotesize\\textit{Department Office: Office of the Guidance Center (OGC) \\quad|\\quad Confidential record protected under RA 10173.}
\\end{center}

\\end{document}
LATEX;
    }

    /**
     * Renders Counseling Contact Sheet (alternate: Counseling Contact Form).
     * Canonical code: F-OGC-GCS-CCF-009-00, rev 00, eff 2025-09-01.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $options
     */
    public static function renderCounselingContact(array $snapshot, array $options = []): string
    {
        $data = isset($snapshot['data']) && is_array($snapshot['data'])
            ? array_merge($snapshot, $snapshot['data'])
            : $snapshot;

        $instance = (array) ($snapshot['instance'] ?? []);
        $definition = (array) ($snapshot['definition'] ?? []);
        $revision = (array) ($snapshot['revision'] ?? []);

        [$authorAttestation, $cosignerAttestation] = self::resolveAttestations($snapshot, $options);
        $verificationCode = $options['verification_code'] ?? $snapshot['verification_code'] ?? ($snapshot['verification']['verification_code'] ?? null);
        $isDraft = (bool) ($options['is_draft'] ?? $snapshot['is_draft'] ?? (($instance['status'] ?? '') === 'draft'));

        $code = self::escape((string) ($definition['form_code'] ?? 'F-OGC-GCS-CCF-009-00'));
        $title = self::escape((string) ($definition['canonical_title'] ?? 'Counseling Contact Sheet'));
        $rev = self::escape((string) ($revision['revision_number'] ?? '00'));

        $effectivityRaw = (string) ($revision['effectivity_date'] ?? 'September 1, 2025');
        if ($effectivityRaw === '2025-09-01' || $effectivityRaw === 'September 1, 2025') {
            $effectivity = 'September 1, 2025 (2025-09-01)';
        } else {
            $effectivity = self::escape($effectivityRaw);
        }

        $instanceNum = self::escape((string) ($instance['instance_number'] ?? $snapshot['instance_number'] ?? $data['instance_number'] ?? ''));

        $dept = self::escape((string) ($data['department'] ?? '---'));
        $sessionDate = self::escape((string) ($data['session_date'] ?? $data['date'] ?? ''));
        $name = self::escape((string) ($data['student_name'] ?? $data['name'] ?? ''));
        $id = self::escape((string) ($data['student_id'] ?? $data['id'] ?? ''));
        $course = self::escape((string) ($data['course_year'] ?? $data['course'] ?? ''));

        $timeStart = self::escape((string) ($data['time_started'] ?? $data['time_start'] ?? ''));
        $timeEnd = self::escape((string) ($data['time_ended'] ?? $data['time_end'] ?? ''));
        $timeStr = ($timeStart !== '' || $timeEnd !== '') ? trim("{$timeStart} -- {$timeEnd}", ' -') : '---';

        $refRaw = strtolower(trim((string) ($data['referral_source'] ?? $data['client_type'] ?? '')));
        $isWalkIn = ($refRaw === '' || str_contains($refRaw, 'walk'));
        $isCounselorInit = str_contains($refRaw, 'counselor') || str_contains($refRaw, 'initiated');
        $isReferral = str_contains($refRaw, 'refer');
        $isFollowUpRef = str_contains($refRaw, 'follow');
        $checkRefWalkIn = self::check($isWalkIn);
        $checkRefCounselor = self::check($isCounselorInit);
        $checkRefReferral = self::check($isReferral);
        $checkRefFollowUp = self::check($isFollowUpRef);

        $sessionTypeRaw = strtolower((string) ($data['session_type'] ?? ''));
        $isTypeIntake = str_contains($sessionTypeRaw, 'intake');
        $isTypeFollowUp = str_contains($sessionTypeRaw, 'follow');
        $isTypeCrisis = str_contains($sessionTypeRaw, 'crisis');
        $isTypeGroup = str_contains($sessionTypeRaw, 'group');
        $isTypeIndividual = str_contains($sessionTypeRaw, 'indiv') || (!$isTypeIntake && !$isTypeFollowUp && !$isTypeCrisis && !$isTypeGroup);

        $checkTypeIntake = self::check($isTypeIntake);
        $checkTypeFollowUp = self::check($isTypeFollowUp);
        $checkTypeCrisis = self::check($isTypeCrisis);
        $checkTypeIndiv = self::check($isTypeIndividual);
        $checkTypeGroup = self::check($isTypeGroup);

        $consentChecked = !empty($data['privacy_consent_acknowledged']);
        $checkConsent = self::check($consentChecked);

        $presentingConcerns = self::escape((string) ($data['presenting_concerns'] ?? $data['concerns_discussed'] ?? $data['concerns'] ?? 'None recorded.'));
        $predisposing = self::escape((string) ($data['predisposing_factors'] ?? '---'));
        $precipitating = self::escape((string) ($data['precipitating_factors'] ?? '---'));
        $perpetuating = self::escape((string) ($data['perpetuating_factors'] ?? '---'));
        $protectiveRisk = self::escape((string) ($data['protective_risk_factors'] ?? '---'));
        $interventionPlan = self::escape((string) ($data['intervention_plan'] ?? $data['interventions_and_responses'] ?? $data['interventions'] ?? 'None recorded.'));
        $followUpPlan = self::escape((string) ($data['follow_up_plan'] ?? $data['homework_and_commitments'] ?? $data['homework'] ?? '---'));
        $nextSchedule = self::escape((string) ($data['next_session_schedule'] ?? $data['next_schedule'] ?? $data['next_session_date'] ?? 'As scheduled / on-call'));

        $watermark = $isDraft
            ? "\\usepackage{draftwatermark}\n\\SetWatermarkText{DRAFT - PREVIEW ONLY}\n\\SetWatermarkScale{0.6}\n\\SetWatermarkColor[gray]{0.85}\n"
            : '';

        $verificationLine = $verificationCode !== null && $verificationCode !== ''
            ? '\\textbf{Verification Code:} ' . self::escape($verificationCode) . ' \\quad \\textit{(Verify status at authorized Synapse portal)}'
            : '\\textbf{Status:} Provisional Draft';

        $attestationBanner = self::renderAttestationBanner($authorAttestation, $cosignerAttestation, $verificationCode, $isDraft);
        $attestationStamp = self::renderAttestationStamp($authorAttestation, $cosignerAttestation, $verificationCode, $isDraft);
        $metaLine = "\\textbf{Rev:} {$rev} \\quad \\textbf{Effectivity Date:} {$effectivity}"
            . ($instanceNum !== '' ? " \\quad \\textbf{Instance:} {$instanceNum}" : '');
        $headerColors = DocumentHeaderLatex::colorDefinitions();
        $fancy = DocumentHeaderLatex::fancyHeaderFooter(
            'Office of the Guidance Center',
            "\\textbf{Form Code:} {$code}",
            $verificationLine,
            'Page \\thepage'
        );
        $headerContainer = DocumentHeaderLatex::headerContainer(
            'guidance',
            'OFFICE OF THE GUIDANCE CENTER',
            $title,
            $metaLine,
            '{\\footnotesize\\textit{(Counseling Contact Form)}}'
        );

        return <<<LATEX
\\documentclass[10pt,a4paper]{article}
\\pdfminorversion=4
\\usepackage[margin=16mm,top=20mm,bottom=20mm,headheight=14pt,headsep=6mm]{geometry}
\\usepackage{amsmath,amssymb}
\\usepackage{graphicx}
\\usepackage{xcolor}
\\usepackage[framemethod=tikz]{mdframed}
\\usepackage{array}
\\usepackage{tabularx}
\\usepackage{fancyhdr}
{$headerColors}
\\setlength{\\parindent}{0pt}
{$fancy}
{$watermark}

\\begin{document}

{$headerContainer}

{$attestationBanner}

\\begin{mdframed}[backgroundcolor=gray!3]
\\footnotesize
\\textbf{PRIVACY NOTICE AND CONSENT:} In accordance with the \\textbf{Data Privacy Act of 2012 (RA 10173)}, the University Guidance Center collects and processes your personal information or your son/daughter/ward's personal information and, when necessary, sensitive personal information for the purposes of providing counseling services, conducting assessments or interventions, maintaining records, and coordinating with authorized University officials for student welfare.\\par\\vspace{0.25em}
Processing is based on the University's legitimate educational mandate and/or your consent, particularly when sensitive personal information is involved. The data will be kept confidential and accessed only by authorized personnel. It will be stored securely, retained in accordance with University policies, and disclosed only with your consent or as required by law.\\par\\vspace{0.25em}
You have the right to be informed, access, correct, object to processing, and request the erasure of your personal data or your son/daughter/ward's personal data, subject to applicable legal limitations. For privacy-related concerns, you may contact the University Data Protection Officer (DPO): \\textbf{dpo@foundationu.com}.\\par\\vspace{0.35em}
{$checkConsent} \\textbf{I have read and understood this Privacy Notice and voluntarily give my consent to the processing of my personal or my son/daughter/ward's personal and sensitive personal information for guidance and counseling purposes.}\\\\[0.25em]
\\textbf{Student / Parent / Guardian:} {$name} \\hfill \\textbf{Date:} {$sessionDate}
\\end{mdframed}\\par\\vspace{0.6em}

\\begin{tabularx}{\\textwidth}{|l|X|l|X|}
\\hline
\\textbf{Department:} & {$dept} & \\textbf{Date:} & {$sessionDate} \\\\ \\hline
\\textbf{Name of Student:} & {$name} & \\textbf{Student / MIS ID:} & {$id} \\\\ \\hline
\\textbf{Course / Year / Grade / Section:} & {$course} & \\textbf{Time Started / Ended:} & {$timeStr} \\\\ \\hline
\\textbf{Contact Source:} & \\multicolumn{3}{l|}{{$checkRefWalkIn} Walk in \\enspace {$checkRefCounselor} Counselor-initiated \\enspace {$checkRefReferral} Referral \\enspace {$checkRefFollowUp} Follow-up} \\\\ \\hline
\\textbf{Type of Session:} & \\multicolumn{3}{l|}{{$checkTypeIntake} Intake \\enspace {$checkTypeFollowUp} Follow-up \\enspace {$checkTypeCrisis} Crisis \\enspace {$checkTypeIndiv} Individual \\enspace {$checkTypeGroup} Group} \\\\ \\hline
\\end{tabularx}\\par\\vspace{0.7em}

\\noindent\\textbf{Summary of Counseling Session}\\par\\vspace{0.35em}
\\noindent\\textbf{Presenting Concern/s:}\\par
\\begin{mdframed}[backgroundcolor=gray!2]
{$presentingConcerns}
\\end{mdframed}\\par\\vspace{0.4em}

\\noindent\\textbf{Predisposing Factors:}\\par
\\begin{mdframed}[backgroundcolor=gray!2]
{$predisposing}
\\end{mdframed}\\par\\vspace{0.4em}

\\noindent\\textbf{Precipitating Factors:}\\par
\\begin{mdframed}[backgroundcolor=gray!2]
{$precipitating}
\\end{mdframed}\\par\\vspace{0.4em}

\\noindent\\textbf{Perpetuating Factors:}\\par
\\begin{mdframed}[backgroundcolor=gray!2]
{$perpetuating}
\\end{mdframed}\\par\\vspace{0.4em}

\\noindent\\textbf{Protective / Risk Factors:}\\par
\\begin{mdframed}[backgroundcolor=gray!2]
{$protectiveRisk}
\\end{mdframed}\\par\\vspace{0.6em}

\\noindent\\textbf{Plans:}\\par\\vspace{0.35em}
\\noindent\\textbf{Intervention:}\\par
\\begin{mdframed}[backgroundcolor=gray!2]
{$interventionPlan}
\\end{mdframed}\\par\\vspace{0.4em}

\\noindent\\textbf{Follow up:}\\par
\\begin{mdframed}[backgroundcolor=gray!2]
{$followUpPlan}
\\end{mdframed}\\par\\vspace{0.5em}

\\noindent\\textbf{Next Session / Follow-Up}\\\\[0.2em]
\\noindent\\textbf{Schedule for Next Session (Date and Time):} {$nextSchedule}\\par\\vspace{0.8em}

\\noindent\\textbf{Counselor's Signature / Attestation}\\par\\vspace{0.3em}
{$attestationStamp}

\\vfill
\\begin{center}
\\footnotesize\\textit{Department Office: Office of the Guidance Center (OGC) \\quad|\\quad Confidential record protected under RA 10173.}
\\end{center}

\\end{document}
LATEX;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $options
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private static function resolveAttestations(array $snapshot, array $options): array
    {
        $author = $options['attestation'] ?? $snapshot['attestation'] ?? null;
        $cosigner = $options['cosigner_attestation']
            ?? $options['co_signer']
            ?? $options['cosigner']
            ?? $options['supervisor_attestation']
            ?? $snapshot['cosigner_attestation']
            ?? $snapshot['co_signer']
            ?? $snapshot['cosigner']
            ?? $snapshot['supervisor_attestation']
            ?? null;

        $all = $options['attestations'] ?? $snapshot['attestations'] ?? null;
        if (is_array($all) && !empty($all)) {
            foreach ($all as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $role = strtolower((string) ($item['signer_role'] ?? ''));
                if (str_contains($role, 'cosign') || str_contains($role, 'supervisor')) {
                    $cosigner ??= $item;
                } else {
                    $author ??= $item;
                }
            }
        }

        if (is_array($author)) {
            if (isset($author['author']) && is_array($author['author'])) {
                if (isset($author['cosigner']) && is_array($author['cosigner'])) {
                    $cosigner ??= $author['cosigner'];
                }
                $author = $author['author'];
            }
            if ($cosigner === null && isset($author['cosigner']) && is_array($author['cosigner'])) {
                $cosigner = $author['cosigner'];
            }
            if ($cosigner === null && (!empty($author['cosigner_name']) || !empty($author['supervisor_name']))) {
                $cosigner = [
                    'signer_name' => $author['cosigner_name'] ?? $author['supervisor_name'],
                    'signer_role' => $author['cosigner_role'] ?? $author['supervisor_role'] ?? 'Guidance Supervisor',
                    'attested_at' => $author['cosigned_at'] ?? $author['supervisor_attested_at'] ?? $author['attested_at'] ?? '',
                    'snapshot_hash' => $author['cosigner_snapshot_hash'] ?? $author['snapshot_hash'] ?? '',
                ];
            }
            if ($cosigner === null && strtolower((string) ($author['signer_role'] ?? '')) === 'cosigner') {
                $cosigner = $author;
            }
        }

        return [$author, $cosigner];
    }

    private static function renderAttestationBanner(
        ?array $author,
        ?array $cosigner,
        ?string $verificationCode,
        bool $isDraft
    ): string {
        if ($isDraft) {
            return "\\begin{mdframed}[backgroundcolor=orange!10]\n"
                . "\\small \\textbf{ATTENTION:} Unfinalized draft record. Not official without authenticated attestation.\n"
                . "\\end{mdframed}\\par\\vspace{0.8em}\n";
        }

        if ($author === null && $cosigner === null) {
            return "\\begin{mdframed}[backgroundcolor=gray!5]\n"
                . "\\small \\textbf{Status:} Provisional Record (Awaiting Attestation)\n"
                . "\\end{mdframed}\\par\\vspace{0.8em}\n";
        }

        $lines = [];
        if ($author !== null) {
            $name = self::escape((string) ($author['signer_name'] ?? $author['name'] ?? $author['signer_role'] ?? 'Counselor'));
            $role = self::escape((string) ($author['signer_role'] ?? 'Counselor'));
            $date = self::escape((string) ($author['attested_at'] ?? $author['date'] ?? ''));
            $hash = self::escape(substr((string) ($author['snapshot_hash'] ?? ''), 0, 16) . '...');
            $lines[] = "\\textbf{Digitally Finalized by:} {$name} ({$role}) on {$date}";
            $lines[] = "\\textbf{Record Hash (SHA-256):} \\texttt{{$hash}} \\quad \\textbf{Method:} Authenticated Account Attestation";
        }

        if ($cosigner !== null) {
            $cosignerName = self::escape((string) ($cosigner['signer_name'] ?? $cosigner['name'] ?? $cosigner['supervisor_name'] ?? $cosigner['signer_role'] ?? 'Supervisor'));
            $cosignerDate = self::escape((string) ($cosigner['attested_at'] ?? $cosigner['cosigned_at'] ?? $cosigner['date'] ?? ''));
            $lines[] = "\\textbf{Supervisor Co-Signature:} Digitally co-signed by {$cosignerName} on {$cosignerDate}";
        }

        if ($verificationCode !== null && $verificationCode !== '') {
            $lines[] = "\\textbf{Verification Code:} " . self::escape($verificationCode);
        }

        return "\\begin{mdframed}[backgroundcolor=gray!10]\n\\small\n"
            . implode(" \\\\\n", $lines) . "\n"
            . "\\end{mdframed}\\par\\vspace{0.8em}\n";
    }

    private static function renderAttestationStamp(
        ?array $author,
        ?array $cosigner,
        ?string $verificationCode,
        bool $isDraft
    ): string {
        if ($isDraft) {
            return <<<LATEX
\\noindent\\begin{mdframed}[backgroundcolor=orange!5]
\\small\\textbf{Draft Watermark Notice:} This is an unfinalized preview document. Digital attestations and verification hashes are applied upon formal review and electronic signing.
\\end{mdframed}
LATEX;
        }

        $authorName = $author !== null ? self::escape((string) ($author['signer_name'] ?? $author['name'] ?? 'Counselor')) : '---';
        $authorRole = $author !== null ? self::escape((string) ($author['signer_role'] ?? 'Counselor')) : '---';
        $authorDate = $author !== null ? self::escape((string) ($author['attested_at'] ?? '')) : '---';
        $vCode = $verificationCode !== null && $verificationCode !== '' ? self::escape($verificationCode) : 'SYN-VERIFIED';

        if ($cosigner !== null) {
            $cosName = self::escape((string) ($cosigner['signer_name'] ?? $cosigner['name'] ?? $cosigner['supervisor_name'] ?? 'Supervisor'));
            $cosRole = self::escape((string) ($cosigner['signer_role'] ?? 'Guidance Supervisor'));
            $cosDate = self::escape((string) ($cosigner['attested_at'] ?? $cosigner['cosigned_at'] ?? ''));

            return <<<LATEX
\\noindent\\begin{tabularx}{\\textwidth}{|X|X|}
\\hline
\\textbf{Author Attestation} & \\textbf{Supervisor Co-Signature} \\\\ \\hline
\\footnotesize\\textbf{Digitally attested by:} {$authorName} & \\footnotesize Digitally co-signed by {$cosName} on {$cosDate} \\\\
\\footnotesize\\textbf{Designation:} {$authorRole} & \\footnotesize\\textbf{Supervisory Role:} {$cosRole} \\\\
\\footnotesize\\textbf{Attested At:} {$authorDate} & \\footnotesize\\textbf{Co-signed At:} {$cosDate} \\\\
\\footnotesize\\textbf{Attestation Method:} Authenticated Account & \\footnotesize\\textbf{Verification Code:} {$vCode} \\\\ \\hline
\\end{tabularx}
LATEX;
        }

        return <<<LATEX
\\noindent\\begin{tabularx}{\\textwidth}{|X|X|}
\\hline
\\textbf{Author Attestation} & \\textbf{Supervisory Standing / Verification} \\\\ \\hline
\\footnotesize\\textbf{Digitally attested by:} {$authorName} & \\footnotesize\\textbf{Direct Finalization Eligibility:} Verified \\\\
\\footnotesize\\textbf{Designation:} {$authorRole} & \\footnotesize\\textbf{Professional Standing:} Licensed Guidance Counselor \\\\
\\footnotesize\\textbf{Attested At:} {$authorDate} & \\footnotesize\\textbf{Verification Code:} {$vCode} \\\\ \\hline
\\end{tabularx}
LATEX;
    }

    /**
     * Renders Psychosocial Support Evaluation Form (Blank/Preview Template OR Threshold-Safe Aggregate Summary Report).
     * Canonical code: F-OGC-GPS-PEF-008-00, rev 00, eff September 1, 2025 / 2025-09-01.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $options
     */
    public static function renderPsychosocialEvaluation(array $snapshot, array $options = []): string
    {
        $dimensions = [
            'rating_clarity' => 'The goal of the psychosocial support session was achieved.',
            'rating_helpfulness' => 'The psychosocial support session was helpful.',
            'rating_welcoming' => 'The guidance personnel were approachable and welcoming.',
            'rating_empathy' => 'The guidance personnel understood and empathized with me.',
            'rating_environment' => 'Confidentiality was kept.',
        ];
        $visitTypeLabels = [
            'walk_in' => 'Voluntarily came / Walk-in',
            'called' => 'Called by Guidance Personnel',
            'referred' => 'Referred',
            'follow_up' => 'Follow-up',
        ];

        return self::renderServiceEvaluationDocument(
            $snapshot,
            $options,
            'F-OGC-GPS-PEF-008-00',
            'Psychosocial Support Evaluation Form',
            'psychosocial_support_evaluation',
            $dimensions,
            'future_visit_willingness',
            'Would you still wish to come and visit your guidance personnel in the future?',
            'Willingness to Visit Again Rate',
            'qualitative_comments',
            true,
            $visitTypeLabels
        );
    }

    /**
     * Renders Counseling Evaluation Form (Blank/Preview Template OR Threshold-Safe Aggregate Summary Report).
     * Canonical code: F-OGC-GCS-CEF-009-00, rev 00, eff September 1, 2025 / 2025-09-01.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $options
     */
    public static function renderCounselingEvaluation(array $snapshot, array $options = []): string
    {
        $dimensions = [
            'rating_goal_progress' => 'The goal of the counseling session was achieved.',
            'rating_overall_service' => 'The counseling session was helpful.',
            'rating_rapport' => 'The counselor was approachable and welcoming.',
            'rating_understanding' => 'The counselor understood and empathized with me.',
            'rating_confidentiality_comfort' => 'Confidentiality was kept.',
        ];
        $visitTypeLabels = [
            'walk_in' => 'Voluntarily came / Walk-in',
            'called' => 'Called by Counselor / Counselor-initiated',
            'referred' => 'Referred',
            'follow_up' => 'Follow-up',
        ];

        return self::renderServiceEvaluationDocument(
            $snapshot,
            $options,
            'F-OGC-GCS-CEF-009-00',
            'Counseling Evaluation Form',
            'counseling_evaluation',
            $dimensions,
            'recommend_service',
            'Would you still wish to come and visit your counselor in the future?',
            'Willingness to Visit Again Rate',
            'qualitative_feedback',
            true,
            $visitTypeLabels
        );
    }

    /**
     * Shared LaTeX renderer for Pseudonymous Service Evaluation Forms (PEF-008 & CEF-009).
     * Supports both form-template/preview mode and threshold-safe Aggregate Summary Report mode.
     * Uses only standard pre-installed LaTeX packages for native pdflatex compilation.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $options
     * @param array<string, string> $dimensions
     * @param array<string, string> $visitTypeLabels
     */
    private static function renderServiceEvaluationDocument(
        array $snapshot,
        array $options,
        string $defaultCode,
        string $defaultTitle,
        string $evaluationTypeKey,
        array $dimensions,
        string $booleanFieldKey,
        string $booleanQuestionText,
        string $booleanAggregateLabel,
        string $commentFieldKey,
        bool $includeVisitTypeSection,
        array $visitTypeLabels = []
    ): string {
        $data = $snapshot;
        if (isset($snapshot['data']) && is_array($snapshot['data'])) {
            $data = array_merge($data, $snapshot['data']);
        }
        if (isset($snapshot['aggregates']) && is_array($snapshot['aggregates'])) {
            $data = array_merge($data, $snapshot['aggregates']);
        }
        if (isset($data['aggregates']) && is_array($data['aggregates'])) {
            $data = array_merge($data, $data['aggregates']);
        }

        $instance = (array) ($snapshot['instance'] ?? []);
        $definition = (array) ($snapshot['definition'] ?? []);
        $revision = (array) ($snapshot['revision'] ?? []);

        $code = self::escape((string) ($definition['form_code'] ?? $data['form_code'] ?? $defaultCode));
        $title = self::escape((string) ($definition['canonical_title'] ?? $data['canonical_title'] ?? $defaultTitle));
        $rev = self::escape((string) ($revision['revision_number'] ?? $data['revision_number'] ?? '00'));

        $effectivityRaw = (string) ($revision['effectivity_date'] ?? $data['effectivity_date'] ?? 'September 1, 2025');
        if ($effectivityRaw === '2025-09-01' || $effectivityRaw === 'September 1, 2025') {
            $effectivity = 'September 1, 2025 (2025-09-01)';
        } else {
            $effectivity = self::escape($effectivityRaw) . ' (September 1, 2025 / 2025-09-01)';
        }

        $verificationCodeRaw = $options['verification_code']
            ?? $snapshot['verification_code']
            ?? ($snapshot['verification']['verification_code'] ?? null)
            ?? $data['verification_code']
            ?? null;
        $verificationCode = ($verificationCodeRaw !== null && trim((string) $verificationCodeRaw) !== '')
            ? self::escape((string) $verificationCodeRaw)
            : 'SYN-DOC-PROVISIONAL';

        $isDraft = (bool) ($options['is_draft']
            ?? $snapshot['is_draft']
            ?? $data['is_draft']
            ?? (($instance['status'] ?? '') === 'draft'));

        // Determine if Aggregate Summary Report mode is active
        $isAggregateMode = array_key_exists('cohort_met', $options)
            || array_key_exists('cohort_met', $snapshot)
            || array_key_exists('cohort_met', $data)
            || array_key_exists('averages', $data)
            || array_key_exists('total_responses', $data)
            || (($options['mode'] ?? $data['mode'] ?? '') === 'aggregate');

        $cohortMet = false;
        if ($isAggregateMode) {
            if (array_key_exists('cohort_met', $options)) {
                $cohortMet = (bool) $options['cohort_met'];
            } elseif (array_key_exists('cohort_met', $data)) {
                $cohortMet = (bool) $data['cohort_met'];
            } elseif (array_key_exists('cohort_met', $snapshot)) {
                $cohortMet = (bool) $snapshot['cohort_met'];
            } elseif (isset($data['total_responses'])) {
                $minReq = (int) ($data['minimum_required'] ?? 5);
                $cohortMet = ((int) $data['total_responses']) >= $minReq;
            } else {
                $cohortMet = is_array($data['averages'] ?? null) && !empty($data['averages']);
            }
        }

        $totalResponses = (int) ($data['total_responses'] ?? ($cohortMet ? 5 : 0));
        $minRequired = (int) ($data['minimum_required'] ?? 5);

        $providerName = self::escape((string) ($data['provider_name']
            ?? $data['counselor_name']
            ?? $data['personnel_name']
            ?? $data['practitioner_name']
            ?? 'Guidance Personnel / Counselor'));
        $academicYear = self::escape((string) ($data['academic_year'] ?? 'All Academic Years'));
        $academicTerm = self::escape((string) ($data['academic_term'] ?? 'All Terms'));
        $reportDate = self::escape((string) ($data['submitted_date'] ?? $data['report_date'] ?? $data['date'] ?? date('Y-m-d')));

        // Visit Type handling (walk_in, called, referred, follow_up)
        if ($visitTypeLabels === []) {
            $visitTypeLabels = [
                'walk_in' => 'Voluntarily came / Walk-in',
                'called' => 'Called by Guidance Personnel / Counselor',
                'referred' => 'Referred',
                'follow_up' => 'Follow-up',
            ];
        }

        $visitTypeBlock = '';
        if ($includeVisitTypeSection || isset($data['visit_type']) || isset($data['visit_type_distribution']) || isset($data['visit_types'])) {
            if ($isAggregateMode) {
                $dist = is_array($data['visit_type_distribution'] ?? null)
                    ? $data['visit_type_distribution']
                    : (is_array($data['visit_types'] ?? null) ? $data['visit_types'] : []);

                $vtRows = '';
                foreach ($visitTypeLabels as $vtKey => $vtLabel) {
                    $escLabel = self::escape($vtLabel);
                    if (!$cohortMet) {
                        $vtVal = 'Withheld (Fewer than ' . $minRequired . ' responses)';
                    } else {
                        $rawCount = $dist[$vtKey] ?? null;
                        $vtVal = $rawCount !== null ? self::escape((string) $rawCount) : 'Recorded in Cohort';
                    }
                    $vtRows .= "\\textbf{{$escLabel}} & {$vtVal} \\\\\n\\hline\n";
                }

                $visitTypeBlock = <<<LATEX
\\vspace{0.5em}
\\noindent{\\bfseries Visit Type Distribution}
\\vspace{0.25em}

\\noindent\\begin{tabularx}{\\textwidth}{|X|c|}
\\hline
\\textbf{Visit Type Category} & \\textbf{Responses} \\\\
\\hline
{$vtRows}\\end{tabularx}
LATEX;
            } else {
                $selectedVt = strtolower(trim((string) ($data['visit_type'] ?? '')));
                $checkWalkIn = self::check($selectedVt === 'walk_in');
                $checkCalled = self::check($selectedVt === 'called');
                $checkReferred = self::check($selectedVt === 'referred');
                $checkFollowUp = self::check($selectedVt === 'follow_up');
                $walkInLabelEsc = self::escape($visitTypeLabels['walk_in'] ?? 'Voluntarily came / Walk-in');
                $calledLabelEsc = self::escape($visitTypeLabels['called'] ?? 'Called by Guidance Personnel');
                $referredLabelEsc = self::escape($visitTypeLabels['referred'] ?? 'Referred');
                $followUpLabelEsc = self::escape($visitTypeLabels['follow_up'] ?? 'Follow-up');

                $visitTypeBlock = <<<LATEX
\\vspace{0.5em}
\\noindent\\textbf{Please check visit type:} \\quad
{$checkWalkIn} {$walkInLabelEsc} \\enspace
{$checkCalled} {$calledLabelEsc} \\\\[0.25em]
\\noindent\\phantom{\\textbf{Please check visit type:} \\quad}
{$checkReferred} {$referredLabelEsc} \\enspace
{$checkFollowUp} {$followUpLabelEsc}
LATEX;
            }
        }

        // Extract individual ratings or aggregate averages
        $rawRatings = is_array($data['ratings'] ?? null) ? $data['ratings'] : [];
        if (empty($rawRatings) && isset($data['ratings_json'])) {
            if (is_string($data['ratings_json'])) {
                $decoded = json_decode($data['ratings_json'], true);
                if (is_array($decoded)) {
                    $rawRatings = $decoded;
                }
            } elseif (is_array($data['ratings_json'])) {
                $rawRatings = $data['ratings_json'];
            }
        }
        $averages = is_array($data['averages'] ?? null) ? $data['averages'] : [];

        $dimensionRows = '';
        $computedSum = 0.0;
        $computedCount = 0;
        $idx = 1;

        foreach ($dimensions as $dimKey => $dimStatement) {
            $escStatement = self::escape($dimStatement);

            if ($isAggregateMode) {
                if (!$cohortMet) {
                    $scoreCell = '\\textbf{SUPPRESSED} (Fewer than ' . $minRequired . ' responses)';
                } else {
                    $rawMean = $averages[$dimKey] ?? $data[$dimKey] ?? $rawRatings[$dimKey] ?? null;
                    if (is_numeric($rawMean)) {
                        $val = (float) $rawMean;
                        $computedSum += $val;
                        $computedCount++;
                        $fmt2 = number_format($val, 2);
                        $scoreCell = "\\textbf{{$fmt2} / 5.00}";
                    } else {
                        $scoreCell = '---';
                    }
                }
                $dimensionRows .= "{$idx} & {$escStatement} & {$scoreCell} \\\\\n\\hline\n";
            } else {
                $selectedScore = $rawRatings[$dimKey] ?? $data[$dimKey] ?? null;
                $selectedInt = is_numeric($selectedScore) ? (int) $selectedScore : null;
                $scaleBoxes = [];
                for ($s = 1; $s <= 5; $s++) {
                    $box = self::check($selectedInt === $s);
                    $scaleBoxes[] = "{$box} {$s}";
                }
                $scaleStr = implode(' \\enspace ', $scaleBoxes);
                $dimensionRows .= "{$idx} & {$escStatement} & {$scaleStr} \\\\\n\\hline\n";
            }
            $idx++;
        }

        // Overall Mean Row in Aggregate Mode
        if ($isAggregateMode) {
            if (!$cohortMet) {
                $overallCell = '\\textbf{SUPPRESSED} (Fewer than ' . $minRequired . ' responses)';
            } else {
                $rawOverall = $averages['overall_mean'] ?? $data['overall_mean'] ?? null;
                if (!is_numeric($rawOverall) && $computedCount > 0) {
                    $rawOverall = $computedSum / $computedCount;
                }
                if (is_numeric($rawOverall)) {
                    $ov = (float) $rawOverall;
                    $ovFmt2 = number_format($ov, 2);
                    $overallCell = "\\textbf{{$ovFmt2} / 5.00}";
                } else {
                    $overallCell = '---';
                }
            }
            $dimensionRows .= "\\multicolumn{2}{|r|}{\\textbf{Overall Mean Rating:}} & {$overallCell} \\\\\n\\hline\n";
        }

        $tableColumnHeader = $isAggregateMode ? 'Mean Rating (1.00--5.00)' : 'Rating Scale (1--5)';

        // Boolean Response (future_visit_willingness / recommend_service)
        $escBoolQuestion = self::escape($booleanQuestionText);
        $escBoolAggLabel = self::escape($booleanAggregateLabel);

        if ($isAggregateMode) {
            if (!$cohortMet) {
                $booleanDisplay = '\\textbf{SUPPRESSED} --- Minimum of ' . $minRequired . ' responses not yet reached.';
            } else {
                $rawRate = $data['boolean_rate']
                    ?? $data[$booleanFieldKey . '_rate']
                    ?? $data[$booleanFieldKey]
                    ?? null;
                if (is_numeric($rawRate)) {
                    $numRate = (float) $rawRate;
                    if ($numRate >= 0.0 && $numRate <= 1.0 && !($numRate == 1.0 && isset($data['boolean_rate']) && $data['boolean_rate'] === 100)) {
                        $pct = $numRate * 100.0;
                    } else {
                        $pct = $numRate;
                    }
                    $pctFmt1 = number_format($pct, 1);
                    $booleanDisplay = "\\textbf{{$pctFmt1}\\%} Affirmative";
                } else {
                    $booleanDisplay = '---';
                }
            }
            $booleanSection = <<<LATEX
\\vspace{0.6em}
\\noindent\\textbf{B. {$escBoolQuestion}}\\\\[0.25em]
\\noindent\\textbf{{$escBoolAggLabel}:} {$booleanDisplay}
LATEX;
        } else {
            $rawBool = $data[$booleanFieldKey] ?? $data['future_visit_willingness'] ?? $data['boolean_response'] ?? null;
            $isYes = ($rawBool === true || $rawBool === 1 || $rawBool === '1' || strtolower((string) $rawBool) === 'yes');
            $isNo = ($rawBool === false || $rawBool === 0 || $rawBool === '0' || strtolower((string) $rawBool) === 'no');
            $checkYes = self::check($isYes);
            $checkNo = self::check($isNo);
            $rawReason = trim((string) ($data['future_visit_reason'] ?? $data['boolean_reason'] ?? ''));
            $reasonDisplay = $rawReason !== ''
                ? self::escape($rawReason)
                : '\\underline{\\hspace{7.5cm}}';

            $booleanSection = <<<LATEX
\\vspace{0.6em}
\\noindent\\textbf{B. {$escBoolQuestion}}\\\\[0.25em]
\\noindent {$checkYes} \\textbf{Yes} \\qquad {$checkNo} \\textbf{No} \\qquad \\textbf{Reason/s why:} {$reasonDisplay}
LATEX;
        }

        // Qualitative Comments / Feedback Section
        if ($isAggregateMode) {
            if (!$cohortMet) {
                $commentsContent = '\\textbf{COHORT SUPPRESSION ACTIVE:} Qualitative comments and feedback are strictly withheld because only '
                    . $totalResponses . ' response(s) have been recorded (minimum required: ' . $minRequired . '). No individual comments are disclosed.';
            } else {
                $rawComments = $data['comments']
                    ?? $data['approved_comments']
                    ?? $data[$commentFieldKey]
                    ?? $data['qualitative_comments']
                    ?? $data['qualitative_feedback']
                    ?? [];
                if (is_string($rawComments)) {
                    $rawComments = trim($rawComments) !== '' ? [$rawComments] : [];
                }
                $approvedList = [];
                if (is_array($rawComments)) {
                    foreach ($rawComments as $cItem) {
                        if (is_string($cItem) && trim($cItem) !== '') {
                            $approvedList[] = self::escape($cItem);
                        } elseif (is_array($cItem)) {
                            $modStatus = strtolower((string) ($cItem['comment_moderation_status'] ?? $cItem['status'] ?? 'approved'));
                            if ($modStatus !== 'approved') {
                                continue;
                            }
                            $cText = (string) ($cItem['comment'] ?? $cItem['text'] ?? $cItem['qualitative_comments'] ?? $cItem['qualitative_feedback'] ?? '');
                            if (trim($cText) !== '') {
                                $approvedList[] = self::escape($cText);
                            }
                        }
                    }
                }
                if (!empty($approvedList)) {
                    $itemsTex = '';
                    foreach ($approvedList as $escapedComment) {
                        $itemsTex .= "  \\item {$escapedComment}\n";
                    }
                    $commentsContent = "\\begin{itemize}\\setlength{\\itemsep}{2pt}\\setlength{\\parskip}{0pt}\n{$itemsTex}\\end{itemize}";
                } else {
                    $commentsContent = '\\textit{No approved qualitative comments recorded for this reporting cohort.}';
                }
            }
        } else {
            $singleComment = (string) ($data[$commentFieldKey]
                ?? $data['qualitative_comments']
                ?? $data['qualitative_feedback']
                ?? $data['comments']
                ?? '');
            if (trim($singleComment) !== '') {
                $commentsContent = self::escape($singleComment);
            } else {
                $commentsContent = "\\vspace{1.2cm}\\noindent\\underline{\\hspace{\\linewidth}}\\\\[0.6em]\\underline{\\hspace{\\linewidth}}";
            }
        }

        // Mode Banner (Aggregate Summary vs Form Template)
        $modeBanner = '';
        if ($isAggregateMode) {
            if ($cohortMet) {
                $modeBanner = <<<LATEX
\\noindent\\fcolorbox{boxborder}{boxbg}{%
\\parbox{\\dimexpr\\linewidth-2\\fboxsep-2\\fboxrule\\relax}{%
\\small
\\textbf{AGGREGATE SUMMARY REPORT MODE (Minimum Threshold Met)}\\\\[0.2em]
\\textbf{Provider / Personnel:} {$providerName} \\hfill \\textbf{Total Responses:} {$totalResponses} (Minimum Required: {$minRequired})\\\\
\\textbf{Academic Year / Term:} {$academicYear} / {$academicTerm} \\hfill \\textbf{Report Date:} {$reportDate}
}%
}
\\vspace{0.5em}
LATEX;
            } else {
                $modeBanner = <<<LATEX
\\noindent\\fcolorbox{boxborder}{warnbg}{%
\\parbox{\\dimexpr\\linewidth-2\\fboxsep-2\\fboxrule\\relax}{%
\\small
\\textbf{COHORT THRESHOLD SUPPRESSION NOTICE}\\\\[0.2em]
Total evaluation responses ({$totalResponses}) have not reached the minimum anonymity threshold of {$minRequired} responses. To prevent student re-identification and preserve strict pseudonymity, all dimension averages, willingness-to-visit-again rates, and qualitative comments are suppressed.\\\\
\\textbf{Provider / Personnel:} {$providerName} \\hfill \\textbf{Report Date:} {$reportDate}
}%
}
\\vspace{0.5em}
LATEX;
            }
        } else {
            $escEvalTypeLabel = self::escape(self::humanizeFieldLabel($evaluationTypeKey));
            $modeBanner = <<<LATEX
\\noindent\\fcolorbox{boxborder}{boxbg}{%
\\parbox{\\dimexpr\\linewidth-2\\fboxsep-2\\fboxrule\\relax}{%
\\small
\\textbf{Personnel / Counselor Evaluated:} {$providerName} \\hfill \\textbf{Date:} {$reportDate}\\\\
\\textbf{Academic Year / Term:} {$academicYear} / {$academicTerm} \\hfill \\textbf{Evaluation Type:} {$escEvalTypeLabel}
}%
}
\\vspace{0.5em}
LATEX;
        }

        // Draft Watermark
        $watermark = $isDraft
            ? "\\begin{tikzpicture}[remember picture,overlay]\n  \\node[rotate=45,scale=4.5,text opacity=0.12,color=gray] at (current page.center) {DRAFT - PREVIEW ONLY};\n\\end{tikzpicture}\n"
            : '';

        $draftNotice = $isDraft
            ? "\\noindent\\fcolorbox{boxborder}{warnbg}{\\parbox{\\dimexpr\\linewidth-2\\fboxsep-2\\fboxrule\\relax}{\\footnotesize\\textbf{ATTENTION:} Unfinalized draft preview record. Provisional Draft.}}\\vspace{0.5em}\n"
            : '';

        $headerColors = DocumentHeaderLatex::colorDefinitions();
        $fancy = DocumentHeaderLatex::fancyHeaderFooter(
            'Office of the Guidance Center',
            "\\textbf{Form Code:} {$code}",
            "\\textbf{Verification Code:} {$verificationCode}",
            'Page \\thepage'
        );
        $headerContainer = DocumentHeaderLatex::headerContainer(
            'guidance',
            'OFFICE OF THE GUIDANCE CENTER',
            $title,
            "\\textbf{Rev:} {$rev} \\quad \\textbf{Effectivity Date:} {$effectivity}"
        );

        return <<<LATEX
\\documentclass[10pt,a4paper]{article}
\\pdfminorversion=4
\\usepackage[margin=16mm,top=20mm,bottom=20mm,headheight=14pt,headsep=6mm]{geometry}
\\usepackage{amsmath,amssymb}
\\usepackage{xcolor}
\\usepackage{tikz}
\\usepackage{tabularx}
\\usepackage{array}
\\usepackage{graphicx}
\\usepackage{fancyhdr}

\\definecolor{boxbg}{RGB}{248, 249, 251}
\\definecolor{warnbg}{RGB}{254, 246, 235}
\\definecolor{boxborder}{RGB}{200, 208, 218}
{$headerColors}

\\setlength{\\parindent}{0pt}
{$fancy}

\\begin{document}
{$watermark}
% Canonical Metadata: {$defaultCode} | {$defaultTitle} | {$evaluationTypeKey}
{$headerContainer}

{$draftNotice}

\\noindent\\fcolorbox{boxborder}{boxbg}{%
\\parbox{\\dimexpr\\linewidth-2\\fboxsep-2\\fboxrule\\relax}{%
\\footnotesize
\\textbf{PRIVACY AND PSEUDONYMITY NOTICE (RA 10173):} In accordance with the Data Privacy Act of 2012 (RA 10173), your evaluation is collected solely for guidance service quality improvement. This evaluation is strictly \\textbf{pseudonymous and cryptographically unlinked} from your student identity (no student ID, personal identifier, session record, or evaluation token is stored with submitted responses). Individual responses are never disclosed to providers; aggregate summaries and moderated qualitative comments are released only when the minimum cohort threshold (\$n \\ge {$minRequired}\$) is met.
}%
}

\\vspace{0.5em}
{$modeBanner}
{$visitTypeBlock}

\\vspace{0.6em}
\\noindent\\textbf{A. Service Quality Rating Dimensions}\\\\[0.2em]
{\\footnotesize\\textit{Rating Scale: 1 --- Not at all \\enspace|\\enspace 2 --- To a lesser extent \\enspace|\\enspace 3 --- To a moderate extent \\enspace|\\enspace 4 --- To a large extent \\enspace|\\enspace 5 --- To the full extent}}
\\vspace{0.3em}

\\noindent\\renewcommand{\\arraystretch}{1.25}
\\begin{tabularx}{\\textwidth}{|c|X|c|}
\\hline
\\textbf{No.} & \\textbf{Evaluation Dimension} & \\textbf{{$tableColumnHeader}} \\\\
\\hline
{$dimensionRows}\\end{tabularx}

{$booleanSection}

\\vspace{0.6em}
\\noindent\\textbf{C. Comments and Suggestions}\\\\[0.3em]
\\noindent\\fcolorbox{boxborder}{white}{%
\\parbox{\\dimexpr\\linewidth-2\\fboxsep-2\\fboxrule\\relax}{%
\\small
{$commentsContent}
}%
}

\\vfill
\\hrule
\\vspace{0.4em}
\\noindent{\\footnotesize\\textbf{Verification Code:} {$verificationCode} \\hfill \\textbf{Department Office:} Office of the Guidance Center (OGC)}\\\\
{\\scriptsize\\textit{CONFIDENTIALITY \\& UNLINKABILITY GUARANTEE: Protected under RA 10173. One-time evaluation tokens are cryptographically separated from submitted evaluation rows. Minimum cohort size for aggregate reporting is {$minRequired}.}}

\\end{document}
LATEX;
    }

    private static function renderBody(string $key, array $data): string
    {
        switch ($key) {
            case 'guidance_general_referral':
                return self::renderGeneralReferral($data);
            case 'guidance_appointment_form':
                return self::renderAppointmentForm($data);
            case 'guidance_call_slip':
                return self::renderCallSlipBody($data);
            case 'psychosocial_support_interview':
                return self::renderInterviewForm($data);
            case 'psychosocial_support_contact':
            case 'counseling_contact_sheet':
                return self::renderContactSheet($data);
            case 'psychosocial_support_evaluation':
                return self::renderEvaluation($data);
            case 'counseling_evaluation':
                return self::renderEvaluation($data);
            default:
                return self::renderGenericFields($data);
        }
    }

    private static function renderGeneralReferral(array $d): string
    {
        $name = self::escape((string) ($d['student_name'] ?? ''));
        $id = self::escape((string) ($d['student_id'] ?? ''));
        $course = self::escape((string) ($d['course_year'] ?? ''));
        $refName = self::escape((string) ($d['referring_name'] ?? ''));
        $refDesigRaw = (string) ($d['referring_designation'] ?? '');
        $refDesigNorm = strtolower(trim($refDesigRaw));
        $refDept = self::escape((string) ($d['referring_department'] ?? ''));
        $contact = self::escape((string) ($d['contact_number'] ?? ''));
        $date = self::escape((string) ($d['referral_date'] ?? ''));
        $time = self::escape((string) ($d['referral_time'] ?? ''));
        $dateTime = trim($date . ($time !== '' ? ' ' . $time : ''));

        $reasons = array_map(static fn ($r): string => (string) $r, (array) ($d['reasons'] ?? []));
        $othersSpec = self::escape((string) ($d['reasons_other_specify'] ?? ''));
        $narrative = self::escape((string) ($d['reasons_narrative'] ?? 'None recorded.'));
        $remarksRaw = trim((string) ($d['remarks'] ?? $d['prior_actions_taken'] ?? ''));
        $remarks = self::escape($remarksRaw !== '' ? $remarksRaw : '---');

        $dateReceived = self::escape((string) ($d['date_received'] ?? ''));
        $respondedDate = self::escape((string) ($d['responded_date'] ?? ''));
        $actionTakenRaw = trim((string) ($d['action_taken'] ?? ''));
        $actionTaken = self::escape($actionTakenRaw !== '' ? $actionTakenRaw : '');

        // Left column (10 official items from 5. F-OGC-GRE-GGR-005-00)
        $cFamily = self::check(in_array('family_concerns', $reasons, true));
        $cPoorSelf = self::check(in_array('poor_self_esteem', $reasons, true));
        $cVictimBully = self::check(in_array('victim_of_bullying', $reasons, true));
        $cVictimPhys = self::check(in_array('victim_of_physical_abuse', $reasons, true));
        $cVictimSex = self::check(in_array('victim_of_sexual_abuse', $reasons, true));
        $cAnger = self::check(in_array('anger_management_issues', $reasons, true));
        $cOutOfTouch = self::check(in_array('out_of_touch_with_reality', $reasons, true));
        $cFear = self::check(in_array('excessive_fear', $reasons, true));
        $cAnxiety = self::check(in_array('excessive_anxiety', $reasons, true));
        $cSadness = self::check(in_array('persistent_sadness', $reasons, true));

        // Right column (10 official items from 5. F-OGC-GRE-GGR-005-00)
        $cSelfHarm = self::check(in_array('self_harming_tendencies', $reasons, true));
        $cAbsenteeism = self::check(in_array('absenteeism', $reasons, true));
        $cTardiness = self::check(in_array('tardiness', $reasons, true));
        $cInattentive = self::check(in_array('inattentiveness', $reasons, true));
        $cDisruptive = self::check(in_array('disruptive_behaviors', $reasons, true));
        $cBullying = self::check(in_array('bullying', $reasons, true));
        $cFighting = self::check(in_array('fighting', $reasons, true));
        $cStealing = self::check(in_array('stealing', $reasons, true));
        $cCheating = self::check(in_array('cheating', $reasons, true));
        $cExtreme = self::check(in_array('extreme_behavioral_changes', $reasons, true));

        $cOthers = self::check(in_array('others', $reasons, true) || $othersSpec !== '');
        $othersLine = $othersSpec !== '' ? $othersSpec : '\\underline{\\hspace{9cm}}';

        // Referrer Designation checkboxes: Adviser, Teacher, Others
        $isAdviser = str_contains($refDesigNorm, 'adviser');
        $isTeacher = !$isAdviser && (str_contains($refDesigNorm, 'teacher') || str_contains($refDesigNorm, 'faculty'));
        $isDesigOther = $refDesigRaw !== '' && !$isAdviser && !$isTeacher;
        $cAdviser = self::check($isAdviser);
        $cTeacher = self::check($isTeacher);
        $cDesigOther = self::check($isDesigOther);
        $desigOtherText = $isDesigOther ? ' (' . self::escape($refDesigRaw) . ')' : '';

        return <<<LATEX
\\noindent\\begin{tabularx}{\\textwidth}{|l|X|l|X|}
\\hline
\\textbf{Department:} & {$refDept} & \\textbf{Date \\& Time:} & {$dateTime} \\\\ \\hline
\\textbf{To:} & \\multicolumn{3}{l|}{The School Counselor/Personnel} \\\\ \\hline
\\textbf{May I refer:} & {$name} & \\textbf{Student / MIS ID:} & {$id} \\\\ \\hline
\\textbf{Course / Year / Grade \\& Section:} & \\multicolumn{3}{l|}{{$course}} \\\\ \\hline
\\end{tabularx}\\par\\vspace{0.55em}

\\noindent\\textbf{Reason For Referral:}\\par\\vspace{0.25em}
\\noindent\\small
\\begin{tabularx}{\\textwidth}{|X|X|}
\\hline
{$cFamily} Family concerns & {$cSelfHarm} Self-harming tendencies/behaviors \\\\
{$cPoorSelf} Poor Self-esteem & {$cAbsenteeism} Absenteeism \\\\
{$cVictimBully} Victim of bullying & {$cTardiness} Tardiness \\\\
{$cVictimPhys} Victim of physical abuse & {$cInattentive} Inattentiveness \\\\
{$cVictimSex} Victim of sexual abuse & {$cDisruptive} Disruptive behaviors inside the classroom \\\\
{$cAnger} Anger management issues & {$cBullying} Bullying \\\\
{$cOutOfTouch} Student seemed out of touch with reality (e.g., having bizarre thoughts or perceptions) & {$cFighting} Fighting \\\\
{$cFear} Excessive fear & {$cStealing} Stealing \\\\
{$cAnxiety} Excessive anxiety & {$cCheating} Cheating \\\\
{$cSadness} Persistent sadness & {$cExtreme} Extreme behavioral changes \\\\ \\hline
\\multicolumn{2}{|p{\\dimexpr\\textwidth-2\\tabcolsep-2\\arrayrulewidth\\relax}|}{{$cOthers} \\textbf{Others (Please specify):} {$othersLine}} \\\\ \\hline
\\end{tabularx}\\normalsize\\par\\vspace{0.55em}

\\noindent\\textbf{Description of Behavior / Incident Observed:} \\textit{\\footnotesize(Note: You may use an additional sheet of paper if the space is not enough. Please submit this form to the Guidance Center.)}\\par\\vspace{0.25em}
\\begin{mdframed}[backgroundcolor=gray!2]
{$narrative}
\\end{mdframed}\\par\\vspace{0.45em}

\\noindent\\textbf{REMARKS:}\\par\\vspace{0.25em}
\\begin{mdframed}[backgroundcolor=gray!2]
{$remarks}
\\end{mdframed}\\par\\vspace{0.6em}

\\noindent\\begin{tabularx}{\\textwidth}{X l}
\\textbf{Signature Over Printed Name:} {$refName} & \\textbf{Contact \\#:} {$contact} \\\\
\\multicolumn{2}{l}{\\textbf{Designation:} {$cAdviser} Adviser \\enspace {$cTeacher} Teacher \\enspace {$cDesigOther} Others{$desigOtherText}} \\\\
\\end{tabularx}\\par\\vspace{0.55em}

\\noindent\\textbf{For School Counselors/Personnel use only}\\par\\vspace{0.2em}
\\begin{tabularx}{\\textwidth}{|l|X|l|X|}
\\hline
\\textbf{Date Received:} & {$dateReceived} & \\textbf{Responded Date:} & {$respondedDate} \\\\ \\hline
\\end{tabularx}
\\vspace{-0.4pt}\\noindent\\begin{tabularx}{\\textwidth}{|l|X|}
\\textbf{Action Taken:} & {$actionTaken} \\\\ \\hline
\\end{tabularx}
LATEX;
    }

    private static function renderAppointmentForm(array $d): string
    {
        $num = self::escape((string) ($d['appointment_number'] ?? ''));
        $name = self::escape((string) ($d['student_name'] ?? ''));
        $id = self::escape((string) ($d['student_id'] ?? ''));
        $course = self::escape((string) ($d['course_year'] ?? ''));
        $contact = self::escape((string) ($d['contact_number'] ?? $d['phone'] ?? ''));
        $date = self::escape((string) ($d['scheduled_date'] ?? ''));
        $time = self::escape((string) ($d['scheduled_time'] ?? ''));
        $counselor = self::escape((string) ($d['counselor_name'] ?? ''));
        $purpose = self::escape((string) ($d['purpose'] ?? ''));
        $prefSched = self::escape((string) ($d['preferred_schedule'] ?? ''));

        return <<<LATEX
\\noindent\\begin{tabularx}{\\textwidth}{|l|X|l|X|}
\\hline
\\textbf{Date:} & {$date} {$time} & \\textbf{Appointment No:} & {$num} \\\\ \\hline
\\textbf{Name:} & {$name} & \\textbf{Student ID:} & {$id} \\\\ \\hline
\\textbf{Grade/Course/YR. Level:} & {$course} & \\textbf{Contact Number:} & {$contact} \\\\ \\hline
\\textbf{Reason:} & {$purpose} & \\textbf{Preferred Sched:} & {$prefSched} \\\\ \\hline
\\textbf{Assigned Counselor:} & \\multicolumn{3}{l|}{{$counselor}} \\\\ \\hline
\\end{tabularx}\\par\\vspace{1em}
\\textbf{Note:} Please be informed that you will be scheduled 2 days prior to your appointment, except for emergencies.
LATEX;
    }

    private static function renderCallSlipBody(array $d): string
    {
        $num = self::escape((string) ($d['call_slip_number'] ?? ''));
        $name = self::escape((string) ($d['student_name'] ?? ''));
        $id = self::escape((string) ($d['student_id'] ?? ''));
        $course = self::escape((string) ($d['course_year'] ?? ''));
        $date = self::escape((string) ($d['call_date'] ?? ''));
        $time = self::escape((string) ($d['call_time'] ?? ''));
        $purpose = self::escape((string) ($d['purpose'] ?? ''));
        $counselor = self::escape((string) ($d['issuing_counselor_name'] ?? ''));
        $note = self::escape((string) ($d['note'] ?? $d['delivery_notes'] ?? ''));

        return <<<LATEX
\\noindent\\textbf{to see the Guidance Counselor for the following purpose/reason:}\\par\\vspace{0.5em}
\\begin{tabularx}{\\textwidth}{|l|X|l|X|}
\\hline
\\textbf{Date:} & {$date} {$time} & \\textbf{Call Slip No:} & {$num} \\\\ \\hline
\\textbf{Name:} & {$name} & \\textbf{Student ID:} & {$id} \\\\ \\hline
\\textbf{Grade/Strand / Course/Yr. Level:} & \\multicolumn{3}{l|}{{$course}} \\\\ \\hline
\\textbf{Purpose:} & {$purpose} & \\textbf{Guidance Personnel:} & {$counselor} \\\\ \\hline
\\textbf{Note:} & \\multicolumn{3}{l|}{{$note}} \\\\ \\hline
\\end{tabularx}\\par\\vspace{1em}
\\noindent\\hfill\\textbf{Guidance Personnel Signature:} \\underline{\\hspace{5.5cm}}
LATEX;
    }

    private static function renderInterviewForm(array $d): string
    {
        $name = self::escape((string) ($d['student_name'] ?? ''));
        $id = self::escape((string) ($d['student_id'] ?? ''));
        $date = self::escape((string) ($d['interview_date'] ?? ''));
        $concerns = self::escape((string) ($d['presenting_concerns'] ?? ''));
        $assessment = self::escape((string) ($d['counselor_assessment'] ?? ''));
        $plan = self::escape((string) ($d['intervention_plan'] ?? ''));

        return <<<LATEX
\\noindent\\begin{tabularx}{\\textwidth}{|l|X|l|X|}
\\hline
\\textbf{Student Name:} & {$name} & \\textbf{Student ID:} & {$id} \\\\ \\hline
\\textbf{Interview Date:} & {$date} & & \\\\ \\hline
\\end{tabularx}\\par\\vspace{0.8em}

\\noindent\\textbf{Presenting Concerns:}\\par
\\begin{mdframed}
{$concerns}
\\end{mdframed}\\par\\vspace{0.8em}

\\noindent\\textbf{Counselor Assessment:}\\par
\\begin{mdframed}
{$assessment}
\\end{mdframed}\\par\\vspace{0.8em}

\\noindent\\textbf{Action \\& Intervention Plan:}\\par
\\begin{mdframed}
{$plan}
\\end{mdframed}
LATEX;
    }

    private static function renderContactSheet(array $d): string
    {
        $sessionNum = self::escape((string) ($d['session_number'] ?? '1'));
        $date = self::escape((string) ($d['session_date'] ?? ''));
        $concerns = self::escape((string) ($d['concerns_discussed'] ?? $d['client_state'] ?? ''));
        $interventions = self::escape((string) ($d['interventions_and_responses'] ?? $d['interventions_used'] ?? ''));
        $plan = self::escape((string) ($d['homework_and_commitments'] ?? $d['action_items'] ?? ''));

        return <<<LATEX
\\noindent\\textbf{Session Number:} {$sessionNum} \\quad \\textbf{Session Date:} {$date}\\par\\vspace{0.8em}

\\noindent\\textbf{Summary of Concerns / Issues Discussed:}\\par
\\begin{mdframed}
{$concerns}
\\end{mdframed}\\par\\vspace{0.8em}

\\noindent\\textbf{Clinical Interventions Used:}\\par
\\begin{mdframed}
{$interventions}
\\end{mdframed}\\par\\vspace{0.8em}

\\noindent\\textbf{Client Insights \\& Action Items:}\\par
\\begin{mdframed}
{$plan}
\\end{mdframed}
LATEX;
    }

    private static function renderEvaluation(array $d): string
    {
        $feedback = self::escape((string) ($d['qualitative_feedback'] ?? $d['qualitative_comments'] ?? ''));

        return <<<LATEX
\\noindent\\textbf{Pseudonymous Service Evaluation Record}\\par
\\textit{Aggregated for quality improvement; no student PII retained in evaluation responses.}\\par\\vspace{0.8em}

\\noindent\\textbf{Student Feedback / Comments:}\\par
\\begin{mdframed}
{$feedback}
\\end{mdframed}
LATEX;
    }

    private static function renderGenericFields(array $d): string
    {
        $lines = [];
        foreach ($d as $key => $val) {
            if (is_scalar($val)) {
                $label = self::humanizeFieldLabel((string) $key);
                $lines[] = '\\textbf{' . self::escape($label) . ':} ' . self::escape((string) $val) . ' \\\\';
            }
        }
        return implode("\n", $lines);
    }

    private static function humanizeFieldLabel(string $key): string
    {
        $normalized = strtolower(trim($key));
        $map = [
            'phq9_score' => 'PHQ-9 Score',
            'gad7_score' => 'GAD-7 Score',
            'risk_level' => 'Risk Level',
            'suicide_risk' => 'Suicide Risk',
            'self_harm_risk' => 'Self-Harm Risk',
            'mood' => 'Mood',
            'affect' => 'Affect',
            'sleep_pattern' => 'Sleep Pattern',
            'psychosocial_support_evaluation' => 'Psychosocial Support Evaluation',
            'counseling_evaluation' => 'Counseling Evaluation',
        ];

        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        // Preserve labels that already contain human formatting (spaces, mixed case, slashes).
        if (str_contains($key, ' ') || str_contains($key, '/') || preg_match('/[A-Z]/', $key) === 1) {
            return trim($key);
        }

        $words = preg_split('/[_-]+/', trim($key)) ?: [$key];
        $formatted = [];
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $upper = strtoupper($word);
            if (in_array($upper, ['ID', 'PHQ9', 'GAD7', 'OGC', 'BMI', 'BP'], true)) {
                $formatted[] = match ($upper) {
                    'PHQ9' => 'PHQ-9',
                    'GAD7' => 'GAD-7',
                    default => $upper,
                };
            } else {
                $formatted[] = ucfirst(strtolower($word));
            }
        }

        return implode(' ', $formatted);
    }

    private static function humanizeScreeningValue(string $key, string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $normalizedKey = strtolower(trim($key));
        if ($normalizedKey === 'risk_level' || str_ends_with($normalizedKey, '_risk')) {
            if ($trimmed === strtolower($trimmed) && preg_match('/^[a-z0-9_-]+$/', $trimmed) === 1) {
                return ucwords(str_replace(['_', '-'], ' ', $trimmed));
            }
        }

        return $trimmed;
    }
}
