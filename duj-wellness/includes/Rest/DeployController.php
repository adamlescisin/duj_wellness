<?php

declare(strict_types=1);

namespace Duj\Wellness\Rest;

use Duj\Wellness\Support\Settings;

/**
 * POST /duj/v1/deploy — GitHub webhook auto-deploy.
 *
 * Downloads the repo ZIP from GitHub and extracts the duj-wellness/ subfolder
 * over the plugin directory. Works on shared hosting with no git installed.
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

        $result = $this->deployFromZip($repoFullName, $afterSha, $pluginDir, $githubToken);

        if (isset($result['error'])) {
            error_log('[duj-wellness] Deploy failed: ' . json_encode($result));
            return new \WP_Error('deploy_failed', $result['message'] ?? 'Deploy failed.', [
                'status' => 500,
                'detail' => $result,
            ]);
        }

        error_log('[duj-wellness] Auto-deploy successful: ' . $repoFullName . '@' . substr($afterSha, 0, 7));

        return new \WP_REST_Response([
            'success' => true,
            'repo'    => $repoFullName,
            'sha'     => substr($afterSha, 0, 7),
            'files'   => $result['files'] ?? 0,
        ], 200);
    }

    /**
     * Downloads the GitHub ZIP for the given commit SHA and extracts
     * the duj-wellness/ subfolder into $pluginDir.
     *
     * @return array{success: true, files: int}|array{error: string, message: string}
     */
    private function deployFromZip(string $repoFullName, string $sha, string $pluginDir, string $token): array
    {
        // GitHub archive URL by exact commit SHA — reproducible and immutable.
        $zipUrl = "https://github.com/{$repoFullName}/archive/{$sha}.zip";

        // Download zip to a temp file.
        $tmpZip = tempnam(sys_get_temp_dir(), 'duj_deploy_') . '.zip';

        $headers = ['User-Agent' => 'duj-wellness-deploy/1.0'];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = wp_remote_get($zipUrl, [
            'timeout'  => 60,
            'stream'   => true,
            'filename' => $tmpZip,
            'headers'  => $headers,
        ]);

        if (is_wp_error($response)) {
            @unlink($tmpZip);
            return ['error' => 'download_failed', 'message' => $response->get_error_message()];
        }

        $httpCode = wp_remote_retrieve_response_code($response);
        if ($httpCode !== 200) {
            @unlink($tmpZip);
            return ['error' => 'download_failed', 'message' => "GitHub returned HTTP {$httpCode} for ZIP download."];
        }

        if (!class_exists('ZipArchive')) {
            @unlink($tmpZip);
            return ['error' => 'zip_unavailable', 'message' => 'ZipArchive extension is not available on this server.'];
        }

        $zip    = new \ZipArchive();
        $opened = $zip->open($tmpZip);
        if ($opened !== true) {
            @unlink($tmpZip);
            return ['error' => 'zip_open_failed', 'message' => "ZipArchive::open() returned error code {$opened}."];
        }

        // GitHub names the root folder: {repo-name}-{sha}
        // Determine it from the first entry.
        $firstEntry = $zip->getNameIndex(0);
        if ($firstEntry === false) {
            $zip->close();
            @unlink($tmpZip);
            return ['error' => 'zip_empty', 'message' => 'Downloaded ZIP has no entries.'];
        }

        $zipRoot      = explode('/', $firstEntry)[0] . '/';       // e.g. "duj_wellness-abc1234/"
        $innerPrefix  = $zipRoot . 'duj-wellness/';               // e.g. "duj_wellness-abc1234/duj-wellness/"
        $prefixLen    = strlen($innerPrefix);

        // Extract only entries inside duj-wellness/ directly to the plugin dir.
        $fileCount = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if ($entryName === false) {
                continue;
            }

            // Skip entries outside the duj-wellness/ subfolder.
            if (strncmp($entryName, $innerPrefix, $prefixLen) !== 0) {
                continue;
            }

            $relativePath = substr($entryName, $prefixLen);
            if ($relativePath === '' || $relativePath === false) {
                continue; // This is the duj-wellness/ directory entry itself.
            }

            // Prevent path traversal.
            $realTarget = realpath($pluginDir . '/' . dirname($relativePath));
            if ($realTarget === false || strncmp($realTarget, $pluginDir, strlen($pluginDir)) !== 0) {
                continue;
            }

            $targetPath = $pluginDir . '/' . $relativePath;

            if (str_ends_with($entryName, '/')) {
                // Directory entry.
                if (!is_dir($targetPath)) {
                    wp_mkdir_p($targetPath);
                }
                continue;
            }

            // File entry — ensure parent directory exists.
            $parentDir = dirname($targetPath);
            if (!is_dir($parentDir)) {
                wp_mkdir_p($parentDir);
            }

            $content = $zip->getFromIndex($i);
            if ($content !== false) {
                file_put_contents($targetPath, $content);
                $fileCount++;
            }
        }

        $zip->close();
        @unlink($tmpZip);

        if ($fileCount === 0) {
            return [
                'error'   => 'no_files_extracted',
                'message' => "No files found under {$innerPrefix} in the ZIP. Check that 'duj-wellness/' exists at repo root.",
            ];
        }

        return ['success' => true, 'files' => $fileCount];
    }
}
