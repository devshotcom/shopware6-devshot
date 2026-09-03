<?php declare(strict_types=1);

namespace Devshot\Connector\Service;

use RuntimeException;

class WorkspaceSyncService
{
    public function __construct(
        private readonly AnonymizedDatabaseBackup $databaseBackup,
        private readonly ProjectFileArchive $fileArchive,
        private readonly AiWorkspaceClient $client,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function sync(string $endpoint, string $token): array
    {
        $syncId = 'shopware-' . gmdate('Ymd-His') . '-' . substr(hash('sha256', random_bytes(16)), 0, 8);
        $targetDirectory = $this->projectDir . '/var/devshot-connector/' . $syncId;

        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0770, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException(sprintf('Workspace sync directory "%s" could not be created.', $targetDirectory));
        }

        $databaseBackupPath = $this->databaseBackup->create($targetDirectory);
        $projectArchivePath = $this->fileArchive->create($targetDirectory);

        $manifest = [
            'syncId' => $syncId,
            'platform' => 'shopware',
            'runtime' => 'local-testserver',
            'createdAt' => gmdate(DATE_ATOM),
            'database' => [
                'format' => 'jsonl',
                'anonymized' => true,
                'path' => basename($databaseBackupPath),
            ],
            'files' => [
                'format' => 'zip',
                'mediaExcluded' => true,
                'secretsExcluded' => true,
                'path' => basename($projectArchivePath),
            ],
            'workspace' => [
                'startCommand' => 'composer install && bin/console system:install --basic-setup && symfony server:start --no-tls',
                'healthCheckPath' => '/',
            ],
        ];

        $response = $this->client->send($endpoint, $token, $manifest, $databaseBackupPath, $projectArchivePath);

        return [
            'syncId' => $syncId,
            'workspaceResponse' => $response,
            'databaseBackupPath' => $databaseBackupPath,
            'projectArchivePath' => $projectArchivePath,
        ];
    }
}
