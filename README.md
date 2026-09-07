# DevShot Connector for Shopware 6

> [!CAUTION]
> **Development environments only.** Install this plugin only in a local, disposable, or otherwise isolated development copy of a Shopware shop that contains synthetic or properly anonymized data. Never install or activate it in production, on a live storefront, or in any environment containing real customer, order, credential, or payment data. Treat the connector as a privileged export component, restrict access to the development shop, and remove the development instance when the import is complete.

The DevShot Connector securely transfers an existing Shopware 6 development shop into a DevShot Studio workspace:

- an anonymized JSONL database backup containing Storefront data only, without personal data or secrets;
- a project ZIP excluding `media`, `public/media`, `public/thumbnail`, `files/media`, `var/cache`, `var/log`, `vendor`, `node_modules`, `.git`, `.env*`, `.ssh`, `auth.json`, `credentials.json`, `.npmrc`, `.netrc`, and `config/jwt`;
- persistent Ed25519 pairing with a visible SHA-256 fingerprint;
- a separate Shopware Administration approval with a fresh one-time nonce for every upload;
- upload to DevShot Console with an internal, short-lived, single-import token; and
- a workspace manifest containing the start command.

This repository is the only source of the plugin. Published versions are traceable through immutable Git tags; there is no second source copy and generated build output is not committed.

## Installation

Before continuing, confirm that the target is an isolated development shop without production data. The connector intentionally refuses arbitrary upload destinations, but it still has access to project files and selected Shopware database tables.

Install the current stable release:

```bash
git clone --branch v0.1.1 --depth 1 https://github.com/devshotcom/shopware6-devshot.git custom/plugins/DevshotConnector
bin/console plugin:refresh
bin/console plugin:install --activate -n DevshotConnector
bin/build-administration.sh
bin/console cache:clear -n
```

## Connect securely

1. Open **Shopware Administration → Settings → DevShot connections** in the development shop.
2. Select **Create pairing code**. The code is valid for ten minutes and can be used only once.
3. Give the DevShot agent the development shop's public HTTPS URL and the pairing code. Do not share Shopware administrator credentials or a DevShot Console token.
4. The agent responds with a full clickable link such as `https://shop.example/admin#/devshot/pairing/request/<request-id>` and its fingerprint.
5. Open that exact link, sign in to Shopware if necessary, compare the fingerprint, and only then select **Connect**. Opening the link does not approve the request.

The agent creates a dedicated Ed25519 key pair and sends only the public key. The deep link opens the authenticated Shopware Administration, where the displayed SHA-256 fingerprint must match the agent output before the connection is explicitly approved. The private key never leaves DevShot. Shopware stores only a hash of the pairing code and invalidates the code after its first use.

The approved connection remains visible under **Existing connections**. It can be revoked there at any time. After revocation, the plugin refuses further signed requests from that agent; reconnecting requires a new pairing code.

## Synchronize a workspace

1. The agent requests a new synchronization and prints a second full link such as `https://shop.example/admin#/devshot/pairing/operation/<operation-id>`.
2. The agent also displays the same fingerprint and a fresh 256-bit approval nonce.
3. Open the link and compare both the fingerprint and nonce with the agent output.
4. Select **Approve once**. The upload starts only after this action. The approval expires after ten minutes and can be consumed exactly once.

A persistent connection never bypasses this approval. The deep link opens only the specific pending request; it does not approve it. Reusing the same operation or nonce is rejected with HTTP 409.

Typical agent output:

```text
Shopware requires a fresh one-time approval for this workspace sync.
Open: https://shop.example/admin#/devshot/pairing/operation/<operation-id>
Agent fingerprint: <fingerprint>
Approval nonce: <one-time-nonce>
```

Every signed request binds the HTTP method, path, SHA-256 body digest, timestamp, and transport nonce into an Ed25519 signature. HTTPS protects the transport. The workspace receives only anonymized database values and project files without media. The internal upload token is bound to one import and expires; Shopware never needs DevShot Console credentials.

After installing or updating the plugin, rebuild the Shopware Administration and clear the cache:

```bash
bin/build-administration.sh
bin/console cache:clear -n
```

`POST /api/ai/shopware/imports` is an internally authenticated agent endpoint. Direct requests from Shopware or a browser are intentionally rejected.

## Agent and operator reference

DevShot Shopware workspaces include these helper commands:

```text
devshot-shopware-import pair <https-shop-url> <pairing-code>
devshot-shopware-import prepare
devshot-shopware-import status [import-id]
devshot-shopware-import apply [import-id]
devshot-shopware-import --help
```

`pair` and `prepare` each wait up to ten minutes for a decision in Shopware. Without an explicit ID, `status` and `apply` use the most recently prepared import. The complete protocol, DevShot components, recovery behavior, and failure handling are documented in the [DevShot Connector documentation](https://github.com/devshotcom/devshot/blob/main/docs/shopware-connector.md).

## Security properties

- Ed25519 proof of possession and request signatures.
- SHA-256 fingerprints displayed by both the agent and Shopware.
- A 256-bit, single-use pairing code stored only as a hash.
- A fresh 256-bit administrator approval nonce for every synchronization.
- Signed timestamp and transport nonce verification with replay rejection.
- Atomic `approved` to `consumed` transition before an upload starts.
- Public HTTPS source URLs with private-network and redirect protection.
- A pinned DevShot HTTPS upload endpoint; arbitrary destinations are rejected.
- Private temporary export directories that are recursively removed after success or failure.

These are concrete protocol guarantees. They are not a claim of hardware-backed key storage, FIPS certification, or a particular military certification profile. The safeguards do not make production installation appropriate.

## Cleanup after an import

1. Revoke the agent under **Existing connections**.
2. Deactivate and uninstall `DevshotConnector` from the development shop.
3. Delete the disposable development instance and its database when they are no longer needed.
4. Do not promote the development database, plugin state, pairing records, or exported artifacts into production.

## Troubleshooting

- **HTTP 401 from `/api/ai/shopware/imports`:** Expected for direct browser or plugin requests. Only the authenticated DevShot agent can use this internal endpoint.
- **Pairing or approval expired:** Create a new pairing code or request a new synchronization. Do not reuse old request IDs or nonces.
- **HTTP 409 `shopware_operation_approval_required`:** The request is pending, rejected, expired, or already consumed. Generate and approve a new synchronization request.
- **Fingerprint or nonce differs:** Do not approve the request. Reject it and start a new one.
- **End access permanently:** Select **Revoke** under **Existing connections**.

## Technical entry points

- `src/Service/PairingService.php` — pairing, approval, consumption, replay protection, and revocation state machine.
- `src/Security/PairingProtocol.php` — canonical Ed25519 request verification.
- `src/Controller/PairingPublicController.php` — signed public pairing, operation, and synchronization routes.
- `src/Controller/PairingAdminController.php` — authenticated Shopware Administration decisions.
- `src/Resources/app/administration/src/module/devshot-pairing/` — pairing, approval, and connection-management UI.
- `src/Service/TemporaryWorkspace.php` — private temporary exports and guaranteed cleanup.

## Development and validation

Run the local protocol and export tests:

```bash
composer test
```

Build and validate with the official Shopware CLI before publishing a release:

```bash
shopware-cli extension validate . --full --format summary
shopware-cli extension build .
```
