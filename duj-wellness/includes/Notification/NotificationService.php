<?php

declare(strict_types=1);

namespace Duj\Wellness\Notification;

use Duj\Wellness\Repository\BookingRow;
use Duj\Wellness\Support\SettingsInterface;

/**
 * Dispečer notifikací — odesílá přes kanály a loguje do duj_notifications.
 * Selhání kanálu NIKDY nepadí booking.
 */
final class NotificationService
{
    public function __construct(
        private readonly \wpdb $wpdb,
        private readonly SettingsInterface $settings,
        private readonly TemplateRenderer $renderer,
        private readonly IcsGenerator $icsGenerator,
        private readonly ActionTokenServiceInterface $tokenService,
        private readonly ?NotificationChannelInterface $emailChannel = null,
        private readonly ?NotificationChannelInterface $telegramChannel = null,
        private readonly ?NotificationChannelInterface $smsChannel = null,
    ) {}

    /**
     * Odešle zákazníkovi e-mail po přechodu do awaiting_confirmation.
     * Přidá ICS přílohu a confirm/cancel action tokeny.
     */
    public function sendAwaitingConfirmation(BookingRow $booking): void
    {
        $confirmToken = $this->tokenService->create($booking->id, 'confirm');
        $cancelToken  = $this->tokenService->create($booking->id, 'cancel');

        $baseUrl    = function_exists('home_url') ? home_url('/') : 'https://example.com/';
        $confirmUrl = $this->buildActionUrl($baseUrl, 'confirm', $confirmToken);
        $cancelUrl  = $this->buildActionUrl($baseUrl, 'cancel', $cancelToken);

        $siteName = function_exists('get_option') ? (string) get_option('blogname', 'Domeček u Josefa') : 'Domeček u Josefa';
        $siteUrl  = function_exists('home_url') ? home_url('/') : 'https://example.com/';

        $data = [
            'site_name'    => $siteName,
            'reference'    => $booking->reference,
            'booking_date' => $booking->bookingDate,
            'slot_from'    => $booking->slotFrom,
            'slot_to'      => $booking->slotTo,
            'resource'     => $booking->comboKey,
            'confirm_url'  => $confirmUrl,
            'cancel_url'   => $cancelUrl,
        ];

        $subjectTpl = (string) $this->settings->get('email_subject_awaiting', 'Vaše rezervace {{reference}} čeká na potvrzení');
        $bodyTpl    = $this->getTemplate('awaiting_confirmation');
        $subject    = $this->renderer->renderSubject($subjectTpl, $data);
        $rendered   = $this->renderer->render($bodyTpl, $data);
        $icsContent = $this->icsGenerator->forBooking($booking, $siteName, $siteUrl);

        $ctx = [
            'subject'     => $subject,
            'html'        => $rendered['html'],
            'text'        => $rendered['text'],
            'attachments' => [['name' => 'rezervace.ics', 'content' => $icsContent]],
        ];

        $this->dispatch('email', $booking->customerEmail, $rendered['text'], $ctx, $booking->id, 'awaiting_confirmation');
    }

    /**
     * Odešle zákazníkovi e-mail po potvrzení rezervace.
     */
    public function sendConfirmed(BookingRow $booking): void
    {
        $siteName = function_exists('get_option') ? (string) get_option('blogname', 'Domeček u Josefa') : 'Domeček u Josefa';

        $data = [
            'site_name'    => $siteName,
            'reference'    => $booking->reference,
            'booking_date' => $booking->bookingDate,
            'slot_from'    => $booking->slotFrom,
            'slot_to'      => $booking->slotTo,
            'resource'     => $booking->comboKey,
        ];

        $subjectTpl = (string) $this->settings->get('email_subject_confirmed', 'Rezervace {{reference}} potvrzena');
        $bodyTpl    = $this->getTemplate('confirmed');
        $subject    = $this->renderer->renderSubject($subjectTpl, $data);
        $rendered   = $this->renderer->render($bodyTpl, $data);

        $ctx = [
            'subject' => $subject,
            'html'    => $rendered['html'],
            'text'    => $rendered['text'],
        ];

        $this->dispatch('email', $booking->customerEmail, $rendered['text'], $ctx, $booking->id, 'confirmed');
    }

    /**
     * Odešle zákazníkovi e-mail po zrušení rezervace.
     */
    public function sendCancelled(BookingRow $booking): void
    {
        $siteName = function_exists('get_option') ? (string) get_option('blogname', 'Domeček u Josefa') : 'Domeček u Josefa';

        $data = [
            'site_name'    => $siteName,
            'reference'    => $booking->reference,
            'booking_date' => $booking->bookingDate,
            'slot_from'    => $booking->slotFrom,
            'slot_to'      => $booking->slotTo,
            'resource'     => $booking->comboKey,
        ];

        $subjectTpl = (string) $this->settings->get('email_subject_cancelled', 'Rezervace {{reference}} zrušena');
        $bodyTpl    = $this->getTemplate('cancelled');
        $subject    = $this->renderer->renderSubject($subjectTpl, $data);
        $rendered   = $this->renderer->render($bodyTpl, $data);

        $ctx = [
            'subject' => $subject,
            'html'    => $rendered['html'],
            'text'    => $rendered['text'],
        ];

        $this->dispatch('email', $booking->customerEmail, $rendered['text'], $ctx, $booking->id, 'cancelled');
    }

    /**
     * Odešle Telegram notifikaci operátorovi při nové rezervaci.
     */
    public function sendTelegramNewBooking(BookingRow $booking, string $confirmUrl, string $rejectUrl): void
    {
        $text = sprintf(
            "🆕 <b>Nová rezervace</b>\n" .
            "Ref: <code>%s</code>\n" .
            "Datum: %s %s–%s\n" .
            "Prostředí: %s\n" .
            "Zákazník: %s",
            htmlspecialchars($booking->reference, ENT_XML1),
            htmlspecialchars($booking->bookingDate, ENT_XML1),
            htmlspecialchars($booking->slotFrom, ENT_XML1),
            htmlspecialchars($booking->slotTo, ENT_XML1),
            htmlspecialchars($booking->comboKey, ENT_XML1),
            htmlspecialchars($booking->customerEmail, ENT_XML1),
        );

        $keyboard = [[
            ['text' => '✅ Potvrdit', 'url' => $confirmUrl],
            ['text' => '❌ Zamítnout', 'url' => $rejectUrl],
        ]];

        $chatId = (string) $this->settings->get('telegram_chat_id', '');

        $this->dispatch('telegram', $chatId, $text, ['inline_keyboard' => $keyboard], $booking->id, 'new_booking');
    }

    /**
     * Dispatchuje přes kanál daného typu a loguje výsledek.
     * Selhání se zaloguje, ale nevyhazuje výjimku.
     */
    private function dispatch(string $channelType, string $to, string $message, array $ctx, int $bookingId, string $event): void
    {
        $channel = match ($channelType) {
            'email'    => $this->emailChannel,
            'telegram' => $this->telegramChannel,
            'sms'      => $this->smsChannel,
            default    => null,
        };

        if ($channel === null || !$channel->supports()) {
            $this->log($bookingId, $channelType, $event, 'skipped', 'no_channel');
            return;
        }

        $error = null;
        try {
            $channel->send($to, $message, $ctx);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $this->log($bookingId, $channelType, $event, $error === null ? 'sent' : 'failed', $error);
    }

    private function log(int $bookingId, string $channel, string $event, string $status, ?string $error): void
    {
        $table = $this->wpdb->prefix . 'duj_notifications';
        $this->wpdb->insert($table, [
            'booking_id' => $bookingId,
            'channel'    => $channel,
            'event'      => $event,
            'status'     => $status,
            'error'      => $error,
            'sent_at'    => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);
    }

    private function getTemplate(string $name): string
    {
        $stored = $this->settings->get("email_template_{$name}", '');
        if ($stored !== '' && $stored !== false && $stored !== null) {
            return (string) $stored;
        }

        $file = dirname(__DIR__, 2) . "/templates/emails/{$name}.php";
        if (!file_exists($file)) {
            return '';
        }

        ob_start();
        include $file;
        return ob_get_clean() ?: '';
    }

    private function buildActionUrl(string $base, string $action, string $token): string
    {
        if (function_exists('add_query_arg')) {
            return add_query_arg(['duj_action' => $action, 'token' => $token], $base);
        }
        $sep = str_contains($base, '?') ? '&' : '?';
        return $base . $sep . 'duj_action=' . urlencode($action) . '&token=' . urlencode($token);
    }
}
