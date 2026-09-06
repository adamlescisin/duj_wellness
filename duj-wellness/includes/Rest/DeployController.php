<?php

declare(strict_types=1);

namespace Duj\Wellness\Rest;

use Duj\Wellness\Support\Settings;

/**
 * POST /duj/v1/deploy — GitHub webhook auto-deploy.
 *
 * Validates the HMAC-SHA256 signature and returns 202 Accepted immediately.
 * The actual ZIP download + extraction runs asynchronously via WP-Cron so
 * GitHub's ~10-second webhook delivery timeout is never breached.
 *
 * Security: validates X-Hub-Signature-256 HMAC before doing anything.
 * Secret: DUJ_DEPLOY_SECRET constant in wp-config.php (preferred) or plugin setting.
 */
final class DeployController
{
    public function register(): void
    {
        register_rest_route('duj/v1', '/deploy', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        $settings = Settings::instance();
        $secret   = defined('DUJ_DEPLOY_SECRET') && DUJ_DEPLOY_SECRET !== ''
            ? DUJ_DEPLOY_SECRET
            : $settings->deploySecret();

        if ($secret === '') {
            return new \WP_Error('deploy_not_configured', 'Deploy secret not configured.', ['status' => 503]);
        }

        // Validate HMAC-SHA256 signature.
        $sigHeader = $req->get_header('x-hub-signature-256') ?? '';
        if ($sigHeader === '') {
            return new \WP_Error('missing_signature', 'Missing X-Hub-Signature-256 header.', ['status' => 401]);
        }

        $rawBody     = $req->get_body();
        $expectedSig = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        if (!hash_equals($expectedSig, $sigHeader)) {
            return new \WP_Error('invalid_signature', 'Signature mismatch.', ['status' => 401]);
        }

        // Only act on push events.
        $event = $req->get_header('x-github-event') ?? '';
        if ($event !== 'push') {
            return new \WP_REST_Response(['skipped' => true, 'event' => $event], 200);
        }

        $payload      = $req->get_json_params();
        $pushedRef    = (string) ($payload['ref'] ?? '');
        $afterSha     = (string) ($payload['after'] ?? '');
        $repoFullName = (string) ($payload['repository']['full_name'] ?? '');
        $isPrivate    = (bool)   ($payload['repository']['private']   ?? false);

        // Skip branch-delete events (after = 000...000).
        if ($afterSha === '' || ltrim($afterSha, '0') === '') {
            return new \WP_REST_Response(['skipped' => true, 'reason' => 'branch_deleted'], 200);
        }

        $targetBranch = $settings->deployBranch() ?: 'main';
        $expectedRef  = 'refs/heads/' . ltrim($targetBranch, '/');

        if ($pushedRef !== $expectedRef) {
            return new \WP_REST_Response([
                'skipped' => true,
                'reason'  => 'Branch mismatch',
                'pushed'  => $pushedRef,
                'target'  => $expectedRef,
            ], 200);
        }

        if ($repoFullName === '') {
            return new \WP_Error('missing_repo', 'Cannot determine repository from payload.', ['status' => 400]);
        }

        // Optional GitHub token for private repos.
        $githubToken = defined('DUJ_GITHUB_TOKEN') && DUJ_GITHUB_TOKEN !== '' ? DUJ_GITHUB_TOKEN : '';
        if ($isPrivate && $githubToken === '') {
            return new \WP_Error('private_repo', 'Repository is private but DUJ_GITHUB_TOKEN is not set.', ['status' => 503]);
        }

        $pluginDir = (string) realpath(dirname(__DIR__, 2));
        if ($pluginDir === '' || !is_dir($pluginDir)) {
            return new \WP_Error('plugin_dir_missing', 'Cannot resolve plugin directory.', ['status' => 500]);
        }

        // Store deploy params for the async job and schedule it via WP-Cron.
        $shortSha     = substr($afterSha, 0, 7);
        $transientKey = 'duj_deploy_' . $shortSha;

        set_transient($transientKey, [
            'repo'  => $repoFullName,
            'sha'   => $afterSha,
            'dir'   => $pluginDir,
            'token' => $githubToken,
        ], 600); // 10-minute TTL — plenty of time for cron to pick it up.

        wp_schedule_single_event(time(), 'duj_wellness_deploy_execute', [$transientKey]);

        // Kick WP-Cron asynchronously (fire-and-forget) so the job runs immediately
        // rather than waiting for the next organic page load.
        wp_remote_post(site_url('/?doing_wp_cron=1'), [
            'blocking'  => false,
            'timeout'   => 0.01,
            'sslverify' => false,
            'body'      => [],
        ]);

        return new \WP_REST_Response([
            'accepted' => true,
            'sha'      => $shortSha,
            'repo'     => $repoFullName,
        ], 202);
    }
}
