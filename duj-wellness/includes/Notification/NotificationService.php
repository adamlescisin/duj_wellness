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
            'logo_url'     => (string) $this->settings->get('logo_url', ''),
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
            'logo_url'     => (string) $this->settings->get('logo_url', ''),
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
            'logo_url'     => (string) $this->settings->get('logo_url', ''),
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
     * Odešle adminu Telegram notifikaci a e-maily o nové rezervaci.
     * Akční tokeny vytváří interně.
     */
    public function sendAdminNewBooking(BookingRow $booking): void
    {
        $baseUrl    = function_exists('home_url') ? home_url('/') : 'https://example.com/';
        $confirmUrl = $this->buildActionUrl($baseUrl, 'confirm', $this->tokenService->create($booking->id, 'confirm'));
        $rejectUrl  = $this->buildActionUrl($baseUrl, 'reject', $this->tokenService->create($booking->id, 'reject'));

        $this->sendTelegramNewBooking($booking, $confirmUrl, $rejectUrl);
        $this->sendAdminEmailNewBooking($booking, $confirmUrl, $rejectUrl);
    }

    private function sendAdminEmailNewBooking(BookingRow $booking, string $confirmUrl, string $rejectUrl): void
    {
        $emails = method_exists($this->settings, 'adminNotifyEmails')
            ? $this->settings->adminNotifyEmails()
            : [];

        if ($emails === []) {
            return;
        }

        $siteName = function_exists('get_option') ? (string) get_option('blogname', 'Domeček u Josefa') : 'Domeček u Josefa';
        $amount   = number_format($booking->amountMinor / 100, 2, ',', "\u{a0}");

        $paymentLabels = [
            'stripe_card'  => 'Platba kartou',
            'qr_checkout'  => 'QR / Stripe Checkout',
            'bank_transfer' => 'Bankovní převod',
        ];

        $data = [
            'site_name'      => $siteName,
            'logo_url'       => (string) $this->settings->get('logo_url', ''),
            'reference'      => $booking->reference,
            'booking_date'   => $booking->bookingDate,
            'slot_from'      => $booking->slotFrom,
            'slot_to'        => $booking->slotTo,
            'resource'       => $booking->comboKey,
            'customer_name'  => $booking->customerName ?? '',
            'customer_email' => $booking->customerEmail,
            'customer_phone' => $booking->customerPhone ?? '',
            'payment_method' => $paymentLabels[$booking->paymentMethod] ?? $booking->paymentMethod,
            'amount'         => $amount,
            'confirm_url'    => $confirmUrl,
            'reject_url'     => $rejectUrl,
        ];

        $subjectTpl = (string) $this->settings->get(
            'email_subject_admin_new_booking',
            '[Wellness] Nová rezervace {{reference}}'
        );
        $bodyTpl  = $this->getTemplate('admin_new_booking');
        $subject  = $this->renderer->renderSubject($subjectTpl, $data);
        $rendered = $this->renderer->render($bodyTpl, $data);

        $ctx = [
            'subject' => $subject,
            'html'    => $rendered['html'],
            'text'    => $rendered['text'],
        ];

        foreach ($emails as $email) {
            $this->dispatch('email', $email, $rendered['text'], $ctx, $booking->id, 'admin_new_booking');
        }
    }

    /**
     * Odešle zákazníkovi e-mail s platebními instrukcemi pro bankovní převod.
     *
     * @param array{iban?:string,account_number?:string,variable_symbol?:string,hold_expires_at?:string,hold_hours?:int} $payment
     */
    public function sendBankTransferInstructions(BookingRow $booking, array $payment): void
    {
        $siteName = function_exists('get_option') ? (string) get_option('blogname', 'Domeček u Josefa') : 'Domeček u Josefa';

        $amount = number_format($booking->amountMinor / 100, 2, ',', "\u{a0}");
        $iban   = $payment['iban'] ?? '';
        $vs     = $payment['variable_symbol'] ?? preg_replace('/[^0-9]/', '', $booking->reference);

        $qrGen     = new \Duj\Wellness\Payment\QrPaymentGenerator();
        $spdString = $iban !== ''
            ? $qrGen->generate($iban, $booking->amountMinor, $vs, 'Wellness ' . $booking->reference)
            : '';
        $qrDataUri = $qrGen->toDataUri($spdString);

        $data = [
            'site_name'       => $siteName,
            'logo_url'        => (string) $this->settings->get('logo_url', ''),
            'reference'       => $booking->reference,
            'booking_date'    => $booking->bookingDate,
            'slot_from'       => $booking->slotFrom,
            'slot_to'         => $booking->slotTo,
            'resource'        => $booking->comboKey,
            'amount'          => $amount,
            'iban'            => $iban,
            'account_number'  => $payment['account_number'] ?? '',
            'variable_symbol' => $vs,
            'deadline'        => isset($payment['hold_expires_at'])
                ? (new \DateTimeImmutable($payment['hold_expires_at'], new \DateTimeZone('Europe/Prague')))->format('j. n. Y H:i')
                : '',
        ];

        $subjectTpl = (string) $this->settings->get(
            'email_subject_bank_transfer',
            'Platební instrukce k rezervaci {{reference}}'
        );
        $bodyTpl = $this->getTemplate('bank_transfer_instructions');
        $bodyTpl = $this->injectQrBlock($bodyTpl, $qrDataUri);
        $subject  = $this->renderer->renderSubject($subjectTpl, $data);
        $rendered = $this->renderer->render($bodyTpl, $data);

        $ctx = [
            'subject' => $subject,
            'html'    => $rendered['html'],
            'text'    => $rendered['text'],
        ];

        $this->dispatch('email', $booking->customerEmail, $rendered['text'], $ctx, $booking->id, 'bank_transfer_instructions');
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

    /**
     * Nahradí {{qr_block}} v šabloně za HTML blok s QR kódem (nebo prázdný řetězec).
     * Injekce před voláním rendereru, aby esc_html nepoškodilo HTML obrázku.
     */
    private function injectQrBlock(string $template, string $qrDataUri): string
    {
        if ($qrDataUri === '') {
            return str_replace('{{qr_block}}', '', $template);
        }

        $block = '<div style="text-align:center;margin:1.5rem 0">'
            . '<p style="font-weight:600;margin-bottom:0.5rem">QR kód pro platbu:</p>'
            . '<img src="' . $qrDataUri . '" alt="QR platba" width="220" height="220"'
            . ' style="display:block;margin:0 auto;border:1px solid #e5e7eb;border-radius:8px">'
            . '<p style="font-size:12px;color:#6b7280;margin-top:0.5rem">'
            . 'Naskenujte kód svou bankovní aplikací (QR Platba)</p>'
            . '</div>';

        return str_replace('{{qr_block}}', $block, $template);
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
