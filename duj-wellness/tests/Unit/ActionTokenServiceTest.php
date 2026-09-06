<?php

declare(strict_types=1);

namespace Duj\Wellness\Tests\Unit;

use Duj\Wellness\Notification\ActionTokenService;
use PHPUnit\Framework\TestCase;

final class ActionTokenServiceTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new \wpdb();
    }

    public function testCreateReturnsPlainte64HexToken(): void
    {
        $svc   = new ActionTokenService($this->wpdb);
        $token = $svc->create(1, 'confirm');

        // bin2hex(random_bytes(32)) produces exactly 64 hex chars
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testCreateStoresHashNotPlaintext(): void
    {
        $insertedData = [];

        $wpdb = $this->createMock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        $wpdb->method('insert')->willReturnCallback(function (string $table, array $data) use (&$insertedData) {
            $insertedData = $data;
            return 1;
        });

        $svc   = new ActionTokenService($wpdb);
        $token = $svc->create(42, 'cancel');

        self::assertArrayHasKey('token_hash', $insertedData);
        self::assertNotSame($token, $insertedData['token_hash']);
        self::assertSame(hash('sha256', $token), $insertedData['token_hash']);
        self::assertSame(42, $insertedData['booking_id']);
        self::assertSame('cancel', $insertedData['action']);
        self::assertNull($insertedData['used_at']);
    }

    public function testConsumeReturnsNullWhenNotFound(): void
    {
        $wpdb = $this->createMock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        $wpdb->method('prepare')->willReturnArgument(0);
        $wpdb->method('get_row')->willReturn(null);

        $svc    = new ActionTokenService($wpdb);
        $result = $svc->consume('nonexistent_token', '127.0.0.1');

        self::assertNull($result);
    }

    public function testConsumeReturnsNullWhenAlreadyUsed(): void
    {
        $row = [
            'id'         => 1,
            'booking_id' => 5,
            'action'     => 'confirm',
            'expires_at' => (new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'used_at'    => '2025-01-01 10:00:00',
        ];

        $wpdb = $this->createMock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        $wpdb->method('prepare')->willReturnArgument(0);
        $wpdb->method('get_row')->willReturn($row);

        $svc    = new ActionTokenService($wpdb);
        $result = $svc->consume('sometoken', '127.0.0.1');

        self::assertNull($result);
    }

    public function testConsumeReturnsNullWhenExpired(): void
    {
        $row = [
            'id'         => 1,
            'booking_id' => 5,
            'action'     => 'confirm',
            'expires_at' => (new \DateTimeImmutable('-1 day', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'used_at'    => null,
        ];

        $wpdb = $this->createMock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        $wpdb->method('prepare')->willReturnArgument(0);
        $wpdb->method('get_row')->willReturn($row);

        $svc    = new ActionTokenService($wpdb);
        $result = $svc->consume('sometoken', '127.0.0.1');

        self::assertNull($result);
    }

    public function testConsumeReturnsDataAndMarksUsed(): void
    {
        $row = [
            'id'         => 7,
            'booking_id' => 99,
            'action'     => 'confirm',
            'expires_at' => (new \DateTimeImmutable('+10 days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'used_at'    => null,
        ];

        $updateCalled = false;
        $queryCalled  = false;

        $wpdb = $this->createMock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        $wpdb->method('prepare')->willReturnArgument(0);
        $wpdb->method('get_row')->willReturn($row);
        $wpdb->method('update')->willReturnCallback(function () use (&$updateCalled) {
            $updateCalled = true;
            return 1;
        });
        $wpdb->method('query')->willReturnCallback(function () use (&$queryCalled) {
            $queryCalled = true;
            return 1;
        });

        $svc    = new ActionTokenService($wpdb);
        $result = $svc->consume('validtoken', '192.168.1.1');

        self::assertNotNull($result);
        self::assertSame(99, $result['booking_id']);
        self::assertSame('confirm', $result['action']);
        self::assertTrue($updateCalled, 'Should mark token as used');
        self::assertTrue($queryCalled, 'Should invalidate other tokens');
    }

    public function testPeekReturnsNullWhenNotFound(): void
    {
        $wpdb = $this->createMock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        $wpdb->method('prepare')->willReturnArgument(0);
        $wpdb->method('get_row')->willReturn(null);

        $svc    = new ActionTokenService($wpdb);
        $result = $svc->peek('badtoken');

        self::assertNull($result);
    }

    public function testPeekReturnsDataWithoutSideEffects(): void
    {
        $row = [
            'booking_id' => 11,
            'action'     => 'cancel',
            'expires_at' => (new \DateTimeImmutable('+5 days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'used_at'    => null,
        ];

        $wpdb = $this->createMock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        $wpdb->method('prepare')->willReturnArgument(0);
        $wpdb->method('get_row')->willReturn($row);
        // update/query must NOT be called
        $wpdb->expects($this->never())->method('update');
        $wpdb->expects($this->never())->method('query');

        $svc    = new ActionTokenService($wpdb);
        $result = $svc->peek('peektoken');

        self::assertNotNull($result);
        self::assertSame(11, $result['booking_id']);
        self::assertSame('cancel', $result['action']);
    }
}
