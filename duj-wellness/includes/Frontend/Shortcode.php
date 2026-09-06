<?php

declare(strict_types=1);

namespace Duj\Wellness\Frontend;

/**
 * Shortcode [duj_wellness_booking] a [duj_wellness_availability].
 */
final class Shortcode
{
    public function __construct(
        private readonly Assets $assets,
    ) {}

    public function register(): void
    {
        add_shortcode('duj_wellness_booking', [$this, 'renderBooking']);
        add_shortcode('duj_wellness_availability', [$this, 'renderAvailability']);
    }

    /**
     * Vykreslí rezervační widget.
     *
     * Atributy:
     *   months  (int)              kolik měsíců dopředu, default 3
     *   service (all|sud|sauna)    filtr služby, default all
     *   heading (string)           nadpis, default "Rezervace wellness"
     *   theme   (auto|light|dark)  barevné schéma, default auto
     */
    public function renderBooking(array $atts): string
    {
        $this->assets->enqueue();

        $atts = shortcode_atts([
            'months'  => '3',
            'service' => 'all',
            'heading' => __('Rezervace wellness', 'duj-wellness'),
            'theme'   => 'auto',
        ], $atts, 'duj_wellness_booking');

        $months  = max(1, min(12, (int) $atts['months']));
        $service = in_array($atts['service'], ['all', 'sud', 'sauna'], true) ? $atts['service'] : 'all';
        $heading = sanitize_text_field((string) $atts['heading']);
        $theme   = in_array($atts['theme'], ['auto', 'light', 'dark'], true) ? $atts['theme'] : 'auto';

        $dataAttrs = sprintf(
            'data-months="%d" data-service="%s" data-theme="%s"',
            $months,
            esc_attr($service),
            esc_attr($theme)
        );

        $headingHtml = $heading !== '' ? '<h2 class="duj-wellness__heading">' . esc_html($heading) . '</h2>' : '';

        return '<div class="duj-wellness" id="duj-wellness-booking" ' . $dataAttrs . ' role="main">'
            . $headingHtml
            . '<div class="duj-wellness__app" aria-live="polite" aria-atomic="false">'
            . '<div class="duj-wellness__skeleton" aria-hidden="true"></div>'
            . '</div>'
            . '</div>';
    }

    /**
     * Vykreslí read-only přehled nejbližších volných termínů.
     *
     * Atributy:
     *   count (int)   kolik termínů zobrazit, default 5
     */
    public function renderAvailability(array $atts): string
    {
        $this->assets->enqueue();

        $atts  = shortcode_atts(['count' => '5'], $atts, 'duj_wellness_availability');
        $count = max(1, min(20, (int) $atts['count']));

        return '<div class="duj-wellness duj-wellness--availability" data-mode="availability" data-count="' . $count . '">'
            . '<div class="duj-wellness__app" aria-live="polite">'
            . '<div class="duj-wellness__skeleton" aria-hidden="true"></div>'
            . '</div>'
            . '</div>';
    }
}
