<?php declare(strict_types=1);

namespace Devshot\Connector\Service;

use Doctrine\DBAL\Connection;
use RuntimeException;

class AnonymizedDatabaseBackup
{
    private const SAFE_TABLE_PATTERNS = [
        '/^(?:category|category_translation|category_tag|product|product_(?!review|export)[a-z0-9_]+|property_group|property_group_[a-z0-9_]+)$/',
        '/^(?:cms_page|cms_page_translation|cms_section|cms_block|cms_slot|cms_slot_translation)$/',
        '/^(?:theme|theme_translation|theme_sales_channel)$/',
        '/^(?:sales_channel|sales_channel_translation|sales_channel_type|sales_channel_type_translation|sales_channel_domain)$/',
        '/^(?:rule|rule_condition|rule_tag|shipping_method|shipping_method_[a-z0-9_]+|payment_method|payment_method_translation)$/',
        '/^(?:promotion|promotion_translation|promotion_discount|promotion_discount_prices|promotion_sales_channel|promotion_cart_rule|promotion_discount_rule)$/',
        '/^(?:tax|tax_rule|tax_rule_type|currency|currency_translation|country|country_[a-z0-9_]+)$/',
        '/^(?:language|locale|snippet|snippet_set|snippet_set_translation|translation_code)$/',
        '/^(?:custom_field|custom_field_set|custom_field_set_relation|tag|unit|unit_translation|delivery_time|delivery_time_translation)$/',
        '/^(?:seo_url|seo_url_template|seo_url_template_translation)$/',
        '/^(?:mail_template|mail_template_translation|mail_template_type|mail_template_type_translation|mail_header_footer|mail_header_footer_translation)$/',
        '/^(?:state_machine|state_machine_state|state_machine_state_translation|state_machine_transition)$/',
        '/^(?:media_default_folder|media_folder|media_folder_configuration)$/',
        '/^plugin$/',
    ];

    private const SENSITIVE_COLUMN_PATTERN = '/(?:email|mail|first_name|firstname|last_name|lastname|street|zipcode|city|phone|mobile|password|hash|token|secret|access_key|private_key|public_key|client_id|api_key|iban|bic|vat|tax_number|birthday|birthdate|customer_number|order_number)/i';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function create(string $targetDirectory): string
    {
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0770, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException(sprintf('Backup directory "%s" could not be created.', $targetDirectory));
        }

        $backupPath = $targetDirectory . '/database-anonymized.jsonl';
        $handle = fopen($backupPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Backup file "%s" could not be opened.', $backupPath));
        }

        fwrite($handle, json_encode([
            'type' => 'devshot-shopware-anonymized-database-backup-v1',
            'createdAt' => gmdate(DATE_ATOM),
            'scope' => 'storefront-content-without-personal-or-secret-data',
        ], JSON_THROW_ON_ERROR) . PHP_EOL);

        foreach ($this->connection->createSchemaManager()->listTableNames() as $tableName) {
            if (!$this->isSafeTable($tableName)) {
                continue;
            }
            $result = $this->connection->executeQuery(sprintf(
                'SELECT * FROM %s',
                $this->connection->quoteIdentifier($tableName)
            ));
            while (($row = $result->fetchAssociative()) !== false) {
                fwrite($handle, json_encode([
                    'table' => $tableName,
                    'row' => $this->anonymizeRow($tableName, $row),
                ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL);
            }
        }

        fclose($handle);
        return $backupPath;
    }

    private function isSafeTable(string $tableName): bool
    {
        foreach (self::SAFE_TABLE_PATTERNS as $pattern) {
            if (preg_match($pattern, $tableName) === 1) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function anonymizeRow(string $tableName, array $row): array
    {
        $safe = [];
        foreach ($row as $column => $value) {
            $columnName = (string) $column;
            if ($columnName === 'created_by_id' || $columnName === 'updated_by_id') {
                $safe[$columnName] = null;
                continue;
            }
            $safe[$columnName] = $this->safeValue($tableName, $columnName, $value);
        }
        return $safe;
    }

    private function safeValue(string $tableName, string $column, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }
        if (preg_match(self::SENSITIVE_COLUMN_PATTERN, $column) === 1) {
            return $this->maskedValue($tableName, $column, $value);
        }
        if (!is_string($value)) {
            return $value;
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            return ['type' => 'bytes', 'base64' => base64_encode($value)];
        }
        $trimmed = ltrim($value);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return json_encode($this->scrubNested($tableName, $column, $decoded), JSON_THROW_ON_ERROR);
            }
        }
        return $value;
    }

    private function maskedValue(string $tableName, string $column, mixed $value): mixed
    {
        $serialized = is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $fingerprint = substr(hash('sha256', $tableName . ':' . $column . ':' . base64_encode($serialized)), 0, 24);
        if (preg_match('/email|mail/i', $column) === 1) {
            return sprintf('anon-%s@example.invalid', $fingerprint);
        }
        if (is_int($value) || is_float($value)) {
            return abs(crc32($fingerprint));
        }
        return 'anon-' . $fingerprint;
    }

    /** @param array<mixed> $value @return array<mixed> */
    private function scrubNested(string $tableName, string $column, array $value): array
    {
        foreach ($value as $key => $item) {
            $keyName = is_string($key) ? $key : $column;
            if (preg_match(self::SENSITIVE_COLUMN_PATTERN, $keyName) === 1) {
                $value[$key] = $this->maskedValue($tableName, $keyName, $item);
            } elseif (is_array($item)) {
                $value[$key] = $this->scrubNested($tableName, $keyName, $item);
            }
        }
        return $value;
    }
}
