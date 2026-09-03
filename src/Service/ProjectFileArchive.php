<?php declare(strict_types=1);

namespace Devshot\Connector\Service;

use RecursiveDirectoryIterator;
use RecursiveCallbackFilterIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

class ProjectFileArchive
{
    private const EXCLUDED_PATH_PATTERNS = [
        '#(^|/)public/(?:media|thumbnail)(?:/|$)#',
        '#(^|/)files/media(?:/|$)#',
        '#(^|/)media(?:/|$)#',
        '#(^|/)var/(?:cache|log|devshot-connector)(?:/|$)#',
        '#(^|/)\.git(?:/|$)#',
        '#(^|/)(?:node_modules|vendor)(?:/|$)#',
        '#(^|/)\.env(?:\.[^/]*)?$#',
        '#(^|/)\.envrc$#',
        '#(^|/)(?:auth|credentials)\.json$#',
        '#(^|/)\.(?:npmrc|netrc)$#',
        '#(^|/)\.ssh(?:/|$)#',
        '#(^|/)config/jwt(?:/|$)#',
        '#(^|/)public/bundles(?:/|$)#',
    ];

    public function __construct(private readonly string $projectDir)
    {
    }

    public function create(string $targetDirectory): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required for the Shopware file archive.');
        }
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0770, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException(sprintf('Archive directory "%s" could not be created.', $targetDirectory));
        }

        $archivePath = $targetDirectory . '/project-without-media.zip';
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException(sprintf('Archive "%s" could not be opened.', $archivePath));
        }

        $directory = new RecursiveDirectoryIterator($this->projectDir, RecursiveDirectoryIterator::SKIP_DOTS);
        $filtered = new RecursiveCallbackFilterIterator($directory, function (SplFileInfo $file): bool {
            return !$this->isExcluded($this->relativePath($file->getPathname()));
        });
        $iterator = new RecursiveIteratorIterator(
            $filtered,
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }
            $relativePath = $this->relativePath($file->getPathname());
            if ($this->isExcluded($relativePath)) {
                continue;
            }
            $zip->addFile($file->getPathname(), $relativePath);
        }

        $zip->addFromString('devshot-manifest.json', json_encode([
            'type' => 'devshot-shopware-project-archive-v1',
            'mediaExcluded' => true,
            'secretsExcluded' => true,
            'createdAt' => gmdate(DATE_ATOM),
        ], JSON_THROW_ON_ERROR));
        $zip->close();
        return $archivePath;
    }

    private function relativePath(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $this->projectDir), '/');
        return ltrim(substr(str_replace('\\', '/', $path), strlen($root)), '/');
    }

    private function isExcluded(string $relativePath): bool
    {
        foreach (self::EXCLUDED_PATH_PATTERNS as $pattern) {
            if (preg_match($pattern, $relativePath) === 1) {
                return true;
            }
        }
        return false;
    }
}
