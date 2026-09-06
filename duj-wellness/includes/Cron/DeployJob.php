<?php

declare(strict_types=1);

namespace Duj\Wellness\Cron;

/**
 * Async deploy job — runs the actual ZIP-based deploy triggered by the GitHub webhook.
 * The REST endpoint schedules this job and returns 202 immediately so GitHub doesn't time out.
 */
final class DeployJob
{
    public const HOOK = 'duj_wellness_deploy_execute';

    /**
     * Called by WP-Cron with the transient key that holds deploy params.
     */
    public function run(string $transientKey): void
    {
        $params = get_transient($transientKey);
        if (!is_array($params)) {
            error_log("[duj-wellness] DeployJob: transient '{$transientKey}' missing or expired, skipping.");
            return;
        }

        delete_transient($transientKey);

        $repo   = (string) ($params['repo']  ?? '');
        $sha    = (string) ($params['sha']   ?? '');
        $dir    = (string) ($params['dir']   ?? '');
        $token  = (string) ($params['token'] ?? '');

        if ($repo === '' || $sha === '' || $dir === '') {
            error_log('[duj-wellness] DeployJob: invalid params, aborting.');
            return;
        }

        $result = $this->deployFromZip($repo, $sha, $dir, $token);

        if (isset($result['error'])) {
            error_log('[duj-wellness] Deploy failed: ' . json_encode($result));
        } else {
            error_log('[duj-wellness] Auto-deploy successful: ' . $repo . '@' . substr($sha, 0, 7) . ' (' . ($result['files'] ?? 0) . ' files)');
        }
    }

    /**
     * Downloads the GitHub ZIP for the given commit SHA and extracts
     * the duj-wellness/ subfolder into $pluginDir.
     *
     * @return array{success: true, files: int}|array{error: string, message: string}
     */
    public function deployFromZip(string $repoFullName, string $sha, string $pluginDir, string $token): array
    {
        $zipUrl  = "https://github.com/{$repoFullName}/archive/{$sha}.zip";
        $tmpZip  = tempnam(sys_get_temp_dir(), 'duj_deploy_') . '.zip';
        $headers = ['User-Agent' => 'duj-wellness-deploy/1.0'];

        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = wp_remote_get($zipUrl, [
            'timeout'  => 90,
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

        $firstEntry = $zip->getNameIndex(0);
        if ($firstEntry === false) {
            $zip->close();
            @unlink($tmpZip);
            return ['error' => 'zip_empty', 'message' => 'Downloaded ZIP has no entries.'];
        }

        $zipRoot     = explode('/', $firstEntry)[0] . '/';
        $innerPrefix = $zipRoot . 'duj-wellness/';
        $prefixLen   = strlen($innerPrefix);

        $fileCount = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if ($entryName === false) {
                continue;
            }

            if (strncmp($entryName, $innerPrefix, $prefixLen) !== 0) {
                continue;
            }

            $relativePath = substr($entryName, $prefixLen);
            if ($relativePath === '' || $relativePath === false) {
                continue;
            }

            // Prevent path traversal.
            $realTarget = realpath($pluginDir . '/' . dirname($relativePath));
            if ($realTarget === false || strncmp($realTarget, $pluginDir, strlen($pluginDir)) !== 0) {
                continue;
            }

            $targetPath = $pluginDir . '/' . $relativePath;

            if (str_ends_with($entryName, '/')) {
                if (!is_dir($targetPath)) {
                    wp_mkdir_p($targetPath);
                }
                continue;
            }

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
