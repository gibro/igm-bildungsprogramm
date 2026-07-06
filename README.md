# IGM Bildungsprogramm

[![WordPress](https://img.shields.io/badge/WordPress-%E2%89%A5%205.8-21759b?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%207.4-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![Lizenz](https://img.shields.io/badge/Lizenz-GPL--2.0--or--later-blue)](LICENSE)

Eigenständiges WordPress-Plugin für Seminar- und Veranstaltungsprogramme:
durchsuchbare Seminarliste mit Filterleiste, Online-Anmeldung mit automatischem
Mail-Versand, CSV-Import und Marketing-Kacheln. Entwickelt für das
Bildungsprogramm der IG Metall, aber ohne externe Abhängigkeiten und damit auf
jeder WordPress-Installation einsetzbar – es werden keine Page-Builder- oder
Formular-Plugins benötigt.

## Inhalt

- [Funktionen](#funktionen)
- [Voraussetzungen](#voraussetzungen)
- [Installation](#installation)
- [Einrichtung](#einrichtung)
- [Shortcodes](#shortcodes)
  - [Seminarsuche](#bi_seminarsuche--such--und-filterleiste)
  - [Anmeldeformular](#bi_anmeldung--anmeldeformular)
  - [Marketing-Kacheln](#bi_kachel--marketing-kacheln)
  - [Programmjahre trennen](#programmjahre-trennen)
- [Mail-Benachrichtigungen](#mail-benachrichtigungen)
- [Datenmodell](#datenmodell)
- [Anpassung](#anpassung)
- [Aufbau des Codes](#aufbau-des-codes)
- [Lizenz](#lizenz)

## Funktionen

- **Seminare als eigener Beitragstyp** (`bi_seminar`) – gepflegt mit der
  WordPress-eigenen Editier-Oberfläche, inklusive Start-/Enddatum, Seminarnummer,
  Plätzen, Kosten und Buchungsstatus.
- **Such- & Filterleiste** per Shortcode: Freitextsuche, Filter-Chips mit
  Mehrfachauswahl (echtes ODER), Datumsbereich mit Kalender, Live-Trefferzähler.
- **Online-Anmeldung** als vierstufiger Formular-Wizard; Anmeldungen werden in
  der Datenbank gespeichert und sind im Backend einsehbar.
- **Mail-Benachrichtigungen**: beliebig viele Mails pro Anmeldung – an
  Teilnehmer*innen, die zuständige Geschäftsstelle (per PLZ-Zuordnung), das
  Bildungszentrum oder feste Adressen; mit Bedingungen und Platzhaltern.
- **CSV-Import für Seminare** mit frei zuordenbaren Spalten
  (Mapping-Schritt mit Auto-Erkennung).
- **CSV-Import für PLZ → Geschäftsstelle** (eigene Tabelle, schneller Lookup).
- **Marketing-Kacheln**: Bild-/Text-Teaser, die auf die Suche mit vorbefüllten
  Filtern verlinken – inklusive Backend-Builder mit Live-Vorschau.
- **Mini-Dashboard** im Backend mit Kennzahlen, Warnhinweisen (z. B. fehlende
  E-Mail-Adressen) und Einrichtungs-Anleitung.

## Voraussetzungen

| | Version |
|---|---|
| WordPress | ≥ 5.8 |
| PHP | ≥ 7.4 |

Keine weiteren Plugin-Abhängigkeiten. Der Datums-Kalender (flatpickr) wird
mitgeliefert.

## Installation

**Aus dem Release (empfohlen):**

1. Aktuelles Release als ZIP herunterladen.
2. Im WP-Adminbereich unter **Plugins → Installieren → Plugin hochladen** die
   ZIP-Datei hochladen – oder den entpackten Ordner `igm-bildungsprogramm`
   nach `wp-content/plugins/` kopieren.
3. Plugin unter **Plugins** aktivieren.

**Aus dem Repository:**

```bash
cd wp-content/plugins
git clone https://github.com/<user>/igm-bildungsprogramm.git
```

Beim Aktivieren werden die Tabellen `wp_bi_plz` und `wp_bi_anmeldungen`
angelegt, der Beitragstyp registriert und drei Standard-Benachrichtigungen
erstellt. Deaktivieren und Löschen entfernt keine Daten.

## Einrichtung

Alle Schritte finden sich auch im Backend unter **Bildungsprogramm → Übersicht**.

1. **PLZ importieren** – *Einstellungen → Tab „PLZ-Import"*: CSV mit den Spalten
   `PLZ`, `Geschäftsstelle`, `E-Mail` (Trennzeichen `,` oder `;` wird automatisch
   erkannt).
2. **Seminare importieren** – *Einstellungen → Tab „Seminar-Import"*: CSV
   hochladen, im zweiten Schritt jede Spalte einem Feld zuordnen. Mehrfach-Felder
   (Zielgruppe, Freistellung) dürfen mehrere Werte je Zelle enthalten, getrennt
   mit `|` oder `,`. Alternativ lassen sich Seminare komplett von Hand anlegen.
3. **Bildungszentren-Mails** – unter *Seminare → Bildungszentrum* je Eintrag eine
   E-Mail-Adresse hinterlegen (für den Trigger „Mail an Bildungszentrum").
4. **Mail-Benachrichtigungen** prüfen und anpassen – gleichnamiger Menüpunkt.
5. **Such-Seite** anlegen mit `[bi_seminarsuche]`.
6. **Anmelde-Seite** anlegen mit `[bi_anmeldung]`.
7. Unter **Einstellungen → Seiten** die beiden Seiten zuordnen (oder die
   automatische Erkennung nutzen) und die Anmeldevarianten konfigurieren.

## Shortcodes

| Shortcode | Zweck | Attribute |
|---|---|---|
| `[bi_seminarsuche]` | Such-/Filterleiste + Ergebnisliste | `anmeldung_url`, `per_page` (Standard 20), `programm` |
| `[bi_anmeldung]` | Anmeldeformular | `seminar="ID"` für ein festes Seminar |
| `[bi_kachel]` | Marketing-Kachel (Teaser mit Filter-Link) | [siehe unten](#bi_kachel--marketing-kacheln) |
| `[bi_kacheln]` | Optionaler Grid-Container für mehrere Kacheln | `spalten` (2/3/4, Standard 3) |

### `[bi_seminarsuche]` – Such- und Filterleiste

Zeigt die Filterleiste (Freitext, Bildungszentrum, Themenfeld, Zielgruppe,
Freistellung, Zeitraum) mit der Ergebnisliste. Gefiltert wird über
GET-Parameter (`?thema=…&ort=…`, Mehrfachwerte pipe-getrennt) – Filterseiten
sind damit verlinkbar und bookmarkfähig. Angezeigt werden nur kommende,
sichtbare Seminare, sortiert nach Startdatum.

Aus der Ergebnisliste führt jedes Seminar auf seine Detailseite; von dort wird
das Seminar automatisch per `?seminar=ID` an die Anmelde-Seite übergeben.

### `[bi_anmeldung]` – Anmeldeformular

Vierstufiger Formular-Wizard (Persönliches → Kontakt → Betrieb → Abschluss)
mit clientseitiger Schritt-Validierung und serverseitiger Verarbeitung.
Die zuständige Geschäftsstelle wird über die **betriebliche PLZ** ermittelt.
Pro Seminar steuern Einstellungen und Regeln, ob die Direktanmeldung oder der
Verweis auf die Geschäftsstelle angeboten wird.

### `[bi_kachel]` – Marketing-Kacheln

Eine Kachel ist ein klickbarer Teaser (Bild + Überschrift + Text + Button), der
auf die Seminarübersicht mit vorbefüllten Filtern verlinkt. Sie füllt immer die
volle Breite ihrer umgebenden Box – die Größe bestimmt der Container (z. B. eine
Elementor-Spalte oder eine Gutenberg-Spalte), in den der Shortcode eingefügt wird.

**Am einfachsten über den Builder:** Menüpunkt **Bildungsprogramm →
Marketing-Kacheln** – Kachel gestalten, Filter per Checkbox auswählen (dieselbe
Auswahl wie in der Frontend-Filterleiste), Bildausschnitt und Fokuspunkt per
Klick festlegen, Live-Vorschau prüfen und den fertigen Shortcode kopieren.

```text
[bi_kachel layout="1" bild="1234" titel="BR kompakt" text="Die Grundlagenreihe für neu gewählte Betriebsrät*innen." thema="BR kompakt"]
[bi_kachel layout="2" bild="1235" titel="Seminare in Lohr" ort="Bildungszentrum Lohr" ueberschrift="h2"]
[bi_kachel layout="1" bild="1236" titel="Unsere Empfehlungen" nr="LO12345|BO67890" button="Jetzt entdecken"]
```

**Layouts:** `layout="1"` = Bild oben, Text darunter (Standard) ·
`layout="2"` = Text über dem Bild (Overlay mit dunklem Verlauf).

**Filter-Attribute** (entsprechen 1:1 den GET-Parametern der Suche,
Mehrfachwerte mit `|`): `q` (Titelsuche), `ort`, `thema`, `ziel`, `frei`
(Gruppen-Labels wie in der Filterleiste), `von` / `bis` (JJJJ-MM-TT),
`nr` (Seminarnummern, z. B. `nr="LO12345|BO67890"`).

**Darstellungs-Attribute:**

| Attribut | Bedeutung |
|---|---|
| `bild` | Attachment-ID oder Bild-URL |
| `ratio` | Bildausschnitt-Seitenverhältnis, z. B. `16:9`, `1:1`, `21:9`; `auto` = nicht beschneiden (nur Layout 1); Standard 16:10 |
| `fokus` | Fokuspunkt des Zuschnitts als `"x% y%"` – im Builder per Klick ins Bild setzbar |
| `titel`, `text` | Überschrift und Teaser-Text |
| `ueberschrift` | HTML-Tag der Überschrift: `h1`–`h3` (Standard `h3`) |
| `button` | Button-Beschriftung (Standard „Zu den Seminaren") |
| `url` | feste Ziel-URL statt Filter-Link |
| `suche_url` | andere Suchseite als die konfigurierte |
| `programm` | Programmjahr für den Redaktions-Zähler (passend zum `programm`-Attribut der Zielseite) |

**Redaktions-Zähler:** Eingeloggte Redakteur*innen sehen auf jeder Kachel ein
Badge mit der aktuellen Trefferzahl des Links („12 buchbare Seminare"; rot bei
0 Treffern). Besucher sehen es nicht – für sie läuft auch keine zusätzliche
Datenbank-Abfrage.

**Mehrere Kacheln ohne Page-Builder** nebeneinander:
`[bi_kacheln spalten="3"] … [/bi_kacheln]` um mehrere `[bi_kachel …]` legen
(mobil automatisch einspaltig).

### Programmjahre trennen

Die CSV-Spalte `programm` (z. B. 2025/2026) wird als Taxonomie `bi_programm`
importiert. Mit dem Attribut `programm` lässt sich eine Seite fest auf einen
Jahrgang beschränken:

```text
Seite „Bildungsprogramm 2026":  [bi_seminarsuche programm="2026"]
Seite „Bildungsprogramm 2025":  [bi_seminarsuche programm="2025"]
```

Ohne das Attribut werden alle Jahrgänge gemeinsam gezeigt (vergangene Termine
sind ohnehin durch den „buchbar"-Filter auf Startdatum ≥ heute ausgeblendet).

## Mail-Benachrichtigungen

Nach jeder Anmeldung können beliebig viele Mails versendet werden. Jeder
Trigger hat einen Empfänger-Typ:

- **Zuständige Geschäftsstelle** – Empfänger über PLZ-Lookup (`wp_bi_plz`).
- **Teilnehmer*in** – Bestätigung an die angegebene E-Mail.
- **Bildungszentrum** – an die E-Mail des Seminarort-Terms.
- **Ansprechpartner*in** – an die im Seminar hinterlegte Kontaktadresse.
- **Feste/eigene Adresse** – fester Empfänger (Platzhalter erlaubt).

Optionale **Bedingung** pro Trigger: nur senden, wenn das Seminar einen
bestimmten Taxonomie-Wert hat (z. B. nur bei Handlungsfeld „Datenschutz").
Ein **Test-Modus** leitet alle Mails an eine Testadresse um.

**Platzhalter** u. a.: `{vorname}`, `{nachname}`, `{name}`, `{email}`,
`{telefon}`, `{betrieb}`, `{plz}`, `{nachricht}`, `{geschaeftsstelle}`,
`{geschaeftsstelle_email}`, `{seminar_titel}`, `{seminar_nummer}`,
`{seminar_startdatum}`, `{seminar_ort}`, `{datum}`.

> **Hinweis:** Zuverlässige Mail-Zustellung hängt von der
> WordPress-Installation ab – ein SMTP-Plugin (z. B. WP Mail SMTP) wird
> empfohlen.

## Datenmodell

| Element | Speicherort |
|---|---|
| Seminartitel / Beschreibung | Post (`post_title`, `post_content`) |
| Startdatum, Enddatum, Seminarnummer, Plätze, Kosten … | Post-Meta `_bi_*` |
| Bildungszentrum, Handlungsfeld | Taxonomien `bi_ort`, `bi_handlungsfeld` |
| Zielgruppe, Freistellung (mehrfach) | Taxonomien `bi_zielgruppe`, `bi_freistellung` |
| Programmjahr (Jahrgang) | Taxonomie `bi_programm` |
| PLZ-Zuordnung | Tabelle `wp_bi_plz` |
| Anmeldungen | Tabelle `wp_bi_anmeldungen` |
| Mail-Benachrichtigungen | Option `bi_mail_triggers` |
| Einstellungen | Option `bi_settings` |

Die Filter-Facetten sind bewusst Taxonomien: WordPress filtert darüber mit
echtem ODER (`tax_query`, `operator IN`), auch bei Mehrfachwerten pro Seminar.

## Anpassung

- **Akzentfarbe**: alle Frontend-Komponenten nutzen die CSS-Variable
  `--bi-accent` (Standard `#e2001a`) und lassen sich per Theme-CSS umfärben.
- **Anmeldevarianten**: unter *Einstellungen* lassen sich Direktanmeldung und
  Geschäftsstellen-Verweis konfigurieren, inklusive Regel-Engine
  („wenn Freistellung Bildungsurlaub enthält → Variante 2").
- Die Templates der Detailseite liegen unter `templates/` und folgen den
  üblichen WordPress-Mechanismen (`template_include`).

## Aufbau des Codes

```text
igm-bildungsprogramm/
├─ igm-bildungsprogramm.php     Bootstrap: Konstanten, Module laden, Aktivierung
├─ includes/
│  ├─ class-bi-cpt.php          CPT bi_seminar + Taxonomien + Editier-Metabox
│  ├─ class-bi-filter.php       Such-/Filterleiste [bi_seminarsuche]
│  ├─ class-bi-kacheln.php      Marketing-Kacheln [bi_kachel] + Backend-Builder
│  ├─ class-bi-detail.php       Seminar-Detailseite
│  ├─ class-bi-registration.php Anmeldeformular [bi_anmeldung] + Tabelle
│  ├─ class-bi-mailer.php       Mail-Trigger-Engine + Einstellungsseite
│  ├─ class-bi-import.php       Seminar-CSV-Import mit Spalten-Mapping
│  ├─ class-bi-plz.php          PLZ→Geschäftsstelle: Tabelle, Lookup, Import
│  ├─ class-bi-settings.php     Einstellungen (Anmeldevarianten, Regeln, Seiten)
│  └─ class-bi-admin.php        Admin-Menü + Mini-Dashboard
├─ templates/                    Detailseiten-Template
└─ assets/                       CSS, JS, flatpickr (gebündelt)
```

## Lizenz

[GPL-2.0-or-later](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html) –
wie WordPress selbst.
