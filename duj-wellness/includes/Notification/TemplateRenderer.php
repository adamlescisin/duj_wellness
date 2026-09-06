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
            return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $bodyHtml . '</body></html>';
        }

        $siteName = $data['site_name'] ?? 'Domeček u Josefa';
        $logoUrl  = isset($data['logo_url']) && $data['logo_url'] !== ''
            ? (function_exists('esc_url') ? esc_url($data['logo_url']) : htmlspecialchars($data['logo_url'], ENT_QUOTES, 'UTF-8'))
            : '';
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

    /** Výchozí šablona (subject + body) pro daný slug. */
    public static function getDefaults(string $slug): array
    {
        $defaults = [
            'awaiting_confirmation' => [
                'subject' => 'Vaše rezervace {{reference}} čeká na potvrzení',
                'body'    => "Dobrý den,\n\nPřijali jsme vaši rezervaci {{reference}} na {{booking_date}} ({{slot_from}}–{{slot_to}}).\n\nRezerváci potvrdíme nejpozději do 24 hodin.\n\nS pozdravem\n{{site_name}}",
            ],
            'confirmed' => [
                'subject' => 'Rezervace {{reference}} potvrzena',
                'body'    => "Dobrý den,\n\nVaše rezervace {{reference}} na {{booking_date}} ({{slot_from}}–{{slot_to}}) byla potvrzena.\n\nTěšíme se na vaši návštěvu!\n\n{{site_name}}",
            ],
            'cancelled' => [
                'subject' => 'Rezervace {{reference}} zrušena',
                'body'    => "Dobrý den,\n\nVaše rezervace {{reference}} na {{booking_date}} byla zrušena.\n\nV případě dotazů nás kontaktujte na {{contact_email}}.\n\n{{site_name}}",
            ],
            'admin_booking_new' => [
                'subject' => '[Admin] Nová rezervace {{reference}}',
                'body'    => "Nová rezervace:\n\nRef: {{reference}}\nDatum: {{booking_date}} {{slot_from}}–{{slot_to}}\nSlužba: {{combo_key}}\nZákazník: {{customer_name}} ({{customer_email}})\nCena: {{amount}} {{currency}}\n",
            ],
            'reminder' => [
                'subject' => 'Připomínka: rezervace {{reference}} zítra',
                'body'    => "Dobrý den,\n\nPřipomínáme vaši rezervaci {{reference}} zítra {{booking_date}} v {{slot_from}}.\n\nAdresa: {{address}}\n\n{{site_name}}",
            ],
            'auth_expiring' => [
                'subject' => 'Váš přístupový kód expiruje',
                'body'    => "Dobrý den,\n\nVáš přístupový kód k wellness expiruje {{valid_to}}.\n\nPokud potřebujete prodloužení, kontaktujte nás na {{contact_email}}.\n\n{{site_name}}",
            ],
            'completed' => [
                'subject' => 'Děkujeme za návštěvu! ({{reference}})',
                'body'    => "Dobrý den,\n\nDěkujeme za vaši návštěvu {{booking_date}}.\n\nTěšíme se na vás opět!\n\n{{site_name}}",
            ],
            'admin_auth_expiring' => [
                'subject' => '[Admin] Přístupový kód expiruje',
                'body'    => "Přístupový kód {{code}} ({{tier_slug}}) expiruje {{valid_to}}.",
            ],
        ];

        return $defaults[$slug] ?? ['subject' => '', 'body' => ''];
    }

    /** Ukázkové hodnoty placeholderů pro test e-mail. */
    public static function getSamplePlaceholders(): array
    {
        return [
            '{{reference}}'      => 'WEL-20260905-ABC1',
            '{{booking_date}}'   => '2026-09-20',
            '{{slot_from}}'      => '16:00',
            '{{slot_to}}'        => '18:00',
            '{{combo_key}}'      => 'sud+sauna',
            '{{customer_name}}'  => 'Jan Novák',
            '{{customer_email}}' => 'jan@example.cz',
            '{{customer_phone}}' => '+420 600 000 000',
            '{{amount}}'         => '2000',
            '{{currency}}'       => 'CZK',
            '{{site_name}}'      => 'Domeček u Josefa',
            '{{contact_email}}'  => 'info@domecekujosefa.cz',
            '{{address}}'        => 'Příkladná 1, 123 45 Obec',
            '{{valid_to}}'       => '2026-12-31',
            '{{code}}'           => 'ABCD1234',
            '{{tier_slug}}'      => 'guest',
        ];
    }
}
