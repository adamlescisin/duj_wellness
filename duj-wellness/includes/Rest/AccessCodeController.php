<?php

declare(strict_types=1);

namespace Duj\Wellness\Rest;

use Duj\Wellness\Domain\AccessCodeService;
use Duj\Wellness\Support\Dates;
use Duj\Wellness\Support\RateLimiter;

final class AccessCodeController
{
    public const NAMESPACE = 'duj/v1';

    public function __construct(
        private readonly AccessCodeService $accessCodeService,
        private readonly RateLimiter $rateLimiter,
    ) {}

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/access-codes/validate', [
            'methods'             => 'GET',
            'callback'            => [$this, 'validate'],
            'permission_callback' => '__return_true',
            'args'                => [
                'code' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'date' => [
                    'required'          => false,
                    'validate_callback' => fn($v) => Dates::isValidDate((string) $v),
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public function validate(\WP_REST_Request $request): \WP_REST_Response
    {
        $ip = $this->getClientIp($request);

        if (!$this->rateLimiter->check('access-code/validate', $ip)) {
            return new \WP_REST_Response(
                ['code' => 'rate_limited', 'message' => __('Příliš mnoho pokusů. Zkuste to znovu za chvíli.', 'duj-wellness')],
                429
            );
        }

        $code = $request->get_param('code');
        $date = $request->get_param('date') ?? Dates::today();

        try {
            $resolution = $this->accessCodeService->validate($code, $date);
        } catch (\Throwable $e) {
            error_log('[duj-wellness] AccessCodeController error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return new \WP_REST_Response([
                'valid'   => false,
                'message' => __('Kód neplatí.', 'duj-wellness'),
                '_debug'  => $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine(),
            ], 200);
        }

        if ($resolution->invalidCode) {
            return new \WP_REST_Response([
                'valid'   => false,
                'message' => __('Kód neplatí.', 'duj-wellness'),
            ], 200);
        }

        return new \WP_REST_Response([
            'valid'      => true,
            'tier'       => $resolution->tier->slug,
            'valid_code' => $resolution->validCode,
        ], 200);
    }

    private function getClientIp(\WP_REST_Request $request): string
    {
        $server = $request->get_server_params();

        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $header) {
            if (!empty($server[$header])) {
                $ip = trim(explode(',', $server[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}
