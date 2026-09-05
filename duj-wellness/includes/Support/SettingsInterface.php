<?php

declare(strict_types=1);

namespace Duj\Wellness\Support;

interface SettingsInterface
{
    public function bufferMinutes(): int;
    public function defaultSlotMinutes(): int;
    public function holdMinutes(): int;
    public function cutoffEnabled(): bool;
    public function cutoffTime(): string;
    public function cutoffTzMode(): string;
    public function minLeadTimeMinutes(): int;
    public function calendarMonths(): int;
    public function stripeMode(): string;
    public function stripeSecretKey(): ?string;
    public function stripePublishableKey(): ?string;
    public function stripeWebhookSecret(): ?string;
    public function paymentCaptureMode(): string;
    public function enabledPaymentMethods(): array;
    public function defaultAccommodationPolicy(): string;
    public function stalePolicy(): string;
    public function accommodationStaleAfterDays(): int;
}
