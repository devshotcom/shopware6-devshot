<?php declare(strict_types=1);

namespace Devshot\Connector\Service;

use Devshot\Connector\Exception\PairingConflictException;
use Devshot\Connector\Exception\PairingDeniedException;
use Devshot\Connector\Security\PairingProtocol;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class PairingService
{
    private const INVITATION_TTL = 600;
    private const OPERATION_TTL = 600;

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array<string, mixed> */
    public function createInvitation(): array
    {
        $this->cleanup();
        $id = bin2hex(random_bytes(16));
        $code = 'dsp_' . PairingProtocol::encode(random_bytes(32));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expiresAt = $now->modify('+' . self::INVITATION_TTL . ' seconds');

        $this->connection->insert('devshot_pairing', [
            'id' => $id,
            'code_hash' => hash('sha256', $code),
            'status' => 'invited',
            'expires_at' => $expiresAt->format('Y-m-d H:i:s.v'),
            'created_at' => $now->format('Y-m-d H:i:s.v'),
        ]);

        return [
            'requestId' => $id,
            'pairingCode' => $code,
            'status' => 'invited',
            'expiresAt' => $expiresAt->format(DATE_ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{timestamp?: string, nonce?: string, contentDigest?: string, signature?: string} $headers
     * @return array<string, mixed>
     */
    public function requestAccess(array $payload, string $body, array $headers): array
    {
        $code = is_string($payload['code'] ?? null) ? trim($payload['code']) : '';
        $publicKey = is_string($payload['publicKey'] ?? null) ? trim($payload['publicKey']) : '';
        $agentName = is_string($payload['agentName'] ?? null) ? trim($payload['agentName']) : '';
        $fingerprint = PairingProtocol::fingerprint($publicKey);

        if (!preg_match('/^dsp_[A-Za-z0-9_-]{43}$/', $code)
            || $fingerprint === ''
            || $agentName === ''
            || strlen($agentName) > 120
            || !PairingProtocol::verify($publicKey, 'POST', '/devshot/api/pairings', $body, $headers)) {
            throw new PairingDeniedException('pairing request denied');
        }

        return $this->connection->transactional(function () use ($code, $publicKey, $agentName, $fingerprint): array {
            $row = $this->connection->fetchAssociative(
                'SELECT * FROM devshot_pairing WHERE code_hash = :hash FOR UPDATE',
                ['hash' => hash('sha256', $code)],
            );
            if (!$row || $row['status'] !== 'invited' || $this->isExpired($row['expires_at'] ?? null)) {
                throw new PairingDeniedException('pairing request denied');
            }

            $now = $this->now();
            $this->connection->update('devshot_pairing', [
                'code_hash' => null,
                'public_key' => $publicKey,
                'fingerprint' => $fingerprint,
                'agent_name' => $agentName,
                'status' => 'pending',
                'requested_at' => $now,
                'updated_at' => $now,
            ], ['id' => $row['id']]);

            return [
                'requestId' => $row['id'],
                'status' => 'pending',
                'fingerprint' => $fingerprint,
                'displayFingerprint' => PairingProtocol::displayFingerprint($fingerprint),
                'approvalPath' => '/admin#/devshot/pairing/request/' . $row['id'],
                'expiresAt' => (new \DateTimeImmutable($row['expires_at'], new \DateTimeZone('UTC')))->format(DATE_ATOM),
            ];
        });
    }

    /**
     * @param array{timestamp?: string, nonce?: string, contentDigest?: string, signature?: string} $headers
     * @return array<string, mixed>
     */
    public function status(string $id, string $method, string $path, string $body, array $headers): array
    {
        $row = $this->authorize($id, $method, $path, $body, $headers, true);

        return $this->publicRow($row);
    }

    /**
     * @param array{timestamp?: string, nonce?: string, contentDigest?: string, signature?: string} $headers
     */
    public function requestOperation(string $id, string $method, string $path, string $body, array $headers, string $action): array
    {
        $this->authorize($id, $method, $path, $body, $headers, false);
        if ($action !== 'workspace-sync') {
            throw new PairingConflictException('unsupported operation');
        }

        $operationId = bin2hex(random_bytes(16));
        $approvalNonce = PairingProtocol::encode(random_bytes(32));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expiresAt = $now->modify('+' . self::OPERATION_TTL . ' seconds');
        $this->connection->insert('devshot_pairing_operation', [
            'id' => $operationId,
            'pairing_id' => $id,
            'action' => $action,
            'approval_nonce' => $approvalNonce,
            'status' => 'pending',
            'expires_at' => $expiresAt->format('Y-m-d H:i:s.v'),
            'created_at' => $now->format('Y-m-d H:i:s.v'),
        ]);

        return [
            'operationId' => $operationId,
            'action' => $action,
            'status' => 'pending',
            'approvalNonce' => $approvalNonce,
            'approvalPath' => '/admin#/devshot/pairing/operation/' . $operationId,
            'expiresAt' => $expiresAt->format(DATE_ATOM),
        ];
    }

    /**
     * @param array{timestamp?: string, nonce?: string, contentDigest?: string, signature?: string} $headers
     * @return array<string, mixed>
     */
    public function operationStatus(string $pairingId, string $operationId, string $method, string $path, string $body, array $headers): array
    {
        $this->authorize($pairingId, $method, $path, $body, $headers, false);
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM devshot_pairing_operation WHERE id = :id AND pairing_id = :pairing',
            ['id' => $operationId, 'pairing' => $pairingId],
        );
        if (!$row) {
            throw new PairingDeniedException('signed request denied');
        }

        return $this->publicOperation($row);
    }

    /**
     * @param array{timestamp?: string, nonce?: string, contentDigest?: string, signature?: string} $headers
     */
    public function authorizeSync(string $id, string $operationId, string $method, string $path, string $body, array $headers): void
    {
        $this->authorize($id, $method, $path, $body, $headers, false);
        $now = $this->now();
        $changed = $this->connection->executeStatement(
            'UPDATE devshot_pairing_operation SET status = :consumed, consumed_at = :now, updated_at = :now'
            . ' WHERE id = :operation AND pairing_id = :pairing AND action = :action'
            . ' AND status = :approved AND expires_at >= :now',
            [
                'consumed' => 'consumed',
                'now' => $now,
                'operation' => $operationId,
                'pairing' => $id,
                'action' => 'workspace-sync',
                'approved' => 'approved',
            ],
        );
        if ($changed !== 1) {
            throw new PairingConflictException('workspace sync requires a current, unconsumed admin approval');
        }
        $this->connection->update('devshot_pairing', ['last_sync_at' => $now, 'updated_at' => $now], ['id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $this->cleanup();
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, fingerprint, agent_name, status, expires_at, requested_at, approved_at, last_sync_at, created_at'
            . ' FROM devshot_pairing ORDER BY created_at DESC LIMIT 100',
        );

        return array_map(fn (array $row): array => $this->publicRow($row), $rows);
    }

    /** @return list<array<string, mixed>> */
    public function listOperations(): array
    {
        $this->cleanup();
        $rows = $this->connection->fetchAllAssociative(
            'SELECT operation.*, pairing.fingerprint, pairing.agent_name'
            . ' FROM devshot_pairing_operation operation'
            . ' INNER JOIN devshot_pairing pairing ON pairing.id = operation.pairing_id'
            . ' ORDER BY operation.created_at DESC LIMIT 100',
        );

        return array_map(fn (array $row): array => $this->publicOperation($row), $rows);
    }

    /** @return array<string, mixed> */
    public function decide(string $id, string $decision): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new PairingConflictException('pairing request not found');
        }
        $transitions = [
            'approve' => ['from' => 'pending', 'to' => 'approved'],
            'reject' => ['from' => 'pending', 'to' => 'rejected'],
            'revoke' => ['from' => 'approved', 'to' => 'revoked'],
        ];
        $transition = $transitions[$decision] ?? null;
        if (!$transition) {
            throw new PairingConflictException('invalid pairing decision');
        }

        $now = $this->now();
        $changed = $this->connection->executeStatement(
            sprintf(
                'UPDATE devshot_pairing SET status = :next, expires_at = NULL, %s updated_at = :now WHERE id = :id AND status = :current',
                $decision === 'approve' ? 'approved_at = :now,' : '',
            ),
            ['next' => $transition['to'], 'now' => $now, 'id' => $id, 'current' => $transition['from']],
        );
        if ($changed !== 1) {
            throw new PairingConflictException('pairing state changed; reload before deciding');
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM devshot_pairing WHERE id = :id', ['id' => $id]);

        return $this->publicRow($row ?: []);
    }

    /** @return array<string, mixed> */
    public function decideOperation(string $id, string $decision): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id) || !in_array($decision, ['approve', 'reject'], true)) {
            throw new PairingConflictException('operation request not found');
        }
        $now = $this->now();
        $next = $decision === 'approve' ? 'approved' : 'rejected';
        $changed = $this->connection->executeStatement(
            'UPDATE devshot_pairing_operation SET status = :next, approved_at = :approved, updated_at = :now'
            . ' WHERE id = :id AND status = :pending AND expires_at >= :now',
            [
                'next' => $next,
                'approved' => $decision === 'approve' ? $now : null,
                'now' => $now,
                'id' => $id,
                'pending' => 'pending',
            ],
        );
        if ($changed !== 1) {
            throw new PairingConflictException('operation state changed or expired; reload before deciding');
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM devshot_pairing_operation WHERE id = :id', ['id' => $id]);

        return $this->publicOperation($row ?: []);
    }

    /**
     * @param array{timestamp?: string, nonce?: string, contentDigest?: string, signature?: string} $headers
     * @return array<string, mixed>
     */
    private function authorize(string $id, string $method, string $path, string $body, array $headers, bool $allowPending): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new PairingDeniedException('signed request denied');
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM devshot_pairing WHERE id = :id', ['id' => $id]);
        $allowed = $allowPending ? ['pending', 'approved', 'rejected', 'revoked'] : ['approved'];
        if (!$row || !in_array($row['status'], $allowed, true)
            || ($row['status'] === 'pending' && $this->isExpired($row['expires_at'] ?? null))
            || !PairingProtocol::verify((string) ($row['public_key'] ?? ''), $method, $path, $body, $headers)) {
            throw new PairingDeniedException('signed request denied');
        }

        $nonceHash = hash('sha256', (string) ($headers['nonce'] ?? ''));
        try {
            $this->connection->insert('devshot_pairing_nonce', [
                'pairing_id' => $id,
                'nonce_hash' => $nonceHash,
                'expires_at' => (new \DateTimeImmutable('+10 minutes'))->format('Y-m-d H:i:s.v'),
                'created_at' => $this->now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new PairingDeniedException('signed request denied');
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function publicRow(array $row): array
    {
        $fingerprint = (string) ($row['fingerprint'] ?? '');

        return [
            'requestId' => (string) ($row['id'] ?? ''),
            'agentName' => (string) ($row['agent_name'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'fingerprint' => $fingerprint,
            'displayFingerprint' => PairingProtocol::displayFingerprint($fingerprint),
            'expiresAt' => $row['expires_at'] ?? null,
            'requestedAt' => $row['requested_at'] ?? null,
            'approvedAt' => $row['approved_at'] ?? null,
            'lastSyncAt' => $row['last_sync_at'] ?? null,
            'createdAt' => $row['created_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function publicOperation(array $row): array
    {
        $fingerprint = (string) ($row['fingerprint'] ?? '');

        return [
            'operationId' => (string) ($row['id'] ?? ''),
            'pairingId' => (string) ($row['pairing_id'] ?? ''),
            'agentName' => (string) ($row['agent_name'] ?? ''),
            'fingerprint' => $fingerprint,
            'displayFingerprint' => PairingProtocol::displayFingerprint($fingerprint),
            'action' => (string) ($row['action'] ?? ''),
            'approvalNonce' => (string) ($row['approval_nonce'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'expiresAt' => $row['expires_at'] ?? null,
            'approvedAt' => $row['approved_at'] ?? null,
            'consumedAt' => $row['consumed_at'] ?? null,
            'createdAt' => $row['created_at'] ?? null,
        ];
    }

    private function cleanup(): void
    {
        $this->connection->executeStatement('DELETE FROM devshot_pairing_nonce WHERE expires_at < UTC_TIMESTAMP(3)');
        $this->connection->executeStatement(
            "DELETE FROM devshot_pairing_operation WHERE expires_at < UTC_TIMESTAMP(3) AND status IN ('pending', 'rejected')",
        );
        $this->connection->executeStatement(
            "DELETE FROM devshot_pairing WHERE expires_at < UTC_TIMESTAMP(3) AND status IN ('invited', 'pending', 'rejected')",
        );
    }

    private function isExpired(mixed $value): bool
    {
        if (!is_string($value) || $value === '') {
            return true;
        }

        return new \DateTimeImmutable($value, new \DateTimeZone('UTC')) < new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
