<?php

declare(strict_types=1);

namespace Duj\Wellness\Rest;

use Duj\Wellness\Domain\AvailabilityService;
use Duj\Wellness\Domain\TierResolver;
use Duj\Wellness\Support\Dates;
use Duj\Wellness\Support\Settings;

final class AvailabilityController
{
    public const NAMESPACE = 'duj/v1';

    public function __construct(
        private readonly AvailabilityService $availabilityService,
        private readonly TierResolver $tierResolver,
        private readonly Settings $settings,
    ) {}

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/availability', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getAvailability'],
            'permission_callback' => '__return_true',
            'args'                => [
                'from' => [
                    'required'          => false,
                    'validate_callback' => fn($v) => Dates::isValidDate((string) $v),
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'to' => [
                    'required'          => false,
                    'validate_callback' => fn($v) => Dates::isValidDate((string) $v),
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'code' => [
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/config', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getConfig'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/availability/nearest', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getNearest'],
            'permission_callback' => '__return_true',
            'args'                => [
                'count' => [
                    'required'          => false,
                    'sanitize_callback' => fn($v) => max(1, min(20, (int) $v)),
                ],
                'service' => [
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'code' => [
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public function getAvailability(\WP_REST_Request $request): \WP_REST_Response
    {
        $today   = Dates::today();
        $months  = $this->settings->calendarMonths();
        $maxTo   = Dates::parse($today)->modify("+{$months} months")->format('Y-m-d');

        $from = $request->get_param('from') ?? $today;
        $to   = $request->get_param('to')   ?? $maxTo;

        if ($from > $maxTo) {
            return new \WP_REST_Response(
                ['code' => 'range_too_far', 'message' => __('Datum je mimo dostupný rozsah.', 'duj-wellness')],
                400
            );
        }

        if ($to > $maxTo) {
            $to = $maxTo;
        }

        $code       = $request->get_param('code') ?: null;
        $resolution = $this->tierResolver->resolve($code, $from);
        $tier       = $resolution->tier;

        $days = $this->availabilityService->getAvailability($from, $to, $tier);

        $data = [
            'from'         => $from,
            'to'           => $to,
            'tier'         => $tier->slug,
            'invalid_code' => $resolution->invalidCode,
            'days'         => array_map(fn($d) => $d->toArray(), $days),
        ];

        return new \WP_REST_Response($data, 200);
    }

    public function getConfig(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'calendar_months'      => $this->settings->calendarMonths(),
            'buffer_minutes'       => $this->settings->bufferMinutes(),
            'default_slot_minutes' => $this->settings->defaultSlotMinutes(),
            'hold_minutes'         => $this->settings->holdMinutes(),
            'cutoff_time'          => $this->settings->cutoffTime(),
        ], 200);
    }

    /**
     * Vrátí nejbližší N dostupných slotů — pro shortcode [duj_wellness_availability].
     */
    public function getNearest(\WP_REST_Request $request): \WP_REST_Response
    {
        $count   = (int) ($request->get_param('count') ?? 5);
        $service = sanitize_text_field($request->get_param('service') ?? '');
        $code    = $request->get_param('code') ?: null;

        $today      = Dates::today();
        $months     = $this->settings->calendarMonths();
        $maxTo      = Dates::parse($today)->modify("+{$months} months")->format('Y-m-d');
        $resolution = $this->tierResolver->resolve($code, $today);
        $tier       = $resolution->tier;

        $days = $this->availabilityService->getAvailability($today, $maxTo, $tier);

        $slots = [];
        foreach ($days as $day) {
            if (count($slots) >= $count) {
                break;
            }
            foreach ($day->availableSlots as $slot) {
                if (count($slots) >= $count) {
                    break;
                }
                foreach ($slot->offers as $offer) {
                    if ($service !== '' && !str_contains($offer->comboKey, $service)) {
                        continue;
                    }
                    $slots[] = [
                        'date'      => $day->date,
                        'slot_from' => $slot->from,
                        'slot_to'   => $slot->to,
                        'combo_key' => $offer->comboKey,
                        'price'     => $offer->price->amountMinor,
                        'currency'  => $offer->price->currency,
                    ];
                    if (count($slots) >= $count) {
                        break 2;
                    }
                }
            }
        }

        return new \WP_REST_Response(['slots' => $slots, 'tier' => $tier->slug]);
    }
}
