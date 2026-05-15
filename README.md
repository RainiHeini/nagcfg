# NagCFG

Web-basierter Konfigurationseditor für Nagios Core 4.x. Ermöglicht das Bearbeiten, Erstellen und Löschen von Nagios-Objekten direkt im Browser.

## Features

- Dashboard mit Übersicht aller Objekttypen und deren Anzahl
- Listen- und Detailansicht für alle Nagios-Objekttypen (Hosts, Services, Hostgroups, Contacts, Commands, Timeperiods, etc.)
- Inline-Editierung mit Autovervollständigung für Direktiven und Referenzen
- Erstellen, Kopieren und Löschen von Objekten
- Automatische Validierung (`nagios -v`) und Reload nach jeder Änderung
- Kaskadierende Umbenennung: Beim Ändern eines Namens werden alle Referenzen automatisch aktualisiert
- Duplikat-Erkennung beim Umbenennen
- Atomare Schreiboperationen mit automatischem Backup
- Einbindung als action_url in Nagios (direkter Link vom Nagios-Webinterface zum Editor)
- Readonly-Modus über Konfiguration
- CSRF-Schutz

## Voraussetzungen

- Nagios Core 4.x
- Apache 2.4 mit mod_php
- PHP 8.0 oder neuer
- Debian/Ubuntu (andere Distributionen mit Anpassungen möglich)

## Installation

### 1. Dateien kopieren

Die Dateien in ein beliebiges Verzeichnis kopieren, das vom Webserver erreichbar ist. In `config.php` die Pfade auf die eigene Nagios-Installation anpassen.

### 2. Berechtigungen setzen

Der Webserver-Benutzer (`www-data`) muss in die Gruppen `nagios` und `nagcmd` aufgenommen werden:

```bash
usermod -aG nagios www-data
usermod -aG nagcmd www-data
systemctl restart apache2
```

Die Nagios-Konfigurationsverzeichnisse und -dateien müssen für die Gruppe beschreibbar sein:

```bash
mkdir -p /usr/local/nagios/etc/backup
chown nagios:nagios /usr/local/nagios/etc/backup
chmod 775 /usr/local/nagios/etc/objects/ /usr/local/nagios/etc/backup/
chmod 664 /usr/local/nagios/etc/objects/*.cfg
```

### 3. Apache konfigurieren

Einen Alias auf das NagCFG-Verzeichnis einrichten und `AllowOverride All` setzen, damit die `.htaccess`-Authentifizierung greift. Den Pfad zur `htpasswd`-Datei in `.htaccess` bei Bedarf anpassen.

### 4. Authentifizierung

Die mitgelieferte `.htaccess` nutzt die Nagios-eigene htpasswd-Datei:

```apache
AuthName "NagCFG"
AuthType Basic
AuthUserFile /usr/local/nagios/etc/htpasswd.users
Require valid-user
```

Falls die htpasswd-Datei an einem anderen Ort liegt, den Pfad in `.htaccess` anpassen.

## action_url-Integration

Über die Einstellungsseite (`/nagcfg/?view=settings`) kann die action_url-Integration aktiviert werden. Dabei werden zwei unsichtbare Templates (`nagcfg-host` und `nagcfg-service`) erstellt und auf alle bestehenden Host- und Service-Templates angewandt. Im Nagios-Webinterface erscheint dann bei jedem Host und Service ein Icon, das direkt zum Editor verlinkt.

Die Integration kann über dieselbe Einstellungsseite wieder deinstalliert werden.

## Dateien

| Datei | Beschreibung |
|---|---|
| `index.php` | Hauptanwendung (Routing, POST-Handler, Views) |
| `NagiosParser.php` | Parser für Nagios-Konfigurationsdateien |
| `NagiosWriter.php` | Schreiboperationen (Save, Delete, Append, Reload) |
| `config.php` | Konfiguration (Pfade, Optionen) |
| `style.css` | Stylesheet |
| `script.js` | Client-seitiges JavaScript |
| `action-gear.gif` | Icon für die action_url-Integration |
| `.htaccess` | Apache-Authentifizierung |

## Hinweise

- HTTPS wird dringend empfohlen (Basic Auth überträgt Zugangsdaten nur Base64-kodiert)
- Bei jeder Schreiboperation wird automatisch ein Backup der betroffenen Datei erstellt
- Die Anzahl der Backups pro Datei ist über `max_backups` in `config.php` konfigurierbar
- Mit `'readonly' => true` in `config.php` kann der Schreibzugriff komplett deaktiviert werden

## Lizenz

MIT
