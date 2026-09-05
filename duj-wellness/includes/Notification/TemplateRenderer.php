<?php

declare(strict_types=1);

namespace Duj\Wellness\Notification;

/**
 * Renderer e-mailových šablon.
 *
 * - Nahrazuje {{placeholder}} za hodnoty z $data.
 * - Hodnoty jsou prošly esc_html (pro HTML výstup).
 * - Generuje i plaintext verzi (strip_tags + obalení do 80 znaků).
 * - Obalí body_html do HTML layoutu (templates/emails/layout.php).
 */
final class TemplateRenderer
{
    private string $layoutPath;

    public function __construct(?string $layoutPath = null)
    {
        $this->layoutPath = $layoutPath ?? dirname(__DIR__, 2) . '/templates/emails/layout.php';
    }

    /**
     * Vykreslí šablonu a obalí ji do e-mailového layoutu.
     *
     * @param  string               $bodyHtml  Obsah šablony z DB (může obsahovat HTML)
     * @param  array<string,string> $data      Hodnoty pro nahrazení placeholderů
     * @return array{html: string, text: string}
     */
    public function render(string $bodyHtml, array $data): array
    {
        $escapedData = array_map(
            static fn(string $v): string => function_exists('esc_html') ? esc_html($v) : htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $data
        );

        $rendered = $this->replacePlaceholders($bodyHtml, $escapedData);
        $html     = $this->wrapInLayout($rendered, $data);
        $text     = $this->toPlaintext($rendered);

        return ['html' => $html, 'text' => $text];
    }

    /** Nahradí {{key}} za hodnotu. Klíče bez hodnoty zůstanou prázdné. */
    public function replacePlaceholders(string $template, array $data): string
    {
        return preg_replace_callback(
            '/\{\{([a-z0-9_]+)\}\}/i',
            static fn(array $m): string => $data[$m[1]] ?? '',
            $template
        ) ?? $template;
    }

    /**
     * Nahradí placeholdery v předmětu (bez esc_html — předmět se nezapisuje do HTML).
     */
    public function renderSubject(string $subject, array $data): string
    {
        return $this->replacePlaceholders($subject, $data);
    }

    private function wrapInLayout(string $bodyHtml, array $data): string
    {
        if (!file_exists($this->layoutPath)) {
            // Základní fallback layout
            return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $bodyHtml . '</body></html>';
        }

        $siteName = $data['site_name'] ?? 'Domeček u Josefa';
        $content  = $bodyHtml;

        ob_start();
        include $this->layoutPath;
        return ob_get_clean() ?: $bodyHtml;
    }

    private function toPlaintext(string $html): string
    {
        $text = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", trim($text)) ?? $text;
        return wordwrap($text, 80, "\n");
    }
}
