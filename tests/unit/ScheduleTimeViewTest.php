<?php

declare(strict_types=1);

namespace Tests\Unit;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class ScheduleTimeViewTest extends TestCase
{
    public function testInitialTimesShowTwelveHourValuesWithoutChangingSubmittedValues(): void
    {
        foreach (['00:00' => '12:00 AM', '12:00' => '12:00 PM',
            '08:05' => '08:05 AM', '17:45' => '05:45 PM',
            '23:59' => '11:59 PM'] as $value => $expected) {
            $xpath = $this->render($value);
            $this->assertSame($expected, $xpath->evaluate('string(//input[@id="testStart"]/@value)'));
            $this->assertSame($value, $xpath->evaluate('string(//input[@type="hidden"][@name="start_time"]/@value)'));
        }
    }

    public function testEmptyTimeUsesRequiredTimePickerAndNamedHiddenValue(): void
    {
        $xpath = $this->render('');
        $this->assertCount(1, $xpath->query('//input[@id="testStart"][@type="text"][@data-timepicker][@required]'));
        $this->assertSame('#testStartValue', $xpath->evaluate('string(//input[@id="testStart"]/@data-target)'));
        $this->assertSame('', $xpath->evaluate('string(//input[@id="testStart"]/@value)'));
        $this->assertCount(1, $xpath->query('//input[@id="testStartValue"][@type="hidden"][@name="start_time"]'));
        $this->assertSame('', $xpath->evaluate('string(//input[@id="testStartValue"]/@value)'));
        $this->assertCount(0, $xpath->query('//input[@type="text"][@name]'));
        $this->assertCount(0, $xpath->query('//select'));
    }

    public function testRepeatedBmgTimesKeepArrayNameAndAccessibleHelpIsOptional(): void
    {
        $xpath = $this->render('12:00', 'times[]', 'rotationHelp');
        $this->assertSame('times[]', $xpath->evaluate('string(//input[@type="hidden"]/@name)'));
        $this->assertSame('12:00', $xpath->evaluate('string(//input[@type="hidden"]/@value)'));
        $this->assertSame('rotationHelp', $xpath->evaluate('string(//input[@id="testStart"]/@aria-describedby)'));
        $this->assertCount(0, $this->render('')->query('//input[@aria-describedby]'));
    }

    private function render(string $value, string $name = 'start_time', ?string $describedBy = null): DOMXPath
    {
        $id = 'testStart';
        $label = 'Start time';
        ob_start();
        include __DIR__ . '/../../app/Views/partials/schedule_time.php';
        $html = ob_get_clean();
        $document = new DOMDocument();
        $document->loadHTML($html);

        return new DOMXPath($document);
    }
}
