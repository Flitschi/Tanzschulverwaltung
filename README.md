# Tanzschule Falkensee - Intranet & Mitgliederverwaltung

WordPress-Plugin zur umfassenden Verwaltung einer Tanzschule inkl. Online-Vertragsabschluss, Mitgliederbereich, QR-Code-System für die Anwesenheitsverfolgung und umfangreicher Administrationsschnittstelle.

## Funktionen

- **Automatische Benutzeranlage bei Vertragsabschluss**
  - Generierung einer Mitgliedsnummer
  - Erstellung eines WordPress-Nutzerprofils
  - Generierung eines individuellen QR-Codes für Check-in
  - Versand einer Vertragsbestätigung per E-Mail mit Zugangsdaten

- **Mitglieder-Dashboard**
  - Übersicht der Vertragsdaten
  - Anzeige des QR-Codes für Kursbesuch
  - Anzeige des Stundenkontingents
  - Liste der letzten Kursbesuche
  - Interne Neuigkeiten der Tanzschule
  - Profildaten-Verwaltung
  - Kündigungsoption

- **QR-Code-Scanner für Trainer**
  - Scannen der Mitglieds-QR-Codes
  - Anwesenheitserfassung in Kursen
  - Automatischer Abzug von Stunden bei Stundenkontingenten
  - Mobile-optimierte Oberfläche

- **Admin-Bereich für Verwaltung**
  - Übersicht aller Mitglieder
  - Verwaltung von Stundenkontingenten
  - Anwesenheitsübersicht und -export
  - Import-Funktion für Bestandskunden
  - Einstellungsoptionen

## Voraussetzungen

- WordPress 5.0 oder höher
- PHP 7.4 oder höher
- Composer (für die Installation der Abhängigkeiten)

## Installation

1. Lade den Plugin-Ordner in das Verzeichnis `/wp-content/plugins/` hoch
2. Installiere die Abhängigkeiten mit Composer:
   ```
   cd wp-content/plugins/tanzvertrag-plugin
   composer install
   ```
3. Aktiviere das Plugin über das WordPress-Admin-Panel
4. Konfiguriere die Einstellungen unter "Mitglieder" → "Einstellungen"

## Shortcodes

- `[tanzschule_dashboard]` - Zeigt das Mitglieder-Dashboard an
- `[tanzschule_qr_scanner]` - Zeigt den QR-Scanner für Trainer an
- `[tanzschule_kuendigung]` - Zeigt das Kündigungsformular an

## Entwicklungsroadmap

- **Phase 1:** Grundlagen & Systemstruktur
- **Phase 2:** Mitgliederportal (Intranet)
- **Phase 3:** QR-Scanner & Anwesenheitssystem für Trainer
- **Phase 4:** Erweiterung für Gemeinschaftskontingente
- **Phase 5:** Integration mit WooCommerce
- **Phase 6:** Import & Bestandsdaten
- **Phase 7:** Auswertungen & Extras

## Lizenz

Dieses Plugin wurde für die Tanzschule Falkensee entwickelt.
