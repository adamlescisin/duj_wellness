<?php

declare(strict_types=1);

namespace Duj\Wellness\Tests\Unit;

use Duj\Wellness\Notification\TemplateRenderer;
use PHPUnit\Framework\TestCase;

final class TemplateRendererTest extends TestCase
{
    private TemplateRenderer $renderer;

    protected function setUp(): void
    {
        // Pass a non-existent layout path to use fallback
        $this->renderer = new TemplateRenderer('/tmp/nonexistent-layout.php');
    }

    public function testReplacePlaceholders(): void
    {
        $result = $this->renderer->replacePlaceholders(
            'Dobrý den, {{name}}! Vaše ref: {{reference}}.',
            ['name' => 'Jan', 'reference' => 'REF-001']
        );

        self::assertSame('Dobrý den, Jan! Vaše ref: REF-001.', $result);
    }

    public function testMissingPlaceholderBecomesEmpty(): void
    {
        $result = $this->renderer->replacePlaceholders('Hello {{missing}}!', []);
        self::assertSame('Hello !', $result);
    }

    public function testRenderSubjectNoEscaping(): void
    {
        $subject = $this->renderer->renderSubject('Rezervace {{reference}} potvrzena', ['reference' => 'REF-001']);
        self::assertSame('Rezervace REF-001 potvrzena', $subject);
    }

    public function testRenderEscapesHtmlInValues(): void
    {
        $result = $this->renderer->render('<p>{{content}}</p>', ['content' => '<script>xss</script>']);
        // HTML escaping applied
        self::assertStringContainsString('&lt;script&gt;', $result['html']);
        self::assertStringNotContainsString('<script>', $result['html']);
    }

    public function testRenderReturnsHtmlAndText(): void
    {
        $result = $this->renderer->render('<p>Hello <strong>World</strong></p>', ['ref' => 'X']);
        self::assertArrayHasKey('html', $result);
        self::assertArrayHasKey('text', $result);
        self::assertStringContainsString('Hello', $result['text']);
        self::assertStringNotContainsString('<p>', $result['text']);
        self::assertStringNotContainsString('<strong>', $result['text']);
    }

    public function testToPlaintextStripsTagsAndWraps(): void
    {
        $result = $this->renderer->render(
            '<p>' . str_repeat('a ', 50) . '</p>',
            []
        );
        // Each line should be at most 80 chars
        foreach (explode("\n", $result['text']) as $line) {
            self::assertLessThanOrEqual(80, strlen($line));
        }
    }

    public function testRenderUsesLayoutFallbackWhenFileMissing(): void
    {
        $result = $this->renderer->render('<p>Test</p>', []);
        self::assertStringContainsString('<!DOCTYPE html>', $result['html']);
        self::assertStringContainsString('<p>Test</p>', $result['html']);
    }
}
