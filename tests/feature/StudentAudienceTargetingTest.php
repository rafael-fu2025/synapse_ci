<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AnnouncementService;
use App\Services\AuthenticatedUser;
use App\Services\EngagementAudience;
use App\Services\NotificationService;
use Config\Database;
use RuntimeException;

require_once __DIR__ . '/SurveyWorkflowTest.php';

/** Exercises the same targeting contract across publishing, portal access, submissions and bell reads. */
final class StudentAudienceTargetingTest extends SurveyTestCase
{
    protected function tearDown(): void
    {
        if (isset($this->tenant)) {
            foreach (['notifications', 'announcements_reads', 'announcements'] as $table) {
                $this->db->table($table)->where('tenant_id', $this->tenant)->delete();
            }
        }
        parent::tearDown();
    }

    private function studentProfile(?int $year, ?string $type, ?bool $graduating): array
    {
        $account = $this->account('student');
        $this->profile($account, $year, $type, $graduating);
        return $account;
    }

    private function profile(array $account, ?int $year, ?string $type, ?bool $graduating): void
    {
        $this->db->table('student_profiles')->where('tenant_id', $this->tenant)->where('person_id', $account['person_id'])
            ->update(['year_level' => $year, 'enrollment_type' => $type, 'is_graduating' => $graduating]);
    }

    private function content(array $target): array
    {
        $this->login($this->admin);
        $survey = $this->surveys->create($this->payload($target + ['audience' => 'students']));
        $this->surveys->publish((int) $survey['id'], $survey['version']);
        $announcements = new AnnouncementService();
        $notice = $announcements->create('clinic', $target + ['audience' => 'students', 'title' => 'Targeted notice', 'body' => 'Only selected students.']);
        $notice = $announcements->publish((int) $notice['id'], 'clinic', $notice['version']);
        return ['survey' => (int) $survey['id'], 'notice' => (int) $notice['id']];
    }

    private function visible(array $account, array $content, bool $expected): void
    {
        $this->login($account);
        $notices = new AnnouncementService();
        $this->assertSame($expected, in_array($content['notice'], array_map('intval', array_column($notices->portalListing()['announcements'], 'id')), true));
        $this->assertSame($expected, in_array($content['survey'], array_map('intval', array_column($this->surveys->available()['items'], 'id')), true));
        $inbox = (new NotificationService())->ownInbox();
        $this->assertCount($expected ? 2 : 0, $inbox['items']);
        $this->assertSame($expected ? 2 : 0, $inbox['unread_count']);
        if ($expected) {
            $this->assertSame($content['notice'], (int) $notices->show($content['notice'])['id']);
            $this->assertSame($content['survey'], (int) $this->surveys->form($content['survey'])['id']);
        } else {
            $this->rejected(fn () => $notices->show($content['notice']), 404);
            $this->rejected(fn () => $notices->read($content['notice']), 404);
            $this->rejected(fn () => $this->surveys->form($content['survey']), 404);
            $this->rejected(fn () => $this->surveys->submit($content['survey'], $this->validAnswers()), 404);
        }
    }

    public function testYearsAndCategoriesUseOrWithinGroupsAndAndBetweenGroups(): void
    {
        $matches = [$this->studentProfile(2, 'continuing', false), $this->studentProfile(3, 'transferee', null)];
        $excluded = [$this->studentProfile(2, 'new', false), $this->studentProfile(4, 'continuing', true), $this->studentProfile(null, null, null)];
        $content = $this->content(['target_year_levels' => ['2', '3'], 'target_student_categories' => ['continuing', 'transferee']]);
        foreach ($matches as $student) { $this->visible($student, $content, true); }
        foreach ($excluded as $student) { $this->visible($student, $content, false); }
        $recipients = $this->db->table('notifications')->where('tenant_id', $this->tenant)->get()->getResultArray();
        $this->assertCount(4, $recipients);
        $this->assertSame(0, $this->db->table('survey_responses')->where('tenant_id', $this->tenant)->countAllResults());
    }

    public function testGraduatingIsExplicitAndCanOverlapContinuingOrTransferee(): void
    {
        $continuing = $this->studentProfile(2, 'continuing', true);
        $transferee = $this->studentProfile(3, 'transferee', true);
        $lastYearUnknown = $this->studentProfile(4, 'continuing', null);
        $lastYearFalse = $this->studentProfile(6, 'new', false);
        $content = $this->content(['target_student_categories' => ['graduating']]);
        foreach ([$continuing, $transferee] as $student) { $this->visible($student, $content, true); }
        foreach ([$lastYearUnknown, $lastYearFalse] as $student) { $this->visible($student, $content, false); }
    }

    public function testCategorySelectionsAreAlternativesNotSimultaneousRequirements(): void
    {
        $continuing = $this->studentProfile(1, 'continuing', false);
        $graduating = $this->studentProfile(5, 'transferee', true);
        $new = $this->studentProfile(1, 'new', false);
        $content = $this->content(['target_student_categories' => ['continuing', 'graduating']]);
        $this->visible($continuing, $content, true);
        $this->visible($graduating, $content, true);
        $this->visible($new, $content, false);
    }

    public function testUnknownCategoryMatchesYearOnlyButNotCategoryFilters(): void
    {
        $unknown = $this->studentProfile(3, null, null);
        $content = $this->content(['target_year_levels' => [3]]);
        $this->visible($unknown, $content, true);
        $row = $this->db->table('surveys')->where('id', $content['survey'])->get()->getRowArray();
        $this->assertNull($row['target_student_categories']);
    }

    public function testEmptyFiltersRemainUnrestrictedForLegacyUnknownProfiles(): void
    {
        $content = $this->content(['target_year_levels' => [], 'target_student_categories' => []]);
        $this->visible($this->student, $content, true);
        foreach (['surveys' => $content['survey'], 'announcements' => $content['notice']] as $table => $id) {
            $row = $this->db->table($table)->where('tenant_id', $this->tenant)->where('id', $id)->get()->getRowArray();
            $this->assertNull($row['target_year_levels']);
            $this->assertNull($row['target_student_categories']);
        }
    }

    public function testEligibilityRefreshRevokesDirectAndBellAccessAndSubmission(): void
    {
        $student = $this->studentProfile(3, 'transferee', false);
        $content = $this->content(['target_year_levels' => [3], 'target_student_categories' => ['transferee']]);
        $this->visible($student, $content, true);
        $bell = new NotificationService();
        $ids = array_column($bell->ownInbox()['items'], 'id');
        $this->profile($student, 4, 'continuing', false);
        $this->visible($student, $content, false);
        foreach ($ids as $id) { $this->rejected(fn () => $bell->markRead($id), 404); }
        $bell->markAllRead();
        $this->assertSame(2, $this->db->table('notifications')->where('tenant_id', $this->tenant)->where('recipient_person_id', $student['person_id'])->where('read_at', null)->countAllResults());
        $this->profile($student, 3, 'transferee', false);
        $this->visible($student, $content, true);
        $this->assertGreaterThan(0, $this->surveys->submit($content['survey'], $this->validAnswers()));
    }

    public function testTargetingIsCanonicalStrictAndNotAllowedForMixedOrEmployeeAudiences(): void
    {
        $this->assertSame(['target_year_levels' => '[2,4]', 'target_student_categories' => '["continuing","graduating"]'],
            EngagementAudience::targeting(['target_year_levels' => ['4', 2], 'target_student_categories' => ['graduating', 'continuing']], 'students'));
        $invalid = [
            ['target_year_levels' => '2'], ['target_year_levels' => [0]], ['target_year_levels' => [7]],
            ['target_year_levels' => ['02']], ['target_year_levels' => [true]], ['target_year_levels' => [2, '2']],
            ['target_year_levels' => ['x' => 2]], ['target_student_categories' => 'continuing'],
            ['target_student_categories' => ['unknown']], ['target_student_categories' => ['new', 'new']],
            ['target_student_categories' => [1]],
        ];
        foreach ($invalid as $input) { $this->rejected(fn () => EngagementAudience::targeting($input, 'students'), 422); }
        foreach (['all', 'employees'] as $audience) {
            $this->rejected(fn () => $this->surveys->create($this->payload(['audience' => $audience, 'target_year_levels' => [2]])), 422);
            $this->rejected(fn () => (new AnnouncementService())->create('clinic', ['title' => 'x', 'body' => 'x', 'audience' => $audience, 'target_student_categories' => ['new']]), 422);
        }
    }

    public function testDraftEditsClearFiltersAndPublishedTargetingCannotChange(): void
    {
        $survey = $this->surveys->create($this->payload(['audience' => 'students', 'target_year_levels' => [2], 'target_student_categories' => ['new']]));
        $survey = $this->surveys->update((int) $survey['id'], $this->payload(['version' => $survey['version'], 'audience' => 'students']));
        $this->assertNull($survey['target_year_levels']);
        $this->assertNull($survey['target_student_categories']);
        $this->surveys->publish((int) $survey['id'], $survey['version']);
        $this->rejected(fn () => $this->surveys->update((int) $survey['id'], $this->payload(['version' => $survey['version'], 'audience' => 'students', 'target_year_levels' => [4]])), 409);
        $service = new AnnouncementService();
        $notice = $service->create('clinic', ['title' => 'x', 'body' => 'x', 'audience' => 'students', 'target_year_levels' => [2]]);
        $notice = $service->update((int) $notice['id'], 'clinic', ['title' => 'x', 'body' => 'x', 'audience' => 'employees', 'version' => $notice['version']]);
        $this->assertNull($notice['target_year_levels']);
        $notice = $service->publish((int) $notice['id'], 'clinic', $notice['version']);
        $this->rejected(fn () => $service->update((int) $notice['id'], 'clinic', ['version' => $notice['version']]), 409);
    }

    public function testDualProfileEmployeeCannotBypassStudentTargeting(): void
    {
        $student = $this->studentProfile(1, 'new', false);
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('employee_profiles')->insert(['tenant_id' => $this->tenant, 'person_id' => $student['person_id'], 'created_at' => $now, 'updated_at' => $now]);
        $content = $this->content(['target_year_levels' => [4], 'target_student_categories' => ['graduating']]);
        $this->visible($student, $content, false);
        $this->login($student);
        $actor = (new AuthenticatedUser())->current();
        $this->assertTrue(EngagementAudience::eligible($actor, 'employees'));
    }

    public function testRealForeignTenantProfileCannotSupplyTargetingOrMembership(): void
    {
        $student = $this->studentProfile(1, 'new', false);
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('target_foreign_'), 'name' => 'Foreign targeting fixture',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $foreign = (int) $this->db->insertID();
        try {
            $this->assertTrue($this->db->table('student_profiles')->insert([
                'tenant_id' => $foreign, 'person_id' => $student['person_id'],
                'year_level' => 4, 'enrollment_type' => 'continuing', 'is_graduating' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]));
            $content = $this->content(['target_year_levels' => [4], 'target_student_categories' => ['graduating']]);
            $this->visible($student, $content, false);
            $actor = (new AuthenticatedUser())->current();
            $this->assertFalse(EngagementAudience::eligible($actor, 'students', [
                'target_year_levels' => '[4]', 'target_student_categories' => '["graduating"]',
            ]));
            $this->assertSame(0, $this->db->table('notifications')->where('tenant_id', $this->tenant)
                ->where('recipient_person_id', $student['person_id'])->countAllResults());
            $this->db->table('student_profiles')->where('tenant_id', $this->tenant)->where('person_id', $student['person_id'])->delete();
            $this->assertSame([], EngagementAudience::audiences($actor));
            $this->visible($student, $content, false);
        } finally {
            $this->db->table('student_profiles')->where('tenant_id', $foreign)->delete();
            $this->db->table('tenants')->where('id', $foreign)->delete();
        }
    }

    public function testNoncanonicalOrMalformedStoredFiltersFailClosedAcrossAllPaths(): void
    {
        $student = $this->studentProfile(2, 'new', false);
        $content = $this->content(['target_year_levels' => [2], 'target_student_categories' => ['new']]);
        $this->visible($student, $content, true);
        $cases = [
            ['target_year_levels', '["2"]'], ['target_year_levels', '[2,2]'], ['target_year_levels', '[2] '],
            ['target_year_levels', '[2,1]'], ['target_year_levels', 'null'], ['target_year_levels', '{}'],
            ['target_year_levels', '[]'], ['target_year_levels', 'not-json'],
            ['target_student_categories', '["new","bogus"]'], ['target_student_categories', '["new","new"]'],
            ['target_student_categories', '["New"]'], ['target_student_categories', 'null'],
        ];
        foreach ($cases as [$field, $value]) {
            $this->db->table('surveys')->where('tenant_id', $this->tenant)->where('id', $content['survey'])->update([$field => $value]);
            $this->db->table('announcements')->where('tenant_id', $this->tenant)->where('id', $content['notice'])->update([$field => $value]);
            $this->assertSame([], $this->surveys->available()['items']);
            $this->assertSame([], (new AnnouncementService())->portalListing()['announcements']);
            $this->assertSame([], (new NotificationService())->ownInbox()['items']);
            $this->rejected(fn () => $this->surveys->form($content['survey']), 503);
            $this->rejected(fn () => $this->surveys->submit($content['survey'], $this->validAnswers()), 503);
            $this->rejected(fn () => (new AnnouncementService())->show($content['notice']), 404);
            $canonical = $field === 'target_year_levels' ? '[2]' : '["new"]';
            $this->db->table('surveys')->where('id', $content['survey'])->update([$field => $canonical]);
            $this->db->table('announcements')->where('id', $content['notice'])->update([$field => $canonical]);
        }
        $this->login($this->admin);
        $draft = $this->surveys->create($this->payload(['audience' => 'students', 'target_year_levels' => [2]]));
        $this->db->table('surveys')->where('id', $draft['id'])->update(['target_year_levels' => '["2"]']);
        $this->rejected(fn () => $this->surveys->publish((int) $draft['id'], $draft['version']), 503);
        $this->assertSame('draft', $this->db->table('surveys')->where('id', $draft['id'])->get()->getRow('status'));
    }

    public function testForeignTenantProfileAndForgedSessionCannotSupplyMembership(): void
    {
        $student = $this->studentProfile(4, null, null);
        $content = $this->content(['target_student_categories' => ['graduating']]);
        $student['is_graduating'] = true;
        $student['year_level'] = 4;
        $student['enrollment_type'] = 'continuing';
        $this->visible($student, $content, false);
        $this->login($student);
        $actor = (new AuthenticatedUser())->current();
        $this->assertFalse(EngagementAudience::eligible($actor + ['is_graduating' => true], 'students', ['target_student_categories' => '["graduating"]']));
    }
}
