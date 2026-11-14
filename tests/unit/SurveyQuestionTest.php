<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Guidance\SurveyQuestion;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class SurveyQuestionTest extends CIUnitTestCase
{
    public static function invalidQuestions(): iterable
    {
        $base = ['type' => 'text', 'label' => 'Feedback', 'required' => true, 'options' => []];
        yield 'not an object' => ['invalid'];
        foreach (['type' => 'unknown', 'label' => '', 'required' => 'yes', 'options' => ['unexpected'], 'extra' => 'injected'] as $key => $value) {
            yield $key => [array_replace($base, [$key => $value])];
        }
        yield 'long label' => [array_replace($base, ['label' => str_repeat('x', 501)])];
        yield 'invalid encoding' => [array_replace($base, ['label' => "\xff"])];
        yield 'missing revision' => [$base + ['bank_id' => 1]];
        yield 'missing source' => [$base + ['bank_revision' => 1]];
        yield 'negative source' => [$base + ['bank_id' => -1, 'bank_revision' => 1]];
        yield 'decimal source' => [$base + ['bank_id' => '1.2', 'bank_revision' => 1]];
        yield 'overflow source' => [$base + ['bank_id' => str_repeat('9', 40), 'bank_revision' => 1]];
        yield 'zero revision' => [$base + ['bank_id' => 1, 'bank_revision' => 0]];
        yield 'one choice' => [array_replace($base, ['type' => 'single', 'options' => ['A']])];
        yield 'duplicate choices' => [array_replace($base, ['type' => 'single', 'options' => ['A', ' A ']])];
        yield 'too many choices' => [array_replace($base, ['type' => 'multiple', 'options' => range(1, 11)])];
        yield 'non-list choices' => [array_replace($base, ['type' => 'single', 'options' => [1 => 'A', 3 => 'B']])];
        yield 'long choice' => [array_replace($base, ['type' => 'single', 'options' => ['A', str_repeat('x', 201)]])];
        yield 'scale label on text' => [array_replace($base, ['type' => 'text', 'scale_min_label' => 'Lowest'])];
        yield 'scale max too long' => [array_replace($base, ['type' => 'rating', 'scale_max_label' => str_repeat('z', 101)])];
        yield 'invalid allow_other' => [array_replace($base, ['type' => 'single', 'options' => ['A', 'B'], 'allow_other' => 'yes'])];
        yield 'allow_other on text' => [array_replace($base, ['type' => 'text', 'allow_other' => true])];
        yield 'allow_other on rating' => [array_replace($base, ['type' => 'rating', 'allow_other' => '1'])];
    }

    #[DataProvider('invalidQuestions')]
    public function testInvalidQuestionContractsAreRejected(mixed $question): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        SurveyQuestion::normalize($question);
    }

    public function testSourceMetadataIsNormalizedButNotAcceptedInsideBankRevisions(): void
    {
        $input = ['type' => 'text', 'label' => ' Feedback ', 'required' => '0', 'options' => [], 'bank_id' => '7', 'bank_revision' => '3'];
        $normalized = SurveyQuestion::normalize($input);
        $this->assertSame('Feedback', $normalized['label']);
        $this->assertFalse($normalized['required']);
        $this->assertSame(7, $normalized['bank_id']);
        $this->assertSame(3, $normalized['bank_revision']);
        $this->expectException(RuntimeException::class);
        SurveyQuestion::normalize($input, false);
    }

    public function testRatingQuestionAllowsOptionalScaleAnchorLabels(): void
    {
        $unlabeled = SurveyQuestion::normalize(['type' => 'rating', 'label' => 'Rate service', 'required' => true, 'options' => []], false);
        $this->assertSame(['type' => 'rating', 'label' => 'Rate service', 'required' => true, 'options' => []], $unlabeled);

        $labeled = SurveyQuestion::normalize([
            'type'            => 'rating',
            'label'           => 'Rate stress level',
            'required'        => '1',
            'options'         => [],
            'scale_min_label' => ' Lowest (No stress) ',
            'scale_max_label' => ' Highest (Severe) ',
        ], false);
        $this->assertSame('Lowest (No stress)', $labeled['scale_min_label']);
        $this->assertSame('Highest (Severe)', $labeled['scale_max_label']);
    }

    public function testChoiceQuestionsSupportOptionalAllowOtherFlagAndHelpers(): void
    {
        $single = SurveyQuestion::normalize([
            'type'        => 'single',
            'label'       => 'Preferred contact',
            'required'    => '1',
            'options'     => ['Email', 'SMS'],
            'allow_other' => '1',
        ], false);
        $this->assertTrue($single['allow_other']);

        $withoutOther = SurveyQuestion::normalize([
            'type'        => 'multiple',
            'label'       => 'Topics',
            'required'    => '0',
            'options'     => ['Study', 'Sleep'],
            'allow_other' => '0',
        ], false);
        $this->assertArrayNotHasKey('allow_other', $withoutOther);

        $this->assertTrue(SurveyQuestion::isOtherOptionLabel('Other'));
        $this->assertTrue(SurveyQuestion::isOtherOptionLabel('Others (please specify)'));
        $this->assertFalse(SurveyQuestion::isOtherOptionLabel('Other students in class'));
        $this->assertSame(1, SurveyQuestion::otherOptionIndex([
            'type'    => 'single',
            'options' => ['Email', 'Other (please specify)'],
        ]));
    }
}
