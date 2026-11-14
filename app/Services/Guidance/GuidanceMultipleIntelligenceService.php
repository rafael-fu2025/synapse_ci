<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Models\GuidanceMultipleIntelligenceAssignmentModel;
use App\Models\GuidanceMultipleIntelligenceSubmissionModel;
use App\Services\AuthenticatedUser;
use App\Services\Crypto\EncryptionService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Service for the 80-Item Multiple Intelligence Subsystem (Walter McKenzie 1999).
 *
 * Implements survey assignment, secure encrypted draft saving, completion validation,
 * modulo-8 domain scoring across 8 intelligences, cryptographic digital account attestation,
 * and result reporting without top-3 truncation or diagnostic labels.
 */
class GuidanceMultipleIntelligenceService
{
    /**
     * Likert response scale (0 to 4 points).
     */
    public const SCALE = [
        0 => ['value' => 0, 'label' => 'Never', 'points' => 0],
        1 => ['value' => 1, 'label' => 'Seldom', 'points' => 1],
        2 => ['value' => 2, 'label' => 'Sometimes', 'points' => 2],
        3 => ['value' => 3, 'label' => 'Frequently', 'points' => 3],
        4 => ['value' => 4, 'label' => 'Always', 'points' => 4],
    ];

    /**
     * Canonical 8 domains in source order (matching scoring-of-M.I.-Test.doc).
     */
    public const DOMAINS = [
        'logical_mathematical' => [
            'key' => 'logical_mathematical',
            'order' => 1,
            'title' => 'Logical-Mathematical Intelligence',
            'abbreviation' => 'L-M',
            'items' => [1, 9, 17, 25, 33, 41, 49, 57, 65, 73],
            'description' => 'Involved in most scientific thinking, including solving logical puzzles, deriving proofs, and performing calculations. High logical mathematical thinking can be demonstrated by philosophers and mathematicians.',
            'characteristics' => 'Do you find it easy to compute math problems mentally and on paper, solve brainteasers, do logic puzzles, conduct science experiments, figure out number-and-sequence patterns and watch shows with science and number themes?',
            'educational_suggestions' => 'Engage with problem-solving activities, logic puzzles, algorithmic reasoning, scientific investigations, spreadsheets, data analysis, and structured step-by-step methodologies.',
            'max_score' => 40,
        ],
        'linguistic' => [
            'key' => 'linguistic',
            'order' => 2,
            'title' => 'Linguistic Intelligence',
            'abbreviation' => 'Ling',
            'items' => [2, 10, 18, 26, 34, 42, 50, 58, 66, 74],
            'description' => 'Involved in reading, writing, listening and talking. High linguistic intelligence can be demonstrated by writers.',
            'characteristics' => 'Do you find it easy to memorize information, write poems or stories, give talks, read books play word games, use big words and remember what you hear?',
            'educational_suggestions' => 'Incorporate reading diverse materials, keeping a journal, creative writing, debating, word puzzles, storytelling, and articulating concepts through written and verbal summaries.',
            'max_score' => 40,
        ],
        'spatial' => [
            'key' => 'spatial',
            'order' => 3,
            'title' => 'Spatial Intelligence',
            'abbreviation' => 'Spa',
            'items' => [3, 11, 19, 27, 35, 43, 51, 59, 67, 75],
            'description' => 'Involved in moving from one location to another or determining one’s orientation in space. High spatial intelligence can be demonstrated by architects and marine navigators.',
            'characteristics' => 'Do you find it easy to draw, paint or doodle, work through puzzles and mazes, build with blocks and follow maps and flowcharts? Do you prefer reading materials that are full of illustrations?',
            'educational_suggestions' => 'Utilize graphic organizers, mind maps, flowcharts, 3D visualization, architectural modeling, visual arts, diagrams, and illustrated notes.',
            'max_score' => 40,
        ],
        'musical' => [
            'key' => 'musical',
            'order' => 4,
            'title' => 'Musical Intelligence',
            'abbreviation' => 'Mus',
            'items' => [4, 12, 20, 28, 36, 44, 52, 60, 68, 76],
            'description' => 'Involved in singing, composing, conducting, or performing on a musical instrument. High musical intelligence can be demonstrated by musicians and composers.',
            'characteristics' => 'Do you find it easy to sing or play a musical instrument, listen to music, create your own songs or maps, recognized off-key recordings and noises, remember television jingles and lyrics, and work while listening to or humming simple melodies and tunes?',
            'educational_suggestions' => 'Integrate rhythmic mnemonic patterns, melodic associations, background instrumental soundscapes while studying, poetry cadence, and musical performance.',
            'max_score' => 40,
        ],
        'bodily_kinesthetic' => [
            'key' => 'bodily_kinesthetic',
            'order' => 5,
            'title' => 'Bodily-Kinesthetic Intelligence',
            'abbreviation' => 'B-K',
            'items' => [5, 13, 21, 29, 37, 45, 53, 61, 69, 77],
            'description' => 'Involved in using one’s body or it’s various parts to perform skillful and purposeful movements. High body-kinesthetic intelligence can be demonstrated by dancers, athletes and surgeons.',
            'characteristics' => 'Do you find it easy to engage in sports and other physical activities, move around, spend free time outdoors, work with your hands in such things as model building or sewing, participate in dance, ballet, gymnastics, plays puppet shows or other performances, and use finger paints, clay and papier machê.',
            'educational_suggestions' => 'Adopt active hands-on learning, tactile experiments, physical movement, role-playing, laboratory manipulatives, sports, and crafts.',
            'max_score' => 40,
        ],
        'interpersonal' => [
            'key' => 'interpersonal',
            'order' => 6,
            'title' => 'Interpersonal Intelligence',
            'abbreviation' => 'Inter',
            'items' => [6, 14, 22, 30, 38, 46, 54, 62, 70, 78],
            'description' => 'Involved in understanding other people and one’s relations to others. High interpersonal intelligence can be demonstrated by psychotherapists, teachers, salespeople and politicians.',
            'characteristics' => 'Do you find it easy to make friends, meet strangers, resolve conflicts, lead groups or clubs, engage in gossip, participate in team sports, plan social activities, and teach or counsel others?',
            'educational_suggestions' => 'Participate in cooperative study groups, peer tutoring, team projects, group discussions, club leadership, conflict resolution, and collaborative brainstorming.',
            'max_score' => 40,
        ],
        'intrapersonal' => [
            'key' => 'intrapersonal',
            'order' => 7,
            'title' => 'Intrapersonal Intelligence',
            'abbreviation' => 'Intra',
            'items' => [7, 15, 23, 31, 39, 47, 55, 63, 71, 79],
            'description' => 'Involved in understanding one’s self and having insights into one’s thoughts, emotions, and actions. High intrapersonal intelligence can be demonstrated by Zen masters.',
            'characteristics' => 'Do you find it easy to function independently, do you own work and thinking, spend time alone, engage in solo hobbies and activities, set goals, analyze your own strengths and weaknesses, and keep private diaries or journals?',
            'educational_suggestions' => 'Encourage independent research, personal goal setting, reflective journaling, metacognitive self-assessment, solo study environments, and alignment with personal values.',
            'max_score' => 40,
        ],
        'naturalist' => [
            'key' => 'naturalist',
            'order' => 8,
            'title' => 'Naturalist Intelligence',
            'abbreviation' => 'Nat',
            'items' => [8, 16, 24, 32, 40, 48, 56, 64, 72, 80],
            'description' => 'Involves in the appreciation of nature and becoming aware of one’s interconnectedness with God’s creation.',
            'characteristics' => 'Do you appreciate nature and love to protect the environment? Do you take care of plants, animals, and pets?',
            'educational_suggestions' => 'Incorporate outdoor fieldwork, ecological projects, nature observation, taxonomy and categorization systems, biological sciences, conservation, and gardening.',
            'max_score' => 40,
        ],
    ];

    /**
     * Verbatim 80 survey items from Walter McKenzie, 1999 (MULTIPLE-INTELLEGENCE-SURVEY.doc).
     */
    public const ITEMS = [
        1 => 'I keep my things neat and orderly.',
        2 => 'I enjoy reading all kinds of materials.',
        3 => 'I can imagine ideas in my mind.',
        4 => 'I easily pick up on patterns.',
        5 => 'I enjoy making things with my hands.',
        6 => 'I learn best interacting with others.',
        7 => 'I am keenly aware of my moral beliefs.',
        8 => 'I enjoy organizing things by common traits.',
        9 => 'Step by step directions are a big help.',
        10 => 'Taking notes help me remember and understand.',
        11 => 'Rearranging the room is fun for me.',
        12 => 'I focus on noise and sounds.',
        13 => 'Sitting still for a long period of time is difficult for me.',
        14 => 'The more the merrier.',
        15 => 'I learn best when I have an emotional attachment to the subject.',
        16 => 'Ecological issues are important to me.',
        17 => 'Solving problems comes easily to me.',
        18 => 'I faithfully contact friends through letters and or email.',
        19 => 'I enjoy creating art using varied media.',
        20 => 'Moving to a beat is easy for me.',
        21 => 'I enjoy outdoor games and sports.',
        22 => 'Study groups are very productive for me.',
        23 => 'Fairness is important to me.',
        24 => 'Hiking and camping are enjoyable activities.',
        25 => 'I get easily frustrated with disorganized people.',
        26 => 'It is easy for me to explain my ideas to others.',
        27 => 'I remember well using graphic organizers.',
        28 => 'I’ve always been interested in playing an instrument.',
        29 => 'I value non-verbal communication such as sign language.',
        30 => 'I enjoy chat rooms.',
        31 => 'My attitude affects how I learn.',
        32 => 'I believe preserving our National Park is important.',
        33 => 'I can complete calculations quickly in my head.',
        34 => 'I keep a journal.',
        35 => 'Performance art can be gratifying.',
        36 => 'The cadence of poetry intrigues me.',
        37 => 'A fit body is important for a fit mind.',
        38 => 'Participating in politics is important.',
        39 => 'Social justice issues concern me.',
        40 => 'Putting things in hierarchies makes sense to me.',
        41 => 'Brainteasers requiring reasoning are exciting.',
        42 => 'Word puzzles like crosswords and jumbles are fun.',
        43 => 'Spreadsheets are great for making charts, graphs and tables.',
        44 => 'I remember things by putting them in a rhyme.',
        45 => 'Arts and crafts are enjoyable pastimes.',
        46 => 'Television and radio talk shows are enjoyable.',
        47 => 'Working alone can be just as productive as working in a group.',
        48 => 'Animals are important in my life.',
        49 => 'I can’t begin an assignment until all my questions are answered.',
        50 => 'I write for pleasure.',
        51 => 'Three dimensional puzzles bring me such enjoyment.',
        52 => 'Concentration is difficult while listening to a radio or television.',
        53 => 'Expression through dance is beautiful.',
        54 => 'I am a “team player.”',
        55 => 'I need to know why I should do something before I agree to do it.',
        56 => 'My home has a recycling system in place.',
        57 => 'Structure helps me to be successful.',
        58 => 'I enjoy playing with words like puns, anagrams, spoonerisms.',
        59 => 'Music videos are stimulating.',
        60 => 'I enjoy many kinds of music.',
        61 => 'I like working with tools.',
        62 => 'I dislike working alone.',
        63 => 'When I believe in something I will give 100% effort to it.',
        64 => 'I enjoy studying Biology, Botany and Zoology.',
        65 => 'I find working on a computer spreadsheet or database rewarding.',
        66 => 'Foreign languages interest me.',
        67 => 'I can recall things in mental pictures.',
        68 => 'Musicals are more interesting than dramatic plays.',
        69 => 'I live and active lifestyle.',
        70 => 'Clubs and extra curricular activities are fun.',
        71 => 'I like to be involved in cause that help others.',
        72 => 'I spend a great deal of time outdoors.',
        73 => 'Things have to make sense to me or I am dissatisfied.',
        74 => 'Debates and public speaking are activities I like to participate.',
        75 => 'I am good at reading maps and blueprints.',
        76 => 'Remembering song lyrics is easy for me.',
        77 => 'I learn by doing.',
        78 => 'I pay attention to social issues and concern.',
        79 => 'I am willing to protest or sign a petition to right a wrong.',
        80 => 'I enjoy working on a garden.',
    ];

    private GuidanceMultipleIntelligenceAssignmentModel $assignmentModel;
    private GuidanceMultipleIntelligenceSubmissionModel $submissionModel;
    private EncryptionService $encryptionService;
    private FormAttestationService $attestationService;
    private BaseConnection $db;

    public function __construct(
        ?GuidanceMultipleIntelligenceAssignmentModel $assignmentModel = null,
        ?GuidanceMultipleIntelligenceSubmissionModel $submissionModel = null,
        ?EncryptionService $encryptionService = null,
        ?FormAttestationService $attestationService = null,
        ?BaseConnection $db = null
    ) {
        $this->db = $db ?? Database::connect();
        $this->assignmentModel = $assignmentModel ?? new GuidanceMultipleIntelligenceAssignmentModel($this->db);
        $this->submissionModel = $submissionModel ?? new GuidanceMultipleIntelligenceSubmissionModel($this->db);
        $this->encryptionService = $encryptionService ?? new EncryptionService();
        $this->attestationService = $attestationService ?? new FormAttestationService();
    }

    /**
     * Resolves domain key for a given item number (1..80) using the modulo-8 formula.
     */
    public static function getDomainKeyForItem(int $itemNumber): string
    {
        if ($itemNumber < 1 || $itemNumber > 80) {
            throw new InvalidArgumentException("Item number must be between 1 and 80, got {$itemNumber}.");
        }

        return match ($itemNumber % 8) {
            1 => 'logical_mathematical',
            2 => 'linguistic',
            3 => 'spatial',
            4 => 'musical',
            5 => 'bodily_kinesthetic',
            6 => 'interpersonal',
            7 => 'intrapersonal',
            0 => 'naturalist',
        };
    }

    /**
     * Returns full survey catalog: items, scale, and domain definitions.
     *
     * @return array<string, mixed>
     */
    public function getSurveyCatalog(): array
    {
        $itemList = [];
        foreach (self::ITEMS as $id => $text) {
            $domainKey = self::getDomainKeyForItem($id);
            $itemList[] = [
                'id' => $id,
                'item_number' => $id,
                'text' => $text,
                'domain_key' => $domainKey,
                'domain_title' => self::DOMAINS[$domainKey]['title'],
            ];
        }

        return [
            'title' => 'Multiple Intelligences Survey (McKenzie 1999)',
            'author' => 'Walter McKenzie, 1999',
            'organization' => 'Surf Aquarium Consulting / Office of the Guidance Center',
            'total_items' => 80,
            'scale' => array_values(self::SCALE),
            'domains' => array_values(self::DOMAINS),
            'items' => $itemList,
        ];
    }

    /**
     * Assigns survey to a person, enforcing single active assignment constraint.
     *
     * @param int $personId
     * @param array<string, mixed> $options
     * @param int|null $tenantId
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed> Created assignment record
     * @throws DomainException If person already has an active assignment or actor is unauthorized
     */
    public function assignSurvey(int $personId, array $options = [], ?int $tenantId = null, ?array $actor = null): array
    {
        $tid = $tenantId ?? CurrentTenant::id();
        $effectiveActor = $actor ?? (isset($options['actor']) && is_array($options['actor']) ? $options['actor'] : null);
        $resolvedActor = $this->authorizeActor($effectiveActor, 'guidance.assessments.assign', $tid);

        if ($personId <= 0) {
            throw new InvalidArgumentException('Valid person ID is required.');
        }

        // Enforce single active assignment
        $active = $this->assignmentModel->scoped($tid)
            ->where('person_id', $personId)
            ->whereIn('status', ['assigned', 'in_progress'])
            ->first();

        if ($active !== null) {
            throw new DomainException('Person already has an active Multiple Intelligence assessment assignment.', 409);
        }

        $assignedByUserId = !empty($options['assigned_by_user_id'])
            ? (int) $options['assigned_by_user_id']
            : (!empty($resolvedActor['id']) ? (int) $resolvedActor['id'] : null);

        $now = date('Y-m-d H:i:s');
        $assignmentData = [
            'tenant_id' => $tid,
            'person_id' => $personId,
            'assigned_by_user_id' => $assignedByUserId,
            'status' => 'assigned',
            'academic_term' => isset($options['academic_term']) ? substr((string) $options['academic_term'], 0, 64) : null,
            'academic_year' => isset($options['academic_year']) ? substr((string) $options['academic_year'], 0, 32) : null,
            'assigned_at' => $options['assigned_at'] ?? $now,
            'due_at' => $options['due_at'] ?? null,
            'completed_at' => null,
        ];

        $id = $this->assignmentModel->insert($assignmentData);
        if ($id === false) {
            throw new RuntimeException('Failed to create multiple intelligence assessment assignment.', 500);
        }

        $assignmentId = (int) $id;

        // Pre-create initial draft submission record
        $this->submissionModel->insert([
            'tenant_id' => $tid,
            'assignment_id' => $assignmentId,
            'person_id' => $personId,
            'status' => 'draft',
            'draft_responses_cipher' => null,
            'scores_json' => null,
            'domain_scores_json' => null,
        ]);

        return $this->assignmentModel->find($assignmentId);
    }

    /**
     * Saves partial responses securely encrypted in draft.
     *
     * @param int $assignmentId
     * @param array<int|string, int|numeric-string> $responses
     * @param int|null $tenantId
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed> Updated draft submission record
     */
    public function saveDraft(int $assignmentId, array $responses, ?int $tenantId = null, ?array $actor = null): array
    {
        $tid = $tenantId ?? CurrentTenant::id();

        $assignment = $this->assignmentModel->scoped($tid)->find($assignmentId);
        if ($assignment === null) {
            throw new InvalidArgumentException('Assessment assignment not found.', 404);
        }

        $resolvedActor = $this->authorizeActor($actor, 'guidance.assessments.take', $tid);
        if ($resolvedActor !== null && array_key_exists('person_id', $resolvedActor) && $resolvedActor['person_id'] !== null) {
            if ((int) $resolvedActor['person_id'] !== (int) $assignment['person_id']) {
                throw new DomainException('Self-taking access forbidden: only the assigned student may save draft responses.', 403);
            }
        }

        if (in_array($assignment['status'], ['completed', 'cancelled'], true)) {
            throw new DomainException("Cannot save draft for an assignment with status '{$assignment['status']}'.", 400);
        }

        if ($assignment['status'] === 'assigned') {
            $this->assignmentModel->update($assignmentId, ['status' => 'in_progress']);
        }

        $normalized = [];
        foreach ($responses as $k => $val) {
            $itemNum = (int) $k;
            if ($itemNum < 1 || $itemNum > 80) {
                throw new InvalidArgumentException("Invalid item index '{$k}'. Expected 1 to 80.", 422);
            }
            if (!is_numeric($val) || (int) $val < 0 || (int) $val > 4) {
                throw new InvalidArgumentException("Score for item {$itemNum} must be between 0 and 4.", 422);
            }
            $normalized[$itemNum] = (int) $val;
        }

        // Encrypt draft responses using AES-256-GCM
        try {
            $plaintext = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $encrypted = $this->encryptionService->encrypt($plaintext);
            $cipherPayload = json_encode([
                'c' => base64_encode($encrypted['cipher']),
                'n' => base64_encode($encrypted['nonce']),
                't' => base64_encode($encrypted['tag']),
                'v' => $encrypted['key_version'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            throw new RuntimeException('Failed to encrypt draft responses: ' . $e->getMessage(), 500, $e);
        }

        $existingSub = $this->submissionModel->scoped($tid)->where('assignment_id', $assignmentId)->first();
        if ($existingSub !== null) {
            $this->submissionModel->update((int) $existingSub['id'], [
                'status' => 'draft',
                'draft_responses_cipher' => $cipherPayload,
            ]);
            $subId = (int) $existingSub['id'];
        } else {
            $subId = (int) $this->submissionModel->insert([
                'tenant_id' => $tid,
                'assignment_id' => $assignmentId,
                'person_id' => (int) $assignment['person_id'],
                'status' => 'draft',
                'draft_responses_cipher' => $cipherPayload,
            ]);
        }

        $sub = $this->submissionModel->find($subId);
        $sub['draft_responses'] = $normalized;
        return $sub;
    }

    /**
     * Finalizes the assessment: validates all 80 items answered, computes all 8 domain scores,
     * cryptographically binds the digital account attestation statement, and marks assignment completed.
     *
     * @param int $assignmentId
     * @param array<int|string, int|numeric-string> $responses
     * @param string $attestationStatement
     * @param int|null $tenantId
     * @param array<string, mixed>|null $actor Fresh authenticated actor context
     * @return array<string, mixed> Finalized submission record with domain scores
     */
    public function finalizeAssessment(
        int $assignmentId,
        array $responses,
        string $attestationStatement,
        ?int $tenantId = null,
        ?array $actor = null
    ): array {
        $tid = $tenantId ?? CurrentTenant::id();

        $assignment = $this->assignmentModel->scoped($tid)->find($assignmentId);
        if ($assignment === null) {
            throw new InvalidArgumentException('Assessment assignment not found.', 404);
        }

        $resolvedActor = $this->authorizeActor($actor, 'guidance.assessments.take', $tid);
        if ($resolvedActor !== null && array_key_exists('person_id', $resolvedActor) && $resolvedActor['person_id'] !== null) {
            if ((int) $resolvedActor['person_id'] !== (int) $assignment['person_id']) {
                throw new DomainException('Self-taking access forbidden: only the assigned student may finalize and attest this assessment.', 403);
            }
        }

        if ($assignment['status'] === 'completed') {
            throw new DomainException('Assessment is already completed and finalized.', 400);
        }
        if ($assignment['status'] === 'cancelled') {
            throw new DomainException('Cannot finalize a cancelled assessment assignment.', 400);
        }

        // 1. Strict validation: all 80 items answered (0..4)
        $normalized = [];
        for ($i = 1; $i <= 80; $i++) {
            if (!array_key_exists($i, $responses) && !array_key_exists((string) $i, $responses)) {
                throw new InvalidArgumentException("Missing response for item {$i}. All 80 items must be answered.", 422);
            }
            $val = $responses[$i] ?? $responses[(string) $i];
            if (!is_numeric($val) || (int) $val < 0 || (int) $val > 4) {
                throw new InvalidArgumentException("Score for item {$i} must be an integer between 0 and 4.", 422);
            }
            $normalized[$i] = (int) $val;
        }

        // 2. Validate digital attestation statement
        $statement = trim($attestationStatement);
        if ($statement === '') {
            throw new InvalidArgumentException('Attestation statement is required.', 422);
        }
        $statementHash = hash('sha256', $statement);

        // 3. Compute domain scores for all 8 domains (0-40)
        $domainScores = [];
        foreach (self::DOMAINS as $domainKey => $def) {
            $sum = 0;
            foreach ($def['items'] as $itemNum) {
                $sum += $normalized[$itemNum];
            }
            $domainScores[$domainKey] = $sum;
        }

        // 4. Snapshot hash calculation for cryptographic integrity
        $snapshot = [
            'tenant_id' => $tid,
            'assignment_id' => $assignmentId,
            'person_id' => (int) $assignment['person_id'],
            'scores' => $normalized,
            'domain_scores' => $domainScores,
            'statement_hash' => $statementHash,
        ];
        $snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $snapshotHash = hash('sha256', $snapshotJson);

        // 5. Attestation actor resolution
        $currentSessionUser = (new AuthenticatedUser())->current();
        $attestedUserId = !empty($actor['id'])
            ? (int) $actor['id']
            : (!empty($currentSessionUser['id']) ? (int) $currentSessionUser['id'] : null);
        $now = date('Y-m-d H:i:s');

        $submissionData = [
            'status' => 'finalized',
            'draft_responses_cipher' => null,
            'scores_json' => json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'domain_scores_json' => json_encode($domainScores, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'attested_by_user_id' => $attestedUserId,
            'attested_at' => $now,
            'attestation_statement_hash' => $statementHash,
            'snapshot_hash' => $snapshotHash,
            'form_instance_id' => !empty($options['form_instance_id']) ? (int) $options['form_instance_id'] : null,
        ];

        $existingSub = $this->submissionModel->scoped($tid)->where('assignment_id', $assignmentId)->first();
        if ($existingSub !== null) {
            $this->submissionModel->update((int) $existingSub['id'], $submissionData);
            $subId = (int) $existingSub['id'];
        } else {
            $submissionData['tenant_id'] = $tid;
            $submissionData['assignment_id'] = $assignmentId;
            $submissionData['person_id'] = (int) $assignment['person_id'];
            $subId = (int) $this->submissionModel->insert($submissionData);
        }

        // 6. Mark assignment completed
        $this->assignmentModel->update($assignmentId, [
            'status' => 'completed',
            'completed_at' => $now,
        ]);

        $finalSubmission = $this->submissionModel->find($subId);
        $finalSubmission['scores'] = $normalized;
        $finalSubmission['domain_scores'] = $domainScores;
        $finalSubmission['statement_text'] = $statement;
        return $finalSubmission;
    }

    /**
     * Retrieves assessment results: all 8 domain scores (0-40), descriptions, and educational suggestions.
     * Strictly NO top-3 truncation and NO diagnostic labels.
     *
     * @param int $submissionId
     * @param int|null $tenantId
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function getResults(int $submissionId, ?int $tenantId = null, ?array $actor = null): array
    {
        $tid = $tenantId ?? CurrentTenant::id();

        $sub = $this->submissionModel->scoped($tid)->find($submissionId);
        if ($sub === null) {
            throw new InvalidArgumentException('Assessment submission not found.', 404);
        }

        $this->assertViewAccess($actor, (int) $sub['person_id'], $tid);

        if ($sub['status'] !== 'finalized') {
            throw new DomainException('Assessment has not been finalized yet.', 400);
        }

        $assignment = $this->assignmentModel->scoped($tid)->find((int) $sub['assignment_id']);

        $scores = json_decode((string) ($sub['scores_json'] ?? '{}'), true) ?: [];
        $domainScores = json_decode((string) ($sub['domain_scores_json'] ?? '{}'), true) ?: [];

        $domains = [];
        foreach (self::DOMAINS as $key => $def) {
            $score = (int) ($domainScores[$key] ?? 0);
            $domains[] = [
                'key' => $key,
                'order' => $def['order'],
                'title' => $def['title'],
                'abbreviation' => $def['abbreviation'],
                'score' => $score,
                'max_score' => $def['max_score'],
                'percentage' => round(($score / $def['max_score']) * 100, 1),
                'description' => $def['description'],
                'characteristics' => $def['characteristics'],
                'educational_suggestions' => $def['educational_suggestions'],
            ];
        }

        return [
            'submission' => $sub,
            'assignment' => $assignment,
            'domain_scores' => $domainScores,
            'domains' => $domains,
            'scores' => $scores,
            'attestation' => [
                'attested_by_user_id' => $sub['attested_by_user_id'],
                'attested_at' => $sub['attested_at'],
                'attestation_statement_hash' => $sub['attestation_statement_hash'],
                'snapshot_hash' => $sub['snapshot_hash'],
            ],
        ];
    }

    /**
     * Retrieves assessment results by assignment ID.
     *
     * @param int $assignmentId
     * @param int|null $tenantId
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function getResultsByAssignment(int $assignmentId, ?int $tenantId = null, ?array $actor = null): array
    {
        $tid = $tenantId ?? CurrentTenant::id();
        $sub = $this->submissionModel->findFinalizedByAssignmentId($assignmentId, $tid);
        if ($sub === null) {
            throw new InvalidArgumentException('Finalized submission not found for assignment.', 404);
        }

        return $this->getResults((int) $sub['id'], $tid, $actor);
    }

    /**
     * Decrypts and retrieves draft responses for an assignment if available.
     *
     * @param int $assignmentId
     * @param int|null $tenantId
     * @param array<string, mixed>|null $actor
     * @return array<int, int>|null Decrypted draft responses or null
     */
    public function getDraft(int $assignmentId, ?int $tenantId = null, ?array $actor = null): ?array
    {
        $tid = $tenantId ?? CurrentTenant::id();
        $sub = $this->submissionModel->findByAssignmentId($assignmentId, $tid);
        if ($sub === null) {
            return null;
        }

        $this->assertViewAccess($actor, (int) $sub['person_id'], $tid);

        if (empty($sub['draft_responses_cipher'])) {
            return null;
        }

        try {
            $cipherData = json_decode((string) $sub['draft_responses_cipher'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($cipherData) || !isset($cipherData['c'], $cipherData['n'], $cipherData['t'], $cipherData['v'])) {
                return is_array($cipherData) ? $cipherData : null;
            }

            $plaintext = $this->encryptionService->decrypt(
                base64_decode($cipherData['c']),
                base64_decode($cipherData['n']),
                base64_decode($cipherData['t']),
                (int) $cipherData['v']
            );

            $decoded = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return null;
            }

            $result = [];
            foreach ($decoded as $k => $v) {
                $result[(int) $k] = (int) $v;
            }
            return $result;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Authorizes actor against database user/role permissions when actor is provided.
     *
     * @param array<string, mixed>|null $actor
     * @param string $requiredPermission
     * @param int $tenantId
     * @return array<string, mixed>|null
     */
    private function authorizeActor(?array $actor, string $requiredPermission, int $tenantId): ?array
    {
        if ($actor === null) {
            return null;
        }

        if (isset($actor['tenant_id']) && (int) $actor['tenant_id'] !== $tenantId) {
            throw new DomainException('Cross-tenant assessment access forbidden.', 403);
        }

        $userId = isset($actor['id']) && is_numeric($actor['id']) ? (int) $actor['id'] : 0;
        if ($userId > 0) {
            $userRow = $this->db->table('users')
                ->where('id', $userId)
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->get()
                ->getRowArray();

            if ($userRow !== null) {
                if (($userRow['status'] ?? '') !== 'active') {
                    throw new DomainException('An active account is required to access assessments.', 403);
                }

                $role = (string) ($userRow['role'] ?? '');
                $permRows = $this->db->table('role_permissions rp')
                    ->select('p.code')
                    ->join('permissions p', 'p.id = rp.permission_id')
                    ->where('rp.role', $role)
                    ->get()
                    ->getResultArray();
                $permissions = array_column($permRows, 'code');

                if (!in_array($requiredPermission, $permissions, true)) {
                    throw new DomainException("Guidance permission '{$requiredPermission}' forbidden for role '{$role}'.", 403);
                }

                return [
                    'id' => (int) $userRow['id'],
                    'tenant_id' => (int) $userRow['tenant_id'],
                    'person_id' => array_key_exists('person_id', $actor)
                        ? ($actor['person_id'] !== null ? (int) $actor['person_id'] : null)
                        : ($userRow['person_id'] !== null ? (int) $userRow['person_id'] : null),
                    'role' => !empty($actor['role']) ? (string) $actor['role'] : $role,
                    'permissions' => $permissions,
                ];
            }
        }

        if (isset($actor['permissions']) && is_array($actor['permissions'])) {
            if (!in_array($requiredPermission, $actor['permissions'], true)) {
                throw new DomainException("Guidance permission '{$requiredPermission}' forbidden.", 403);
            }
        } elseif (!empty($actor['role'])) {
            $role = (string) $actor['role'];
            $permRows = $this->db->table('role_permissions rp')
                ->select('p.code')
                ->join('permissions p', 'p.id = rp.permission_id')
                ->where('rp.role', $role)
                ->get()
                ->getResultArray();
            $permissions = array_column($permRows, 'code');
            if (!empty($permissions) && !in_array($requiredPermission, $permissions, true)) {
                throw new DomainException("Guidance permission '{$requiredPermission}' forbidden for role '{$role}'.", 403);
            }
        }

        return $actor;
    }

    /**
     * Enforces view access control: students may only view their own assessment;
     * guidance practitioners with guidance.assessments.view may view any student's assessment in the tenant.
     *
     * @param array<string, mixed>|null $actor
     * @param int $targetPersonId
     * @param int $tenantId
     */
    private function assertViewAccess(?array $actor, int $targetPersonId, int $tenantId): void
    {
        $resolvedActor = $this->authorizeActor($actor, 'guidance.assessments.view', $tenantId);
        if ($resolvedActor === null) {
            return;
        }

        $role = (string) ($resolvedActor['role'] ?? '');
        if ($role === 'student') {
            $actorPersonId = isset($resolvedActor['person_id']) ? (int) $resolvedActor['person_id'] : 0;
            if ($actorPersonId !== $targetPersonId) {
                throw new DomainException('Students may only view their own Multiple Intelligence assessment results.', 403);
            }
        }
    }
}
