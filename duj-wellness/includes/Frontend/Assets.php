<?php

declare(strict_types=1);

namespace Duj\Wellness\Frontend;

use Duj\Wellness\Support\SettingsInterface;

/**
 * Registrace a enqueue assetů frontendu.
 * Assety jsou enqueue jen pokud je shortcode na stránce.
 */
final class Assets
{
    private bool $enqueued = false;

    public function __construct(
        private readonly SettingsInterface $settings,
        private readonly string $pluginUrl,
        private readonly string $pluginVersion,
    ) {}

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'conditionalEnqueue']);
    }

    public function conditionalEnqueue(): void
    {
        global $post;
        if (!$post instanceof \WP_Post) {
            return;
        }

        $content = $post->post_content;
        if (!has_shortcode($content, 'duj_wellness_booking') && !has_shortcode($content, 'duj_wellness_availability')) {
            return;
        }

        $this->enqueue();
    }

    public function enqueue(): void
    {
        if ($this->enqueued) {
            return;
        }
        $this->enqueued = true;

        wp_register_style(
            'duj-wellness-booking',
            $this->pluginUrl . 'assets/css/booking.css',
            [],
            $this->pluginVersion
        );
        wp_enqueue_style('duj-wellness-booking');

        wp_register_script(
            'duj-wellness-booking',
            $this->pluginUrl . 'assets/js/booking.js',
            [],
            $this->pluginVersion,
            true
        );
        wp_script_add_data('duj-wellness-booking', 'type', 'module');
        wp_enqueue_script('duj-wellness-booking');

        // Stripe.js — načteme jen když je potřeba platba
        wp_register_script('stripe-js', 'https://js.stripe.com/v3/', [], null, true);

        $publishableKey = $this->settings->stripePublishableKey();
        if ($publishableKey === null || $publishableKey === '') {
            $publishableKey = defined('DUJ_STRIPE_PUBLISHABLE_KEY') ? constant('DUJ_STRIPE_PUBLISHABLE_KEY') : '';
        }

        wp_localize_script('duj-wellness-booking', 'dujWellness', [
            'restUrl'        => esc_url_raw(rest_url('duj/v1/')),
            'nonce'          => wp_create_nonce('wp_rest'),
            'stripeKey'      => (string) $publishableKey,
            'homeUrl'        => home_url('/'),
            'i18n'           => $this->getI18n(),
        ]);
    }

    private function getI18n(): array
    {
        return [
            'loading'              => __('Načítání…', 'duj-wellness'),
            'selectDay'            => __('Vyberte den v kalendáři', 'duj-wellness'),
            'selectSlot'           => __('Vyberte termín', 'duj-wellness'),
            'selectService'        => __('Vyberte službu', 'duj-wellness'),
            'fillDetails'          => __('Vyplňte údaje', 'duj-wellness'),
            'payment'              => __('Platba', 'duj-wellness'),
            'bookingConfirmed'     => __('Rezervace přijata', 'duj-wellness'),
            'noSlotsAvailable'     => __('V tento den není žádný volný termín.', 'duj-wellness'),
            'slotTaken'            => __('Tento termín právě někdo zarezervoval, vyberte prosím jiný.', 'duj-wellness'),
            'errorGeneric'         => __('Nastala chyba. Zkuste to prosím znovu.', 'duj-wellness'),
            'holdTimer'            => __('Termín držíme ještě', 'duj-wellness'),
            'thankYou'             => __('Děkujeme, rezervaci potvrdíme do 24 hodin. Poslali jsme vám e-mail.', 'duj-wellness'),
            'guestCode'            => __('Jste u nás ubytovaní? Zadejte kód', 'duj-wellness'),
            'invalidCode'          => __('Kód neplatí', 'duj-wellness'),
            'validCode'            => __('Kód přijat — cena pro ubytované', 'duj-wellness'),
            'pricePublic'          => __('Veřejnost', 'duj-wellness'),
            'priceGuest'           => __('Ubytovaní u nás', 'duj-wellness'),
            'sud'                  => __('Koupací sud', 'duj-wellness'),
            'sauna'                => __('Sauna', 'duj-wellness'),
            'saunaSud'             => __('Sud + sauna', 'duj-wellness'),
            'persons'              => __('Počet osob', 'duj-wellness'),
            'note'                 => __('Poznámka', 'duj-wellness'),
            'consentPrefix'        => __('Souhlasím s', 'duj-wellness'),
            'consentLink'          => __('obchodními podmínkami', 'duj-wellness'),
            'consentRequired'      => __('Souhlas s podmínkami je povinný.', 'duj-wellness'),
            'pay'                  => __('Zaplatit', 'duj-wellness'),
            'continue'             => __('Pokračovat', 'duj-wellness'),
            'back'                 => __('Zpět', 'duj-wellness'),
            'closed'               => __('Zavřeno', 'duj-wellness'),
            'reserved'             => __('Vyhrazeno ubytovaným', 'duj-wellness'),
            'fullyBooked'          => __('Obsazeno', 'duj-wellness'),
            'available'            => __('Volno', 'duj-wellness'),
            'partial'              => __('Částečně volno', 'duj-wellness'),
            'unavailable'          => __('Nedostupné', 'duj-wellness'),
            'priceFrom'            => __('od', 'duj-wellness'),
            'czk'                  => __('Kč', 'duj-wellness'),
            'payStripeCard'        => __('Platba kartou', 'duj-wellness'),
            'payBankTransfer'      => __('Bankovní převod / QR platba', 'duj-wellness'),
        ];
    }
}
