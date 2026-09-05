<?php

declare(strict_types=1);

namespace Duj\Wellness\Tests\Unit;

use Duj\Wellness\Notification\ActionTokenServiceInterface;
use Duj\Wellness\Notification\IcsGenerator;
use Duj\Wellness\Notification\NotificationChannelInterface;
use Duj\Wellness\Notification\NotificationService;
use Duj\Wellness\Notification\TemplateRenderer;
use Duj\Wellness\Repository\BookingRow;
use Duj\Wellness\Support\SettingsInterface;
use PHPUnit\Framework\TestCase;

final class NotificationServiceTest extends TestCase
{
    private function makeBooking(): BookingRow
    {
        return new BookingRow(
            id:              1,
            uuid:            'test-uuid-1234',
            reference:       'REF-001',
            bookingDate:     '2026-09-10',
            slotFrom:        '10:00:00',
            slotTo:          '12:00:00',
            comboKey:        'sauna',
            guests:          null,
            status:          'awaiting_confirmation',
            tierSlug:        'public',
            accessCode:      null,
            amountMinor:     150000,
            currency:        'CZK',
            customerName:    'Jan Novák',
            customerEmail:   'test@example.com',
            customerPhone:   '+420777000000',
            customerNote:    null,
            adminNote:       null,
            paymentMethod:   'stripe_card',
            paymentStatus:   'authorized',
            paymentProvider: 'stripe',
            paymentIntentId: 'pi_test123',
            paymentMeta:     null,
            holdExpiresAt:   '2026-09-10 10:30:00',
            authExpiresAt:   null,
            confirmedAt:     null,
            confirmedBy:     null,
            cancelledAt:     null,
            cancelReason:    null,
            consentAt:       null,
            source:          'web',
            locale:          'cs_CZ',
            createdAt:       '2026-09-10 09:00:00',
            updatedAt:       '2026-09-10 09:00:00',
        );
    }

    private function makeSettings(array $values = []): SettingsInterface
    {
        $settings = $this->createMock(SettingsInterface::class);
        $settings->method('get')->willReturnCallback(static function (string $key, mixed $default = '') use ($values) {
            return $values[$key] ?? $default;
        });
        return $settings;
    }

    private function makeWpdb(array &$insertLog = []): \wpdb
    {
        $wpdb = $this->createMock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        $wpdb->method('insert')->willReturnCallback(function (string $table, array $data) use (&$insertLog) {
            $insertLog[] = $data;
            return 1;
        });
        $wpdb->method('prepare')->willReturnArgument(0);
        $wpdb->method('get_row')->willReturn(null);
        return $wpdb;
    }

    private function makeChannel(bool $supports = true, ?\Throwable $throws = null): NotificationChannelInterface
    {
        $channel = $this->createMock(NotificationChannelInterface::class);
        $channel->method('supports')->willReturn($supports);
        if ($throws !== null) {
            $channel->method('send')->willThrowException($throws);
        }
        return $channel;
    }

    private function makeService(\wpdb $wpdb, SettingsInterface $settings, ?NotificationChannelInterface $emailChannel = null): NotificationService
    {
        $tokenSvc = $this->createMock(ActionTokenServiceInterface::class);
        $tokenSvc->method('create')->willReturn(str_repeat('a', 64));

        return new NotificationService(
            $wpdb,
            $settings,
            new TemplateRenderer('/tmp/nonexistent-layout.php'),
            new IcsGenerator(),
            $tokenSvc,
            $emailChannel,
            null,
        );
    }

    public function testSendAwaitingConfirmationUsesEmailChannelAndLogs(): void
    {
        $insertLog = [];
        $wpdb      = $this->makeWpdb($insertLog);

        $emailSent = false;
        $channel   = $this->createMock(NotificationChannelInterface::class);
        $channel->method('supports')->willReturn(true);
        $channel->method('send')->willReturnCallback(function () use (&$emailSent) {
            $emailSent = true;
        });

        $svc = $this->makeService($wpdb, $this->makeSettings(), $channel);
        $svc->sendAwaitingConfirmation($this->makeBooking());

        self::assertTrue($emailSent, 'Email should be sent');

        $notifLog = array_filter($insertLog, fn(array $r) => ($r['channel'] ?? '') === 'email');
        self::assertNotEmpty($notifLog, 'Notification log row expected');
        self::assertSame('sent', array_values($notifLog)[0]['status']);
        self::assertSame('awaiting_confirmation', array_values($notifLog)[0]['event']);
    }

    public function testChannelFailureDoesNotThrow(): void
    {
        $insertLog = [];
        $wpdb      = $this->makeWpdb($insertLog);
        $channel   = $this->makeChannel(true, new \RuntimeException('SMTP fail'));

        $svc = $this->makeService($wpdb, $this->makeSettings(), $channel);

        // Must not throw
        $svc->sendAwaitingConfirmation($this->makeBooking());

        $notifLog = array_filter($insertLog, fn(array $r) => ($r['channel'] ?? '') === 'email');
        self::assertNotEmpty($notifLog);
        self::assertSame('failed', array_values($notifLog)[0]['status']);
    }

    public function testSkippedWhenChannelDoesNotSupport(): void
    {
        $insertLog = [];
        $wpdb      = $this->makeWpdb($insertLog);
        $channel   = $this->makeChannel(false);

        $svc = $this->makeService($wpdb, $this->makeSettings(), $channel);
        $svc->sendAwaitingConfirmation($this->makeBooking());

        $notifLog = array_filter($insertLog, fn(array $r) => ($r['channel'] ?? '') === 'email');
        self::assertNotEmpty($notifLog);
        self::assertSame('skipped', array_values($notifLog)[0]['status']);
    }

    public function testSkippedWhenNoEmailChannel(): void
    {
        $insertLog = [];
        $wpdb      = $this->makeWpdb($insertLog);

        $svc = $this->makeService($wpdb, $this->makeSettings(), null);
        $svc->sendAwaitingConfirmation($this->makeBooking());

        $notifLog = array_filter($insertLog, fn(array $r) => ($r['channel'] ?? '') === 'email');
        self::assertNotEmpty($notifLog);
        self::assertSame('skipped', array_values($notifLog)[0]['status']);
    }

    public function testSendConfirmedLogs(): void
    {
        $insertLog = [];
        $wpdb      = $this->makeWpdb($insertLog);
        $channel   = $this->makeChannel(true);

        $svc = $this->makeService($wpdb, $this->makeSettings(), $channel);
        $svc->sendConfirmed($this->makeBooking());

        $notifLog = array_filter($insertLog, fn(array $r) => ($r['event'] ?? '') === 'confirmed');
        self::assertNotEmpty($notifLog);
        self::assertSame('sent', array_values($notifLog)[0]['status']);
    }

    public function testSendCancelledLogs(): void
    {
        $insertLog = [];
        $wpdb      = $this->makeWpdb($insertLog);
        $channel   = $this->makeChannel(true);

        $svc = $this->makeService($wpdb, $this->makeSettings(), $channel);
        $svc->sendCancelled($this->makeBooking());

        $notifLog = array_filter($insertLog, fn(array $r) => ($r['event'] ?? '') === 'cancelled');
        self::assertNotEmpty($notifLog);
        self::assertSame('sent', array_values($notifLog)[0]['status']);
    }
}
