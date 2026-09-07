<?php declare(strict_types=1);

namespace Devshot\Connector\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1788800000CreatePairingTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1788800000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `devshot_pairing` (
    `id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `code_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `public_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `fingerprint` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `agent_name` VARCHAR(120) NULL,
    `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `expires_at` DATETIME(3) NULL,
    `requested_at` DATETIME(3) NULL,
    `approved_at` DATETIME(3) NULL,
    `last_sync_at` DATETIME(3) NULL,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq.devshot_pairing.code_hash` (`code_hash`),
    KEY `idx.devshot_pairing.status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `devshot_pairing_nonce` (
    `pairing_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `nonce_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `expires_at` DATETIME(3) NOT NULL,
    `created_at` DATETIME(3) NOT NULL,
    PRIMARY KEY (`pairing_id`, `nonce_hash`),
    KEY `idx.devshot_pairing_nonce.expires_at` (`expires_at`),
    CONSTRAINT `fk.devshot_pairing_nonce.pairing_id`
        FOREIGN KEY (`pairing_id`) REFERENCES `devshot_pairing` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `devshot_pairing_operation` (
    `id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `pairing_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `action` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `approval_nonce` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `expires_at` DATETIME(3) NOT NULL,
    `approved_at` DATETIME(3) NULL,
    `consumed_at` DATETIME(3) NULL,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`),
    KEY `idx.devshot_pairing_operation.pairing_status` (`pairing_id`, `status`),
    KEY `idx.devshot_pairing_operation.expires_at` (`expires_at`),
    CONSTRAINT `fk.devshot_pairing_operation.pairing_id`
        FOREIGN KEY (`pairing_id`) REFERENCES `devshot_pairing` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }
}
