<?php

declare(strict_types=1);

namespace Duj\Wellness\Rest;

use Duj\Wellness\Domain\BookingStatus;
use Duj\Wellness\Domain\BookingServiceInterface;
use Duj\Wellness\Notification\ActionTokenServiceInterface;
use Duj\Wellness\Notification\NotificationService;
use Duj\Wellness\Repository\BookingRepositoryInterface;

/**
 * GET  /duj/v1/action  — zobrazí stránku s formulářem (NIKDY neprovádí akci)
 * POST /duj/v1/action  — provede akci (confirm / cancel / reject)
 *
 * Rate-limit: 10 pokusů / IP / hodinu (via WP transient).
 *
 * BEZPEČNOSTNÍ PRAVIDLO: GET NIKDY nic nemění — e-mailoví klienti prefetchují.
 */
final class ActionController
{
    private const RATE_LIMIT = 10;
    private const RATE_WINDOW = HOUR_IN_SECONDS;

    public function __construct(
        private readonly ActionTokenServiceInterface $tokenService,
        private readonly BookingRepositoryInterface $bookingRepo,
        private readonly BookingServiceInterface $bookingService,
        private readonly NotificationService $notificationService,
    ) {}

    public function register(): void
    {
        register_rest_route('duj/v1', '/action', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'handleGet'],
                'permission_callback' => '__return_true',
                'args'                => $this->getArgs(),
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'handlePost'],
                'permission_callback' => '__return_true',
                'args'                => $this->getArgs(),
            ],
        ]);

        // WordPress always JSON-encodes REST responses. For browser-navigable HTML pages
        // we intercept before encoding and output the HTML body directly.
        add_filter('rest_pre_serve_request', [$this, 'serveHtmlResponse'], 10, 2);
    }

    public function serveHtmlResponse(bool $served, \WP_REST_Response $result): bool
    {
        $headers     = $result->get_headers();
        $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';
        if ($contentType !== 'text/html; charset=UTF-8') {
            return $served;
        }

        $html = $result->get_data();
        if (!is_string($html)) {
            return $served;
        }

        status_header($result->get_status());
        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        return true;
    }

    /**
     * GET: Peek na token, zobrazí potvrzovací stránku.
     * NIKDY neprovádí žádnou akci.
     */
    public function handleGet(\WP_REST_Request $request): \WP_REST_Response
    {
        $token  = (string) $request->get_param('token');
        $action = (string) $request->get_param('duj_action');

        if ($token === '') {
            return $this->htmlResponse($this->renderError(__('Odkaz je neplatný.', 'duj-wellness')), 400);
        }

        $meta = $this->tokenService->peek($token);

        if ($meta === null) {
            return $this->htmlResponse($this->renderError(__('Odkaz je neplatný, expiroval nebo byl již použit.', 'duj-wellness')), 410);
        }

        $booking = $this->bookingRepo->findById($meta['booking_id']);
        if ($booking === null) {
            return $this->htmlResponse($this->renderError(__('Rezervace nenalezena.', 'duj-wellness')), 404);
        }

        $resolvedAction = $action !== '' ? $action : $meta['action'];

        return $this->htmlResponse($this->renderConfirmPage($resolvedAction, $booking->reference, $token));
    }

    /**
     * POST: Spotřebuje token a provede akci.
     */
    public function handlePost(\WP_REST_Request $request): \WP_REST_Response
    {
        $clientIp = $this->getClientIp();

        if (!$this->checkRateLimit($clientIp)) {
            return new \WP_REST_Response(
                ['error' => __('Příliš mnoho pokusů. Zkuste to za hodinu.', 'duj-wellness')],
                429
            );
        }

        $token  = (string) $request->get_param('token');
        $action = (string) $request->get_param('duj_action');

        if ($token === '' || $action === '') {
            return new \WP_REST_Response(['error' => __('Chybí parametry.', 'duj-wellness')], 400);
        }

        $meta = $this->tokenService->consume($token, $clientIp);

        if ($meta === null) {
            return $this->htmlResponse($this->renderError(__('Odkaz je neplatný, expiroval nebo byl již použit.', 'duj-wellness')), 410);
        }

        $booking = $this->bookingRepo->findById($meta['booking_id']);
        if ($booking === null) {
            return $this->htmlResponse($this->renderError(__('Rezervace nenalezena.', 'duj-wellness')), 404);
        }

        try {
            $currentStatus = BookingStatus::from($booking->status);

            // Admin "confirm" token is created when booking is still pending_payment
            // (before payment arrives). If the booking hasn't moved to awaiting_confirmation
            // yet we perform the intermediate step first so the transition matrix is satisfied.
            if ($action === 'confirm' && $currentStatus === BookingStatus::PENDING_PAYMENT) {
                $this->bookingService->transition($booking->id, BookingStatus::AWAITING_CONFIRMATION);
            }

            $newStatus = $this->resolveTargetStatus($action);
            $this->bookingService->transition($booking->id, $newStatus);

            // Načti čerstvý stav po přechodu
            $updatedBooking = $this->bookingRepo->findById($booking->id);

            if ($updatedBooking !== null) {
                match ($action) {
                    'confirm' => $this->notificationService->sendConfirmed($updatedBooking),
                    'cancel', 'reject' => $this->notificationService->sendCancelled($updatedBooking),
                    default => null,
                };
            }
        } catch (\InvalidArgumentException $e) {
            return $this->htmlResponse($this->renderError($e->getMessage()), 409);
        } catch (\Throwable) {
            // Notifikace selhala — booking přechod proběhl, stránku zobrazíme OK
        }

        return $this->htmlResponse($this->renderSuccess($action, $booking->reference));
    }

    private function resolveTargetStatus(string $action): BookingStatus
    {
        return match ($action) {
            'confirm' => BookingStatus::CONFIRMED,
            'cancel'  => BookingStatus::CANCELLED,
            'reject'  => BookingStatus::REJECTED,
            default   => throw new \InvalidArgumentException(
                __('Neznámá akce.', 'duj-wellness')
            ),
        };
    }

    private function checkRateLimit(string $ip): bool
    {
        $key     = 'duj_action_rl_' . md5($ip);
        $current = (int) get_transient($key);

        if ($current >= self::RATE_LIMIT) {
            return false;
        }

        if ($current === 0) {
            set_transient($key, 1, self::RATE_WINDOW);
        } else {
            set_transient($key, $current + 1, self::RATE_WINDOW);
        }

        return true;
    }

    private function getClientIp(): string
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($headers as $h) {
            $val = $_SERVER[$h] ?? '';
            if ($val !== '') {
                return trim(explode(',', $val)[0]);
            }
        }
        return '0.0.0.0';
    }

    private function getArgs(): array
    {
        return [
            'token' => [
                'type'     => 'string',
                'required' => false,
                'default'  => '',
            ],
            'duj_action' => [
                'type'     => 'string',
                'required' => false,
                'default'  => '',
                'enum'     => ['confirm', 'cancel', 'reject', ''],
            ],
        ];
    }

    private function htmlResponse(string $html, int $status = 200): \WP_REST_Response
    {
        $response = new \WP_REST_Response($html, $status);
        $response->header('Content-Type', 'text/html; charset=UTF-8');
        return $response;
    }

    private function renderConfirmPage(string $action, string $reference, string $token): string
    {
        $labels = [
            'confirm' => __('Potvrdit rezervaci', 'duj-wellness'),
            'cancel'  => __('Zrušit rezervaci', 'duj-wellness'),
            'reject'  => __('Zamítnout rezervaci', 'duj-wellness'),
        ];

        $buttonLabel = $labels[$action] ?? __('Odeslat', 'duj-wellness');
        $safeRef     = esc_html($reference);
        $safeToken   = esc_attr($token);
        $safeAction  = esc_attr($action);
        $actionUrl   = esc_url(rest_url('duj/v1/action'));

        return <<<HTML
        <!DOCTYPE html>
        <html lang="cs">
        <head><meta charset="UTF-8"><title>{$buttonLabel}</title>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <style>body{font-family:sans-serif;max-width:480px;margin:60px auto;padding:0 20px;text-align:center}
        .btn{display:inline-block;padding:12px 28px;background:#2d6a4f;color:#fff;border:none;border-radius:6px;font-size:16px;cursor:pointer;text-decoration:none}</style>
        </head>
        <body>
        <h2>{$buttonLabel}</h2>
        <p>Rezervace: <strong>{$safeRef}</strong></p>
        <form method="post" action="{$actionUrl}">
            <input type="hidden" name="token" value="{$safeToken}">
            <input type="hidden" name="duj_action" value="{$safeAction}">
            <button type="submit" class="btn">{$buttonLabel}</button>
        </form>
        </body></html>
        HTML;
    }

    private function renderSuccess(string $action, string $reference): string
    {
        $messages = [
            'confirm' => __('Rezervace byla úspěšně potvrzena.', 'duj-wellness'),
            'cancel'  => __('Rezervace byla zrušena.', 'duj-wellness'),
            'reject'  => __('Rezervace byla zamítnuta.', 'duj-wellness'),
        ];

        $msg     = $messages[$action] ?? __('Hotovo.', 'duj-wellness');
        $safeRef = esc_html($reference);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="cs">
        <head><meta charset="UTF-8"><title>Hotovo</title>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <style>body{font-family:sans-serif;max-width:480px;margin:60px auto;padding:0 20px;text-align:center}
        .ok{color:#2d6a4f;font-size:48px}</style>
        </head>
        <body>
        <div class="ok">✓</div>
        <h2>{$msg}</h2>
        <p>Rezervace: <strong>{$safeRef}</strong></p>
        </body></html>
        HTML;
    }

    private function renderError(string $message): string
    {
        $safeMsg = esc_html($message);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="cs">
        <head><meta charset="UTF-8"><title>Chyba</title>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <style>body{font-family:sans-serif;max-width:480px;margin:60px auto;padding:0 20px;text-align:center}
        .err{color:#c0392b;font-size:48px}</style>
        </head>
        <body>
        <div class="err">✗</div>
        <h2>{$safeMsg}</h2>
        </body></html>
        HTML;
    }
}
