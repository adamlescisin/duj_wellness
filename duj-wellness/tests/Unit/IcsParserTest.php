<?php

declare(strict_types=1);

namespace Duj\Wellness\Tests\Unit;

use Duj\Wellness\Accommodation\IcsParser;
use PHPUnit\Framework\TestCase;

final class IcsParserTest extends TestCase
{
    private IcsParser $parser;

    protected function setUp(): void
    {
        $this->parser = new IcsParser();
    }

    private function makeClassifier(string $returns = 'guests_only'): callable
    {
        return static fn(string $summary, string $description): string => $returns;
    }

    public function testParsesSimpleDateEvent(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'DTSTART;VALUE=DATE:20250610',
            'DTEND;VALUE=DATE:20250615',
            'SUMMARY:Test event',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $events = $this->parser->parse($ics, $this->makeClassifier('guests_only'));

        $this->assertCount(1, $events);
        $this->assertSame('2025-06-10', $events[0]->dtStart);
        $this->assertSame('2025-06-15', $events[0]->dtEnd);
        $this->assertSame('guests_only', $events[0]->policy);
    }

    public function testParsesDateTimeEvent(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'DTSTART:20250701T160000Z',
            'DTEND:20250703T100000Z',
            'SUMMARY:Guests',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $events = $this->parser->parse($ics, $this->makeClassifier('guests_only'));

        $this->assertCount(1, $events);
        $this->assertSame('2025-07-01', $events[0]->dtStart);
        $this->assertSame('2025-07-03', $events[0]->dtEnd);
    }

    public function testClassifierReceivesSummaryAndDescription(): void
    {
        $capturedSummary     = null;
        $capturedDescription = null;

        $classifier = function (string $summary, string $description) use (&$capturedSummary, &$capturedDescription): string {
            $capturedSummary     = $summary;
            $capturedDescription = $description;
            return 'closed';
        };

        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'DTSTART;VALUE=DATE:20250801',
            'DTEND;VALUE=DATE:20250802',
            'SUMMARY:Zavřeno údržba',
            'DESCRIPTION:Servis sudů',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $events = $this->parser->parse($ics, $classifier);

        $this->assertSame('Zavřeno údržba', $capturedSummary);
        $this->assertSame('Servis sudů', $capturedDescription);
        $this->assertSame('closed', $events[0]->policy);
    }

    public function testSkipsEventWithoutDtstart(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'DTEND;VALUE=DATE:20250802',
            'SUMMARY:Broken event',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $events = $this->parser->parse($ics, $this->makeClassifier());

        $this->assertCount(0, $events);
    }

    public function testSkipsEventWithoutDtend(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'DTSTART;VALUE=DATE:20250801',
            'SUMMARY:Broken event',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $events = $this->parser->parse($ics, $this->makeClassifier());

        $this->assertCount(0, $events);
    }

    public function testHandlesLineFolding(): void
    {
        // RFC 5545: continuation lines start with CRLF + space
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20250901\r\nDTEND;VALUE=DATE\r\n :20250905\r\nSUMMARY:Folded\r\nEND:VEVENT\r\nEND:VCALENDAR";

        $events = $this->parser->parse($ics, $this->makeClassifier('guests_only'));

        $this->assertCount(1, $events);
        $this->assertSame('2025-09-01', $events[0]->dtStart);
    }

    public function testUnknownPolicyDefaultsToGuestsOnly(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'DTSTART;VALUE=DATE:20251001',
            'DTEND;VALUE=DATE:20251002',
            'SUMMARY:Something',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        // Classifier returns invalid value
        $events = $this->parser->parse($ics, static fn() => 'unknown_value');

        $this->assertSame('guests_only', $events[0]->policy);
    }

    public function testParsesMultipleEvents(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'DTSTART;VALUE=DATE:20250610',
            'DTEND;VALUE=DATE:20250612',
            'SUMMARY:First',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'DTSTART;VALUE=DATE:20250620',
            'DTEND;VALUE=DATE:20250622',
            'SUMMARY:Second',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $events = $this->parser->parse($ics, $this->makeClassifier('guests_only'));

        $this->assertCount(2, $events);
        $this->assertSame('2025-06-10', $events[0]->dtStart);
        $this->assertSame('2025-06-20', $events[1]->dtStart);
    }
}
