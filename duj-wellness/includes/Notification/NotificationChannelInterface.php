<?php

declare(strict_types=1);

namespace Duj\Wellness\Notification;

interface NotificationChannelInterface
{
    /** Vrací true, pokud je kanál nakonfigurován a připraven k odesílání. */
    public function supports(): bool;

    /**
     * Odešle zprávu.
     *
     * @param string $to      Adresát (e-mail, telefon, chat_id…)
     * @param string $message Text zprávy (může obsahovat HTML u kanálů, které to podporují)
     * @param array  $ctx     Kontext (subject, attachments, booking_id…)
     * @throws \RuntimeException Pokud odeslání selže
     */
    public function send(string $to, string $message, array $ctx = []): void;
}
