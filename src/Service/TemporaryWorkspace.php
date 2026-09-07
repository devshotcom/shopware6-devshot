<?php declare(strict_types=1);

namespace Devshot\Connector\Service;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class TemporaryWorkspace
{
    private const PREFIX = 'devshot-connector-';

    public function create(): string
    {
        $temporaryMarker = tempnam(sys_get_temp_dir(), self::PREFIX);
        if ($temporaryMarker === false) {
            throw new RuntimeException('A secure temporary workspace could not be allocated.');
        }
        if (!unlink($temporaryMarker) || !mkdir($temporaryMarker, 0700)) {
            throw new RuntimeException(sprintf('Temporary workspace "%s" could not be created.', $temporaryMarker));
        }

        return $temporaryMarker;
    }

    public function cleanup(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $tempRoot = realpath(sys_get_temp_dir());
        $temporaryWorkspace = realpath($directory);
        if ($tempRoot === false || $temporaryWorkspace === false
            || dirname($temporaryWorkspace) !== $tempRoot
            || !str_starts_with(basename($temporaryWorkspace), self::PREFIX)
        ) {
            throw new RuntimeException(sprintf('Refusing to clean unsafe temporary workspace "%s".', $directory));
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporaryWorkspace, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            $temporaryPath = $file->getPathname();
            if ($file->isLink() || $file->isFile()) {
                if (!unlink($temporaryPath)) {
                    throw new RuntimeException(sprintf('Temporary file "%s" could not be deleted.', $temporaryPath));
                }
                continue;
            }
            if ($file->isDir() && !rmdir($temporaryPath)) {
                throw new RuntimeException(sprintf('Temporary directory "%s" could not be deleted.', $temporaryPath));
            }
        }
        if (!rmdir($temporaryWorkspace)) {
            throw new RuntimeException(sprintf('Temporary workspace "%s" could not be deleted.', $temporaryWorkspace));
        }
    }
}
