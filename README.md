# DevShot Connector für Shopware 6

Shopware 6 Plugin, das einen Shop in einen DevShot Studio Workspace überträgt:

- anonymisiertes Datenbank-Backup als JSONL — nur Storefront-Inhalte, keine Personen- und keine Geheimnisdaten
- Projektdateien als ZIP ohne `media`, `public/media`, `public/thumbnail`, `files/media`, `var/cache`, `var/log`, `vendor`, `node_modules`, `.git`, `.env*`, `.ssh`, `auth.json`, `credentials.json`, `.npmrc`, `.netrc` und `config/jwt`
- Upload an die DevShot Console per kurzlebigem Bearer Token
- Manifest mit Startkommando für den Workspace

Dieses Repository ist die einzige Quelle des Plugins. Die DevShot Console lädt es
pinned per Commit-SHA von `codeload.github.com` — es gibt keine zweite Kopie und
kein eingechecktes Build-Artefakt.

## Installation

Die Console erzeugt das vollständige Installationskommando pro Import
(`POST /api/ai/shopware/imports` mit `{"action":"prepare"}`). Manuell:

```bash
curl -fsSL https://codeload.github.com/devshotcom/shopware6-devshot/tar.gz/<COMMIT_SHA> -o /tmp/devshot-connector.tar.gz
mkdir -p custom/plugins/DevshotConnector
tar -xzf /tmp/devshot-connector.tar.gz -C custom/plugins/DevshotConnector --strip-components=1
bin/console plugin:refresh
bin/console plugin:install --activate -n DevshotConnector
```

## Sync starten

```bash
DEVSHOT_ENDPOINT="https://console.devshot.com/api/public/studio/shopware-import" \
DEVSHOT_TOKEN="dssi_…" \
bin/console devshot:workspace:sync
```

Der Workspace erhält nur anonymisierte Datenbankwerte und Projektdateien ohne
Media. Das Token ist an genau einen Import gebunden und läuft ab.
