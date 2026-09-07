# DevShot Connector für Shopware 6

Shopware 6 Plugin, das einen Shop in einen DevShot Studio Workspace überträgt:

- anonymisiertes Datenbank-Backup als JSONL — nur Storefront-Inhalte, keine Personen- und keine Geheimnisdaten
- Projektdateien als ZIP ohne `media`, `public/media`, `public/thumbnail`, `files/media`, `var/cache`, `var/log`, `vendor`, `node_modules`, `.git`, `.env*`, `.ssh`, `auth.json`, `credentials.json`, `.npmrc`, `.netrc` und `config/jwt`
- dauerhaftes Ed25519-Pairing mit sichtbarem SHA-256-Fingerprint
- separate Shopware-Admin-Freigabe mit neuem Einmal-Nonce für jeden Upload
- Upload an die DevShot Console per internem, kurzlebigem Einmal-Token
- Manifest mit Startkommando für den Workspace

Dieses Repository ist die einzige Quelle des Plugins. Veröffentlichte Versionen
sind über unveränderliche Git-Tags nachvollziehbar — es gibt keine zweite Kopie
und kein eingechecktes Build-Artefakt.

## Installation

Für die Entwicklung kann das Plugin direkt aus diesem Repository installiert werden:

```bash
git clone https://github.com/devshotcom/shopware6-devshot.git custom/plugins/DevshotConnector
bin/console plugin:refresh
bin/console plugin:install --activate -n DevshotConnector
bin/build-administration.sh
bin/console cache:clear -n
```

## Sicher verbinden

1. Öffne **Shopware Administration → Einstellungen → DevShot-Verbindungen**.
2. Klicke **Pairing-Code erzeugen**. Der Code ist zehn Minuten gültig und nur einmal verwendbar.
3. Gib dem DevShot-Agenten die öffentliche HTTPS-Adresse des Shops und den Pairing-Code. Keine Shopware-Admin-Zugangsdaten und keinen DevShot-Console-Token weitergeben.
4. Der Agent antwortet mit einem vollständigen, anklickbaren Link wie `https://shop.example/admin#/devshot/pairing/request/<request-id>` und seinem Fingerprint.
5. Öffne genau diesen Link, melde dich bei Bedarf in Shopware an, vergleiche den Fingerprint und klicke erst dann **Verbinden**. Das bloße Öffnen des Links erteilt keine Freigabe.

Der Agent erzeugt ein eigenes Ed25519-Schlüsselpaar, sendet nur den öffentlichen
Schlüssel und liefert einen anklickbaren Deep-Link zurück. Der Link öffnet die
authentifizierte Shopware-Administration. Dort muss der angezeigte
SHA-256-Fingerprint mit dem Agenten übereinstimmen und die Verbindung bewusst
freigegeben werden. Der private Schlüssel verlässt DevShot nie; der
Pairing-Code wird im Shop nur als Hash gespeichert und nach einmaliger Nutzung
ungültig.

Die bestätigte Verbindung bleibt unter **Bestehende Verbindungen** sichtbar.
Sie kann dort jederzeit widerrufen werden; danach akzeptiert das Plugin keine
weiteren signierten Anfragen dieses Agenten. Für einen späteren Zugriff ist ein
neues Pairing erforderlich.

## Workspace synchronisieren

1. Der Agent fordert eine neue Synchronisation an und gibt einen zweiten vollständigen Link aus, beispielsweise `https://shop.example/admin#/devshot/pairing/operation/<operation-id>`.
2. Der Agent zeigt zusätzlich denselben Fingerprint und einen neuen 256-Bit-Freigabe-Nonce an.
3. Öffne den Link und vergleiche Fingerprint und Nonce mit der Ausgabe des Agenten.
4. Klicke **Einmal freigeben**. Erst jetzt beginnt der Upload. Jede Freigabe ist zehn Minuten gültig und gilt genau einmal.

Eine dauerhafte Verbindung überspringt diese Freigabe nicht. Für jede
Synchronisation erzeugt das Plugin einen neuen 256-Bit-Nonce. Der Deep-Link
öffnet nur die konkrete Anfrage; er bestätigt sie nicht. Eine Wiederholung mit
demselben Nonce oder derselben Freigabe wird mit HTTP 409 abgewiesen.

Typische Agent-Ausgabe:

```text
Shopware requires a fresh one-time approval for this workspace sync.
Open: https://shop.example/admin#/devshot/pairing/operation/<operation-id>
Agent fingerprint: <fingerprint>
Approval nonce: <one-time-nonce>
```

Jeder signierte Aufruf bindet HTTP-Methode, Pfad, SHA-256-Body-Hash,
Zeitstempel und Transport-Nonce in eine Ed25519-Signatur ein. Zusätzlich schützt
die verpflichtende HTTPS-Verbindung den Transport. Der Workspace erhält weiterhin nur anonymisierte
Datenbankwerte und Projektdateien ohne Media. Das interne Upload-Token ist an
genau einen Import gebunden und läuft ab; Shopware benötigt keine Zugangsdaten
zur DevShot Console.

Die Admin-Erweiterung muss nach Installation oder Update mit dem üblichen
Shopware-Administration-Build gebaut und anschließend der Cache geleert werden.

```bash
bin/build-administration.sh
bin/console cache:clear -n
```

`POST /api/ai/shopware/imports` ist ausschließlich der intern authentifizierte
Agent-Endpunkt. Ein direkter Aufruf aus Shopware oder dem Browser wird deshalb
absichtlich abgewiesen.

## Agent- und Operator-Referenz

Der in DevShot-Workspaces installierte Helfer bietet folgende Befehle:

```text
devshot-shopware-import pair <https-shop-url> <pairing-code>
devshot-shopware-import prepare
devshot-shopware-import status [import-id]
devshot-shopware-import apply [import-id]
devshot-shopware-import --help
```

`pair` und `prepare` warten jeweils bis zu zehn Minuten auf die Entscheidung in
Shopware. `status` und `apply` verwenden ohne explizite ID den zuletzt
vorbereiteten Import. Das vollständige Protokoll, die DevShot-Komponenten und
Fehlerbehandlung sind in der
[DevShot Connector-Dokumentation](https://github.com/devshotcom/devshot/blob/main/docs/shopware-connector.md)
beschrieben.

## Fehlerbehebung

- **401 von `/api/ai/shopware/imports`:** Das ist bei direkten Browser- oder Plugin-Aufrufen korrekt. Nur der authentifizierte DevShot-Agent darf diesen internen Endpunkt verwenden.
- **Pairing oder Freigabe abgelaufen:** Neuen Pairing-Code erzeugen beziehungsweise die Synchronisation erneut anfordern. Alte IDs oder Nonces nicht wiederverwenden.
- **HTTP 409 `shopware_operation_approval_required`:** Die Freigabe fehlt, wurde abgelehnt, ist abgelaufen oder wurde bereits verbraucht. Eine neue Synchronisationsanfrage erzeugen.
- **Fingerprint oder Nonce unterscheiden sich:** Nicht freigeben. Anfrage ablehnen und neu starten.
- **Zugriff dauerhaft beenden:** Unter **Bestehende Verbindungen** auf **Widerrufen** klicken.

## Technische Einstiegspunkte

- `src/Service/PairingService.php` — Pairing-, Freigabe-, Verbrauchs- und Widerrufsstatus
- `src/Security/PairingProtocol.php` — Prüfung kanonischer Ed25519-Signaturen
- `src/Controller/PairingPublicController.php` — signierte öffentliche Pairing- und Sync-Routen
- `src/Controller/PairingAdminController.php` — authentifizierte Admin-Entscheidungen
- `src/Resources/app/administration/src/module/devshot-pairing/` — Shopware-Admin-Oberfläche
- `src/Service/TemporaryWorkspace.php` — private temporäre Exporte und garantierte Bereinigung
