<?php declare(strict_types=1);

namespace Devshot\Connector\Service;

class WorkspaceSyncService
{
    public function __construct(
        private readonly AnonymizedDatabaseBackup $databaseBackup,
        private readonly ProjectFileArchive $fileArchive,
        private readonly AiWorkspaceClient $client,
        private readonly TemporaryWorkspace $temporaryWorkspace,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function sync(string $endpoint, string $token): array
    {
        $syncId = 'shopware-' . gmdate('Ymd-His') . '-' . substr(hash('sha256', random_bytes(16)), 0, 8);
        $targetDirectory = $this->temporaryWorkspace->create();

        try {
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
            ];
        } finally {
            $this->temporaryWorkspace->cleanup($targetDirectory);
        }
    }
}
