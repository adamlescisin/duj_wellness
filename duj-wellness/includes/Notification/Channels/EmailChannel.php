<?php

declare(strict_types=1);

namespace Duj\Wellness\Notification\Channels;

use Duj\Wellness\Notification\NotificationChannelInterface;
use Duj\Wellness\Support\SettingsInterface;

/**
 * E-mailový kanál — odesílá přes wp_mail().
 *
 * Context klíče:
 *   subject      string  Předmět zprávy (povinné)
 *   html         string  HTML verze (povinné)
 *   text         string  Plaintext verze (fallback)
 *   attachments  array   [['name' => 'rezervace.ics', 'content' => '...']]
 */
final class EmailChannel implements NotificationChannelInterface
{
    public function __construct(
        private readonly SettingsInterface $settings,
    ) {}

    public function supports(): bool
    {
        return true; // wp_mail() je vždy k dispozici
    }

    public function send(string $to, string $message, array $ctx = []): void
    {
        $subject     = $ctx['subject'] ?? __('Rezervace wellness', 'duj-wellness');
        $html        = $ctx['html'] ?? nl2br(htmlspecialchars($message));
        $text        = $ctx['text'] ?? $message;
        $attachments = [];

        // ICS příloha — uložíme do temp souboru a předáme wp_mail
        $tmpFiles = [];
        foreach ($ctx['attachments'] ?? [] as $att) {
            if (isset($att['name'], $att['content'])) {
                $tmpPath = sys_get_temp_dir() . '/' . sanitize_file_name($att['name']);
                if (file_put_contents($tmpPath, $att['content']) !== false) {
                    $attachments[] = $tmpPath;
                    $tmpFiles[]    = $tmpPath;
                }
            }
        }

        $fromName  = function_exists('get_option') ? get_option('blogname', 'Domeček u Josefa') : 'Domeček u Josefa';
        $fromEmail = function_exists('get_option') ? get_option('admin_email', 'info@domecekujosefa.cz') : 'info@domecekujosefa.cz';

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            "From: {$fromName} <{$fromEmail}>",
        ];

        if (!empty($text)) {
            // wp_mail přijímá jen jeden typ těla — HTML posíláme jako tělo, text ignorujeme
            // Pro multipart/alternative by bylo nutné rozšíření (PHPMailer callback)
        }

        $sent = wp_mail($to, $subject, $html, $headers, $attachments);

        // Cleanup temp souborů
        foreach ($tmpFiles as $tmp) {
            @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }

        if (!$sent) {
            throw new \RuntimeException("wp_mail selhal pro příjemce: (masked)");
        }
    }
}
