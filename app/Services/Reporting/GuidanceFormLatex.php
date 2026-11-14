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
            $attestationBanner = "\\begin{mdframed}[backgroundcolor=gray!10,roundcorner=4pt]\n"
                . "\\small \\textbf{Digitally Finalized by:} {$signer} on {$date} \\\\\n"
                . "\\textbf{Record Hash (SHA-256):} \\texttt{{$hash}} \\quad \\textbf{Method:} Authenticated Account Attestation\n"
                . "\\end{mdframed}\\par\\vspace{0.8em}\n";
        } elseif ($isDraft) {
            $attestationBanner = "\\begin{mdframed}[backgroundcolor=orange!10,roundcorner=4pt]\n"
                . "\\small \\textbf{ATTENTION:} Unfinalized draft record. Not official without authenticated attestation.\n"
                . "\\end{mdframed}\\par\\vspace{0.8em}\n";
        }

        // Build dynamic content based on internal key
        $body = self::renderBody($key, $formData);

        return <<<LATEX
\\documentclass[10pt,a4paper]{article}
\\usepackage[margin=18mm,top=20mm,bottom=22mm]{geometry}
\\usepackage{amsmath,amssymb}
\\usepackage{xcolor}
\\usepackage{mdframed}
\\usepackage{array}
\\usepackage{tabularx}
\\usepackage{fancyhdr}
\\pagestyle{fancy}
\\fancyhf{}
\\renewcommand{\\headrulewidth}{0.4pt}
\\renewcommand{\\footrulewidth}{0.4pt}
\\fancyhead[L]{\\small\\textbf{Foundation University} --- Office of the Guidance Center}
\\fancyhead[R]{\\small\\textbf{Form Code:} {$code}}
\\fancyfoot[L]{\\footnotesize {$verificationLine}}
\\fancyfoot[R]{\\footnotesize Page \\thepage\\ of 1}
{$watermark}

\\begin{document}

\\begin{center}
{\\Large\\textbf{OFFICE OF THE GUIDANCE CENTER}}\\par\\vspace{0.2em}
{\\large\\textbf{{$title}}}\\par\\vspace{0.3em}
{\\footnotesize\\textbf{Form Code:} {$code} \\quad \\textbf{Rev:} {$rev} \\quad \\textbf{Effectivity Date:} {$effectivity} \\quad \\textbf{Instance:} {$instanceNum}}
\\end{center}
\\vspace{0.5em}
\\hrule
\\vspace{0.8em}

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
        $date = self::escape((string) ($data['scheduled_date'] ?? $data['appointment_date'] ?? ''));
        $time = self::escape((string) ($data['scheduled_time'] ?? $data['appointment_time'] ?? ''));
        $counselor = self::escape((string) ($data['counselor_name'] ?? $data['assigned_counselor'] ?? ''));

        $purposeRaw = (string) ($data['purpose'] ?? '');
        $purposeOtherRaw = (string) ($data['purpose_other'] ?? $data['other_purpose'] ?? '');

        $normPurpose = strtolower(str_replace([' ', '-'], '_', trim($purposeRaw)));
        $isRoutine = ($normPurpose === 'routine_interview');
        $isCounseling = ($normPurpose === 'counseling');
        $isFollowUp = ($normPurpose === 'follow_up');
        $isIntake = ($normPurpose === 'intake');
        $isInquiry = ($normPurpose === 'inquiry');
        $isOthers = ($normPurpose === 'others' || $normPurpose === 'other' || (!$isRoutine && !$isCounseling && !$isFollowUp && !$isIntake && !$isInquiry && $normPurpose !== ''));

        $checkRoutine = self::check($isRoutine);
        $checkCounseling = self::check($isCounseling);
        $checkFollowUp = self::check($isFollowUp);
        $checkIntake = self::check($isIntake);
        $checkInquiry = self::check($isInquiry);
        $checkOthers = self::check($isOthers);

        $otherText = $purposeOtherRaw !== '' ? $purposeOtherRaw : ($isOthers && $normPurpose !== 'others' && $normPurpose !== 'other' ? $purposeRaw : '');
        $othersSpecify = ($isOthers && $otherText !== '') ? ' (' . self::escape($otherText) . ')' : '';

        $watermark = $isDraft
            ? "\\usepackage{draftwatermark}\n\\SetWatermarkText{DRAFT - PREVIEW ONLY}\n\\SetWatermarkScale{0.6}\n\\SetWatermarkColor[gray]{0.85}\n"
            : '';

        if ($isQuad) {
            return self::renderAppointmentSlipQuad(
                $num,
                $name,
                $id,
                $course,
                $date,
                $time,
                $counselor,
                $checkRoutine,
                $checkCounseling,
                $checkFollowUp,
                $checkIntake,
                $checkInquiry,
                $checkOthers,
                $othersSpecify,
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
            $date,
            $time,
            $counselor,
            $checkRoutine,
            $checkCounseling,
            $checkFollowUp,
            $checkIntake,
            $checkInquiry,
            $checkOthers,
            $othersSpecify,
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
        string $date,
        string $time,
        string $counselor,
        string $checkRoutine,
        string $checkCounseling,
        string $checkFollowUp,
        string $checkIntake,
        string $checkInquiry,
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
            $attestationBanner = "\\begin{mdframed}[backgroundcolor=gray!10,roundcorner=4pt]\n"
                . "\\small \\textbf{Digitally Finalized by:} {$signer} on {$attDate} \\\\\n"
                . "\\textbf{Record Hash (SHA-256):} \\texttt{{$hash}} \\quad \\textbf{Method:} Authenticated Account Attestation\n"
                . "\\end{mdframed}\\par\\vspace{0.8em}\n";
        } elseif ($isDraft) {
            $attestationBanner = "\\begin{mdframed}[backgroundcolor=orange!10,roundcorner=4pt]\n"
                . "\\small \\textbf{ATTENTION:} Unfinalized draft record. Not official without authenticated attestation.\n"
                . "\\end{mdframed}\\par\\vspace{0.8em}\n";
        }

        return <<<LATEX
\\documentclass[10pt,a4paper]{article}
\\usepackage[margin=18mm,top=20mm,bottom=22mm]{geometry}
\\usepackage{amsmath,amssymb}
\\usepackage{xcolor}
\\usepackage{mdframed}
\\usepackage{array}
\\usepackage{tabularx}
\\usepackage{fancyhdr}
\\pagestyle{fancy}
\\fancyhf{}
\\renewcommand{\\headrulewidth}{0.4pt}
\\renewcommand{\\footrulewidth}{0.4pt}
\\fancyhead[L]{\\small\\textbf{Foundation University} --- Office of the Guidance Center}
\\fancyhead[R]{\\small\\textbf{Form Code:} F-OGC-GAP-006-00 (In-Doc: F-OGC-GRE-GAP-006-00)}
\\fancyfoot[L]{\\footnotesize {$verificationLine}}
\\fancyfoot[R]{\\footnotesize Page \\thepage\\ of 1}
{$watermark}

\\begin{document}

\\begin{center}
{\\normalsize\\textbf{FOUNDATION UNIVERSITY}}\\par\\vspace{0.2em}
{\\Large\\textbf{OFFICE OF THE GUIDANCE CENTER}}\\par\\vspace{0.2em}
{\\large\\textbf{GUIDANCE APPOINTMENT FORM}}\\par\\vspace{0.3em}
{\\footnotesize\\textbf{Canonical Code:} F-OGC-GAP-006-00 \\quad \\textbf{In-Doc Code:} F-OGC-GRE-GAP-006-00 \\quad \\textbf{Rev:} 00 \\quad \\textbf{Effectivity Date:} September 1, 2025}
\\end{center}
\\vspace{0.5em}
\\hrule
\\vspace{0.8em}

{$attestationBanner}

\\noindent\\textbf{Appointment Details}\\par\\vspace{0.4em}
\\begin{tabularx}{\\textwidth}{|l|X|l|X|}
\\hline
\\textbf{Appointment No:} & {$num} & \\textbf{Student ID:} & {$id} \\\\ \\hline
\\textbf{Student Name:} & {$name} & \\textbf{Course \\& Year:} & {$course} \\\\ \\hline
\\textbf{Scheduled Date:} & {$date} & \\textbf{Scheduled Time:} & {$time} \\\\ \\hline
\\textbf{Assigned Counselor:} & {$counselor} & & \\\\ \\hline
\\end{tabularx}\\par\\vspace{1.2em}

\\noindent\\textbf{Purpose of Appointment:}\\par\\vspace{0.4em}
\\begin{tabular}{lll}
{$checkRoutine} Routine Interview & {$checkCounseling} Counseling & {$checkFollowUp} Follow-up \\\\
{$checkIntake} Intake & {$checkInquiry} Inquiry & {$checkOthers} Others{$othersSpecify} \\\\
\\end{tabular}\\par\\vspace{1.5em}

\\begin{mdframed}[backgroundcolor=gray!5,roundcorner=3pt]
\\small\\textbf{Important Reminders for the Student:}
\\begin{itemize}
  \\item Please be at the Office of the Guidance Center 5 minutes before your scheduled appointment.
  \\item Present this appointment slip or confirmation upon arrival at the guidance reception.
  \\item In case of unavoidable conflicts, please notify the Guidance Office ahead of time.
\\end{itemize}
\\end{mdframed}

\\vspace{1em}
\\noindent\\begin{tabularx}{\\textwidth}{X r}
\\textbf{Student Acknowledgment Signature:} \\underline{\\hspace{5.5cm}} & \\textbf{Date:} \\underline{\\hspace{3cm}} \\\\
\\end{tabularx}

\\vfill
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
        string $date,
        string $time,
        string $counselor,
        string $checkRoutine,
        string $checkCounseling,
        string $checkFollowUp,
        string $checkIntake,
        string $checkInquiry,
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
            $attDate = self::escape((string) ($attestation['attested_at'] ?? ''));
            $hash = self::escape(substr((string) ($attestation['snapshot_hash'] ?? ''), 0, 10) . '...');
            $attestationLine = "\\textbf{Attested:} {$signer} ({$hash})";
        } elseif ($isDraft) {
            $attestationLine = "\\textbf{Notice:} Draft preview (Unofficial)";
        } else {
            $attestationLine = "\\textbf{Attestation:} Official Record";
        }

        $copies = [
            1 => 'Copy 1 (Student Copy)',
            2 => 'Copy 2 (Counselor / Office Copy)',
            3 => 'Copy 3 (Teacher / Department Copy)',
            4 => 'Copy 4 (Guard / File Copy)',
        ];

        $quads = [];
        foreach ($copies as $idx => $label) {
            $quads[$idx] = self::renderAppointmentQuadrant(
                $label,
                $num,
                $name,
                $id,
                $course,
                $date,
                $time,
                $counselor,
                $checkRoutine,
                $checkCounseling,
                $checkFollowUp,
                $checkIntake,
                $checkInquiry,
                $checkOthers,
                $othersSpecify,
                $verificationLine,
                $attestationLine
            );
        }

        return <<<LATEX
\\documentclass[10pt,a4paper]{article}
\\usepackage[margin=8mm,top=8mm,bottom=8mm,left=8mm,right=8mm]{geometry}
\\usepackage{amsmath,amssymb}
\\usepackage{xcolor}
\\usepackage{mdframed}
\\usepackage{array}
\\usepackage{tabularx}
\\pagestyle{empty}
\\setlength{\\parindent}{0pt}
{$watermark}

\\begin{document}

\\noindent
\\begin{minipage}[t][132mm][t]{93mm}
{$quads[1]}
\\end{minipage}%
\\hfill%
\\begin{minipage}[t][132mm][t]{6mm}
\\centering
\\vbox to 132mm{\\cleaders\\vbox to 4mm{\\vss\\rule{0.5pt}{2mm}\\vss}\\vfill}
\\end{minipage}%
\\hfill%
\\begin{minipage}[t][132mm][t]{93mm}
{$quads[2]}
\\end{minipage}

\\vspace{2mm}
\\noindent\\cleaders\\hbox to 4mm{\\hss\\rule{2mm}{0.5pt}\\hss}\\hfill%
\\quad\\raisebox{-0.2ex}{\\scriptsize - - - - - - \\textbf{CUT ALONG DASHED LINE} - - - - - -}\\quad%
\\cleaders\\hbox to 4mm{\\hss\\rule{2mm}{0.5pt}\\hss}\\hfill\\kern0pt
\\vspace{2mm}

\\noindent
\\begin{minipage}[t][132mm][t]{93mm}
{$quads[3]}
\\end{minipage}%
\\hfill%
\\begin{minipage}[t][132mm][t]{6mm}
\\centering
\\vbox to 132mm{\\cleaders\\vbox to 4mm{\\vss\\rule{0.5pt}{2mm}\\vss}\\vfill}
\\end{minipage}%
\\hfill%
\\begin{minipage}[t][132mm][t]{93mm}
{$quads[4]}
\\end{minipage}

\\end{document}
LATEX;
    }

    private static function renderAppointmentQuadrant(
        string $copyLabel,
        string $num,
        string $name,
        string $id,
        string $course,
        string $date,
        string $time,
        string $counselor,
        string $checkRoutine,
        string $checkCounseling,
        string $checkFollowUp,
        string $checkIntake,
        string $checkInquiry,
        string $checkOthers,
        string $othersSpecify,
        string $verificationLine,
        string $attestationLine
    ): string {
        return <<<LATEX
\\begin{mdframed}[linewidth=0.5pt,innerleftmargin=2mm,innerrightmargin=2mm,innertopmargin=2mm,innerbottommargin=2mm]
{\\centering
{\\bfseries\\scriptsize FOUNDATION UNIVERSITY}\\par
{\\bfseries\\tiny OFFICE OF THE GUIDANCE CENTER}\\par
{\\bfseries\\footnotesize GUIDANCE APPOINTMENT FORM}\\par
{\\tiny\\textbf{Canonical Code:} F-OGC-GAP-006-00 \\quad \\textbf{In-Doc:} F-OGC-GRE-GAP-006-00 \\quad \\textbf{Rev:} 00 \\quad \\textbf{Eff:} 2025-09-01}\\par
}\\vspace{1mm}\\hrule\\vspace{0.8mm}
{\\raggedleft\\tiny\\textit{{$copyLabel}}}\\par\\vspace{0.5mm}
\\begin{tabularx}{\\linewidth}{|l|X|l|X|}
\\hline
\\textbf{\\tiny Appt No:} & {\\tiny {$num}} & \\textbf{\\tiny ID:} & {\\tiny {$id}} \\\\ \\hline
\\textbf{\\tiny Name:} & \\multicolumn{3}{l|}{\\tiny {$name}} \\\\ \\hline
\\textbf{\\tiny Course/Yr:} & {\\tiny {$course}} & \\textbf{\\tiny Sched:} & {\\tiny {$date} {$time}} \\\\ \\hline
\\textbf{\\tiny Counselor:} & \\multicolumn{3}{l|}{\\tiny {$counselor}} \\\\ \\hline
\\end{tabularx}\\par\\vspace{1mm}
{\\tiny\\textbf{Purpose of Appointment:}}\\par
{\\tiny
{$checkRoutine} Routine Int. \\enspace {$checkCounseling} Counseling \\enspace {$checkFollowUp} Follow-up \\\\
{$checkIntake} Intake \\enspace {$checkInquiry} Inquiry \\enspace {$checkOthers} Others{$othersSpecify}
}\\par\\vspace{1mm}
{\\tiny\\textit{Note: Please be at Guidance Center 5 mins before schedule.}}\\par\\vspace{1mm}
{\\tiny\\textbf{Student Sig:} \\underline{\\hspace{2.5cm}} \\hfill \\textbf{Date:} \\underline{\\hspace{1.5cm}}}\\par\\vspace{1mm}
{\\tiny {$verificationLine} \\hfill {$attestationLine}}\\par\\vspace{0.5mm}
{\\tiny\\centering\\textit{Confidential Guidance Record --- RA 10173}\\par}
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

        $purposeRaw = (string) ($data['purpose'] ?? '');
        $purposeOtherRaw = (string) ($data['purpose_other'] ?? $data['other_purpose'] ?? '');

        $normPurpose = strtolower(str_replace([' ', '-'], '_', trim($purposeRaw)));
        $isPersonal = ($normPurpose === 'personal_counseling');
        $isCareer = ($normPurpose === 'career_counseling');
        $isRoutine = ($normPurpose === 'routine_interview');
        $isFollowUp = ($normPurpose === 'follow_up');
        $isAcademic = ($normPurpose === 'academic_counseling');
        $isOthers = ($normPurpose === 'others' || $normPurpose === 'other' || (!$isPersonal && !$isCareer && !$isRoutine && !$isFollowUp && !$isAcademic && $normPurpose !== ''));

        $checkPersonal = self::check($isPersonal);
        $checkCareer = self::check($isCareer);
        $checkRoutine = self::check($isRoutine);
        $checkFollowUp = self::check($isFollowUp);
        $checkAcademic = self::check($isAcademic);
        $checkOthers = self::check($isOthers);

        $otherText = $purposeOtherRaw !== '' ? $purposeOtherRaw : ($isOthers && $normPurpose !== 'others' && $normPurpose !== 'other' ? $purposeRaw : '');
        $othersSpecify = ($isOthers && $otherText !== '') ? ' (' . self::escape($otherText) . ')' : '';

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
                $checkPersonal,
                $checkCareer,
                $checkRoutine,
                $checkFollowUp,
                $checkAcademic,
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
            $checkPersonal,
            $checkCareer,
            $checkRoutine,
            $checkFollowUp,
            $checkAcademic,
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
        string $checkPersonal,
        string $checkCareer,
        string $checkRoutine,
        string $checkFollowUp,
        string $checkAcademic,
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
            $attestationBanner = "\\begin{mdframed}[backgroundcolor=gray!10,roundcorner=4pt]\n"
                . "\\small \\textbf{Digitally Finalized by:} {$signer} on {$attDate} \\\\\n"
                . "\\textbf{Record Hash (SHA-256):} \\texttt{{$hash}} \\quad \\textbf{Method:} Authenticated Account Attestation\n"
                . "\\end{mdframed}\\par\\vspace{0.8em}\n";
        } elseif ($isDraft) {
            $attestationBanner = "\\begin{mdframed}[backgroundcolor=orange!10,roundcorner=4pt]\n"
                . "\\small \\textbf{ATTENTION:} Unfinalized draft record. Not official without authenticated attestation.\n"
                . "\\end{mdframed}\\par\\vspace{0.8em}\n";
        }

        return <<<LATEX
\\documentclass[10pt,a4paper]{article}
\\usepackage[margin=18mm,top=20mm,bottom=22mm]{geometry}
\\usepackage{amsmath,amssymb}
\\usepackage{xcolor}
\\usepackage{mdframed}
\\usepackage{array}
\\usepackage{tabularx}
\\usepackage{fancyhdr}
\\pagestyle{fancy}
\\fancyhf{}
\\renewcommand{\\headrulewidth}{0.4pt}
\\renewcommand{\\footrulewidth}{0.4pt}
\\fancyhead[L]{\\small\\textbf{Foundation University} --- Office of the Guidance Center}
\\fancyhead[R]{\\small\\textbf{Form Code:} F-OGC-GSP-CSP-007-00}
\\fancyfoot[L]{\\footnotesize {$verificationLine}}
\\fancyfoot[R]{\\footnotesize Page \\thepage\\ of 1}
{$watermark}

\\begin{document}

\\begin{center}
{\\normalsize\\textbf{FOUNDATION UNIVERSITY}}\\par\\vspace{0.2em}
{\\Large\\textbf{OFFICE OF THE GUIDANCE CENTER}}\\par\\vspace{0.2em}
{\\large\\textbf{GUIDANCE CALL SLIP}}\\par\\vspace{0.3em}
{\\footnotesize\\textbf{Canonical Code:} F-OGC-GSP-CSP-007-00 \\quad \\textbf{Rev:} 00 \\quad \\textbf{Effectivity Date:} September 1, 2025}
\\end{center}
\\vspace{0.5em}
\\hrule
\\vspace{0.8em}

{$attestationBanner}

\\noindent\\textit{Please excuse the student named below to report to the Office of the Guidance Center:}\\par\\vspace{0.5em}

\\noindent\\textbf{Student \\& Call Details}\\par\\vspace{0.4em}
\\begin{tabularx}{\\textwidth}{|l|X|l|X|}
\\hline
\\textbf{Call Slip No:} & {$num} & \\textbf{Student ID:} & {$id} \\\\ \\hline
\\textbf{Student Name:} & {$name} & \\textbf{Course \\& Year:} & {$course} \\\\ \\hline
\\textbf{Date of Call:} & {$date} & \\textbf{Time of Call:} & {$time} \\\\ \\hline
\\textbf{Issuing Counselor:} & {$counselor} & & \\\\ \\hline
\\end{tabularx}\\par\\vspace{1.2em}

\\noindent\\textbf{Purpose of Call:}\\par\\vspace{0.4em}
\\begin{tabular}{lll}
{$checkPersonal} Personal Counseling & {$checkCareer} Career Counseling & {$checkRoutine} Routine Interview \\\\
{$checkFollowUp} Follow-up & {$checkAcademic} Academic Counseling & {$checkOthers} Others{$othersSpecify} \\\\
\\end{tabular}\\par\\vspace{1.5em}

\\vspace{1.5em}
\\noindent\\cleaders\\hbox to 4mm{\\hss\\rule{2mm}{0.4pt}\\hss}\\hfill%
\\quad\\raisebox{-0.2ex}{\\footnotesize - - - - - - \\textbf{TEAR-OFF RETURN SLIP / TEACHER ACKNOWLEDGMENT} - - - - - -}\\quad%
\\cleaders\\hbox to 4mm{\\hss\\rule{2mm}{0.4pt}\\hss}\\hfill\\kern0pt
\\vspace{1em}

\\noindent\\begin{mdframed}[roundcorner=3pt]
\\textbf{Teacher / Instructor Acknowledgment Return Section}\\par\\vspace{0.4em}
\\small
\\begin{tabularx}{\\textwidth}{|l|X|l|X|}
\\hline
\\textbf{Student Name:} & {$name} & \\textbf{Student ID:} & {$id} \\\\ \\hline
\\textbf{Call Slip No:} & {$num} & \\textbf{Date:} & {$date} \\\\ \\hline
\\textbf{Subject / Section:} & & \\textbf{Time Released from Class:} & \\\\ \\hline
\\end{tabularx}\\par\\vspace{1em}
\\noindent\\begin{tabularx}{\\textwidth}{X r}
\\textbf{Teacher / Instructor Signature:} \\underline{\\hspace{5.5cm}} & \\textbf{Date Signed:} \\underline{\\hspace{2.5cm}} \\\\
\\end{tabularx}\\par\\vspace{0.4em}
\\textit{\\footnotesize Instructions: Please sign and return this acknowledgment slip with the student or to the Office of the Guidance Center.}
\\end{mdframed}

\\vfill
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
        string $checkPersonal,
        string $checkCareer,
        string $checkRoutine,
        string $checkFollowUp,
        string $checkAcademic,
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
            $attDate = self::escape((string) ($attestation['attested_at'] ?? ''));
            $hash = self::escape(substr((string) ($attestation['snapshot_hash'] ?? ''), 0, 10) . '...');
            $attestationLine = "\\textbf{Attested:} {$signer} ({$hash})";
        } elseif ($isDraft) {
            $attestationLine = "\\textbf{Notice:} Draft preview (Unofficial)";
        } else {
            $attestationLine = "\\textbf{Attestation:} Official Record";
        }

        $copies = [
            1 => 'Copy 1 (Student Copy)',
            2 => 'Copy 2 (Guidance File Copy)',
            3 => 'Copy 3 (Teacher / Class Copy)',
            4 => 'Copy 4 (Department / Admin Copy)',
        ];

        $quads = [];
        foreach ($copies as $idx => $label) {
            $quads[$idx] = self::renderCallSlipQuadrant(
                $label,
                $num,
                $name,
                $id,
                $course,
                $date,
                $time,
                $counselor,
                $checkPersonal,
                $checkCareer,
                $checkRoutine,
                $checkFollowUp,
                $checkAcademic,
                $checkOthers,
                $othersSpecify,
                $verificationLine,
                $attestationLine
            );
        }

        return <<<LATEX
\\documentclass[10pt,a4paper]{article}
\\usepackage[margin=8mm,top=8mm,bottom=8mm,left=8mm,right=8mm]{geometry}
\\usepackage{amsmath,amssymb}
\\usepackage{xcolor}
\\usepackage{mdframed}
\\usepackage{array}
\\usepackage{tabularx}
\\pagestyle{empty}
\\setlength{\\parindent}{0pt}
{$watermark}

\\begin{document}

\\noindent
\\begin{minipage}[t][132mm][t]{93mm}
{$quads[1]}
\\end{minipage}%
\\hfill%
\\begin{minipage}[t][132mm][t]{6mm}
\\centering
\\vbox to 132mm{\\cleaders\\vbox to 4mm{\\vss\\rule{0.5pt}{2mm}\\vss}\\vfill}
\\end{minipage}%
\\hfill%
\\begin{minipage}[t][132mm][t]{93mm}
{$quads[2]}
\\end{minipage}

\\vspace{2mm}
\\noindent\\cleaders\\hbox to 4mm{\\hss\\rule{2mm}{0.5pt}\\hss}\\hfill%
\\quad\\raisebox{-0.2ex}{\\scriptsize - - - - - - \\textbf{CUT ALONG DASHED LINE} - - - - - -}\\quad%
\\cleaders\\hbox to 4mm{\\hss\\rule{2mm}{0.5pt}\\hss}\\hfill\\kern0pt
\\vspace{2mm}

\\noindent
\\begin{minipage}[t][132mm][t]{93mm}
{$quads[3]}
\\end{minipage}%
\\hfill%
\\begin{minipage}[t][132mm][t]{6mm}
\\centering
\\vbox to 132mm{\\cleaders\\vbox to 4mm{\\vss\\rule{0.5pt}{2mm}\\vss}\\vfill}
\\end{minipage}%
\\hfill%
\\begin{minipage}[t][132mm][t]{93mm}
{$quads[4]}
\\end{minipage}

\\end{document}
LATEX;
    }

    private static function renderCallSlipQuadrant(
        string $copyLabel,
        string $num,
        string $name,
        string $id,
        string $course,
        string $date,
        string $time,
        string $counselor,
        string $checkPersonal,
        string $checkCareer,
        string $checkRoutine,
        string $checkFollowUp,
        string $checkAcademic,
        string $checkOthers,
        string $othersSpecify,
        string $verificationLine,
        string $attestationLine
    ): string {
        return <<<LATEX
\\begin{mdframed}[linewidth=0.5pt,innerleftmargin=2mm,innerrightmargin=2mm,innertopmargin=2mm,innerbottommargin=2mm]
{\\centering
{\\bfseries\\scriptsize FOUNDATION UNIVERSITY}\\par
{\\bfseries\\tiny OFFICE OF THE GUIDANCE CENTER}\\par
{\\bfseries\\footnotesize GUIDANCE CALL SLIP}\\par
{\\tiny\\textbf{Canonical Code:} F-OGC-GSP-CSP-007-00 \\quad \\textbf{Rev:} 00 \\quad \\textbf{Eff:} 2025-09-01}\\par
}\\vspace{1mm}\\hrule\\vspace{0.8mm}
{\\raggedleft\\tiny\\textit{{$copyLabel}}}\\par
{\\tiny\\textit{Please excuse student to report to the Guidance Center:}}\\par\\vspace{0.5mm}
\\begin{tabularx}{\\linewidth}{|l|X|l|X|}
\\hline
\\textbf{\\tiny Call Slip No:} & {\\tiny {$num}} & \\textbf{\\tiny ID:} & {\\tiny {$id}} \\\\ \\hline
\\textbf{\\tiny Name:} & \\multicolumn{3}{l|}{\\tiny {$name}} \\\\ \\hline
\\textbf{\\tiny Course/Yr:} & {\\tiny {$course}} & \\textbf{\\tiny Call Date/Time:} & {\\tiny {$date} {$time}} \\\\ \\hline
\\textbf{\\tiny Counselor:} & \\multicolumn{3}{l|}{\\tiny {$counselor}} \\\\ \\hline
\\end{tabularx}\\par\\vspace{0.8mm}
{\\tiny\\textbf{Purpose:}}\\par
{\\tiny
{$checkPersonal} Personal \\enspace {$checkCareer} Career \\enspace {$checkRoutine} Routine \\\\
{$checkFollowUp} Follow-up \\enspace {$checkAcademic} Academic \\enspace {$checkOthers} Others{$othersSpecify}
}\\par\\vspace{0.8mm}
\\noindent\\cleaders\\hbox to 3mm{\\hss\\rule{1.5mm}{0.3pt}\\hss}\\hfill{\\tiny - - - Tear-Off Return Slip - - -}\\hfill\\cleaders\\hbox to 3mm{\\hss\\rule{1.5mm}{0.3pt}\\hss}\\hfill\\kern0pt\\par\\vspace{0.5mm}
{\\tiny\\textbf{Teacher Acknowledgment:} Subj: \\underline{\\hspace{1.5cm}} Rel: \\underline{\\hspace{1.2cm}}}\\par
{\\tiny\\textbf{Signature:} \\underline{\\hspace{2.5cm}} Date: \\underline{\\hspace{1.2cm}}}\\par\\vspace{0.8mm}
{\\tiny {$verificationLine} \\hfill {$attestationLine}}\\par\\vspace{0.5mm}
{\\tiny\\centering\\textit{Confidential Guidance Record --- RA 10173}\\par}
\\end{mdframed}
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

        $clientTypeRaw = strtolower((string) ($data['client_type'] ?? $data['type_of_client'] ?? ''));
        $isWalkIn = str_contains($clientTypeRaw, 'walk');
        $isFollowUp = str_contains($clientTypeRaw, 'follow');
        $isReferral = str_contains($clientTypeRaw, 'refer');

        $checkWalkIn = self::check($isWalkIn);
        $checkFollowUp = self::check($isFollowUp);
        $checkReferral = self::check($isReferral);

        // Demographics
        $demo = is_array($data['demographics'] ?? null) ? $data['demographics'] : [];
        $age = self::escape((string) ($demo['age'] ?? $data['age'] ?? '---'));
        $sex = self::escape((string) ($demo['sex'] ?? $demo['gender'] ?? $data['sex'] ?? $data['gender'] ?? '---'));
        $contact = self::escape((string) ($demo['contact_number'] ?? $demo['contact'] ?? $data['contact_number'] ?? $data['contact'] ?? '---'));
        $civil = self::escape((string) ($demo['civil_status'] ?? $data['civil_status'] ?? '---'));
        $living = self::escape((string) ($demo['living_arrangement'] ?? $data['living_arrangement'] ?? '---'));
        $emergency = self::escape((string) ($demo['emergency_contact'] ?? $data['emergency_contact'] ?? '---'));

        // Presenting Concerns
        $concernsRaw = (string) ($data['presenting_concerns'] ?? $data['issues_concerns'] ?? $data['concerns'] ?? '');
        $concerns = self::escape($concernsRaw !== '' ? $concernsRaw : 'None recorded.');

        // Mental Health Screening
        $screeningRaw = $data['mental_health_screening'] ?? $data['screening'] ?? null;
        $screeningText = '';
        if (is_array($screeningRaw)) {
            $parts = [];
            foreach ($screeningRaw as $k => $v) {
                if (is_scalar($v)) {
                    $parts[] = '\textbf{' . self::escape((string) $k) . ':} ' . self::escape((string) $v);
                }
            }
            $screeningText = !empty($parts) ? implode(" \\enspace \\textbar \\enspace \n", $parts) : '\textit{No acute risk indicators recorded.}';
        } elseif (is_string($screeningRaw) && trim($screeningRaw) !== '') {
            $screeningText = self::escape($screeningRaw);
        } else {
            $screeningText = '\textit{No acute mental health risk indicators or suicide risk flagged during intake screening.}';
        }

        // Family & Social Support
        $familyRaw = (string) ($data['family_social_support'] ?? $data['family_support'] ?? $data['social_support'] ?? '');
        $familySupport = self::escape($familyRaw !== '' ? $familyRaw : 'Adequate family and social support reported; no severe domestic stressors identified.');

        // Counselor Assessment & Action/Intervention Plan
        $assessmentRaw = (string) ($data['counselor_assessment'] ?? $data['assessment'] ?? '');
        $assessment = self::escape($assessmentRaw !== '' ? $assessmentRaw : 'No formal clinical assessment notes recorded.');

        $planRaw = (string) ($data['intervention_plan'] ?? $data['action_plan'] ?? $data['plan'] ?? '');
        $plan = self::escape($planRaw !== '' ? $planRaw : 'Routine monitoring and open-door follow-up as needed.');

        $watermark = $isDraft
            ? "\\usepackage{draftwatermark}\n\\SetWatermarkText{DRAFT - PREVIEW ONLY}\n\\SetWatermarkScale{0.6}\n\\SetWatermarkColor[gray]{0.85}\n"
            : '';

        $verificationLine = $verificationCode !== null && $verificationCode !== ''
            ? '\\textbf{Verification Code:} ' . self::escape($verificationCode) . ' \\quad \\textit{(Verify status at authorized Synapse portal)}'
            : '\\textbf{Status:} Provisional Draft';

        $attestationBanner = self::renderAttestationBanner($authorAttestation, $cosignerAttestation, $verificationCode, $isDraft);
        $attestationStamp = self::renderAttestationStamp($authorAttestation, $cosignerAttestation, $verificationCode, $isDraft);

        return <<<LATEX
\\documentclass[10pt,a4paper]{article}
\\usepackage[margin=18mm,top=20mm,bottom=22mm]{geometry}
\\usepackage{amsmath,amssymb}
\\usepackage{xcolor}
\\usepackage{mdframed}
\\usepackage{array}
\\usepackage{tabularx}
\\usepackage{fancyhdr}
\\pagestyle{fancy}
\\fancyhf{}
\\renewcommand{\\headrulewidth}{0.4pt}
\\renewcommand{\\footrulewidth}{0.4pt}
\\fancyhead[L]{\\small\\textbf{Foundation University} --- Office of the Guidance Center}
\\fancyhead[R]{\\small\\textbf{Form Code:} {$code}}
\\fancyfoot[L]{\\footnotesize {$verificationLine}}
\\fancyfoot[R]{\\footnotesize Page \\thepage}
{$watermark}

\\begin{document}

\\begin{center}
{\\normalsize\\textbf{FOUNDATION UNIVERSITY}}\\par\\vspace{0.2em}
{\\Large\\textbf{OFFICE OF THE GUIDANCE CENTER}}\\par\\vspace{0.2em}
{\\large\\textbf{{$title}}}\\par\\vspace{0.3em}
{\\footnotesize\\textbf{Canonical Code:} {$code} \\quad \\textbf{Rev:} {$rev} \\quad \\textbf{Effectivity Date:} {$effectivity} \\quad \\textbf{Instance:} {$instanceNum}}
\\end{center}
\\vspace{0.5em}
\\hrule
\\vspace{0.8em}

{$attestationBanner}

\\noindent\\textbf{I. Student Demographics \\& Academic Profile}\\par\\vspace{0.4em}
\\begin{tabularx}{\\textwidth}{|l|X|l|X|}
\\hline
\\textbf{Student Name:} & {$name} & \\textbf{Student ID:} & {$id} \\\\ \\hline
\\textbf{Course \\& Year:} & {$course} & \\textbf{Interview Date:} & {$date} \\\\ \\hline
\\textbf{Client Type:} & \\multicolumn{3}{l|}{{$checkWalkIn} Walk-in \\enspace\\quad {$checkFollowUp} Follow-up \\enspace\\quad {$checkReferral} Referral} \\\\ \\hline
\\textbf{Age / Sex:} & {$age} / {$sex} & \\textbf{Contact No:} & {$contact} \\\\ \\hline
\\textbf{Civil Status:} & {$civil} & \\textbf{Living Setup:} & {$living} \\\\ \\hline
\\textbf{Emergency Contact:} & \\multicolumn{3}{l|}{{$emergency}} \\\\ \\hline
\\end{tabularx}\\par\\vspace{0.8em}

\\noindent\\textbf{II. Presenting Concerns \\& Mental Health Screening}\\par\\vspace{0.4em}
\\noindent\\textbf{A. Presenting Concerns:}\\par
\\begin{mdframed}[backgroundcolor=gray!2,roundcorner=3pt]
{$concerns}
\\end{mdframed}\\par\\vspace{0.5em}

\\noindent\\textbf{B. Mental Health Screening Indicators:}\\par
\\begin{mdframed}[backgroundcolor=gray!2,roundcorner=3pt]
{$screeningText}
\\end{mdframed}\\par\\vspace{0.8em}

\\noindent\\textbf{III. Family \\& Social Support}\\par\\vspace{0.4em}
\\begin{mdframed}[backgroundcolor=gray!2,roundcorner=3pt]
{$familySupport}
\\end{mdframed}\\par\\vspace{0.8em}

\\noindent\\textbf{IV. Counselor Assessment \\& Clinical Impressions}\\par\\vspace{0.4em}
\\begin{mdframed}[backgroundcolor=gray!2,roundcorner=3pt]
{$assessment}
\\end{mdframed}\\par\\vspace{0.5em}

\\noindent\\textbf{V. Action \\& Intervention Plan}\\par\\vspace{0.4em}
\\begin{mdframed}[backgroundcolor=gray!2,roundcorner=3pt]
{$plan}
\\end{mdframed}\\par\\vspace{1em}

\\noindent\\textbf{VI. Digital Attestation \\& Professional Standing}\\par\\vspace{0.4em}
{$attestationStamp}

\\vfill
\\begin{center}
\\footnotesize\\textit{CONFIDENTIALITY NOTICE: This document contains strictly privileged psychological/guidance information protected under RA 10173 and professional ethics standards. Unauthorized disclosure or reproduction is strictly prohibited.}
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

        $sessionNum = self::escape((string) ($data['session_number'] ?? $data['session_num'] ?? '1'));
        $sessionDate = self::escape((string) ($data['session_date'] ?? $data['date'] ?? ''));

        $modalityRaw = strtolower((string) ($data['modality'] ?? 'in_person'));
        $isTelehealth = str_contains($modalityRaw, 'tele');
        $isPhone = str_contains($modalityRaw, 'phone');
        $isInPerson = !$isTelehealth && !$isPhone;

        $checkInPerson = self::check($isInPerson);
        $checkTelehealth = self::check($isTelehealth);
        $checkPhone = self::check($isPhone);

        $timeStart = self::escape((string) ($data['time_started'] ?? $data['time_start'] ?? ''));
        $timeEnd = self::escape((string) ($data['time_ended'] ?? $data['time_end'] ?? ''));
        $timeStr = ($timeStart !== '' || $timeEnd !== '') ? "{$timeStart} -- {$timeEnd}" : '---';

        $name = self::escape((string) ($data['student_name'] ?? $data['name'] ?? ''));
        $id = self::escape((string) ($data['student_id'] ?? $data['id'] ?? ''));
        $course = self::escape((string) ($data['course_year'] ?? $data['course'] ?? ''));
        $dept = self::escape((string) ($data['department'] ?? ''));
        $counselor = self::escape((string) ($data['counselor_name'] ?? $data['counselor_associate_name'] ?? $data['practitioner_name'] ?? ''));

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

        $clientState = self::escape((string) ($data['client_state'] ?? $data['client_presentation'] ?? 'None recorded.'));
        $interventions = self::escape((string) ($data['interventions_used'] ?? $data['interventions'] ?? 'None recorded.'));
        $clientResponse = self::escape((string) ($data['client_response'] ?? $data['response'] ?? 'None recorded.'));
        $actionItems = self::escape((string) ($data['action_items'] ?? $data['homework'] ?? 'None recorded.'));
        $nextDate = self::escape((string) ($data['next_session_date'] ?? $data['next_schedule'] ?? 'As scheduled / on-call'));

        $watermark = $isDraft
            ? "\\usepackage{draftwatermark}\n\\SetWatermarkText{DRAFT - PREVIEW ONLY}\n\\SetWatermarkScale{0.6}\n\\SetWatermarkColor[gray]{0.85}\n"
            : '';

        $verificationLine = $verificationCode !== null && $verificationCode !== ''
            ? '\\textbf{Verification Code:} ' . self::escape($verificationCode) . ' \\quad \\textit{(Verify status at authorized Synapse portal)}'
            : '\\textbf{Status:} Provisional Draft';

        $attestationBanner = self::renderAttestationBanner($authorAttestation, $cosignerAttestation, $verificationCode, $isDraft);
        $attestationStamp = self::renderAttestationStamp($authorAttestation, $cosignerAttestation, $verificationCode, $isDraft);

        return <<<LATEX
\\documentclass[10pt,a4paper]{article}
\\usepackage[margin=18mm,top=20mm,bottom=22mm]{geometry}
\\usepackage{amsmath,amssymb}
\\usepackage{xcolor}
\\usepackage{mdframed}
\\usepackage{array}
\\usepackage{tabularx}
\\usepackage{fancyhdr}
\\pagestyle{fancy}
\\fancyhf{}
\\renewcommand{\\headrulewidth}{0.4pt}
\\renewcommand{\\footrulewidth}{0.4pt}
\\fancyhead[L]{\\small\\textbf{Foundation University} --- Office of the Guidance Center}
\\fancyhead[R]{\\small\\textbf{Form Code:} {$code}}
\\fancyfoot[L]{\\footnotesize {$verificationLine}}
\\fancyfoot[R]{\\footnotesize Page \\thepage}
{$watermark}

\\begin{document}

\\begin{center}
{\\normalsize\\textbf{FOUNDATION UNIVERSITY}}\\par\\vspace{0.2em}
{\\Large\\textbf{OFFICE OF THE GUIDANCE CENTER}}\\par\\vspace{0.2em}
{\\large\\textbf{{$title}}}\\par\\vspace{0.3em}
{\\footnotesize\\textbf{Canonical Code:} {$code} \\quad \\textbf{Rev:} {$rev} \\quad \\textbf{Effectivity Date:} {$effectivity} \\quad \\textbf{Instance:} {$instanceNum}}
\\end{center}
\\vspace{0.5em}
\\hrule
\\vspace{0.8em}

{$attestationBanner}

\\noindent\\textbf{I. Session \\& Student Information}\\par\\vspace{0.4em}
\\begin{tabularx}{\\textwidth}{|l|X|l|X|}
\\hline
\\textbf{Session Number:} & {$sessionNum} & \\textbf{Session Date:} & {$sessionDate} \\\\ \\hline
\\textbf{Student Name:} & {$name} & \\textbf{Student ID:} & {$id} \\\\ \\hline
\\textbf{Course \\& Year:} & {$course} & \\textbf{Department:} & {$dept} \\\\ \\hline
\\textbf{Counselor / Associate:} & {$counselor} & \\textbf{Time Started/Ended:} & {$timeStr} \\\\ \\hline
\\textbf{Session Modality:} & \\multicolumn{3}{l|}{{$checkInPerson} In-Person \\enspace\\quad {$checkTelehealth} Telehealth \\enspace\\quad {$checkPhone} Phone} \\\\ \\hline
\\textbf{Session Type:} & \\multicolumn{3}{l|}{{$checkTypeIntake} Intake \\enspace {$checkTypeFollowUp} Follow-up \\enspace {$checkTypeCrisis} Crisis \\enspace {$checkTypeIndiv} Individual \\enspace {$checkTypeGroup} Group} \\\\ \\hline
\\end{tabularx}\\par\\vspace{0.8em}

\\noindent\\textbf{II. Client State \\& Presentation}\\par\\vspace{0.4em}
\\begin{mdframed}[backgroundcolor=gray!2,roundcorner=3pt]
{$clientState}
\\end{mdframed}\\par\\vspace{0.8em}

\\noindent\\textbf{III. Psychosocial Interventions Applied}\\par\\vspace{0.4em}
\\begin{mdframed}[backgroundcolor=gray!2,roundcorner=3pt]
{$interventions}
\\end{mdframed}\\par\\vspace{0.8em}

\\noindent\\textbf{IV. Client Response \\& Progress}\\par\\vspace{0.4em}
\\begin{mdframed}[backgroundcolor=gray!2,roundcorner=3pt]
{$clientResponse}
\\end{mdframed}\\par\\vspace{0.8em}

\\noindent\\textbf{V. Action Items \\& Next Session Schedule}\\par\\vspace{0.4em}
\\begin{mdframed}[backgroundcolor=gray!2,roundcorner=3pt]
{$actionItems}
\\end{mdframed}\\par\\vspace{0.4em}
\\noindent\\textbf{Next Session Schedule:} {$nextDate}\\par\\vspace{1em}

\\noindent\\textbf{VI. Digital Attestation \\& Verification}\\par\\vspace{0.4em}
{$attestationStamp}

\\vfill
\\begin{center}
\\footnotesize\\textit{CONFIDENTIALITY NOTICE: This document contains strictly privileged psychological/guidance information protected under RA 10173 and professional ethics standards. Unauthorized disclosure or reproduction is strictly prohibited.}
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

        $sessionNum = self::escape((string) ($data['session_number'] ?? $data['session_num'] ?? '1'));
        $sessionDate = self::escape((string) ($data['session_date'] ?? $data['date'] ?? ''));
        $timeStart = self::escape((string) ($data['time_start'] ?? $data['time_started'] ?? ''));
        $timeEnd = self::escape((string) ($data['time_end'] ?? $data['time_ended'] ?? ''));
        $timeStr = ($timeStart !== '' || $timeEnd !== '') ? "{$timeStart} -- {$timeEnd}" : '---';

        $sessionTypeRaw = strtolower((string) ($data['session_type'] ?? 'regular'));
        $isTypeIntake = str_contains($sessionTypeRaw, 'intake');
        $isTypeRegular = str_contains($sessionTypeRaw, 'regular');
        $isTypeCrisis = str_contains($sessionTypeRaw, 'crisis');
        $isTypeFollowUp = str_contains($sessionTypeRaw, 'follow');
        $isTypeClosure = str_contains($sessionTypeRaw, 'closure') || str_contains($sessionTypeRaw, 'close');

        $checkTypeIntake = self::check($isTypeIntake);
        $checkTypeRegular = self::check($isTypeRegular);
        $checkTypeCrisis = self::check($isTypeCrisis);
        $checkTypeFollowUp = self::check($isTypeFollowUp);
        $checkTypeClosure = self::check($isTypeClosure);

        $name = self::escape((string) ($data['student_name'] ?? $data['name'] ?? ''));
        $id = self::escape((string) ($data['student_id'] ?? $data['id'] ?? ''));
        $course = self::escape((string) ($data['course_year'] ?? $data['course'] ?? ''));
        $dept = self::escape((string) ($data['department'] ?? ''));
        $counselor = self::escape((string) ($data['counselor_name'] ?? $data['practitioner_name'] ?? ''));

        $concerns = self::escape((string) ($data['concerns_discussed'] ?? $data['concerns'] ?? 'None recorded.'));
        $goals = self::escape((string) ($data['counseling_goals'] ?? $data['goals'] ?? 'None recorded.'));
        $interventions = self::escape((string) ($data['interventions_and_responses'] ?? $data['interventions'] ?? 'None recorded.'));
        $homework = self::escape((string) ($data['homework_and_commitments'] ?? $data['homework'] ?? $data['action_items'] ?? 'None recorded.'));
        $nextSchedule = self::escape((string) ($data['next_schedule'] ?? $data['next_session_date'] ?? 'To be coordinated with student'));

        $watermark = $isDraft
            ? "\\usepackage{draftwatermark}\n\\SetWatermarkText{DRAFT - PREVIEW ONLY}\n\\SetWatermarkScale{0.6}\n\\SetWatermarkColor[gray]{0.85}\n"
            : '';

        $verificationLine = $verificationCode !== null && $verificationCode !== ''
            ? '\\textbf{Verification Code:} ' . self::escape($verificationCode) . ' \\quad \\textit{(Verify status at authorized Synapse portal)}'
            : '\\textbf{Status:} Provisional Draft';

        $attestationBanner = self::renderAttestationBanner($authorAttestation, $cosignerAttestation, $verificationCode, $isDraft);
        $attestationStamp = self::renderAttestationStamp($authorAttestation, $cosignerAttestation, $verificationCode, $isDraft);

        return <<<LATEX
\\documentclass[10pt,a4paper]{article}
\\usepackage[margin=18mm,top=20mm,bottom=22mm]{geometry}
\\usepackage{amsmath,amssymb}
\\usepackage{xcolor}
\\usepackage{mdframed}
\\usepackage{array}
\\usepackage{tabularx}
\\usepackage{fancyhdr}
\\pagestyle{fancy}
\\fancyhf{}
\\renewcommand{\\headrulewidth}{0.4pt}
\\renewcommand{\\footrulewidth}{0.4pt}
\\fancyhead[L]{\\small\\textbf{Foundation University} --- Office of the Guidance Center}
\\fancyhead[R]{\\small\\textbf{Form Code:} {$code}}
\\fancyfoot[L]{\\footnotesize {$verificationLine}}
\\fancyfoot[R]{\\footnotesize Page \\thepage}
{$watermark}

\\begin{document}

\\begin{center}
{\\normalsize\\textbf{FOUNDATION UNIVERSITY}}\\par\\vspace{0.2em}
{\\Large\\textbf{OFFICE OF THE GUIDANCE CENTER}}\\par\\vspace{0.2em}
{\\large\\textbf{{$title}}}\\par\\vspace{0.1em}
{\\footnotesize\\textit{(Counseling Contact Form)}}\\\\
{\\footnotesize\\textbf{Canonical Code:} {$code} \\quad \\textbf{Rev:} {$rev} \\quad \\textbf{Effectivity Date:} {$effectivity} \\quad \\textbf{Instance:} {$instanceNum}}
\\end{center}
\\vspace{0.5em}
\\hrule
\\vspace{0.8em}

{$attestationBanner}

\\noindent\\textbf{I. Session \\& Student Information}\\par\\vspace{0.4em}
\\begin{tabularx}{\\textwidth}{|l|X|l|X|}
\\hline
\\textbf{Session Number:} & {$sessionNum} & \\textbf{Session Date:} & {$sessionDate} \\\\ \\hline
\\textbf{Student Name:} & {$name} & \\textbf{Student ID:} & {$id} \\\\ \\hline
\\textbf{Course \\& Year:} & {$course} & \\textbf{Department:} & {$dept} \\\\ \\hline
\\textbf{Counselor:} & {$counselor} & \\textbf{Time Start/End:} & {$timeStr} \\\\ \\hline
\\textbf{Session Type:} & \\multicolumn{3}{l|}{{$checkTypeIntake} Intake \\enspace {$checkTypeRegular} Regular \\enspace {$checkTypeCrisis} Crisis \\enspace {$checkTypeFollowUp} Follow-up \\enspace {$checkTypeClosure} Closure} \\\\ \\hline
\\end{tabularx}\\par\\vspace{0.8em}

\\noindent\\textbf{II. Concerns Discussed}\\par\\vspace{0.4em}
\\begin{mdframed}[backgroundcolor=gray!2,roundcorner=3pt]
{$concerns}
\\end{mdframed}\\par\\vspace{0.8em}

\\noindent\\textbf{III. Counseling Goals}\\par\\vspace{0.4em}
\\begin{mdframed}[backgroundcolor=gray!2,roundcorner=3pt]
{$goals}
\\end{mdframed}\\par\\vspace{0.8em}

\\noindent\\textbf{IV. Interventions Applied \\& Client Responses}\\par\\vspace{0.4em}
\\begin{mdframed}[backgroundcolor=gray!2,roundcorner=3pt]
{$interventions}
\\end{mdframed}\\par\\vspace{0.8em}

\\noindent\\textbf{V. Homework, Commitments \\& Next Schedule}\\par\\vspace{0.4em}
\\begin{mdframed}[backgroundcolor=gray!2,roundcorner=3pt]
{$homework}
\\end{mdframed}\\par\\vspace{0.4em}
\\noindent\\textbf{Next Scheduled Session:} {$nextSchedule}\\par\\vspace{1em}

\\noindent\\textbf{VI. Digital Attestation \\& Supervisor Co-Signature}\\par\\vspace{0.4em}
{$attestationStamp}

\\vfill
\\begin{center}
\\footnotesize\\textit{CONFIDENTIALITY NOTICE: This document contains strictly privileged psychological/guidance information protected under RA 10173 and professional ethics standards. Unauthorized disclosure or reproduction is strictly prohibited.}
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
            return "\\begin{mdframed}[backgroundcolor=orange!10,roundcorner=4pt]\n"
                . "\\small \\textbf{ATTENTION:} Unfinalized draft record. Not official without authenticated attestation.\n"
                . "\\end{mdframed}\\par\\vspace{0.8em}\n";
        }

        if ($author === null && $cosigner === null) {
            return "\\begin{mdframed}[backgroundcolor=gray!5,roundcorner=4pt]\n"
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

        return "\\begin{mdframed}[backgroundcolor=gray!10,roundcorner=4pt]\n\\small\n"
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
\\noindent\\begin{mdframed}[backgroundcolor=orange!5,roundcorner=3pt]
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
            'rating_welcoming' => 'The guidance personnel were approachable and welcoming.',
            'rating_empathy' => 'The guidance personnel understood and empathized with me.',
            'rating_clarity' => 'The goal of the psychosocial support session was clear and achieved.',
            'rating_helpfulness' => 'The psychosocial support session was helpful.',
            'rating_environment' => 'Confidentiality and a safe, supportive environment were maintained.',
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
            true
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
            'rating_rapport' => 'The counselor was approachable, welcoming, and established good rapport.',
            'rating_understanding' => 'The counselor understood and empathized with me.',
            'rating_goal_progress' => 'The goal of the counseling session was achieved and progress was made.',
            'rating_confidentiality_comfort' => 'Confidentiality was kept and I felt comfortable during the session.',
            'rating_overall_service' => 'Overall, the counseling session was helpful and effective.',
        ];

        return self::renderServiceEvaluationDocument(
            $snapshot,
            $options,
            'F-OGC-GCS-CEF-009-00',
            'Counseling Evaluation Form',
            'counseling_evaluation',
            $dimensions,
            'recommend_service',
            'Would you recommend this counseling service / wish to visit your counselor in the future?',
            'Service Recommendation Rate',
            'qualitative_feedback',
            false
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
        bool $includeVisitTypeSection
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
        $visitTypeLabels = [
            'walk_in' => 'Voluntarily came / Walk-in',
            'called' => 'Called by Guidance Personnel / Counselor',
            'referred' => 'Referred',
            'follow_up' => 'Follow-up',
        ];

        $visitTypeBlock = '';
        if ($includeVisitTypeSection || isset($data['visit_type']) || isset($data['visit_type_distribution']) || isset($data['visit_types'])) {
            if ($isAggregateMode) {
                $dist = is_array($data['visit_type_distribution'] ?? null)
                    ? $data['visit_type_distribution']
                    : (is_array($data['visit_types'] ?? null) ? $data['visit_types'] : []);

                $vtRows = '';
                foreach ($visitTypeLabels as $vtKey => $vtLabel) {
                    $escKey = self::escape($vtKey);
                    $escLabel = self::escape($vtLabel);
                    if (!$cohortMet) {
                        $vtVal = 'Suppressed (Cohort $<$ ' . $minRequired . ')';
                    } else {
                        $rawCount = $dist[$vtKey] ?? null;
                        $vtVal = $rawCount !== null ? self::escape((string) $rawCount) : 'Recorded in Cohort';
                    }
                    $vtRows .= "% [visit_type: {$vtKey}]\n"
                        . "\\textbf{{$escLabel}} (\\texttt{{$escKey}}) & {$vtVal} \\\\\n\\hline\n";
                }

                $visitTypeBlock = <<<LATEX
\\vspace{0.5em}
\\noindent{\\bfseries Visit Type Distribution (walk\\_in, called, referred, follow\\_up)}
\\vspace{0.25em}

\\noindent\\begin{tabularx}{\\textwidth}{|X|c|}
\\hline
\\textbf{Visit Type Category} & \\textbf{Cohort Count / Share} \\\\
\\hline
{$vtRows}\\end{tabularx}
LATEX;
            } else {
                $selectedVt = strtolower(trim((string) ($data['visit_type'] ?? '')));
                $checkWalkIn = self::check($selectedVt === 'walk_in');
                $checkCalled = self::check($selectedVt === 'called');
                $checkReferred = self::check($selectedVt === 'referred');
                $checkFollowUp = self::check($selectedVt === 'follow_up');

                $visitTypeBlock = <<<LATEX
% [visit_type_options: walk_in, called, referred, follow_up]
\\vspace{0.5em}
\\noindent\\textbf{Please check visit type:} \\quad
{$checkWalkIn} Voluntarily came / Walk-in (\\texttt{walk\\_in}) \\enspace
{$checkCalled} Called by Guidance Personnel (\\texttt{called}) \\\\[0.25em]
\\noindent\\phantom{\\textbf{Please check visit type:} \\quad}
{$checkReferred} Referred (\\texttt{referred}) \\enspace
{$checkFollowUp} Follow-up (\\texttt{follow\\_up})
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
            $escKey = self::escape($dimKey);
            $escStatement = self::escape($dimStatement);

            if ($isAggregateMode) {
                if (!$cohortMet) {
                    $scoreCell = '\\textbf{SUPPRESSED} (Cohort $<$ ' . $minRequired . ')';
                } else {
                    $rawMean = $averages[$dimKey] ?? $data[$dimKey] ?? $rawRatings[$dimKey] ?? null;
                    if (is_numeric($rawMean)) {
                        $val = (float) $rawMean;
                        $computedSum += $val;
                        $computedCount++;
                        $fmt2 = number_format($val, 2);
                        $fmtTrim = rtrim(rtrim($fmt2, '0'), '.');
                        $scoreCell = ($fmt2 !== $fmtTrim)
                            ? "\\textbf{{$fmt2} / 5.00} ({$fmtTrim})"
                            : "\\textbf{{$fmt2} / 5.00}";
                    } else {
                        $scoreCell = '---';
                    }
                }
                $dimensionRows .= "% [dimension: {$dimKey}]\n"
                    . "{$idx} & {$escStatement} (\\texttt{{$escKey}}) & {$scoreCell} \\\\\n\\hline\n";
            } else {
                $selectedScore = $rawRatings[$dimKey] ?? $data[$dimKey] ?? null;
                $selectedInt = is_numeric($selectedScore) ? (int) $selectedScore : null;
                $scaleBoxes = [];
                for ($s = 1; $s <= 5; $s++) {
                    $box = self::check($selectedInt === $s);
                    $scaleBoxes[] = "{$box} {$s}";
                }
                $scaleStr = implode(' \\enspace ', $scaleBoxes);
                $dimensionRows .= "% [dimension: {$dimKey}]\n"
                    . "{$idx} & {$escStatement} (\\texttt{{$escKey}}) & {$scaleStr} \\\\\n\\hline\n";
            }
            $idx++;
        }

        // Overall Mean Row in Aggregate Mode
        if ($isAggregateMode) {
            if (!$cohortMet) {
                $overallCell = '\\textbf{SUPPRESSED} (Cohort $<$ ' . $minRequired . ')';
            } else {
                $rawOverall = $averages['overall_mean'] ?? $data['overall_mean'] ?? null;
                if (!is_numeric($rawOverall) && $computedCount > 0) {
                    $rawOverall = $computedSum / $computedCount;
                }
                if (is_numeric($rawOverall)) {
                    $ov = (float) $rawOverall;
                    $ovFmt2 = number_format($ov, 2);
                    $ovTrim = rtrim(rtrim($ovFmt2, '0'), '.');
                    $overallCell = ($ovFmt2 !== $ovTrim)
                        ? "\\textbf{{$ovFmt2} / 5.00} ({$ovTrim})"
                        : "\\textbf{{$ovFmt2} / 5.00}";
                } else {
                    $overallCell = '---';
                }
            }
            $dimensionRows .= "% [metric: overall_mean]\n"
                . "\\multicolumn{2}{|r|}{\\textbf{Overall Mean Rating (\\texttt{overall\\_mean}):}} & {$overallCell} \\\\\n\\hline\n";
        }

        $tableColumnHeader = $isAggregateMode ? 'Mean Rating (1.00--5.00)' : 'Rating Scale (1--5)';

        // Boolean Response (future_visit_willingness / recommend_service)
        $escBoolKey = self::escape($booleanFieldKey);
        $escBoolQuestion = self::escape($booleanQuestionText);
        $escBoolAggLabel = self::escape($booleanAggregateLabel);

        if ($isAggregateMode) {
            if (!$cohortMet) {
                $booleanDisplay = '\\textbf{SUPPRESSED} --- Minimum cohort size of ' . $minRequired . ' not met (\\texttt{cohort\\_met = false}).';
            } else {
                $rawRate = $data['boolean_rate']
                    ?? $data[$booleanFieldKey . '_rate']
                    ?? $data[$booleanFieldKey]
                    ?? null;
                if (is_numeric($rawRate)) {
                    $numRate = (float) $rawRate;
                    if ($numRate >= 0.0 && $numRate <= 1.0 && !($numRate == 1.0 && isset($data['boolean_rate']) && $data['boolean_rate'] === 100)) {
                        $pct = $numRate * 100.0;
                        $pctFmt1 = number_format($pct, 1);
                        $pctTrim = rtrim(rtrim($pctFmt1, '0'), '.');
                        $decFmt2 = number_format($numRate, 2);
                        $decTrim = rtrim(rtrim($decFmt2, '0'), '.');
                        $booleanDisplay = "\\textbf{{$pctFmt1}\\%} ({$pctTrim}\\% / ratio: {$decFmt2} / {$decTrim}) Affirmative";
                    } else {
                        $pctFmt1 = number_format($numRate, 1);
                        $pctTrim = rtrim(rtrim($pctFmt1, '0'), '.');
                        $booleanDisplay = "\\textbf{{$pctFmt1}\\%} ({$pctTrim}\\%) Affirmative";
                    }
                } else {
                    $booleanDisplay = '---';
                }
            }
            $booleanSection = <<<LATEX
% [boolean_field: {$booleanFieldKey}]
\\vspace{0.6em}
\\noindent\\textbf{B. {$escBoolQuestion}} (\\texttt{{$escBoolKey}})\\\\[0.25em]
\\noindent\\textbf{{$escBoolAggLabel} (\\texttt{boolean\\_rate}):} {$booleanDisplay}
LATEX;
        } else {
            $rawBool = $data[$booleanFieldKey] ?? $data['boolean_response'] ?? null;
            $isYes = ($rawBool === true || $rawBool === 1 || $rawBool === '1' || strtolower((string) $rawBool) === 'yes');
            $isNo = ($rawBool === false || $rawBool === 0 || $rawBool === '0' || strtolower((string) $rawBool) === 'no');
            $checkYes = self::check($isYes);
            $checkNo = self::check($isNo);

            $booleanSection = <<<LATEX
% [boolean_field: {$booleanFieldKey}]
\\vspace{0.6em}
\\noindent\\textbf{B. {$escBoolQuestion}} (\\texttt{{$escBoolKey}})\\\\[0.25em]
\\noindent {$checkYes} \\textbf{Yes} \\qquad {$checkNo} \\textbf{No} \\qquad \\textbf{Reason(s) why:} \\underline{\\hspace{7.5cm}}
LATEX;
        }

        // Qualitative Comments / Feedback Section
        $escCommentKey = self::escape($commentFieldKey);
        if ($isAggregateMode) {
            if (!$cohortMet) {
                $commentsContent = '\\textbf{COHORT SUPPRESSION ACTIVE:} Qualitative comments and feedback are strictly suppressed because the response count ($N = '
                    . $totalResponses . '$) is below the minimum anonymity threshold ($M = ' . $minRequired . '$). No individual comments are disclosed.';
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
% [cohort_met: true]
\\noindent\\fcolorbox{boxborder}{boxbg}{%
\\parbox{\\dimexpr\\linewidth-2\\fboxsep-2\\fboxrule\\relax}{%
\\small
\\textbf{AGGREGATE SUMMARY REPORT MODE (Threshold Met --- \\texttt{cohort\\_met = true})}\\\\[0.2em]
\\textbf{Provider / Personnel:} {$providerName} \\hfill \\textbf{Total Responses:} {$totalResponses} (Minimum Required: {$minRequired})\\\\
\\textbf{Academic Year / Term:} {$academicYear} / {$academicTerm} \\hfill \\textbf{Report Date:} {$reportDate}
}%
}
\\vspace{0.5em}
LATEX;
            } else {
                $modeBanner = <<<LATEX
% [cohort_met: false]
\\noindent\\fcolorbox{boxborder}{warnbg}{%
\\parbox{\\dimexpr\\linewidth-2\\fboxsep-2\\fboxrule\\relax}{%
\\small
\\textbf{COHORT THRESHOLD SUPPRESSION NOTICE (\\texttt{cohort\\_met = false})}\\\\[0.2em]
Total evaluation responses (\$N = {$totalResponses}\$) have not reached the minimum anonymity threshold of \$M = {$minRequired}\$ (\\texttt{MIN\\_COHORT\\_SIZE = 5}). To prevent student re-identification and preserve strict pseudonymity, all dimension averages, willingness/recommendation rates, and qualitative comments are suppressed.\\\\
\\textbf{Provider / Personnel:} {$providerName} \\hfill \\textbf{Report Date:} {$reportDate}
}%
}
\\vspace{0.5em}
LATEX;
            }
        } else {
            $escEvalTypeKey = self::escape($evaluationTypeKey);
            $modeBanner = <<<LATEX
% [mode: form_template_preview] [evaluation_type: {$evaluationTypeKey}]
\\noindent\\fcolorbox{boxborder}{boxbg}{%
\\parbox{\\dimexpr\\linewidth-2\\fboxsep-2\\fboxrule\\relax}{%
\\small
\\textbf{Personnel / Counselor Evaluated:} {$providerName} \\hfill \\textbf{Date:} {$reportDate}\\\\
\\textbf{Academic Year / Term:} {$academicYear} / {$academicTerm} \\hfill \\textbf{Evaluation Type:} \\texttt{{$escEvalTypeKey}}
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

        return <<<LATEX
\\documentclass[10pt,a4paper]{article}
\\usepackage[margin=16mm,top=18mm,bottom=18mm]{geometry}
\\usepackage{amsmath,amssymb}
\\usepackage{xcolor}
\\usepackage{tikz}
\\usepackage{tabularx}
\\usepackage{array}
\\usepackage{graphicx}

\\definecolor{boxbg}{RGB}{248, 249, 251}
\\definecolor{warnbg}{RGB}{254, 246, 235}
\\definecolor{boxborder}{RGB}{200, 208, 218}

\\pagestyle{plain}

\\begin{document}
{$watermark}
% Canonical Metadata: {$defaultCode} | {$defaultTitle} | {$evaluationTypeKey}
\\begin{center}
{\\normalsize\\textbf{FOUNDATION UNIVERSITY}}\\par\\vspace{0.15em}
{\\Large\\textbf{OFFICE OF THE GUIDANCE CENTER}}\\par\\vspace{0.2em}
{\\large\\textbf{{$title}}}\\par\\vspace{0.25em}
{\\footnotesize\\textbf{Canonical Code:} {$code} \\quad \\textbf{Rev:} {$rev} \\quad \\textbf{Effectivity Date:} {$effectivity}}
\\end{center}
\\vspace{0.3em}
\\hrule
\\vspace{0.6em}

{$draftNotice}

\\noindent\\fcolorbox{boxborder}{boxbg}{%
\\parbox{\\dimexpr\\linewidth-2\\fboxsep-2\\fboxrule\\relax}{%
\\footnotesize
\\textbf{PRIVACY AND PSEUDONYMITY NOTICE (RA 10173):} In accordance with the Data Privacy Act of 2012 (RA 10173), your evaluation is collected solely for guidance service quality improvement. This evaluation is strictly \\textbf{pseudonymous and cryptographically unlinked} from your student identity (no \\texttt{person\\_id}, \\texttt{student\\_id}, \\texttt{session\\_id}, \\texttt{episode\\_id}, or \\texttt{token\\_id} is stored in evaluation records). Individual responses are never disclosed to providers; aggregate summaries and moderated qualitative comments are released only when the minimum cohort threshold (\$n \\ge {$minRequired}\$) is met.
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
% [comment_field: {$commentFieldKey}]
\\noindent\\textbf{C. Comments and Suggestions / Qualitative Feedback (\\texttt{{$escCommentKey}})}\\\\[0.3em]
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
        $refDept = self::escape((string) ($d['referring_department'] ?? ''));
        $date = self::escape((string) ($d['referral_date'] ?? ''));
        $reasons = (array) ($d['reasons'] ?? []);
        $narrative = self::escape((string) ($d['reasons_narrative'] ?? ''));
        $prior = self::escape((string) ($d['prior_actions_taken'] ?? ''));

        $checkAcademic = self::check(in_array('academic_deficiency', $reasons, true));
        $checkAbsences = self::check(in_array('frequent_absences_tardiness', $reasons, true));
        $checkBehavior = self::check(in_array('behavioral_misconduct', $reasons, true));
        $checkEmotional = self::check(in_array('emotional_distress', $reasons, true));
        $checkFamily = self::check(in_array('family_problems', $reasons, true));
        $checkPeer = self::check(in_array('peer_relationship_conflict', $reasons, true));

        return <<<LATEX
\\noindent\\textbf{A. Student Information}\\par\\vspace{0.3em}
\\begin{tabularx}{\\textwidth}{|l|X|l|X|}
\\hline
\\textbf{Student Name:} & {$name} & \\textbf{Student ID:} & {$id} \\\\ \\hline
\\textbf{Course \\& Year:} & {$course} & \\textbf{Referral Date:} & {$date} \\\\ \\hline
\\textbf{Referred By:} & {$refName} & \\textbf{Department:} & {$refDept} \\\\ \\hline
\\end{tabularx}\\par\\vspace{0.8em}

\\noindent\\textbf{B. Reasons for Referral}\\par\\vspace{0.3em}
\\begin{tabular}{llll}
{$checkAcademic} Academic Deficiency & {$checkAbsences} Absences/Tardiness & {$checkBehavior} Behavioral Misconduct \\\\
{$checkEmotional} Emotional Distress & {$checkFamily} Family Problems & {$checkPeer} Peer Conflicts \\\\
\\end{tabular}\\par\\vspace{0.8em}

\\noindent\\textbf{C. Observations \\& Reason Narrative}\\par\\vspace{0.3em}
\\begin{mdframed}
{$narrative}
\\end{mdframed}\\par\\vspace{0.8em}

\\noindent\\textbf{D. Actions Taken Prior to Referral}\\par\\vspace{0.3em}
\\begin{mdframed}
{$prior}
\\end{mdframed}
LATEX;
    }

    private static function renderAppointmentForm(array $d): string
    {
        $num = self::escape((string) ($d['appointment_number'] ?? ''));
        $name = self::escape((string) ($d['student_name'] ?? ''));
        $id = self::escape((string) ($d['student_id'] ?? ''));
        $course = self::escape((string) ($d['course_year'] ?? ''));
        $date = self::escape((string) ($d['scheduled_date'] ?? ''));
        $time = self::escape((string) ($d['scheduled_time'] ?? ''));
        $counselor = self::escape((string) ($d['counselor_name'] ?? ''));
        $purpose = self::escape((string) ($d['purpose'] ?? ''));

        return <<<LATEX
\\noindent\\begin{tabularx}{\\textwidth}{|l|X|l|X|}
\\hline
\\textbf{Appointment No:} & {$num} & \\textbf{Student ID:} & {$id} \\\\ \\hline
\\textbf{Student Name:} & {$name} & \\textbf{Course/Yr:} & {$course} \\\\ \\hline
\\textbf{Scheduled Date:} & {$date} & \\textbf{Time:} & {$time} \\\\ \\hline
\\textbf{Assigned Counselor:} & {$counselor} & \\textbf{Purpose:} & {$purpose} \\\\ \\hline
\\end{tabularx}\\par\\vspace{1em}
\\textit{Note: Please be at the Guidance Center 5 minutes before your scheduled appointment.}
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

        return <<<LATEX
\\noindent Please excuse the student named below to report to the Guidance Center:\\par\\vspace{0.5em}
\\begin{tabularx}{\\textwidth}{|l|X|l|X|}
\\hline
\\textbf{Call Slip No:} & {$num} & \\textbf{Student ID:} & {$id} \\\\ \\hline
\\textbf{Student Name:} & {$name} & \\textbf{Course/Yr:} & {$course} \\\\ \\hline
\\textbf{Date of Call:} & {$date} & \\textbf{Time:} & {$time} \\\\ \\hline
\\textbf{Purpose:} & {$purpose} & \\textbf{Issuing Counselor:} & {$counselor} \\\\ \\hline
\\end{tabularx}\\par\\vspace{1em}
\\textbf{Instructor/Teacher Acknowledgment Signature:} \\underline{\\hspace{6cm}}
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
                $lines[] = '\\textbf{' . self::escape((string) $key) . ':} ' . self::escape((string) $val) . ' \\\\';
            }
        }
        return implode("\n", $lines);
    }
}
