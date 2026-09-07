<?php declare(strict_types=1);

namespace Devshot\Connector\Service;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiWorkspaceClient
{
    private const UPLOAD_HOST = 'console.devshot.com';
    private const UPLOAD_PATH = '/api/public/studio/shopware-import';

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function send(string $endpoint, string $token, array $manifest, string $databaseBackupPath, string $projectArchivePath): array
    {
        self::assertAllowedEndpoint($endpoint);
        $databaseHandle = fopen($databaseBackupPath, 'rb');
        $archiveHandle = fopen($projectArchivePath, 'rb');

        if ($databaseHandle === false || $archiveHandle === false) {
            throw new RuntimeException('Workspace payload files could not be opened.');
        }

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'X-Devshot-Connector' => 'shopware6-devshot',
                ],
                'body' => [
                    'manifest' => json_encode($manifest, JSON_THROW_ON_ERROR),
                    'database_backup' => $databaseHandle,
                    'project_archive' => $archiveHandle,
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new RuntimeException(sprintf('DevShot Workspace rejected the sync with HTTP %d.', $statusCode));
            }

            $content = $response->getContent(false);
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                throw new RuntimeException('DevShot Workspace returned an invalid response.');
            }

            return $decoded;
        } finally {
            if (is_resource($databaseHandle)) {
                fclose($databaseHandle);
            }

            if (is_resource($archiveHandle)) {
                fclose($archiveHandle);
            }
        }
    }

    public static function assertAllowedEndpoint(string $endpoint): void
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== self::UPLOAD_HOST
            || ($parts['path'] ?? '') !== self::UPLOAD_PATH
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new RuntimeException('Only the pinned DevShot HTTPS upload endpoint is allowed.');
        }
    }
}
