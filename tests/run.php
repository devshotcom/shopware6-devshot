<?php declare(strict_types=1);

use Devshot\Connector\Security\PairingProtocol;
use Devshot\Connector\Service\AiWorkspaceClient;
use Devshot\Connector\Service\TemporaryWorkspace;

require dirname(__DIR__) . '/src/Security/PairingProtocol.php';
require dirname(__DIR__) . '/src/Service/AiWorkspaceClient.php';
require dirname(__DIR__) . '/src/Service/TemporaryWorkspace.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$keypair = sodium_crypto_sign_keypair();
$publicKey = PairingProtocol::encode(sodium_crypto_sign_publickey($keypair));
$secretKey = sodium_crypto_sign_secretkey($keypair);
$body = json_encode(['action' => 'workspace-sync'], JSON_THROW_ON_ERROR);
$timestamp = (string) time();
$nonce = PairingProtocol::encode(random_bytes(24));
$digest = PairingProtocol::contentDigest($body);
$canonical = PairingProtocol::canonicalRequest('POST', '/devshot/api/operations', $timestamp, $nonce, $digest);
$headers = [
    'timestamp' => $timestamp,
    'nonce' => $nonce,
    'contentDigest' => $digest,
    'signature' => PairingProtocol::encode(sodium_crypto_sign_detached($canonical, $secretKey)),
];

$assert(PairingProtocol::verify($publicKey, 'POST', '/devshot/api/operations', $body, $headers), 'valid Ed25519 request must verify');
$assert(!PairingProtocol::verify($publicKey, 'POST', '/devshot/api/operations', $body . ' ', $headers), 'body tampering must fail');
$assert(!PairingProtocol::verify($publicKey, 'POST', '/devshot/api/workspace-sync', $body, $headers), 'path tampering must fail');
$assert(!PairingProtocol::verify($publicKey, 'POST', '/devshot/api/operations', $body, $headers, time() + 301), 'stale timestamps must fail');
$assert(strlen(PairingProtocol::fingerprint($publicKey)) === 64, 'fingerprint must be SHA-256 hex');
$assert(substr_count(PairingProtocol::displayFingerprint(PairingProtocol::fingerprint($publicKey)), ':') === 15, 'display fingerprint must be grouped');

AiWorkspaceClient::assertAllowedEndpoint('https://console.devshot.com/api/public/studio/shopware-import');
$assert(true, 'pinned upload endpoint must be accepted');
foreach ([
    'http://console.devshot.com/api/public/studio/shopware-import',
    'https://console.devshot.com.evil.test/api/public/studio/shopware-import',
    'https://console.devshot.com/api/public/studio/shopware-import?next=https://evil.test',
    'https://127.0.0.1/api/public/studio/shopware-import',
] as $endpoint) {
    try {
        AiWorkspaceClient::assertAllowedEndpoint($endpoint);
        $assert(false, sprintf('unsafe endpoint was accepted: %s', $endpoint));
    } catch (RuntimeException) {
        $assert(true, sprintf('unsafe endpoint rejected: %s', $endpoint));
    }
}

foreach (['services.xml', 'routes.xml'] as $xmlFile) {
    $document = new DOMDocument();
    $assert($document->load(dirname(__DIR__) . '/src/Resources/config/' . $xmlFile), sprintf('%s must be valid XML', $xmlFile));
}

$administrationTemplate = file_get_contents(dirname(__DIR__) . '/src/Resources/app/administration/src/module/devshot-pairing/page/devshot-pairing-page/devshot-pairing-page.html.twig');
$assert(is_string($administrationTemplate) && !str_contains($administrationTemplate, '<sw-empty-state'), 'pairing cards must use compact empty states that stay inside the card');

$temporaryWorkspace = new TemporaryWorkspace();
$temporaryDirectory = $temporaryWorkspace->create();
$assert(is_dir($temporaryDirectory), 'temporary workspace must be created');
$assert(dirname((string) realpath($temporaryDirectory)) === (string) realpath(sys_get_temp_dir()), 'temporary workspace must be outside the Shopware project');
$assert((fileperms($temporaryDirectory) & 0777) === 0700, 'temporary workspace must be private');
file_put_contents($temporaryDirectory . '/database-anonymized.jsonl', "sensitive\n");
mkdir($temporaryDirectory . '/nested', 0700);
file_put_contents($temporaryDirectory . '/nested/project-without-media.zip', 'sensitive');
$temporaryWorkspace->cleanup($temporaryDirectory);
$assert(!file_exists($temporaryDirectory), 'temporary workspace must be removed recursively');

$unsafeMarker = tempnam(sys_get_temp_dir(), 'not-owned-by-devshot-');
if ($unsafeMarker === false || !unlink($unsafeMarker) || !mkdir($unsafeMarker, 0700)) {
    throw new RuntimeException('Unsafe cleanup test directory could not be created.');
}
$unsafeDirectory = $unsafeMarker;
try {
    $temporaryWorkspace->cleanup($unsafeDirectory);
    $assert(false, 'cleanup must reject directories outside its namespace');
} catch (RuntimeException) {
    $assert(true, 'cleanup rejects directories outside its namespace');
} finally {
    rmdir($unsafeDirectory);
}

fwrite(STDOUT, sprintf("OK (%d assertions)\n", $assertions));
