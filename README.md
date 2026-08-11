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
- [Kampagnen-Auswertung](#kampagnen-auswertung)
- [Datenmodell](#datenmodell)
- [Anpassung](#anpassung)
- [Aufbau des Codes](#aufbau-des-codes)
- [Lizenz](#lizenz)

## Funktionen

- **Seminare als eigener Beitragstyp** (`bi_seminar`) – gepflegt mit der
  WordPress-eigenen Editier-Oberfläche, inklusive Start-/Enddatum, Seminarnummer,
  Plätzen, Kosten und Buchungsstatus.
- **Online-Seminare als zweiter Beitragstyp** (`bi_online`) – eigene Liste und
  eigenes Feldset (Untertitel, Referent\*innen, Veranstalter\*in, Webinar-Tool,
  Anmelde- und Teilnahme-Link), aber dieselben Taxonomien und dieselbe
  Darstellung im Frontend.
- **Such- & Filterleiste** per Shortcode: Freitextsuche, Filter-Chips mit
  Mehrfachauswahl (echtes ODER), Datumsbereich mit Kalender, Live-Trefferzähler.
  Der Chip **Seminarform** trennt Präsenz- und Online-Seminare.
- **Online-Anmeldung** als vierstufiger Formular-Wizard; Anmeldungen werden in
  der Datenbank gespeichert und sind im Backend einsehbar.
- **Mail-Benachrichtigungen**: beliebig viele Mails pro Anmeldung – an
  Teilnehmer*innen, die zuständige Geschäftsstelle (per PLZ-Zuordnung), das
  Bildungszentrum oder feste Adressen; mit Bedingungen und Platzhaltern.
- **CSV-Import für Seminare** mit frei zuordenbaren Spalten
  (Mapping-Schritt mit Auto-Erkennung) – je ein eigener Tab für Präsenz- und
  Online-Seminare.
- **CSV-Import für PLZ → Geschäftsstelle** (eigene Tabelle, schneller Lookup).
- **Marketing-Kacheln**: Bild-/Text-Teaser, die auf die Suche mit vorbefüllten
  Filtern verlinken – inklusive Backend-Builder mit Live-Vorschau.
- **Kampagnen-Auswertung**: eigene Links für Newsletter und Mailings, die den
  Weg vom Klick über die Seminaransicht bis zur abgeschickten Anmeldung
  nachvollziehbar machen.
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

Beim Aktivieren werden die Tabellen `wp_bi_plz`, `wp_bi_anmeldungen`,
`wp_bi_kampagnen` und `wp_bi_events` angelegt, der Beitragstyp registriert und
drei Standard-Benachrichtigungen erstellt. Deaktivieren und Löschen entfernt keine Daten.

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
8. Optional: unter **Kampagnen** je Newsletter einen Link anlegen, wenn die
   Wirkung von Mailings ausgewertet werden soll.

## Shortcodes

| Shortcode | Zweck | Attribute |
|---|---|---|
| `[bi_seminarsuche]` | Such-/Filterleiste + Ergebnisliste | `anmeldung_url`, `per_page` (Standard 20), `programm`, `form` (`praesenz`/`online`) |
| `[bi_suchmaske]` | Nur die Suchmaske, ohne Ergebnisliste – Button springt auf die Übersicht | `ziel_url`, `button`, `titel`, `kicker`, `hinweis`, `programm`, `form` |
| `[bi_anmeldung]` | Anmeldeformular | `seminar="ID"` für ein festes Seminar |
| `[bi_kachel]` | Marketing-Kachel (Teaser mit Filter-Link) | [siehe unten](#bi_kachel--marketing-kacheln) |
| `[bi_kacheln]` | Optionaler Grid-Container für mehrere Kacheln | `spalten` (2/3/4, Standard 3) |

### `[bi_seminarsuche]` – Such- und Filterleiste

Zeigt die Filterleiste (Freitext, Seminarform, Bildungszentrum, Themenfeld,
Zielgruppe, Freistellung, Zeitraum) mit der Ergebnisliste. Gefiltert wird über
GET-Parameter (`?thema=…&ort=…`, Mehrfachwerte pipe-getrennt) – Filterseiten
sind damit verlinkbar und bookmarkfähig. Angezeigt werden nur kommende,
sichtbare Seminare, sortiert nach Startdatum.

Die Liste enthält **Präsenz- und Online-Seminare gemeinsam**; der Chip
*Seminarform* (`?form=praesenz` bzw. `?form=online`) schränkt darauf ein. Der
Chip erscheint nur, wenn beide Formen im aktuellen Ergebnis vorkommen. Soll eine
Seite dauerhaft nur eine Form zeigen, setzt man das Attribut
`form="online"` – dann entfällt der Chip.

Online-Seminare erkennt man in der Liste am Badge **Online**; statt des
Bildungszentrums steht dort die Veranstalter\*in, statt der Tagesdauer die
Uhrzeit-Spanne. Importiert werden sie über *Einstellungen → Online-Seminar-Import*.

Aus der Ergebnisliste führt jedes Seminar auf seine Detailseite; von dort wird
das Seminar automatisch per `?seminar=ID` an die Anmelde-Seite übergeben.

### Online-Seminare und die Anmelde-Weiche

Auf der Detailseite eines Online-Seminars entscheidet das Feld **Webinar-Tool**,
wohin der Buchungs-Button führt:

| Webinar-Tool | Anmeldelink | Buchungs-Button |
|---|---|---|
| Teams – Webinar | gesetzt | „Zur Anmeldung" → externe Anmeldeseite des Webinars |
| Teams – Webinar | leer | Fallback auf das interne Formular (Hinweis im Dashboard) |
| Teams – Besprechung / anderes Tool | – | internes Anmeldeformular `[bi_anmeldung]` |

Zusätzlich gilt weiterhin: ausgebuchte Seminare zeigen keinen Button, und die
Regel-Engine unter *Einstellungen* kann einzelne Seminare auf die
Geschäftsstellen-Variante schicken. Ist ein **Online-Link (öffentlich)**
hinterlegt, erscheint darunter der Button „Direkt teilnehmen".

Sonderfall **offene Veranstaltung**: Steht „Anmeldung möglich" auf *nein* und ist
ein öffentlicher Online-Link hinterlegt, entfällt die Geschäftsstellen-Suche –
dann zählt nur der Teilnahme-Link („eine Anmeldung ist nicht nötig").

### `[bi_anmeldung]` – Anmeldeformular

Vierstufiger Formular-Wizard (Persönliches → Kontakt → Betrieb → Abschluss)
mit clientseitiger Schritt-Validierung und serverseitiger Verarbeitung.
Die zuständige Geschäftsstelle wird über die **betriebliche PLZ** ermittelt.
Das Feld **„Freistellung nach"** listet genau die Freistellungen, die am
Seminar hinterlegt sind (Taxonomie `bi_freistellung` aus der CSV-Spalte
„Freistellung"). Gibt es nur eine, ist sie vorausgewählt; ist am Seminar nichts
gepflegt, greift die kanonische Gesamtliste. Serverseitig wird geprüft, dass
der gesendete Wert zu den Seminardaten passt.
Pro Seminar steuern Einstellungen und Regeln, ob die Direktanmeldung oder der
Verweis auf die Geschäftsstelle angeboten wird.

### Geschäftsstellen-Anfrage direkt auf der Detailseite (GS-Variante)

Bei Seminaren mit Geschäftsstellen-Anmeldung (z. B. Bildungsurlaub) enthält
die Sidebar eine **integrierte PLZ-Suche**: Postleitzahl eingeben →
AJAX-Lookup gegen die `wp_bi_plz`-Tabelle → die zuständige Geschäftsstelle
wird angezeigt und ein Button **„Anfrage senden"** öffnet das Mailprogramm
(`mailto:`) mit vorausgefüllter Anfrage: Seminarnummer und Titel im Betreff,
Zeitraum, Bildungszentrum, Seminar-Link und ein Datenblock zum Ausfüllen
(Name, Anschrift, …) im Text. Auch die GS-Buttons in der Tabelle „Weitere
Termine" werden nach erfolgreicher Suche auf mailto-Links mit den Daten des
jeweiligen Termins umgeschrieben. Die PLZ wird in `localStorage` gemerkt.
Ist zur Geschäftsstelle keine E-Mail hinterlegt (oder die PLZ unbekannt),
bleibt der Fallback-Link zur Geschäftsstellensuche auf igmetall.de.

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

#### Vorgefertigte Themen-Kacheln (`[bi_kachel_vorlagen]`)

Im Tab **Bildungsprogramm → Marketing-Kacheln → Kachel-Vorlagen** wird jedem
Themenfeld-Filter ein Bild aus der **Mediathek** zugeordnet (die lizenzierten
IG-Metall-Motive dort hochladen – nicht ins Plugin legen, so überleben sie
Plugin-Updates und WordPress erzeugt automatisch alle Bildgrößen) sowie
bewusst ein **Layout** gewählt (1 = Bild oben, Überschrift darunter –
Standard; 2 = Overlay – beide Varianten sind oben auf der Seite als
Vorschau zu sehen).
Die Liste entspricht exakt der Themenfeld-Filterleiste im Frontend: gleiche
Einträge (nur mit buchbaren Seminaren), gleiche Überschriften, gleiche
Reihenfolge. Daraus entstehen fertige Kacheln ohne Teaser-Text, nur mit dem
Filter-Label als Überschrift (z. B. „Grundlagen für Betriebsrät*innen"),
verlinkt auf die Übersicht mit vorausgewähltem Themenfeld. Pro Zeile steht
der fertige `[bi_kachel …]`-Shortcode zum Kopieren;
`[bi_kachel_vorlagen spalten="3"]` rendert alle Filter mit zugeordnetem Bild
auf einmal als Grid – in der Reihenfolge der Filterleiste, jede Kachel im
für sie gewählten Layout (optional `ratio`, `button`). Zuordnungen bleiben
gespeichert, auch wenn ein Themenfeld zwischenzeitlich keine buchbaren
Seminare hat.

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

**Seminarform** pro Trigger: *Präsenz- und Online-Seminare* (Standard, so
verhalten sich auch alle bestehenden Benachrichtigungen), *nur Präsenz* oder
*nur Online*. Damit lassen sich zwei Fassungen desselben Textes sauber trennen –
Online-Seminare brauchen keinen Anreisetag, dafür den Zugangslink.

Optionale **Bedingung** pro Trigger: nur senden, wenn das Seminar einen
bestimmten Taxonomie-Wert **hat** oder **nicht hat** (z. B. nur bei
Handlungsfeld „Datenschutz", oder nur wenn Bildungszentrum *nicht*
„Kritische Akademie" ist). Sollen sich zwei Trigger an denselben Empfänger
gegenseitig ausschließen, bekommen sie gegenteilige Bedingungen auf denselben
Wert („hat" / „nicht hat") – dann greift pro Anmeldung genau einer. Ein
**Konsistenz-Check** oben auf der Trigger-Seite warnt vor Doppelversand
(mehrere Trigger an denselben Empfänger, die gleichzeitig greifen können)
und möglichen Lücken (kein Trigger greift). Er ist rein informativ und
greift nicht in den Versand ein. Ein **Test-Modus** leitet alle Mails an
eine Testadresse um.

**Platzhalter** u. a.: `{vorname}`, `{nachname}`, `{name}`, `{email}`,
`{telefon}`, `{betrieb}`, `{plz}`, `{nachricht}`, `{geschaeftsstelle}`,
`{geschaeftsstelle_email}`, `{seminar_titel}`, `{seminar_nummer}`,
`{seminar_startdatum}`, `{seminar_ort}`, `{datum}`.
Für Online-Seminare zusätzlich: `{seminar_form}`, `{seminar_untertitel}`,
`{seminar_referenten}`, `{seminar_plattform}`, `{seminar_online_link}`,
`{seminar_anmeldelink}` – bei Präsenz-Seminaren bleiben sie leer, dieselbe
Vorlage taugt also für beide Formen.

Der Empfänger-Typ **Ansprechpartner\*in** gilt für beide Seminarformen; bei
Online-Seminaren heißt dasselbe Feld nur *Anmeldung (E-Mail)*.

### Absender und Antwortadresse

Je Benachrichtigung lassen sich **Absender (From)** und **Antworten an
(Reply-To)** getrennt setzen. Die Antwortadresse akzeptiert Platzhalter –
typisch `{email}`, damit die Geschäftsstelle direkt der angemeldeten Person
antworten kann, oder `{geschaeftsstelle_email}` in der Teilnehmerbestätigung.
Erlaubt sind auch feste Adressen, die Form `Name <mail@domain.de>` und mehrere
Adressen mit Komma. Ergibt der Platzhalter keine gültige Adresse, bleibt der
Header weg und die Mail geht trotzdem raus. In der Wochenzusammenfassung
werden die Antwortadressen aller gesammelten Anmeldungen zusammengefasst.

### Anhänge

Jede Benachrichtigung mit Sofortversand kann zwei Dateien mitschicken, die beim
Versand aus den Seminar- und Anmeldedaten erzeugt und danach wieder gelöscht
werden:

- **Seminardetails (PDF)** – alle Angaben zum gebuchten Seminar auf einem Blatt,
  mit dem hinterlegten Logo oben rechts.
- **Beschlussvorlage (Word, .docx)** – „Mitteilung über Seminarteilnahme nach
  § 37 Abs. 6 BetrVG" an den Arbeitgeber. Bewusst **kein PDF**: Der Betriebsrat
  muss Sitzungsdatum sowie Ort und Datum noch ergänzen und das Schreiben auf
  seinen eigenen Briefbogen bringen.

  Der Wortlaut folgt exakt der vorgegebenen Muster-Vorlage. Eingesetzt werden nur
  Angaben, die aus der Anmeldung sicher bekannt sind: **betriebliche** Anschrift
  (`betrieb`, `betrieb_strasse`, `betrieb_plz`, `betrieb_ort` – nicht die
  Privatadresse), Name der angemeldeten Person, Seminartitel, Veranstalter,
  Beginn/Ende. Sitzungsdatum sowie Ort/Datum bleiben als Punktelinie stehen.
  Das Schreiben bekommt **bewusst kein Logo** – es kommt vom Betriebsrat, nicht
  vom Veranstalter.

Logo und Veranstalterangabe stehen zentral unter *Einstellungen → PDF-Anhänge*,
dort gibt es auch eine Vorschau beider Dokumente. Ohne Eintrag steht als
Veranstalter `Industriegewerkschaft Metall`. Bei der **Wochenzusammenfassung**
gibt es keine Anhänge, weil dort mehrere Seminare in einer Mail stecken.

### Rich Text mit Klartext-Fallback

Die Texte werden als **reiner Text mit Steuerzeichen** gepflegt – über jedem
Textfeld liegt dafür eine kleine Formatier-Leiste (Fett, Kursiv, Überschrift,
Aufzählung, Link, Trennlinie) plus ein Auswahlfeld, das Platzhalter an der
Cursorposition einsetzt:

| Steuerzeichen | Wirkung |
|---|---|
| `**fett**` | fetter Text |
| `_kursiv_` | kursiver Text |
| `# … `, `## … `, `### … ` | Überschriften |
| `- Punkt` | Aufzählung |
| `[Text](https://…)` | Link |
| `---` | Trennlinie |

Beim Versand entstehen daraus zwei Fassungen: eine gestaltete HTML-Mail und
eine Klartext-Fassung ohne Steuerzeichen. Sie gehen als `multipart/alternative`
raus (HTML als Body, Klartext als `AltBody` über den Hook `phpmailer_init`) –
Mailprogramme ohne HTML-Darstellung zeigen automatisch den Text. Die Checkbox
*Darstellung → Gestaltete Mails senden (HTML)* (Option `bi_mail_format`)
schaltet auf reinen Textversand um; die Steuerzeichen werden auch dann
entfernt.

Gespeichert wird ausschließlich der Quelltext mit Steuerzeichen, nie HTML. Der
Text wird vor der Umwandlung vollständig escaped, damit eingesetzte
Platzhalterwerte (Namen, Betriebe, Bemerkungen) kein Markup in die Mail
schmuggeln können; eigenes HTML ist entsprechend nicht möglich.

### Sofort oder wöchentlich gesammelt

Jede Benachrichtigung hat eine **Versandart**:

- **Sofort** (Standard) – eine Mail je Anmeldung, direkt beim Absenden.
- **Wöchentliche Zusammenfassung** – die Anmeldung wird nicht sofort
  versendet, sondern fertig gerendert in einer Warteschlange abgelegt
  (Option `bi_mail_queue`). Ein WP-Cron-Job (`bi_mail_weekly_digest`) fasst
  sie zum eingestellten Wochentermin (Option `bi_mail_schedule`, Wochentag +
  Uhrzeit im Kasten *Wöchentlicher Versand*) je **Benachrichtigung und
  Empfänger** zu einer Mail zusammen – jede Geschäftsstelle bekommt also nur
  ihre eigenen Anmeldungen. Betreff und Einleitung der Sammelmail sind pro
  Benachrichtigung konfigurierbar; dort gelten die Platzhalter `{anzahl}`,
  `{zeitraum}`, `{benachrichtigung}` und `{datum}` (die Platzhalter einer
  einzelnen Anmeldung stehen nur im normalen Text zur Verfügung, der je
  gesammelter Anmeldung wiederholt wird).

Weil Betreff und Text schon beim Eintreffen der Anmeldung gerendert werden,
bleiben eingereihte Einträge korrekt, auch wenn die Benachrichtigung später
geändert oder gelöscht wird. Der Button **„Warteschlange jetzt versenden"**
leert sie sofort, ohne den regulären Termin zu verschieben.

> **Hinweis:** WP-Cron läuft nur, wenn die Seite besucht wird – der Versand
> kann sich um einige Minuten verschieben. Ist WP-Cron per `DISABLE_WP_CRON`
> abgeschaltet, warnt die Übersichtsseite, sobald ein Termin fehlt.

> **Hinweis:** Zuverlässige Mail-Zustellung hängt von der
> WordPress-Installation ab – ein SMTP-Plugin (z. B. WP Mail SMTP) wird
> empfohlen.

## Kampagnen-Auswertung

Damit sich beurteilen lässt, wie viele **echte Anmeldungen** ein Newsletter
gebracht hat, bekommt jedes Mailing unter *Bildungsprogramm → Kampagnen* eine
eigene Kampagne mit eigenem Link:

```text
https://beispiel.de/?bi_k=newsletter-juli-2026
```

Dieser Link ersetzt im Newsletter die normale Adresse. Er protokolliert den
Aufruf und leitet dann auf das hinterlegte Ziel weiter – wahlweise eine
Seminar-Detailseite (der Permalink wird bei jedem Aufruf frisch aufgelöst,
der Link bleibt also auch nach Titeländerungen gültig) oder eine freie Adresse,
z. B. die Seminarsuche mit gesetzten Filtern. Zusätzliche Parameter am
Kampagnen-Link werden an das Ziel durchgereicht.

Ab dem Klick wird der Weg desselben Besuchs mitgeschrieben:

| Schritt | Wird erfasst, wenn … |
|---|---|
| Link aufgerufen | der Kampagnen-Link angeklickt wurde |
| Seminar angesehen | eine Seminar-Detailseite geöffnet wurde |
| Anmeldung begonnen | das Anmeldeformular geöffnet wurde („Jetzt buchen") |
| Anmeldung abgeschickt | die Anmeldung tatsächlich gespeichert wurde |

Die Auswertung zeigt diese vier Stufen als Trichter samt Absprungquote, dazu
die Seminare der Kampagne, die Liste der entstandenen Anmeldungen und – zum
Nachvollziehen einzelner Fälle – die letzten 20 Wege in ihrer zeitlichen
Abfolge. Die Zahl der Anmeldungen stammt dabei aus der Anmeldetabelle
(Spalte `kampagne`), nicht aus den Ereignissen: Sie bleibt korrekt, auch wenn
alte Ereignisse aufgeräumt werden, und steht ebenso im CSV-Export.

**Zuordnung und Genauigkeit.** Erfasst wird ein Zufalls-Token in einem
First-Party-Cookie (`bi_track`, 30 Tage) – keine IP, kein User-Agent, kein
Gerät. Mehrfaches Neuladen zählt nicht doppelt, ein erneuter Klick auf den
Newsletter-Link startet einen neuen Weg. Link-Scanner von Mailprogrammen
werden über die Browser-Kennung aussortiert; exakt ist das nicht, Klickzahlen
bleiben eine Näherung.

> **Wichtig:** Ein Full-Page-Cache vor WordPress kann die Zwischenschritte
> verschlucken – ausgelieferte Seiten aus dem Cache führen keinen PHP-Code aus.
> Die Weiterleitung des Kampagnen-Links (Query-Parameter) und die gespeicherte
> Anmeldung sind davon nicht betroffen.

Erfasste Ereignisse werden automatisch gelöscht, sobald sie älter als zwölf
Monate sind (täglicher Cron-Job); der Knopf unter *Kampagnen* stößt dasselbe
sofort an. Die Anmeldungen und damit die Kampagnen-Zahlen bleiben davon
unberührt.

> **Rechtliches:** Das Cookie dient der Reichweitenmessung und ist technisch
> nicht zwingend erforderlich – nach § 25 Abs. 1 TDDDG braucht es dafür in der
> Regel eine Einwilligung (Consent-Banner). Unter *Einstellungen → Datenschutz*
> liegt ein fertiger Textbaustein für die Datenschutzerklärung zum Kopieren,
> der sowohl die Anmeldedaten als auch die Reichweitenmessung beschreibt; er
> steht ebenso im WordPress-Richtlinien-Leitfaden unter *Werkzeuge →
> Datenschutz*. Der Baustein ist ein Entwurf und ersetzt keine rechtliche
> Prüfung.

## Datenmodell

| Element | Speicherort |
|---|---|
| Seminartitel / Beschreibung | Post (`post_title`, `post_content`) |
| Präsenz-Seminare / Online-Seminare | Beitragstypen `bi_seminar` / `bi_online` |
| Startdatum, Enddatum, Seminarnummer, Plätze, Kosten … | Post-Meta `_bi_*` (für beide Beitragstypen dieselben Schlüssel) |
| Untertitel, Referent\*innen, Webinar-Tool, Anmelde-/Online-Link | Post-Meta `_bi_*` (nur `bi_online`) |
| Bildungszentrum bzw. Veranstalter\*in, Handlungsfeld | Taxonomien `bi_ort`, `bi_handlungsfeld` (an beiden Beitragstypen) |
| Zielgruppe, Freistellung (mehrfach) | Taxonomien `bi_zielgruppe`, `bi_freistellung` |
| Programmjahr (Jahrgang) | Taxonomie `bi_programm` |
| PLZ-Zuordnung | Tabelle `wp_bi_plz` |
| Anmeldungen | Tabelle `wp_bi_anmeldungen` |
| Mail-Benachrichtigungen | Option `bi_mail_triggers` |
| Kampagnen (Newsletter-Links) | Tabelle `wp_bi_kampagnen` |
| Kampagnen-Ereignisse | Tabelle `wp_bi_events` |
| Kampagne einer Anmeldung | Spalte `kampagne` in `wp_bi_anmeldungen` |
| Warteschlange Wochenzusammenfassung | Option `bi_mail_queue` |
| Termin des Wochenversands | Option `bi_mail_schedule` |
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
│  ├─ class-bi-online.php       CPT bi_online (Online-Seminare) + Anmelde-Weiche
│  ├─ class-bi-filter.php       Such-/Filterleiste [bi_seminarsuche]
│  ├─ class-bi-kacheln.php      Marketing-Kacheln [bi_kachel] + Backend-Builder
│  ├─ class-bi-detail.php       Seminar-Detailseite
│  ├─ class-bi-registration.php Anmeldeformular [bi_anmeldung] + Tabelle
│  ├─ class-bi-mailer.php       Mail-Trigger-Engine + Einstellungsseite
│  ├─ class-bi-tracking.php     Kampagnen-Links + Auswertung Klick → Anmeldung
│  ├─ class-bi-import.php       Seminar-CSV-Import mit Spalten-Mapping
│  ├─ class-bi-plz.php          PLZ→Geschäftsstelle: Tabelle, Lookup, Import
│  ├─ class-bi-settings.php     Einstellungen (Anmeldevarianten, Regeln, Seiten, PDF)
│  ├─ class-bi-pdf.php          Seminardetails als PDF-Anhang
│  ├─ class-bi-pdf-doc.php      Grundgerüst der PDFs (lädt erst mit FPDF nach)
│  ├─ class-bi-beschluss.php    Beschlussvorlage § 37 Abs. 6 BetrVG als Word-Datei
│  ├─ class-bi-docx.php         minimaler .docx-Schreiber (ZipArchive, keine Bibliothek)
│  └─ class-bi-admin.php        Admin-Menü + Mini-Dashboard
├─ templates/                    Detailseiten-Template
├─ vendor/fpdf/                  FPDF 1.86 (PDF-Erzeugung, freie Lizenz)
└─ assets/                       CSS, JS, flatpickr (gebündelt)
```

## Lizenz

[GPL-2.0-or-later](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html) –
wie WordPress selbst.

Mitgeliefert: **FPDF 1.86** von Olivier Plathey (`vendor/fpdf/`) für die
PDF-Anhänge – freie Lizenz ohne Einschränkungen, siehe `vendor/fpdf/license.txt`.
