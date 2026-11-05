<?php
declare(strict_types=1);

namespace ZkTimeNet\Services;

/**
 * Resolves the immutable XLSX source for a report run.
 * New runs keep exports/<run-id>/source.xlsx; historical runs may still have
 * uploads/<run-id>.xlsx. The run ID is the only accepted lookup key.
 */
final class RunSourceResolver
{
    /**
     * @return array{path:string,run_directory:string,storage:string}|null
     */
    public static function resolve(string $runId, string $exportsRoot, string $uploadsRoot): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $runId) !== 1) {
            return null;
        }

        $exportsBase = realpath($exportsRoot);
        if ($exportsBase === false || !is_dir($exportsBase)) {
            return null;
        }

        $runCandidate = $exportsBase . DIRECTORY_SEPARATOR . $runId;
        if (is_link($runCandidate)) {
            return null;
        }
        $runDirectory = realpath($runCandidate);
        if ($runDirectory === false || dirname($runDirectory) !== $exportsBase || !is_dir($runDirectory)) {
            return null;
        }

        // Prefer the durable run-local copy created by current releases.
        $runSource = self::resolveFile($runDirectory . DIRECTORY_SEPARATOR . 'source.xlsx', $runDirectory, 'source.xlsx');
        if ($runSource !== null) {
            return ['path' => $runSource, 'run_directory' => $runDirectory, 'storage' => 'run'];
        }

        // Compatibility for runs created before source.xlsx was retained.
        // Their upload was named with the exact same 32-character run ID.
        $uploadsBase = realpath($uploadsRoot);
        if ($uploadsBase === false || !is_dir($uploadsBase)) {
            return null;
        }
        $uploadName = $runId . '.xlsx';
        $uploadCandidate = $uploadsBase . DIRECTORY_SEPARATOR . $uploadName;
        if (is_link($uploadCandidate)) {
            return null;
        }
        $legacyUpload = self::resolveFile($uploadCandidate, $uploadsBase, $uploadName);
        if ($legacyUpload !== null) {
            return ['path' => $legacyUpload, 'run_directory' => $runDirectory, 'storage' => 'uploads'];
        }

        return null;
    }

    private static function resolveFile(string $candidate, string $expectedDirectory, string $expectedName): ?string
    {
        if (is_link($candidate)) {
            return null;
        }
        $resolved = realpath($candidate);
        if ($resolved === false
            || dirname($resolved) !== $expectedDirectory
            || basename($resolved) !== $expectedName
            || !is_file($resolved)
            || !is_readable($resolved)
            || strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) !== 'xlsx') {
            return null;
        }
        $size = @filesize($resolved);
        if (!is_int($size) || $size < 1) {
            return null;
        }
        return $resolved;
    }
}
