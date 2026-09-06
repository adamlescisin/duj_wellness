<?php

declare(strict_types=1);

namespace Duj\Wellness\Rest;

use Duj\Wellness\Support\Settings;

/**
 * POST /duj/v1/deploy — GitHub webhook auto-deploy.
 *
 * Validates X-Hub-Signature-256, checks pushed branch, runs git pull.
 * The shared secret must be set in wp-config.php as DUJ_DEPLOY_SECRET
 * or in plugin settings as 'deploy_secret'.
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
        $secret   = defined('DUJ_DEPLOY_SECRET')
            ? DUJ_DEPLOY_SECRET
            : $settings->deploySecret();

        if ($secret === '') {
            return new \WP_Error('deploy_not_configured', 'Deploy secret not configured.', ['status' => 503]);
        }

        // Validate HMAC-SHA256 signature from GitHub
        $sigHeader = $req->get_header('x-hub-signature-256');
        if ($sigHeader === null || $sigHeader === '') {
            return new \WP_Error('missing_signature', 'Missing X-Hub-Signature-256 header.', ['status' => 401]);
        }

        $rawBody       = $req->get_body();
        $expectedSig   = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        if (!hash_equals($expectedSig, $sigHeader)) {
            return new \WP_Error('invalid_signature', 'Signature mismatch.', ['status' => 401]);
        }

        // Only act on push events
        $event = $req->get_header('x-github-event');
        if ($event !== 'push') {
            return new \WP_REST_Response(['skipped' => true, 'event' => $event], 200);
        }

        $payload     = $req->get_json_params();
        $pushedRef   = (string) ($payload['ref'] ?? '');
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

        $pluginDir = realpath(dirname(__DIR__, 2));
        if ($pluginDir === false || !is_dir($pluginDir . '/.git')) {
            return new \WP_Error('not_a_git_repo', 'Plugin directory is not a git repository.', ['status' => 500]);
        }

        $output = [];
        $code   = 0;
        $this->runGitPull($pluginDir, $targetBranch, $output, $code);

        if ($code !== 0) {
            error_log('[duj-wellness] git pull failed (exit ' . $code . '): ' . implode("\n", $output));

            return new \WP_Error('git_pull_failed', 'git pull returned non-zero exit code.', [
                'status' => 500,
                'output' => $output,
                'code'   => $code,
            ]);
        }

        error_log('[duj-wellness] Auto-deploy successful: ' . implode(' | ', $output));

        return new \WP_REST_Response([
            'success' => true,
            'branch'  => $targetBranch,
            'output'  => $output,
        ], 200);
    }

    private function runGitPull(string $dir, string $branch, array &$output, int &$exitCode): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = array_merge($_ENV, [
            'HOME'              => $dir,
            'GIT_TERMINAL_PROMPT' => '0',
        ]);

        $proc = proc_open(
            ['git', '-C', $dir, 'pull', 'origin', $branch, '--ff-only'],
            $descriptors,
            $pipes,
            $dir,
            $env
        );

        if (!is_resource($proc)) {
            $exitCode = 1;
            $output   = ['Failed to start git process.'];
            return;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        $output = array_filter(
            array_merge(
                explode("\n", trim((string) $stdout)),
                explode("\n", trim((string) $stderr))
            ),
            static fn(string $l): bool => $l !== ''
        );
        $output = array_values($output);
    }
}
