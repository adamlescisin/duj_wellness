<?php

declare(strict_types=1);

namespace Duj\Wellness\Rest;

use Duj\Wellness\Notification\TemplateRenderer;
use Duj\Wellness\Support\Settings;

/**
 * Admin REST endpointy pro správu e-mailových šablon.
 */
final class AdminTemplatesController
{
    private const VALID_TEMPLATES = [
        'awaiting_confirmation',
        'confirmed',
        'cancelled',
        'admin_booking_new',
        'reminder',
        'auth_expiring',
        'completed',
        'admin_auth_expiring',
    ];

    public function register(): void
    {
        $cb = fn($m, $h) => ['methods' => $m, 'callback' => [$this, $h], 'permission_callback' => [$this, 'can']];

        register_rest_route('duj/v1', '/admin/templates/(?P<slug>[a-z_]+)', [
            $cb('GET',    'get'),
            $cb('PATCH',  'patch'),
            $cb('DELETE', 'reset'),
        ]);
        register_rest_route('duj/v1', '/admin/templates/(?P<slug>[a-z_]+)/test', [
            $cb('POST', 'test'),
        ]);
    }

    public function can(): bool
    {
        return current_user_can('duj_manage_bookings');
    }

    public function get(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        $slug = sanitize_key($req['slug']);
        if (!in_array($slug, self::VALID_TEMPLATES, true)) {
            return new \WP_Error('invalid_template', 'Neplatná šablona.', ['status' => 404]);
        }

        $option   = get_option('duj_email_template_' . $slug, []);
        $defaults = TemplateRenderer::getDefaults($slug);

        return new \WP_REST_Response([
            'slug'            => $slug,
            'subject'         => $option['subject'] ?? $defaults['subject'] ?? '',
            'body'            => $option['body']    ?? $defaults['body']    ?? '',
            'is_customized'   => !empty($option),
        ]);
    }

    public function patch(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        $slug = sanitize_key($req['slug']);
        if (!in_array($slug, self::VALID_TEMPLATES, true)) {
            return new \WP_Error('invalid_template', 'Neplatná šablona.', ['status' => 404]);
        }

        $body    = $req->get_json_params();
        $subject = sanitize_text_field($body['subject'] ?? '');
        $tmplBody = wp_kses_post($body['body'] ?? '');

        if ($subject === '' || $tmplBody === '') {
            return new \WP_Error('missing_fields', 'Chybí subject nebo body.', ['status' => 400]);
        }

        update_option('duj_email_template_' . $slug, [
            'subject' => $subject,
            'body'    => $tmplBody,
        ], false);

        return new \WP_REST_Response(['saved' => true]);
    }

    public function reset(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        $slug = sanitize_key($req['slug']);
        if (!in_array($slug, self::VALID_TEMPLATES, true)) {
            return new \WP_Error('invalid_template', 'Neplatná šablona.', ['status' => 404]);
        }

        delete_option('duj_email_template_' . $slug);

        return new \WP_REST_Response(['reset' => true]);
    }

    public function test(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        $slug = sanitize_key($req['slug']);
        if (!in_array($slug, self::VALID_TEMPLATES, true)) {
            return new \WP_Error('invalid_template', 'Neplatná šablona.', ['status' => 404]);
        }

        $body      = $req->get_json_params();
        $recipient = sanitize_email($body['email'] ?? '');
        if (!is_email($recipient)) {
            $recipient = wp_get_current_user()->user_email;
        }

        $settings = Settings::instance();
        $from     = $settings->contactEmail() ?: get_bloginfo('admin_email');
        $option   = get_option('duj_email_template_' . $slug, []);
        $defaults = TemplateRenderer::getDefaults($slug);

        $subject = $option['subject'] ?? $defaults['subject'] ?? "Test: {$slug}";
        $tmplBody = $option['body']   ?? $defaults['body']    ?? "(prázdná šablona)";

        // Replace placeholders with sample values for test email
        $placeholders = TemplateRenderer::getSamplePlaceholders();
        $subject  = str_replace(array_keys($placeholders), array_values($placeholders), $subject);
        $tmplBody = str_replace(array_keys($placeholders), array_values($placeholders), $tmplBody);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            "From: {$from}",
        ];

        $sent = wp_mail($recipient, "[TEST] {$subject}", nl2br(esc_html($tmplBody)), $headers);

        if (!$sent) {
            return new \WP_Error('mail_failed', 'E-mail se nepodařilo odeslat.', ['status' => 500]);
        }

        return new \WP_REST_Response(['sent_to' => $recipient]);
    }
}
