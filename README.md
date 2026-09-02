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
    - [Seminare je Seite](#wie-viele-seminare-auf-eine-seite-kommen-seit-11240)
    - [Suche nach der Seminarnummer](#die-seminarnummer-findet-ihr-seminar-seit-1950)
  - [Anmeldeformular](#bi_anmeldung--anmeldeformular)
  - [Marketing-Kacheln](#bi_kachel--marketing-kacheln)
  - [Kacheln für Ausbildungsreihen](#kacheln-für-ausbildungsreihen)
  - [Gespeicherte Kacheln](#gespeicherte-kacheln)
  - [Programmjahre trennen](#programmjahre-trennen)
- [Benachrichtigungen](#benachrichtigungen)
  - [Wohin die Post geht](#wohin-die-post-geht)
  - [Kopie an (Cc)](#kopie-an-cc)
- [Verfügbarkeits-Ampel](#verfügbarkeits-ampel)
- [Kampagnen-Auswertung](#kampagnen-auswertung)
- [Die Detailseiten](#die-detailseiten)
  - [Seminardetails als PDF](#seminardetails-als-pdf-herunterladen)
- [Ausbildungsreihen](#ausbildungsreihen)
  - [Die Reihenliste durchsuchen](#die-reihenliste-durchsuchen)
  - [Die Reihenseite](#die-reihenseite)
  - [Sammelanmeldung](#sammelanmeldung-eine-reihe-in-einem-zug)
  - [Übersicht aller Reihen](#übersicht-aller-reihen)
- [Der Import-Lauf](#der-import-lauf)
- [Kosten und Orte ab Programm 2027](#kosten-und-orte-ab-programm-2027)
- [Anmeldevarianten](#anmeldevarianten)
- [Anmeldeformulare](#anmeldeformulare)
  - [Der Feld-Bestand](#der-feld-bestand)
  - [Ein Formular zusammenstellen](#ein-formular-zusammenstellen)
  - [Zuordnung: welches Seminar bekommt welches Formular](#zuordnung-welches-seminar-bekommt-welches-formular)
- [Die drei Haken am Seminar](#die-drei-haken-am-seminar)
- [Datenqualität](#datenqualität)
- [Die Seminarliste im Backend](#die-seminarliste-im-backend)
- [Die Bearbeiten-Maske](#die-bearbeiten-maske)
  - [Titel für viele Termine setzen](#den-seminartitel-für-viele-termine-auf-einmal-setzen)
- [Eigene Datenfelder](#eigene-datenfelder)
- [Datenpflege: Export und Umzug](#datenpflege-export-und-umzug)
- [Abgleich mehrerer Websites](#abgleich-mehrerer-websites)
  - [Einrichten](#einrichten-in-fünf-schritten)
  - [Was der Abgleich überträgt](#was-der-abgleich-überträgt-und-was-nicht)
  - [Wenn etwas nicht ankommt](#wenn-etwas-nicht-ankommt)
- [Datenmodell](#datenmodell)
- [Anpassung](#anpassung)
- [Aufbau des Codes](#aufbau-des-codes)
- [Lizenz](#lizenz)

## Funktionen

- **Seminare als eigener Beitragstyp** (`bi_seminar`) – gepflegt mit der
  WordPress-eigenen Editier-Oberfläche, inklusive Start-/Enddatum, Seminarnummer,
  Plätzen, Kosten und Buchungsstatus.
- **Online-Seminare als zweiter Beitragstyp** (`bi_online`) – eigenes Feldset
  (Untertitel, Referent\*innen, Veranstalter\*in, Webinar-Tool, Anmelde- und
  Teilnahme-Link), aber dieselben Taxonomien und dieselbe Darstellung im
  Frontend. Im Backend stehen sie **gemeinsam mit den Präsenz-Seminaren unter
  „Seminare"**; das Auswahlfeld *Seminarform* grenzt bei Bedarf ein.
- **Such- & Filterleiste** per Shortcode: Volltextsuche mit
  **Autovervollständigung**, Filter-Chips mit Mehrfachauswahl (echtes ODER),
  Datumsbereich mit Kalender, Live-Trefferzähler. Der Chip **Seminarform** trennt
  Präsenz- und Online-Seminare. Die Volltextsuche liest **alle Datenfelder** –
  Titel, Beschreibung, Themen, Seminarort, Referent\*innen, Ansprechpartner\*in,
  Seminarnummer, den Namen der Ausbildungsreihe und die Begriffe aller
  Filter-Chips. Alles zusammen steht in einer eigenen Indextabelle mit
  `FULLTEXT`-Index. Wer mag, verknüpft mit **`ODER`, `NICHT`, `"Wortgruppen"`
  und Klammern** — ohne Zutun bleibt es beim UND.
- **Online-Anmeldung** als vierstufiger Formular-Wizard; Anmeldungen werden in
  der Datenbank gespeichert und sind im Backend einsehbar.
- **Benachrichtigungen**: beliebig viele Mails pro Anmeldung – an
  Teilnehmer*innen, die zuständige Geschäftsstelle (per PLZ-Zuordnung), das
  Bildungszentrum oder feste Adressen; mit Bedingungen und Platzhaltern.
- **CSV-Import für Seminare** mit frei zuordenbaren Spalten
  (Mapping-Schritt mit Auto-Erkennung) – je ein eigener Tab für Präsenz- und
  Online-Seminare.
- **CSV-Import für PLZ → Geschäftsstelle** (eigene Tabelle, schneller Lookup).
- **Marketing-Kacheln**: Bild-/Text-Teaser, die auf die Suche mit vorbefüllten
  Filtern verlinken – inklusive Backend-Builder mit Live-Vorschau.
- **Verfügbarkeits-Ampel**: auf der Detailseite steht neben dem Termin, ob er
  noch frei ist (*Verfügbar* / *Fast ausgebucht* / *Warteliste möglich*). Die
  Angabe wird nachts mit der Seminarsuche auf `igmetall.de` abgeglichen und über
  die Seminarnummer zugeordnet.
- **Kampagnen-Auswertung**: eigene Links für Newsletter und Mailings, die den
  Weg vom Klick über die Seminaransicht bis zur abgeschickten Anmeldung
  nachvollziehbar machen.
- **Mini-Dashboard** im Backend mit Kennzahlen, Warnhinweisen (z. B. fehlende
  E-Mail-Adressen) und Einrichtungs-Anleitung.

- **Abgleich mehrerer Websites** – Sprockhövel und Berlin pflegen ihre Seminare
  selbst, bildung.igmetall.de holt sie ab. Kein iframe: Jede Website führt ihren
  eigenen Bestand und findet in der Suche nur ihn.

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
   hochladen, im zweiten Schritt jede Spalte einem Feld zuordnen. Der Import
   läuft anschließend in Häppchen mit Fortschrittsbalken und Zähler. Mehrfach-Felder
   (Zielgruppe, Freistellung) dürfen mehrere Werte je Zelle enthalten, getrennt
   mit `|` oder `,`. Alternativ lassen sich Seminare komplett von Hand anlegen.
3. **Bildungszentren-Mails** – unter *Seminare → Bildungszentrum* je Eintrag eine
   E-Mail-Adresse hinterlegen (für den Trigger „Mail an Bildungszentrum").
4. **Benachrichtigungen** prüfen und anpassen – gleichnamiger Menüpunkt.
5. **Such-Seite** anlegen mit `[bi_seminarsuche]`.
6. **Anmelde-Seite** anlegen mit `[bi_anmeldung]`.
7. Unter **Einstellungen → Seiten** die beiden Seiten zuordnen (oder die
   automatische Erkennung nutzen) und die Anmeldevarianten konfigurieren.
8. Optional: unter **Kampagnen** je Newsletter einen Link anlegen, wenn die
   Wirkung von Mailings ausgewertet werden soll.

## Shortcodes

| Shortcode | Zweck | Attribute |
|---|---|---|
| `[bi_seminarsuche]` | Such-/Filterleiste + Ergebnisliste | `anmeldung_url`, `per_page` (Vorgabe, Standard 20), `pro_seite` (wählbare Stufen, Standard `10\|20\|50\|100`, `nein` = keine Wahl), `programm`, `form` (`praesenz`/`online`) |
| `[bi_suchmaske]` | Nur die Suchmaske, ohne Ergebnisliste – Button springt auf die Übersicht | `ziel_url`, `button`, `titel`, `kicker`, `hinweis`, `programm`, `form` |
| `[bi_anmeldung]` | Anmeldeformular | `seminar="ID"` für ein festes Seminar, `reihe="ID"` für eine [Sammelanmeldung](#sammelanmeldung-eine-reihe-in-einem-zug) |
| `[bi_kachel]` | Marketing-Kachel (Teaser mit Filter-Link) | [siehe unten](#bi_kachel--marketing-kacheln) |
| `[bi_kacheln]` | Optionaler Grid-Container für mehrere Kacheln | `spalten` (2/3/4, Standard 3) |
| `[bi_reihen]` | Kachelübersicht aller Ausbildungsreihen | `titel`, `overline`, `subline`, `anzahl` |

### `[bi_seminarsuche]` – Such- und Filterleiste

Zeigt die Filterleiste (Volltextsuche, Seminarform, Programm, Bildungszentrum,
Themenfeld, Zielgruppe, Freistellung, Zeitraum) mit der Ergebnisliste. Gefiltert
wird über GET-Parameter (`?thema=…&ort=…`, Mehrfachwerte pipe-getrennt) –
Filterseiten sind damit verlinkbar und bookmarkfähig. Angezeigt werden nur
kommende, sichtbare Seminare, sortiert nach Startdatum.

#### Wie viele Seminare auf eine Seite kommen (seit 1.124.0)

Zwischen der Filterleiste und der Trefferliste steht rechtsbündig die Wahl
**„Seminare je Seite"** (10 · 20 · 50 · 100). Die Redaktion kann das nicht für
beide Seiten zugleich richtig entscheiden: **Wer sucht, will eine kurze Liste;
wer stöbert, will scrollen statt klicken.** Also entscheidet es, wer davorsitzt.

Sie steht **über** der Liste, weil sie zu dem gehört, was gleich kommt – unter
der Liste hätte man erst zwanzig Treffer durchgescrollt, um zu erfahren, dass
es auch fünfzig auf einmal gegeben hätte. Rechtsbündig, weil dort schon die
Trefferzahl der Filterleiste steht; links bleibt der Rand für die roten Balken
der Trefferzeilen frei. Das Blättern bleibt unverändert mittig unter der
Liste.

```
[bi_seminarsuche]                          Vorgabe 20, Stufen 10|20|50|100
[bi_seminarsuche per_page="50"]            Seite startet mit 50 – 50 steht mit zur Wahl
[bi_seminarsuche pro_seite="10|25|50"]     eigene Stufen
[bi_seminarsuche pro_seite="nein"]         keine Wahl anbieten, per_page gilt fest
```

Drei Entscheidungen stecken darin:

- **Links, kein Auswahlfeld mit JavaScript.** Jede Stufe ist eine eigene
  Adresse (`?bi_pro_seite=50`) – teilbar, als Lesezeichen brauchbar, und sie
  funktioniert auch dort, wo das Skript der Filterleiste nicht ankommt
  (Page-Builder, eingebetteter Rahmen). Die Vorgabe der Seite kommt ohne
  Parameter aus, damit die Adresse beim Zurückstellen wieder sauber ist.
- **Nur die angebotenen Stufen gelten.** Der Wunsch steht in der Adresse und
  ist damit öffentlich; `?bi_pro_seite=5000` wäre eine Einladung, den Server
  rechnen zu lassen, und jede beliebige Zahl wäre zusätzlich ein eigener
  Eintrag im Seiten-Cache. Die Vorgabe aus `per_page` reiht sich immer mit ein,
  sonst ließe sich der Ausgangszustand nicht wiederherstellen.
- **Die Wahl verschwindet, wo sie folgenlos wäre** – bei `pro_seite="nein"`,
  wenn nur eine Stufe übrig bleibt, und wenn ohnehin alle Treffer auf die
  kleinste Stufe passen. Ein Bedienelement, das nichts ändert, ist keine
  Freundlichkeit.

Die gewählte Länge hängt an der Adresse und bleibt deshalb beim Blättern und
beim Filtern erhalten. Auch „Alle zurücksetzen" in der Filterleiste lässt sie
stehen: Das ist eine Aussage über die Ansicht, nicht darüber, welche Seminare
gesucht werden. Ein Wechsel der Länge beginnt wieder auf Seite eins – Seite 7
von 40 hat bei 100 Treffern je Seite keine Entsprechung.

Im **Einbettungsmodus** (`?bi_embed=1`) bleibt es zusätzlich bei der alten
Freiheit: Dort nennt die Adresse weiterhin jede Zahl zwischen 3 und 50, weil
das fremde Redaktionssystem oft nur ein Adressfeld anbietet und bestehende
Rahmen nicht umspringen sollen.

Geprüft wird das in `tests/test-pro-seite.php` (ohne WordPress lauffähig).

#### Warum die Seite schnell lädt (seit 1.96.0)

Die Seminarsuche brauchte auf dem echten Bestand (2.400 Seminare, 51.000
Meta-Zeilen) rund **500 ms**, davon 95 % in der Datenbank — und fast alles in
**drei** Abfragen à ~120 ms: die Facetten-Basis, die Trefferliste und die
Gesamtzahl.

Schuld war eine einzige Bedingung: **„Auf der Website anzeigen"**. Als
`meta_query` geschrieben ist sie ein ODER („Wert ist 1 **oder** es gibt gar keine
Zeile"), und ein ODER zwingt WordPress, den `meta_key` aus der ON-Bedingung zu
nehmen und `postmeta` zweimal zusätzlich anzuhängen — einmal davon ganz ohne
Einschränkung. Aus 2.400 Seminaren wurden so über 40.000 Zwischenzeilen, die ein
`GROUP BY` danach wieder einsammelte.

Dieselbe Aussage als **korrelierte Unterabfrage** — „es gibt keine Zeile
`_bi_anzeigen` mit einem anderen Wert als 1" — kostet nichts, weil sie über den
`post_id`-Index läuft:

| Abfrage | vorher | nachher |
|---|---|---|
| Facetten-Basis | 121 ms | 4 ms |
| Trefferliste | 125 ms | 7 ms |
| Gesamtzahl | 124 ms | 4 ms |

Gemessen an derselben Datenbank, mit identischem Ergebnis (1777 Treffer) und
identischer Reihenfolge. Dazu holt die **Gesamtzahl** nicht mehr alle IDs
(`nopaging`) und sortiert sie auch nicht mehr nach `post_date` — gebraucht wird
die Zahl, also liest sie `found_posts` aus einer Abfrage über eine Zeile.

Benutzt wird das über `BI_CPT::sichtbar_arg()`, das ein Abfrage-Argument
(`bi_sichtbar`) setzt; `BI_CPT::sichtbar_where()` hängt die Unterabfrage an.
Das alte Fragment `BI_CPT::visible_clause()` bleibt bestehen und ist für kleine
Abfragen — zwei Dutzend Reihen, eine Kachel — völlig in Ordnung.

> **Eine Falle steckt darin.** `get_posts()` setzt `suppress_filters` auf true,
> und dann läuft `posts_where` nicht: Die Einschränkung fiele **still** weg, die
> Liste zeigte ein paar Einträge zu viel, und niemand suchte danach. Deshalb
> meldet `BI_CPT::sichtbar_pruefen()` diesen Fall über `_doing_it_wrong()`,
> statt ihn hinzunehmen. Die Gleichwertigkeit beider Formulierungen steht als
> Wahrheitstafel in `tests/test-sichtbarkeit.php`.

**Welche Chips erscheinen**, stellt man unter *Einstellungen → Tab
„Such-Filterleiste"* je Filter mit einem Haken ein. Gedacht ist das für Filter,
die nur zeitweise gebraucht werden: Der Chip **Programm** (`?programm=2027`) ist
im Auslieferungszustand aus und wird für den Übergang eingeschaltet, in dem zwei
Programmjahre nebeneinander buchbar sind – danach wieder aus.

Ein abgeschalteter Filter verschwindet nur aus der Leiste; steht sein Wert in
der Adresse, filtert er weiterhin. Sonst würden bestehende Links und
Marketing-Kacheln in dem Moment still eine größere Treffermenge zeigen, in dem
jemand den Haken entfernt.

#### Seiten-Cache: das Plugin leert ihn selbst (seit 1.110.0)

Ein Seiten-Cache liefert fertiges HTML aus, ohne PHP zu starten — auf einer
gemessenen Installation der Unterschied zwischen rund einer Sekunde und rund
fünfzig Millisekunden. Er weiß aber nichts davon, wann sich der Seminarbestand
ändert. Ohne Zutun zeigte die Liste nach einem CSV-Import stundenlang den alten
Stand, und die Trefferzahl in der Filterleiste („1.200 buchbare Seminare") stünde
eingefroren im HTML. **Ein Cache, der falsche Zahlen ausliefert, ist schlimmer
als kein Cache** — beim langsamen Aufruf sieht man wenigstens, was stimmt.

Das Plugin leert ihn deshalb bei **zwei** Anlässen:

1. **Daten geändert** — Seminar gespeichert, importiert, massenbearbeitet,
   gelöscht, Begriffe zugeordnet. Anders als beim Suchindex zählt hier *jedes*
   Meta-Feld: „Ausgebucht", „Freie Plätze" und das Startdatum tragen keine
   Wörter und stehen deshalb nicht im Suchindex — die Seite ändern sie trotzdem.
2. **Der Tag hat gewechselt.** *Buchbar* heißt „Startdatum ab **heute**", und das
   wird beim Rendern ausgewertet. Eine um 23:50 gespeicherte Seite zeigte um
   00:10 noch den Termin von gestern. Deshalb läuft kurz nach Mitternacht ein
   Leeren, ohne dass sich irgendetwas geändert haben muss.

**Einmal je Aufruf, höchstens einmal je Minute.** Ein Import setzt zwanzig
Meta-Felder je Zeile und legt hundert Zeilen je Häppchen an; würde jede Änderung
sofort leeren, liefen tausende Löschläufe über das Cache-Verzeichnis — und der
Cache wäre dauerhaft leer, also wirkungslos. Während des Aufrufs wird deshalb nur
vorgemerkt, am Ende einmal geleert. Kommt in der Sperrfrist noch etwas, bleibt es
als *offen* stehen und wird nachgeholt — **von jedem beliebigen Aufruf**, nicht
nur von WP-Cron. Sonst bliebe auf einer Installation mit `DISABLE_WP_CRON` genau
der letzte Import-Stand ungeleert.

Erkannt werden **WP Super Cache, WP Rocket, W3 Total Cache, WP Fastest Cache,
Cache Enabler, LiteSpeed Cache, SG Optimizer, Kinsta und WP Engine** — an ihrer
Funktion bzw. Klasse, nicht am Plugin-Namen, damit es auch bei zwei parallel
laufenden greift. Ist keiner da, passiert schlicht nichts. Für alles andere:

```php
add_action( 'bi_cache_leeren', function () { /* eigenen Cache leeren */ } );
```

*Nicht* dabei ist **Autoptimize**: Das ist kein Seiten-Cache, sondern ein
Asset-Optimierer. Seinen Zwischenspeicher bei jeder Seminaränderung zu leeren,
hieße CSS und JS ohne Grund neu bauen zu lassen.

Unter *Einstellungen → Such-Filterleiste* steht unten, **welcher Cache erkannt
wurde und wann zuletzt geleert wurde**, dazu ein Knopf **Cache jetzt leeren**
(der geht an der Sperrfrist vorbei — wer ihn drückt, will jetzt leeren).

> **Wichtig bei der Einrichtung des Cache-Plugins:** Adressen **mit Parametern**
> (`?q=`, `?ort=`, `?thema=` …) müssen vom Caching **ausgenommen** bleiben. Sonst
> bekämen alle Besucher dieselbe Trefferliste, egal was sie filtern. Die
> Blätterung ist davon nicht betroffen — `/seminare/page/2/` ist ein Pfad und
> darf gecacht werden.

Geprüft in `tests/test-cache-purge.php`.

#### Volltextsuche: Wörter statt Wortfolge, mit Tippfehler-Korrektur

Gesucht wird **Wort für Wort und mit UND verknüpft** (seit 1.87.0):
`arbeitsrecht grundlagen` findet „Grundlagen des Arbeitsrechts", die Reihenfolge
spielt keine Rolle. Vorher wurde die ganze Eingabe als eine Zeichenkette
verglichen — schon zwei Wörter in der falschen Reihenfolge fanden nichts.

Seit 1.126.0 lässt sich das UND überschreiben — siehe den nächsten Abschnitt.

#### Boolesche Operatoren (seit 1.126.0)

Das UND bleibt die Vorgabe — wer nichts weiter tut, sucht wie vorher. Wer mehr
braucht, schreibt es hin:

| Eingabe | Bedeutung |
|---|---|
| `arbeitsrecht bonn` | beide Wörter müssen vorkommen (wie bisher) |
| `bonn ODER berlin` | eines von beiden genügt — auch `OR`, auch <code>&#124;</code> |
| `arbeitsrecht UND bonn` | dasselbe wie ohne `UND` — auch `AND`, auch `&` |
| `NICHT online` | ohne dieses Wort — auch `NOT`, auch `-online` |
| `"neue Betriebsräte"` | genau diese Wortfolge — auch `„…"` aus dem Programmheft |
| `(bonn ODER berlin) jav` | Klammern fassen zusammen |

Vorrang wie überall: `NICHT` bindet stärker als `UND`, `UND` stärker als `ODER`.
`a ODER b c` heißt also `a ODER (b UND c)`.

Unter dem Suchfeld steht der aufklappbare Hinweis **„Suche verfeinern"** mit
denselben Beispielen. Zugeklappt ist er eine Zeile: Wer die Operatoren nicht
braucht, soll sie nicht wegsehen müssen.

Vier Entscheidungen dahinter:

- **Der Bindestrich ist nur am Anfang eines Stücks ein Ausschluss.** Mitten im
  Wort ist er ein Bindestrich — sonst würde aus `E-Learning` ein „alles ohne
  Learning" und aus der Seminarnummer `LO-1234` eine Suche ohne die 1234. Ein
  Gedankenstrich mit Leerzeichen ringsum (`Grundlagen – Teil 1`) ist gar kein
  Operator und fällt weg.
- **Klein geschrieben ist ein Angebot, GROSS geschrieben eine Ansage.** „nicht",
  „und" und „oder" sind auch gewöhnliches Deutsch: *Nicht nur reden* ist ein
  Seminartitel, keine Ausschlussbedingung. Findet eine Anfrage mit
  kleingeschriebenen Operatorwörtern nichts, wird sie deshalb ein zweites Mal
  **wortwörtlich** gelesen — ohne Hinweis über der Liste, denn es steht nichts
  anderes darin, als eingetippt wurde; nur die Verknüpfung war eine andere.
  Bei `NICHT` in Großbuchstaben und bei jedem Zeichen (`-`, `"`, <code>&#124;</code>,
  `&`, Klammern) passiert das **nicht**: Wer so schreibt, meint es, und eine
  leere Liste ist dann die richtige Antwort. Sonst zeigte
  `arbeitsrecht NICHT online` am Ende genau die Online-Seminare, die jemand
  gerade ausgeschlossen hat.
- **Was `MATCH` nicht kann, geht sofort zu `LIKE`.** `BOOLEAN MODE` kennt kein
  UND innerhalb einer ODER-Gruppe (`(a b) ODER c`), keinen Ausschluss einer
  ganzen Gruppe (`NICHT (a ODER b)`) und liefert bei einer Anfrage aus lauter
  Ausschlüssen die leere Menge statt „alles außer". Solche Bäume gehen deshalb
  von vornherein über `LIKE` — das bildet **jeden** Baum ab. Die Antwort wäre
  sonst nicht langsam, sondern falsch. Entschieden wird das in
  `BI_Suche::modus_fuer()`, einmal je Suche, vor der ersten Abfrage.
- **Es bleibt EINE Unterabfrage.** Egal wie verschachtelt die Eingabe ist —
  aus dem Baum wird ein einziges `EXISTS` auf `wp_bi_suchindex`.

Die Tippfehler-Korrektur lässt Operatoren stehen und behält das Vorzeichen:
aus `-arbeitsercht` wird `-Arbeitsrecht`, nicht `Arbeitsrecht`. Auch die
Autovervollständigung ergänzt nur das angefangene Wort und rührt weder
Operatorwörter noch ein gesetztes `-` an.

Geprüft in `tests/test-suche-boolean.php`. Ausschalten lassen sich die
Operatorwörter über den Filter `bi_suche_operatoren` (die Zeichen `-`, `"` und
die Klammern bleiben davon unberührt).

#### Über alle Datenfelder (seit 1.107.0)

Bis 1.106.3 verglich die Suche **nur den Titel**. Alles andere, was ein Seminar
ausmacht, war unsichtbar: Wer `Sprockhövel` eintippte, bekam nichts — obwohl
dreihundert Seminare dort stattfinden. Wer sich an ein Stichwort aus den Themen
erinnerte, an die Referentin oder an die Ansprechpartner\*in aus dem Telefonat,
suchte vergeblich.

Jedes Suchwort wird jetzt gegen **alles** gehalten:

| Wo | Was |
|---|---|
| Titel | `post_title` |
| Beschreibung und Themen im Seminar | **ohne HTML**, siehe unten |
| alle Meta-Felder | Seminarnummer, Untertitel, Seminarort, Referent\*innen, Ansprechpartner\*in und deren Mailadresse, Kosten-Hinweis, Webinar-Tool, Anmelde- und Online-Link, „Teil \| Reihe" — **und jedes selbst angelegte Feld** (`_bix_…`) |
| alle Taxonomien | Bildungszentrum, Themenfeld, Zielgruppe, Freistellung, Programmjahr |

Alles zusammen steht seit 1.109.0 in **einer Zeile je Seminar** in der Tabelle
`wp_bi_suchindex` — siehe [Der Suchindex](#der-suchindex-seit-11090).

Vier Entscheidungen dahinter:

- **Das UND gilt zwischen den Wörtern, das ODER zwischen den Feldern.**
  `sprockhövel betriebsrat` findet ein Seminar, das in Sprockhövel stattfindet
  **und** „Betriebsrat" im Titel trägt. Beide Wörter im *selben* Feld zu
  verlangen wäre unbrauchbar — niemand tippt so. (Das UND zwischen den Wörtern
  ist seit 1.126.0 nur noch die *Vorgabe*, siehe oben; das ODER zwischen den
  Feldern gilt unverändert immer.)
- **Zahlen, Daten und Haken bleiben draußen.** Ja/Nein, Datum, Uhrzeit, Zahl und
  Betrag tragen keine Wörter. Wären sie dabei, fände `1` jedes Seminar mit
  irgendeinem gesetzten Haken und `12` jeden Dezember-Termin, jeden Termin am
  12. und jeden Preis mit einer 12 darin. Für genau diese Felder gibt es den
  Chip **Zeitraum**. Die Liste steht in `BI_Suche::keine_wortfelder()`.
- **Ein eigenes Feld ist von allein durchsuchbar.** Die Liste der Meta-Schlüssel
  kommt aus der Feld-Registry beider Seminarformen
  (`BI_Suche::meta_keys()`) — ein in der Datenpflege angelegtes Textfeld muss
  nirgends nachgetragen werden. Filter: `bi_suche_meta_keys`,
  `bi_suche_taxonomien`.
- **Eine einzige Unterabfrage, egal wie viele Wörter.** Bis 1.108.0 stand je
  Suchwort eine Unterabfrage über dreißig `postmeta`-Schlüssel und eine über die
  drei Term-Tabellen in der Bedingung — sechs Wörter waren zwölf Unterabfragen,
  und keine davon konnte einen Index benutzen. Das kostete auf dem Bestand
  Sekunden. Seit 1.109.0 ist es ein `EXISTS` auf `wp_bi_suchindex`.

Was die Leiste als **Chip** anbietet, ändert sich dadurch nicht: Filterbare
Facetten bleiben Taxonomien. Neu ist nur, dass man ihre Begriffe auch tippen
kann, statt zweimal zu klicken.

#### Der Suchindex (seit 1.109.0)

Neben jedem Seminar steht **eine Zeile** in `wp_bi_suchindex`: Titel,
Beschreibung, alle Meta-Werte, alle Begriffe — in einem Feld, ohne HTML, mit
einem `FULLTEXT`-Index darauf.

**Warum eine eigene Tabelle.** Bis 1.108.0 suchte die Bedingung je Wort mit zwei
Unterabfragen: eine über `postmeta` (dreißig Schlüssel, `meta_value LIKE
'%wort%'`) und eine über die drei Term-Tabellen. Sechs Wörter waren zwölf
Unterabfragen, und keine davon konnte einen Index benutzen — `LIKE '%…%'` kann
das nie. Gemessen auf 20.000 Indexzeilen:

| Weg | eine selektive Suche |
|---|---|
| `MATCH … AGAINST` auf `wp_bi_suchindex` | **1 ms** |
| `LIKE` auf `wp_bi_suchindex` | 58 ms |
| die alten `EXISTS` über `postmeta` | 98 ms |

**Zwei Wege, weil Deutsch zusammensetzt.** `MATCH` findet Wortanfänge:
`betriebs` findet „Betriebsrat". Es findet aber **nicht** mitten im Wort — `rat`
findet kein „Betriebsrat", und im Deutschen ist das kein Sonderfall, sondern der
Alltag. Deshalb läuft die Suche **erst mit MATCH**, und nur wenn das nichts
findet, noch einmal mit `LIKE` auf derselben Tabelle. Der teure Weg wird also nur
bezahlt, wenn der billige nichts bringt — dieselbe Regel wie bei der
Tippfehler-Korrektur.

Von vornherein mit `LIKE` gesucht wird, wenn ein Wort **kürzer als drei Zeichen**
ist. Das ist keine Feinheit: InnoDB nimmt kürzere Wörter gar nicht erst in den
Index auf (`innodb_ft_min_token_size`), und ein Pflichtwort ohne Indexeintrag
lässt `MATCH` die **gesamte** Abfrage leer zurückgeben. Aus `BR im Betrieb` würde
also nicht etwa eine ungenauere Suche, sondern gar keine. Dasselbe gilt für
`E-Learning` — zerlegt sind das „E" und „Learning".

Lässt sich der `FULLTEXT`-Index nicht anlegen (alte MySQL-Version, MyISAM), merkt
das Plugin sich das und sucht durchgehend mit `LIKE`. Langsamer als `MATCH`, aber
immer noch um Größenordnungen schneller als die alten Unterabfragen.

##### Kein rohes HTML

Die Beschreibung (`post_content`) und die *Themen im Seminar* (`_bi_themen`) sind
HTML. Roh mit `LIKE` verglichen war das in **beide Richtungen** falsch:

- `class` traf jeden formatierten Absatz, `https` jeden Link, `strong` jede fette
  Stelle. Treffer, die mit dem Inhalt nichts zu tun haben.
- `Betriebs<strong>rat</strong>` enthält kein „Betriebsrat". Das Seminar war für
  die Suche danach unsichtbar, obwohl das Wort auf der Seite steht.

Beim Entkleiden (`BI_Suche::text_entkleiden()`) gilt deshalb eine Unterscheidung,
die sich nicht von selbst versteht:

| HTML | wird zu | warum |
|---|---|---|
| `Betriebs<strong>rat</strong>` | `Betriebsrat` | Auszeichnungen im Wort (`b`, `strong`, `em`, `a`, `span` …) verschwinden **spurlos** |
| `<li>Kündigung</li><li>Abmahnung</li>` | `Kündigung Abmahnung` | jedes **andere** Tag hinterlässt ein **Leerzeichen** |
| `<p class="hinweis">Kosten</p>` | `Kosten` | Attribute werden nie zu Wörtern |
| `Arbeit &amp; Recht` | `Arbeit & Recht` | Entities werden aufgelöst, sonst stünde „amp" im Index |
| `<script>…</script>` | *(nichts)* | dort steht Code, kein Text |

##### Umlaute stehen doppelt drin

Zu jedem Wort mit Umlaut kommt die aufgelöste Schreibweise dazu — „Betriebsräte"
bringt „betriebsraete" mit. Die Datenbank hält „ä" und „a" für gleich, „ae" und
„ä" aber nicht; wer ohne Umlauttaste tippt, fände das Seminar sonst erst über die
Tippfehler-Korrektur, also nach einer zweiten Abfrage und mit einem Hinweis über
der Liste. So trifft schon die erste.

##### Wie der Index aktuell bleibt

Beim Speichern, bei jeder Änderung an einem beteiligten Feld und beim Setzen von
Begriffen — das deckt auch CSV-Import und Massenbearbeitung ab, die beide an
`save_post` vorbeiarbeiten. Gesammelt wird während des Aufrufs, geschrieben
einmal am Ende: Ein Import setzt zwanzig Meta-Felder je Zeile und würde die
Zeile sonst zwanzigmal neu bauen.

**Für den Bestand läuft ein Nachbau in Häppchen** (200 Zeilen), angestoßen bei
jedem Aufruf im Backend und zusätzlich von einem Cron-Ereignis. Je Aufruf laufen
so viele Häppchen, wie in **drei Sekunden** passen: Solange der Index leer ist,
findet die Suche gar nichts, und ein einzelnes Häppchen je Aufruf hieße bei
zweitausend Seminaren zehn Klicks im Backend, bis die Suche wieder trägt. Kein Knopf, den
jemand drücken muss — und keine Abhängigkeit von WP-Cron allein, das auf vielen
Installationen abgeschaltet ist. Solange etwas fehlt, steht der Stand als Hinweis
im Backend:

> **Bildungsprogramm:** Der Suchindex wird aufgebaut – 1.200 von etwa 5.400
> Einträgen. Bis er fertig ist, findet die Volltextsuche nicht alle Seminare.

Geprüft in `tests/test-suche-vorschlag.php` und `tests/test-suche-nummer.php`.

#### Eine Suche, nicht vier (seit 1.109.0)

Ein Aufruf mit `?q=…` fuhr bis 1.108.0 **drei bis vier Mal** dieselbe teure
Bedingung:

1. eine Probeabfrage, nur um zu wissen, *ob* es Treffer gibt
2. die Facettenzähler (`count_base_ids`, mit `nopaging` über alle Treffer-IDs)
3. die eigentliche Liste
4. und griff die Tippfehler-Korrektur, noch einmal Nummer 1

Jetzt wird zuerst die Liste geholt — ob sie leer ist, sagt `found_posts` von
selbst — und die Facetten rechnen **danach** mit dem Text und dem Suchweg, mit
dem am Ende tatsächlich gesucht wurde. Dazu merkt sich `count_base_ids()` sein
Ergebnis für die Dauer eines Aufrufs; dieselbe Frage kommt je Seite mehrfach
(Gesamtzahl, je Facette mit eigener Auswahl, Kopfzahl der Suchmaske).

#### Autovervollständigung (seit 1.108.0)

Ab dem **dritten** Zeichen schlägt das Suchfeld etwas vor, nach einer Denkpause
von 300 ms. Drei Arten, weil es drei Arten von Absicht gibt:

| Gruppe | Beispiel | ein Klick bewirkt |
|---|---|---|
| **Suchbegriffe** | `betrieb` → „Betriebsrat" | das angefangene Wort wird zu Ende geschrieben und gesucht |
| **Filter** | `sprock` → „Bildungszentrum Sprockhövel" | setzt den **Chip**, nicht den Volltext |
| **Seminare** | der Treffer selbst, mit Datum und Ort | führt direkt auf die Detailseite |

Entschieden ist dabei:

- **Die Suche läuft weiterhin erst mit Enter.** Die Vorschläge sind ein Angebot,
  keine Suche beim Tippen. Wer sie ignoriert und Enter drückt, bekommt genau das,
  was im Feld steht.
- **Erst verfeinern, dann treffen** — außer bei einer Seminarnummer. Wer eine
  Nummer abtippt, will genau ein Seminar und nichts sonst; dann stehen die
  Seminare oben und sonst nichts.
- **Nur Wortanfänge.** `arbeit` schlägt „Arbeitsrecht" vor, nicht
  „Sozialarbeit". Ein Vorschlag, der irgendwo in der Mitte passt, wirkt wie ein
  Fehler.
- **Ein Filtervorschlag liefert den Wert, den die Leiste kennt.** Bildungszentrum
  und Zielgruppe fasst die Leiste zu Gruppen zusammen („Berlin-Pichelssee" gehört
  zu „Bildungszentrum Berlin"); ein Vorschlag mit dem rohen Begriff führte auf
  einen Chip, den es gar nicht gibt. Verglichen wird deshalb gegen die fertigen
  Optionen der Leiste.
- **Das angefangene Wort wird ersetzt, der Rest bleibt.** Aus
  `arbeitsrecht sprock` + Klick auf das Bildungszentrum wird `q=arbeitsrecht`
  **plus** Chip — nicht beides doppelt.
- **Bedienbar ohne Maus.** ↓ ↑ wandern durch die Liste (und wieder heraus zur
  eigenen Eingabe), Enter übernimmt, Esc schließt. Das Feld ist eine
  `combobox` mit `aria-activedescendant`.
- **Über JSON geht Text, kein HTML** (seit 1.109.1). `get_the_title()` schickt den
  Titel durch `wptexturize()`; aus `"Ent-rüstet euch!"` wird dabei
  `&#8222;Ent-rüstet euch!&#8220;` und aus einem Gedankenstrich `&#8211;`. Für
  HTML ist das richtig — aber das JavaScript maskiert beim Einsetzen selbst, und
  dann stand `&amp;#8211;` in der Liste und der Browser zeigte die Zeichenfolge
  an. Anzeigetexte werden deshalb serverseitig aufgelöst. **Filterwerte nicht:**
  Ein Wert wandert in die Adresszeile und wird gegen den Begriffsnamen gehalten,
  so wie er in der Datenbank steht. Dasselbe gilt für die Beschriftungen der
  Filter-Chips (`Arbeit &amp; Recht` → „Arbeit & Recht").

- **Seminarvorschläge erst ab vier Zeichen.** Suchbegriffe und Filter kommen aus
  zwischengespeicherten Listen und kosten praktisch nichts; ein Seminarvorschlag
  ist dagegen eine echte Abfrage gegen den Bestand — bei drei Buchstaben mit
  einer Trefferliste, die noch nichts über die Absicht verrät.

Technisch: eine AJAX-Aktion `bi_suche_vorschlag` (auch für Gäste, rein lesend),
entprellt um 300 ms, laufende Anfragen werden abgebrochen. Zwischengespeichert
wird zweimal — die Facettenliste eine Stunde (sie hängt nur am Bestand) und jede
fertige Antwort fünf Minuten. Ohne das liefe bei jedem Tastendruck eine
Zählabfrage über den ganzen Bestand; und jede Anfrage kostet auf dem Server
einen vollen WordPress-Start, ganz gleich wie billig die Abfrage dahinter ist.

#### Die Seminarnummer findet ihr Seminar (seit 1.95.0)

Die Seminarnummer steht im gedruckten Programm, in jeder Bestätigungsmail und
auf jedem Anmeldebogen. Wer sie in das Suchfeld tippt — `B00027030`,
`SE14822` —, landet auf dem Seminar. Vorher verglich die Suche nur den Titel:
Die Nummer, mit der am Telefon und in der Geschäftsstelle gearbeitet wird, fand
als Einzige nichts.

- **Nur wenn die Eingabe nach einer Nummer aussieht** wird die Nummer überhaupt
  abgefragt: ein Wort, mindestens vier Zeichen, mindestens eine Ziffer darin.
  Sonst zöge eine Suche nach `2026` alle Nummern mit einer 26 darin herein, und
  die Trefferliste wäre nicht mehr die Antwort auf die Frage.
- **Teilstücke reichen.** `B0002` findet `B00027030` — beim Abtippen aus dem
  Heft bricht es sonst am letzten Zeichen ab.
- **Neben dem Titel, nicht statt seiner.** Gefunden wird, was im Titel passt
  **oder** die Nummer trägt. Alle übrigen Filter gelten unverändert weiter.
- **Keine Tippfehler-Korrektur auf Nummern.** Findet eine Nummer nichts, ist das
  kein Buchstabendreher, sondern eine Nummer ohne Seminar. Sie zu einem ähnlich
  geschriebenen Titelwort zu verbiegen, beantwortete eine Frage, die niemand
  gestellt hat.

**Was die Suche im Frontend nicht zeigt**, zeigt sie auch mit Nummer nicht: Die
Ergebnisliste kennt nur veröffentlichte, sichtbare Seminare ab heute. Die Nummer
eines vergangenen oder ausgeblendeten Termins bleibt deshalb ohne Treffer — im
Backend findet dieselbe Nummer ihn weiterhin.

Nicht zu verwechseln mit dem Parameter `?nr=…`: Der filtert **exakt** auf eine
oder mehrere Nummern (pipe-getrennt) und ist für verlinkte Listen und
Marketing-Kacheln gedacht. Das Suchfeld vergleicht dagegen auch Teilstücke.
Geprüft in `tests/test-suche-nummer.php`.

**Findet die Suche nichts, wird einmal korrigiert.** Jedes Wort der Eingabe wird
mit dem Wortschatz aller Seminartitel verglichen (Levenshtein-Abstand); liegt ein
Wort nah genug an einem bekannten, wird damit erneut gesucht:

> Keine Treffer für **Arbeitsercht**. Gezeigt werden Treffer für
> **Arbeitsrechts**.

Vier Entscheidungen dahinter:

- **Nur bei null Treffern.** Solange die Suche etwas findet, wird nichts geraten.
  Ein Wort, das im Wortschatz steht, ist richtig geschrieben — auch wenn es einem
  anderen ähnlich sieht.
- **Nie stillschweigend.** Der Hinweis über der Liste nennt beides: was eingetippt
  wurde und wonach gesucht wird. Eine Suche, die heimlich etwas anderes sucht,
  verwirrt mehr, als sie hilft — man sieht Ergebnisse und weiß nicht, wieso.
- **Umlaute sind der halbe Nutzen.** `betriebsraete` findet „Betriebsräte". Die
  Datenbank hält „ae" und „ä" nicht für dasselbe; der häufigste „Tippfehler" ist
  gar keiner, sondern eine Tastatur ohne Umlauttaste. Deshalb steht im Wortschatz
  zu jedem normalisierten Wort auch die **Originalschreibweise** — gesucht wird
  mit der.
- **Lieber zaghaft als falsch.** Die Toleranz wächst mit der Wortlänge (1 Fehler
  bis 5 Zeichen, 2 bis 9, sonst 3), Wörter unter vier Zeichen bleiben unangetastet,
  und ein Wortanfang wie `bilanz` wird nicht zu „Bilanzanalyse" gemacht — er
  findet ohnehin. Bringt auch die Korrektur nichts, steht in der Meldung wieder
  das, was eingetippt wurde.

Der Wortschatz kommt seit 1.108.0 aus **drei** Quellen: den Titeln aller
veröffentlichten Seminare und Reihen, den **Begriffen aller Taxonomien**
(Bildungszentrum, Themenfeld, Zielgruppe, Freistellung, Programm) und einer
Handvoll **Begriffsfelder** — Seminarort, Untertitel, Referent\*innen,
Ansprechpartner\*in, „Teil \| Reihe". Damit wird auch `sprockhövl` zu
„Sprockhövel" korrigiert, was vorher nicht ging: Der Ort steht in keinem Titel.

Begriffe zählen dabei **mit ihrer Verwendung**: Ein Bildungszentrum steht einmal
in der Term-Tabelle, hängt aber an dreihundert Seminaren. Ohne Gewicht verlöre
„Sprockhövel" jeden Gleichstand gegen ein beliebiges Titelwort.

**Bewusst nicht aus dem Fließtext.** Beschreibung und Themen bestehen zu großen
Teilen aus „werden", „einer", „Teilnehmerinnen". Im Wortschatz verdrängten diese
Wörter die Begriffe — die Häufigkeit entscheidet ja bei gleichem Abstand —, und
jede Korrektur führe gegen das nächstbeste Füllwort. Ein Wortschatz ist eine
Begriffsliste, kein Textkorpus. Die Liste steht in
`BI_Suche::wortschatz_felder()`, Filter `bi_suche_wortschatz_felder`.

Er liegt in einem Transient und wird verworfen, sobald ein Seminar **oder ein
Begriff** gespeichert oder gelöscht wird. Die Korrektur gilt für die **Ergebnisliste**
(`[bi_seminarsuche]`) — die eigenständige Suchmaske zählt weiter die Eingabe
wörtlich, weil sie bei jedem Tastendruck neu zählt und ein halb getipptes Wort
keine Korrektur verdient. Geprüft in `tests/test-suche-fuzzy.php`.

**Die Auswahl klappt direkt unter ihrem Filter auf**, nicht unter der ganzen
Leiste. Auf dem Handy stehen die Filter in mehreren Reihen; hing der aufgeklappte
Bereich hinter allen Reihen, öffnete ein Tipp auf den obersten Filter etwas, das
erst unterhalb von vier weiteren Buttons auftauchte – man sah nicht, dass
überhaupt etwas passiert war. Technisch ist der Bereich ein Kind derselben
Flex-Leiste mit voller Breite; er bricht die Zeile hinter dem angetippten Filter.
Passen alle Filter in eine Zeile – der Normalfall am Schreibtisch –, ändert sich
dadurch nichts.

Die Liste enthält **Präsenz- und Online-Seminare gemeinsam**; der Chip
*Seminarform* (`?form=praesenz` bzw. `?form=online`) schränkt darauf ein. Der
Chip erscheint nur, wenn beide Formen im aktuellen Ergebnis vorkommen. Soll eine
Seite dauerhaft nur eine Form zeigen, setzt man das Attribut
`form="online"` – dann entfällt der Chip.

Rechts in jeder Trefferzeile steht der Weg zum Seminar — **Text plus roter Pfeil
im Kreis**, kein Vollton-Button: Die Zeile hat nur ein Ziel, und ein roter Block
daneben zog mehr Aufmerksamkeit auf sich als der Seminartitel. Die Beschriftung
sagt, was einen dort erwartet:

| Beschriftung | wann |
|---|---|
| **Details und Anmeldung** | es gibt einen Anmeldeweg — Formular, Geschäftsstelle oder die Anmeldeseite eines Webinars |
| **Details** | ausgebucht, keine Anmeldung vorgesehen, oder offene Online-Veranstaltung (Teilnahme-Link statt Anmeldung) |

Die Unterscheidung trifft `BI_Detail::anmeldung_moeglich()` — dieselbe Quelle wie
die [ausgegraute Darstellung](#nicht-buchbare-termine-treten-zurück). Sonst
verspräche die Liste einen Knopf, den die Detailseite nicht hat.

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

Mehrstufiger Formular-Wizard mit clientseitiger Schritt-Validierung und
serverseitiger Verarbeitung. Welche Seiten und Felder er zeigt, steht unter
*Anmeldeformulare* – ausgeliefert wird der vierstufige Ablauf
(Persönliches → Kontakt → Betrieb → Abschluss); siehe
[Anmeldeformulare](#anmeldeformulare).
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
wird angezeigt. Der Button **„Anfrage senden"** öffnet dann einen Dialog nach
den persönlichen Daten (Name, E-Mail, Mobilnummer, Anschrift, Betrieb,
freiwillig die Mitgliedsnummer); **„Absenden"** setzt sie in den Text ein und
öffnet das Mailprogramm (`mailto:`): Seminarnummer und Titel im Betreff,
Zeitraum, Bildungszentrum, Seminar-Link und der Datenblock im Text. Die
Eingaben aus dem Dialog erreichen diese Website nie – das Formular hat kein
Ziel auf dem Server. Gemerkt wird nur die PLZ, in `localStorage`.
Ist zur Geschäftsstelle keine E-Mail hinterlegt (oder die PLZ unbekannt),
bleibt der Fallback-Link zur Geschäftsstellensuche auf igmetall.de.

> **Im Rahmen (iframe) gelten für diesen Dialog zwei Sonderregeln** – seit
> 1.123.2, vorher war die GS-Anfrage auf igmetall.de kaputt.
>
> Die Fremdseite zieht den `<iframe>` auf die volle Inhaltshöhe – `embed.js`
> meldet sie ihr laufend. Damit ist „das Fenster" aus Sicht der
> eingebetteten Seite über zehntausend Pixel hoch – und `showModal()` stellt
> einen Dialog in die **Fenstermitte**, also mehrere tausend Pixel unter den
> Knopf, den jemand gerade gedrückt hat. Der Dialog war offen und trotzdem
> nirgends zu sehen; manche Browser rollten die Fremdseite von sich aus
> hin, andere nicht – daher „geht bei manchen Browsern nicht".
> `gs-anfrage.js` setzt ihn deshalb selbst an die Höhe des Widgets und gibt
> ihm als Höhengrenze den Platz, der darunter noch im Rahmen ist.
>
> Und ein `mailto:` ist keine Seite, die in einen Ausschnitt gehört, sondern
> ein Auftrag ans Betriebssystem. Aus einem eingebetteten Rahmen heraus
> verwerfen ihn mehrere Browser still, wenn ihn Skriptcode über
> `location.href` auslöst. Er geht deshalb über einen echten Link mit
> `target="_top"` – die Bewegung, für die jeder Browser eine Regel hat. Die
> Seite dahinter wird dabei nicht verlassen: Adressen, die der Browser an ein
> anderes Programm weiterreicht, laden nichts.
>
> Ein Rahmen mit **fester** Höhe rollt selbst und verhält sich wie ein
> normales Fenster – dort bleibt alles beim Alten. Unterschieden wird das
> nicht an „im Rahmen", sondern daran, ob im Fenster überhaupt etwas zu
> rollen ist.

> Diese Adresse war einmal eine Einstellung. Sie ist seit 1.72.0 fest im Code
> (`BI_Detail::GS_SUCHE_URL`, änderbar über den Filter `bi_gs_suche_url`): Sie
> zeigt seit jeher auf dieselbe Seite, war nur noch der Ausweichlink unter der
> PLZ-Suche und stand in den Einstellungen zwischen Texten, die man wirklich
> ändert. Unter *Einstellungen → Anmeldung* bleiben für die
> Geschäftsstellen-Variante nur **Hinweistext** und **Button-Text**.

### `[bi_kachel]` – Marketing-Kacheln

Eine Kachel ist ein klickbarer Teaser (Bild + Überschrift + Text, wahlweise mit
Button). Sie verlinkt wahlweise auf die Seminarübersicht mit vorbefüllten Filtern oder auf eine
[Ausbildungsreihe](#kacheln-für-ausbildungsreihen). Sie füllt immer die
volle Breite ihrer umgebenden Box – die Größe bestimmt der Container (z. B. eine
Elementor-Spalte oder eine Gutenberg-Spalte), in den der Shortcode eingefügt wird.

**Am einfachsten über den Builder:** Menüpunkt **Bildungsprogramm →
Marketing-Kacheln** – oben das **Ziel** der Kachel wählen (Seminarsuche, eine
Ausbildungsreihe oder mehrere), gestalten, Filter per Checkbox auswählen (dieselbe
Auswahl wie in der Frontend-Filterleiste), Bildausschnitt und Fokuspunkt per
Klick festlegen, Live-Vorschau prüfen und den fertigen Shortcode kopieren. Der
Builder zeigt immer nur die Felder, die zum gewählten Ziel gehören.

```text
[bi_kachel layout="1" bild="1234" titel="BR kompakt" text="Die Grundlagenreihe für neu gewählte Betriebsrät*innen." thema="BR kompakt"]
[bi_kachel layout="2" bild="1235" titel="Seminare in Lohr" ort="Bildungszentrum Lohr" ueberschrift="h2"]
[bi_kachel layout="1" bild="1236" titel="Unsere Empfehlungen" nr="LO12345|BO67890" button="Jetzt entdecken"]
[bi_kachel layout="2" bild="1237" titel="Neu im Programm" thema="Digitalisierung" button=""]
```

**Layouts:** `layout="1"` = Bild oben, Text darunter (Standard) ·
`layout="2"` = Text über dem Bild (Overlay mit dunklem Verlauf).

**Der rote Button ist abwählbar** – in beiden Layouts. Im Builder ist das der
Haken *Roten Button anzeigen*, im Shortcode `button=""`. Ohne ihn endet die
Kachel nach dem Text; klickbar bleibt sie genauso, denn verlinkt war nie der
Button, sondern die ganze Kachel.

> `button=""` ist deshalb kein weggelassener Wert, sondern eine Ansage. Der
> Builder schreibt das Attribut auch dann in den Shortcode, wenn es leer ist –
> fiele es weg wie jeder andere leere Wert, träte beim Rendern die Vorgabe
> „Zu den Seminaren" an seine Stelle und der abgewählte Button wäre wieder da.

**Filter-Attribute** (entsprechen 1:1 den GET-Parametern der Suche,
Mehrfachwerte mit `|`): `q` (Titelsuche), `ort`, `thema`, `ziel`, `frei`
(Gruppen-Labels wie in der Filterleiste), `von` / `bis` (JJJJ-MM-TT),
`nr` (Seminarnummern, z. B. `nr="LO12345|BO67890"`).

### Kacheln für Ausbildungsreihen

Dieselbe Kachel kann statt auf die Suche auf eine **Ausbildungsreihe** zeigen.
Das Attribut `reihe` nimmt die ID oder den Slug der Reihe:

```text
[bi_kachel reihe="br-kompakt"]
[bi_kachel reihe="1234" layout="2" titel="Jetzt Betriebsrat werden" button="Reihe ansehen"]
```

**Die Reihe füllt, was leer bleibt:** Überschrift, Teaser und Bild kommen aus der
Reihe (Beitragsbild, Auszug – ersatzweise der Anfang des Textes), solange die
Attribute nicht gesetzt sind. Wer etwas einträgt, behält es: Eine Kachel ist ein
Werbemittel, und ein Werbetext ist nicht immer der Auszug. Die Button-Vorgabe
wechselt auf „Zur Ausbildungsreihe" – „Zu den Seminaren" führt hier schließlich
nicht in eine Liste.

Unter dem Text stehen die **Kennzahlen** der Reihe: `4 Teile · 2 Gruppen ·
ab 03/2026`. Gezählt werden nur **kommende** Termine – dieselbe Grundlage wie auf
der Reihenseite. `meta="nein"` blendet die Zeile aus.

**Die Kennzahlen sitzen am Button, nicht unter der Überschrift** (seit 1.97.1).
Teaser sind unterschiedlich lang; hing die Zeile am Text, stand sie in jeder
Kachel eines Gitters auf einer anderen Höhe, während die Buttons darunter sauber
in einer Reihe lagen. Technisch wandert das `margin-top:auto` vom Button auf die
Kennzahlen – dann rutschen beide gemeinsam nach unten. Ohne Kennzahlen behält
der Button sein eigenes `auto` und klebt weiter unten.

**`beschreibung="nein"` lässt den Beschreibungstext weg** (seit 1.97.0) – die
Kachel zeigt dann nur Bild, Überschrift und Button. Im Builder ist es der Haken
*Beschreibungstext anzeigen*, gleich neben den Kennzahlen.

> **Warum es dafür einen eigenen Schalter braucht.** Bei einer Reihen-Kachel
> füllt sich der Text von selbst aus der Reihe. Ein leeres `text`-Attribut heißt
> dort also „nimm den Auszug", nicht „lass ihn weg" – ohne ausdrücklichen
> Schalter bekommt man die Beschreibung schlicht nicht los. Der Schalter gilt
> auch dann, wenn zusätzlich ein eigener Text eingetragen ist: Sonst täte er mal
> etwas und mal nichts, je nachdem, was vorher im Formular stand.

> **Zeigt die Kachel ins Leere, zeigt sie gar nichts.** Ist die Reihe gelöscht
> oder noch ein Entwurf, rendert die Kachel für Besucher **nichts** – ein Teaser,
> der auf eine 404 führt, ist schlimmer als eine Lücke im Layout. Eingeloggte
> Redakteur*innen sehen die Kachel stattdessen mit rotem Badge und dem Grund
> („Diese Ausbildungsreihe ist noch nicht veröffentlicht"). Dasselbe Badge meldet
> „keine kommenden Termine", wenn die Reihe zwar steht, aber alle Termine
> gelaufen sind: Im Frontend sieht sie dann fertig aus und ist es nicht.

#### Mehrere Reihen als Übersicht (`[bi_kachel_reihen]`)

```text
[bi_kachel_reihen]                              alle ausgeschriebenen Reihen, 3 Spalten
[bi_kachel_reihen reihen="12|34|56" spalten="3"]  genau diese drei, in dieser Reihenfolge
[bi_kachel_reihen spalten="2" layout="2" meta="nein" beschreibung="nein"]
```

| Attribut | Bedeutung |
|---|---|
| `reihen` | IDs oder Slugs, mit `\|` oder Komma getrennt. **Leer = alle** |
| `spalten` | `2`, `3` (Standard) oder `4`; mobil bricht das Gitter von selbst um |
| `anzahl` | Höchstzahl der Kacheln |
| `sortierung` | `titel` sortiert auch eine handverlesene Auswahl alphabetisch |
| `layout`, `ratio`, `ueberschrift`, `button`, `meta`, `beschreibung` | gelten für jede Kachel der Übersicht |

**Der Unterschied zwischen „alle" und einer Auswahl ist Absicht:**

- **Ohne `reihen`** erscheinen alle ausgeschriebenen Reihen, alphabetisch – und
  es gilt der Haken *Auf der Website anzeigen* wie überall sonst. Eine später
  angelegte Reihe ist von allein dabei.
- **Mit `reihen="…"`** erscheint genau diese Auswahl in genau dieser Reihenfolge,
  auch eine Reihe, die der Haken aus den Listen genommen hat. Wer sie hier
  hinschreibt, meint sie. Doppelt genannte Reihen erscheinen einmal, Entwürfe
  gar nicht.

Bliebe die Übersicht leer, sehen Besucher nichts und Redakteur*innen einen
Hinweis, warum an dieser Stelle nichts steht.

> **Abgrenzung zu `[bi_reihen]`:** Der ältere Shortcode rendert die Reihen im
> Gewand der **Detailseiten** – mit Überschrift, Subline und breiten Zeilen; er
> ist die Programmseite. `[bi_kachel_reihen]` rendert dieselben Reihen als
> **Marketing-Kacheln** – gleiches Aussehen wie alle anderen Kacheln, damit sie
> in einer Kachelreihe nicht aus dem Rahmen fallen. Beide bleiben.

Festgehalten in `tests/test-kachel-reihen.php`.

### Gespeicherte Kacheln

Der kopierte Shortcode ist eine **Momentaufnahme**: Steht dieselbe Kachel auf
sechs Seiten und die Kampagne ändert sich, sind es sechs Änderungen – und die
sechste vergisst man. Deshalb lässt sich eine im Builder gestaltete Kachel
speichern und danach als **Verweis** einsetzen:

```text
[bi_kachel gespeichert="fruehjahrskampagne"]
```

Im Beitrag steht dann nur dieser Verweis, die Gestaltung bleibt im Reiter
**Gespeicherte Kacheln**. Wer sie dort ändert, ändert sie überall zugleich.

Einzelne Angaben lassen sich trotzdem übersteuern – das Attribut im Shortcode
sticht die gespeicherte Fassung:

```text
[bi_kachel gespeichert="fruehjahrskampagne" titel="Für die JAV"]
```

Der Verweis funktioniert für alle drei Ziele. Auch eine gespeicherte
**Übersicht mehrerer Reihen** wird mit `[bi_kachel gespeichert="…"]` eingesetzt
und rendert dann das Gitter – ein Schlüssel, eine Schreibweise, niemand muss
sich merken, welcher Shortcode zu welcher Kachel gehört.

| | |
|---|---|
| **Speichern** | im Builder rechts unter dem Shortcode: Name eintragen, *Als Kachel speichern* |
| **Ändern** | Reiter *Gespeicherte Kacheln* → *Bearbeiten* öffnet die Kachel im Builder; der Hinweis oben nennt die Zahl der Stellen, die sich mitändern |
| **Kopie anlegen** | eine geöffnete Kachel mit *Als neue Kachel speichern* ablegen |
| **Löschen** | die Rückfrage nennt die Zahl der Stellen, an denen die Kachel noch steht |

Der **Schlüssel bleibt für immer** – auch beim Umbenennen. Er steht in jedem
Beitrag, der die Kachel benutzt; ihn nachzuziehen hieße, überall tote Verweise
zu hinterlassen.

Zeigt ein Verweis ins Leere (Kachel gelöscht, Schlüssel vertippt), erscheint für
Besucher **nichts** – für eingeloggte Redakteur\*innen ein Hinweis mit dem
Schlüssel. Dieselbe Regel wie bei einer Kachel auf eine gelöschte Reihe.

#### Benutzt in

Die gleichnamige Spalte zeigt zu jeder Kachel, in welchen Seiten und Beiträgen
ihr Verweis steht – verlinkt zum Bearbeiten. Gesucht wird an **zwei** Stellen:

1. im **Beitragsinhalt** (`post_content`),
2. in den **Elementor-Daten** – ein Shortcode-Widget legt seinen Inhalt nicht im
   Beitragstext ab, sondern als JSON in einem eigenen Meta-Feld, dort mit
   maskierten Anführungszeichen. Ohne diesen zweiten Weg meldete die Spalte bei
   der üblichen Arbeitsweise immer „nirgends".

Verglichen wird auf ganze Schlüssel: `fruehjahr` trifft nicht `fruehjahr_2`.
Andere Seitenbaukästen und Widgets außerhalb von Beiträgen findet die Spalte
nicht – sie ist eine Hilfe, keine Garantie, und sagt das auch. Festgehalten in
`tests/test-kachel-reihen.php`.

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
| `gespeichert` | Schlüssel einer [gespeicherten Kachel](#gespeicherte-kacheln); weitere Attribute übersteuern sie |
| `reihe` | ID oder Slug einer [Ausbildungsreihe](#kacheln-für-ausbildungsreihen) statt Filter-Link |
| `meta` | `nein` blendet die Kennzahlen einer Reihen-Kachel aus |
| `beschreibung` | `nein` blendet den Beschreibungstext aus – vor allem für Reihen-Kacheln, deren Text sich sonst aus der Reihe füllt |
| `url` | feste Ziel-URL statt Filter-Link |
| `suche_url` | andere Suchseite als die konfigurierte |
| `programm` | Programmjahr für den Redaktions-Zähler (passend zum `programm`-Attribut der Zielseite) |

#### Überschriften in schmalen Kacheln

Deutsche Komposita sind länger als die Spalte, in der sie stehen. Drei Dinge
greifen deshalb ineinander (seit 1.97.1):

1. **Umbrechen statt abschneiden.** Der Kachelkörper ist eine Flex-Spalte mit
   `align-items:flex-start` – seine Kinder sind damit nur so breit wie ihr
   Inhalt. „Betriebsverfassungsrecht" machte die Überschrift also **breiter als
   die Kachel**, und deren `overflow:hidden` schnitt das Wort ab, statt es
   umbrechen zu lassen. Das sah aus wie ein fehlender Zeilenumbruch und war ein
   fehlendes `max-width`.
2. **Trennen, dann brechen.** `hyphens:auto` setzt den Trennstrich an der
   richtigen Stelle (das braucht das `lang`-Attribut der Seite, WordPress setzt
   es); `overflow-wrap:break-word` greift nur, wenn auch das nicht reicht.
3. **Ein paar Punkt kleiner, wenn es eng wird.** Die Kachel misst **sich selbst**
   (`container-type: inline-size`) – in einem vierspaltigen Gitter ist sie schmal,
   obwohl das Fenster breit ist, und eine Media-Query sähe davon nichts:

   | Kachelbreite | Überschrift |
   |---|---|
   | über 320px | 21px |
   | bis 320px | 19px |
   | bis 250px | 17px, dazu engeres Padding |

Gemessen im Browser über alle Gitterbreiten: kein Überlauf mehr, und die
Kennzahlen stehen in jeder Kachel exakt 83px über der Unterkante, die Buttons
58px – unabhängig davon, wie lang der Teaser ist.

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
Übergangsseite mit beiden:      [bi_seminarsuche programm="2027|2028"]
```

Ohne das Attribut werden alle Jahrgänge gemeinsam gezeigt (vergangene Termine
sind ohnehin durch den „buchbar"-Filter auf Startdatum ≥ heute ausgeblendet).

Soll die Auswahl bei den Besucher\*innen liegen statt in der Seite, schaltet man
stattdessen den Chip **Programm** ein – siehe
[Such- und Filterleiste](#bi_seminarsuche--such--und-filterleiste). Beides
zugleich ergibt keinen Sinn: Legt das Attribut den Jahrgang fest, erscheint
kein Chip.

## Benachrichtigungen

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
Themenfeld „Datenschutz", oder nur wenn Bildungszentrum *nicht*
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

### Wohin die Post geht

Am Seminar stehen **zwei** Adressen, und sie haben verschiedene Aufgaben:

| Feld | Aufgabe |
|---|---|
| **E-Mail zuständiges Bildungszentrum** (`_bi_bz_email`) | **Wohin** die Post geht. Nur dieses Feld steuert Benachrichtigungen. |
| **E-Mail Ansprechpartner** (`_bi_ansprechpartner_email`) | **Wen** Interessierte fragen können. Steht auf der Detailseite, ist für den Versand ohne jede Bedeutung. |

Dazu **Telefon Ansprechpartner** (`_bi_ansprechpartner_telefon`) – ebenfalls
eine Kontaktangabe, kein Zustellweg. Es erscheint auf der Detailseite und im
PDF nur, wenn dort etwas steht; ein leeres Feld hinterlässt keine Lücke.

> **Warum die Trennung.** Bis Fassung 1.115.0 gab es nur ein Adressfeld. Es hieß
> „E-Mail Ansprechpartner", trug aber die Zustelladresse des Bildungszentrums –
> dorthin gingen alle Benachrichtigungen. Zwei Rollen in einem Feld, und die
> Beschriftung nannte die falsche. Wer sie las und daraufhin die persönliche
> Adresse einer Kollegin eintrug, leitete damit sämtliche Anmeldungen dieses
> Seminars in ein persönliches Postfach um – ohne dass am Feld irgendetwas
> darauf hindeutete.

#### Die Rangfolge

Der Empfänger einer Benachrichtigung wird in dieser Reihenfolge gesucht:

1. **`_bi_bz_email` am Seminar** – die Ausnahme schlägt die Regel. Ein einzelnes
   Seminar darf eine abweichende Anmeldestelle haben.
2. **Die Adresse am Begriff** des zuständigen Bildungszentrums (Term-Meta
   `email` an `bi_ort`) – die Regel. An einer Stelle gepflegt, gilt für alle
   seine Seminare.

**Die Adresse der Ansprechperson kommt hier nicht vor** – auch nicht als letzter
Rückfall. Steht in beiden Feldern nichts, geht keine Mail: Lieber gar keine
Zustellung als eine an die falsche Stelle. Eine unbrauchbare Eingabe (kein `@`,
nur Leerzeichen) zählt dabei als leer und fällt auf den Begriff zurück.

#### Die Trigger-Typen

Aus den früheren zwei Punkten *„Bildungszentrum (Seminarort)"* und
*„Ansprechpartner des Seminars"* ist einer geworden: **„Zuständiges
Bildungszentrum"**. Beide liefen ohnehin auf dieselbe Stelle hinaus, nur über
verschiedene Felder.

Gespeicherte Trigger vom alten Typ `ansprechpartner` werden beim Aktualisieren
auf `bildungszentrum` umgeschrieben. Ein dabei übersehener stellt trotzdem
richtig zu – der alte Typ bleibt lesbar und folgt derselben Rangfolge. Das ist
kein Zufall, sondern Absicht: Ein Trigger, der still verstummt, fällt erst auf,
wenn jemand eine Anmeldung vermisst.

#### Die Platzhalter

| Platzhalter | Liefert |
|---|---|
| `{bildungszentrum_email}` | die Zustelladresse (Rangfolge wie oben) |
| `{ansprechpartner_email}` | **dasselbe** – der alte Name, damit bestehende Vorlagen weiterlaufen |
| `{ansprechpartner}` | den Namen der Ansprechperson |
| `{ansprechpartner_telefon}` | ihre Telefonnummer, leer wenn nicht gepflegt |

`{ansprechpartner_email}` steht in Vorlagen, die es seit Jahren gibt. Er wird
deshalb nicht abgeschafft, sondern liefert weiter genau das, was er vor der
Trennung lieferte: die Adresse des Bildungszentrums. In neuen Vorlagen gehört
der Name aus der ersten Zeile.

#### Was beim Aktualisieren passiert

Einmalig, beim ersten Aufruf nach dem Update:

1. Jede vorhandene Adresse aus `_bi_ansprechpartner_email` wird nach
   `_bi_bz_email` **kopiert** – nicht verschoben. Der Altbestand bleibt
   unangetastet; bis er von Hand aufgeräumt ist, steht in beiden Feldern
   dasselbe, und **nichts verhält sich anders als vorher**.
2. Gespeicherte Mail-Trigger vom Typ `ansprechpartner` werden umgeschrieben.

Ein Hinweis im Adminbereich nennt beide Zahlen und lässt sich wegklicken. Der
Lauf ist wiederholbar: Er schreibt nur dort, wo noch nichts steht, und
überschreibt keine Adresse, die inzwischen von Hand korrigiert wurde.

> **Die Aufräumarbeit bleibt Handarbeit.** Nach dem Update steht in
> `_bi_ansprechpartner_email` weiterhin die Bildungszentrums-Adresse. Wer dort
> echte Ansprechpersonen einträgt, ändert damit den Versand nicht mehr – aber
> die Detailseite. Die Massenbearbeitung in der Seminarliste hilft dabei: Beide
> Felder stehen dort zur Auswahl.

Festgehalten in `tests/test-mail-empfaenger.php`.

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
  mit dem hinterlegten Logo oben rechts. Dasselbe Dokument steht auf der
  Detailseite zum [Herunterladen](#seminardetails-als-pdf-herunterladen).
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
Veranstalter `IG Metall`. Bei der **Wochenzusammenfassung** gibt es keine
Anhänge, weil dort mehrere Seminare in einer Mail stecken.

**Das Logo oben rechts** ist ohne eigene Auswahl das mitgelieferte
IG-Metall-Signet (`assets/img/igm-logo.png`); die Einstellung schlägt es. Das
Bild wird in ein Feld von **32 × 15 mm** eingepasst – ein Schriftzug schöpft die
Breite aus, ein quadratisches Zeichen die Höhe, und beides bleibt über der roten
Linie des Kopfes. Wer gar kein Logo will, gibt am Filter `bi_pdf_logo_path` ''
zurück. Die **Beschlussvorlage** bleibt in jedem Fall ohne Logo – sie ist ein
Schreiben des Betriebsrats, nicht des Veranstalters.

> **Zur mitgelieferten Datei:** `assets/img/igm-logo.png` (900 × 900 px, RGB ohne
> Alphakanal) ist aus dem offiziellen Signet des Design-Systems gerastert, das als
> `assets/img/igm-logo.svg` danebenliegt (*IGMetall-Logo-3C-RGB*). **Das PNG ist
> die Datei, die zählt** – FPDF kann kein SVG einbetten; das SVG steht als Quelle
> daneben. Neu rastern, falls nötig:
>
> ```bash
> qlmanage -t -s 900 -o . assets/img/igm-logo.svg
> ```
>
> Danach auf RGB abflachen (FPDF verträgt keinen Alphakanal) und als
> `igm-logo.png` ablegen.

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

### Kopie an (Cc)

Jede Benachrichtigung kann zusätzlich an eine oder mehrere Adressen gehen –
typisch ein Archiv oder ein zweites Postfach. Das Feld steht in der
Bearbeiten-Maske unter *Antworten an*.

Erlaubt sind feste Adressen, die Platzhalter aus der Liste und die Form
`Name <mail@domain.de>`; mehrere Adressen werden mit Komma getrennt. **Leer
lassen**, wenn niemand mitlesen soll – die Vorgabe für neue Benachrichtigungen
ist leer, und bestehende bekommen kein Cc dazu.

Ergibt der Platzhalter keine gültige Adresse, entfällt die Kopie und die Mail
geht trotzdem raus. Eine Kopie ist nie ein Grund, eine Benachrichtigung nicht
zuzustellen.

> **Eine Kopie ist sichtbar.** Alle Empfänger sehen, wer sonst noch mitliest,
> und die Adresse steht in jeder Mail. Für stille Mitleser ist das der falsche
> Weg – dafür gäbe es Bcc, das dieses Feld bewusst nicht ist.

In der **Liste der Benachrichtigungen** steht ein gesetztes Cc unter der
Empfängerspalte. Wer die Liste überfliegt, soll sehen, dass hier jemand
mitliest; sonst fällt eine vergessene Archivadresse erst auf, wenn sich jemand
über Post wundert, die er nicht bestellt hat.

#### Zwei Stellen, an denen die Kopie anders arbeitet

**Beim Probeversand geht keine Kopie.** Ein Test geht an die eingetragene
Testadresse, damit niemand sonst behelligt wird – die Kopie ginge aber an den
echten Verteiler und damit an Menschen, die gerade nichts von einem Test
erfahren sollen. Der Hinweis in der Testmail nennt stattdessen, wer sie bekommen
hätte.

**In der Wochenzusammenfassung werden Kopien nicht zusammengefasst.** Bei den
[Antwortadressen](#absender-und-antwortadresse) ist das Sammeln richtig – eine
Antwort soll alle enthaltenen Personen erreichen. Bei der Kopie wäre es ein
Datenleck: Stünde dort ein Platzhalter wie `{email}`, bekäme jede angemeldete
Person die Zusammenfassung samt den Adressen aller anderen zu sehen. Eine Kopie
ist als fester Mitleser gedacht, und der ist über alle Einträge einer Gruppe
derselbe – verschickt wird deshalb genau eine.

#### Kopfzeilen lassen sich nicht einschmuggeln

Zeilenumbrüche in einem Adressfeld werden zu Leerzeichen. Ein Wert wie
`archiv@example.de\r\nBcc: heimlich@example.com` ist danach eine einzige,
ungültige Adresse – die Kopie entfällt vollständig, statt eine fremde Kopfzeile
in die Mail zu tragen. Dasselbe gilt für Absender und Antwortadresse.

Festgehalten in `tests/test-mail-empfaenger.php`.

## Verfügbarkeits-Ampel

Auf der Seminar-Detailseite steht neben dem Termin, wie es um die Plätze steht:

| Punkt | Beschriftung der Quelle |
|---|---|
| grün | Verfügbar |
| orange | Fast ausgebucht |
| rot | Warteliste möglich |

Die Angabe stammt aus dem Seminarverwaltungssystem des Vorstands und wird über
die Seminarsuche auf `igmetall.de` abgeglichen. Verknüpft wird über die
**Seminarnummer** – deshalb erscheint eine Ampel nur dort, wo die Nummer
gepflegt ist und auf beiden Seiten übereinstimmt.

*Einstellungen → Verfügbarkeits-Ampel* steuert das Modul, zeigt den Stand und
erlaubt einen Abgleich von Hand.

### Wie der Abgleich arbeitet

Beim Seitenaufruf wird **nichts** nachgeladen. Die Website liest ausschließlich
die eigene Tabelle `wp_bi_ampel`. Ein fremder Server bestimmt so weder die
Ladezeit noch die Verfügbarkeit der eigenen Seite.

Der Abgleich läuft nachts, in Häppchen von je etwa 20 Abrufen pro Taktschlag:

1. Trefferliste der erweiterten Seminarsuche holen → Kennungen der Seminarangebote
2. je Angebot die Termintabelle holen und auslesen
3. ist der Lauf vollständig, wird er als neue Generation live geschaltet und die
   alte gelöscht

Schritt 3 ist der Grund, warum ein abgebrochener Lauf nichts kaputt macht: bis
er durch ist, bleibt die bisherige Generation unverändert sichtbar. Ein
vollständiger Lauf braucht rund eine Stunde (etwa 230 Angebote mit gut einer
Sekunde Pause dazwischen – Rücksicht auf die fremde Infrastruktur).

### Der Abgleich setzt den Haken „Ausgebucht"

Seit 1.78.0 zeigt der Lauf die Verfügbarkeit nicht nur an, er **pflegt daraus
den vorhandenen Haken** am Seminar:

| Zustand der Quelle | Haken „Ausgebucht" |
|---|---|
| grün – Verfügbar | wird entfernt |
| orange – Fast ausgebucht | wird entfernt |
| **rot – Warteliste möglich** | **wird gesetzt** |
| unbekannter Zustand | bleibt, wie er ist |

Damit greift alles, was ohnehin am Haken hängt: kein Buchen-Button, „Ausgebucht"
in der Terminliste, Ausschluss aus den buchbaren Seminaren des Anmeldeformulars.

Drei Grenzen:

- **In beide Richtungen.** Wird ein Termin wieder frei, fällt der Haken weg —
  sonst bliebe er für immer als ausgebucht stehen.
- **Nur bekannte Nummern.** Seminare, deren Seminarnummer in der Generation
  nicht vorkommt, rührt der Abgleich nicht an — dort entscheidet weiter die
  Pflege.
- **Nur nach einem vollständigen Lauf**, direkt nachdem die neue Generation live
  ist. Ein Lauf ohne Daten fasst die Haken nicht an; sonst würde eine Störung
  der Quelle den ganzen Katalog freigeben. Festgehalten in
  `tests/test-ampel-lauf.php`.

Das Protokoll des Laufs nennt die Bilanz: *„… 2 auf ‚ausgebucht' gesetzt,
1 wieder freigegeben."*

#### Handkorrekturen halten

Meldet die Quelle einen Termin falsch als voll und nimmt jemand den Haken von
Hand heraus, **setzt der nächste Abgleich ihn nicht wieder**. Bis 1.98.0 hielt
eine solche Korrektur nur bis zum nächsten Lauf — also höchstens einen Tag.

Dafür merkt sich die Ampel in `_bi_ausgebucht_ampel`, was sie **selbst** zuletzt
geschrieben hat. Weicht der Haken heute davon ab, war ein Mensch am Werk:

| Lage | Was der Abgleich tut |
|---|---|
| Haken = Notiz | normal: er schreibt den Stand der Quelle |
| Haken ≠ Notiz, Quelle sagt **dasselbe wie zuvor** | **nichts** — die Korrektur bleibt stehen |
| Haken ≠ Notiz, Quelle sagt **etwas Neues** | die frische Nachricht sticht die Korrektur |

Die zweite Hälfte ist die wichtigere: Ohne sie fröre ein einmal von Hand
freigegebenes Seminar für immer als buchbar ein, auch wenn es später wirklich
voll ist. Beide Richtungen gelten — auch ein von Hand *gesetzter* Haken bleibt
stehen, etwa wenn das Bildungszentrum früher Bescheid weiß als die Quelle.

Sichtbar ist das an drei Stellen:

- Das Protokoll zählt sie mit: *„… 3 Handkorrektur(en) unangetastet gelassen."*
- *Einstellungen → Verfügbarkeits-Ampel* listet unter **Handkorrekturen** jede
  betroffene Seminarnummer mit beiden Ständen — was der Haken sagt und was die
  Quelle sagt.
- Ein Knopf hebt alle auf einmal auf. Er löscht die **Notiz**, nicht den Haken:
  Der Stand am Seminar bleibt, wie er ist, bis die Quelle das nächste Mal etwas
  sagt. Eine einzelne Korrektur nimmt man zurück, indem man den Haken wieder auf
  den Stand der Quelle setzt — dann sind sich beide einig.

> **Ein Import hebt die Korrektur auf.** Steht der Haken in einer eingelesenen
> CSV-Datei oder in einem JSON-Paket, ist das eine frische Aussage der
> Programmplanung und keine Korrektur an der Ampel — die Notiz wird dabei
> vergessen. Ohne diese Ausnahme hielte ein Reimport, der alle Haken auf „nein"
> setzt, anschließend jedes volle Seminar fälschlich als buchbar fest.

Festgehalten in `tests/test-ampel-handkorrektur.php`.

#### Abschalten gibt die Seminare wieder frei (seit 1.100.0)

Ist die Quelle zeitweise unzuverlässig und meldet Termine als voll, die es nicht
sind, schaltet man das Modul ab. Bis 1.99.4 war das nur die halbe Miete: Die
Haken **Ausgebucht**, die die Ampel gesetzt hatte, blieben stehen — und ohne
Abgleich nahm sie niemand mehr zurück. Jedes betroffene Seminar musste von Hand
gesucht und freigegeben werden.

**Seit 1.100.0 nimmt das Abschalten sie mit.** Wer den Haken *Ampel anzeigen und
regelmäßig abgleichen* entfernt und speichert, bekommt zurückgemeldet, wie viele
Seminare dabei wieder buchbar wurden.

Angefasst wird nur, was der Ampel gehört. Das weiß sie aus zwei Vermerken:

| Am Seminar steht | Herkunft des Hakens | Freigabe |
|---|---|---|
| `_bi_ausgebucht_von_ampel` = 1 | der Abgleich hat den Haken selbst umgelegt | ja |
| `_bi_ausgebucht_von_ampel` = 0 | von Hand oder aus einem Import gesetzt | **nein** |
| kein Vermerk, Notiz `_bi_ausgebucht_ampel` = 1 | Altbestand von vor 1.100.0 | ja |
| kein Vermerk, keine Notiz | die Ampel war dort nie | **nein** |

Der Vermerk entsteht bei jedem Abgleich, der ein Seminar berührt — auch als
ausdrückliches *„gehört mir nicht"*. Deshalb bleibt ein von Hand gesetzter Haken
selbst dann fremd, wenn die Quelle ihm zustimmt.

Die dritte Zeile ist der Übergang: Vor 1.100.0 hielt die Ampel nur fest, *was*
sie zuletzt geschrieben hat, nicht *ob* sie den Haken damit bewegt hat. Für diese
Seminare ist die Notiz der einzige Hinweis — und sie sagt immerhin, dass dort
zuletzt die Ampel „voll" geschrieben hat. Läuft ein Abgleich, trägt er den
fehlenden Vermerk nach derselben Regel nach; danach ist die Herkunft eindeutig.

Mit dem Haken gehen Vermerk und Notiz. Danach steht am Seminar keine halbe
Aussage der Ampel mehr: Wird das Modul später wieder eingeschaltet, schreibt der
erste Abgleich dort ohne Rückfrage den Stand der Quelle. Bliebe die Notiz
stehen, sähe die Freigabe wie eine Handkorrektur aus und hielte ein später
wirklich volles Seminar auf Dauer als buchbar fest.

> **Der Knopf für danach.** Wurde schon abgeschaltet und die Haken stehen noch,
> hilft *Einstellungen → Verfügbarkeits-Ampel → **Von der Ampel gesetzte
> Ausbuchungen***. Der Abschnitt listet die betroffenen Seminare und gibt sie auf
> Knopfdruck alle frei. Er ist auch bei eingeschaltetem Modul sichtbar — dann mit
> dem Hinweis, dass eine Freigabe nur bis zum nächsten Abgleich hält.

Festgehalten in `tests/test-ampel-freigabe.php`.

### Wann keine Ampel erscheint

Es wird lieber gar nichts angezeigt als etwas Falsches. Die Ampel entfällt,
wenn

- die Seminarnummer nicht in der Tabelle steht (z. B. frisch importiert),
- die Quelle einen unbekannten Zustand liefert,
- die Daten älter sind als die eingestellte Höchstfrist (Vorgabe 48 Stunden),
- das Modul abgeschaltet ist.

Eine grüne Ampel auf einem vollen Seminar wäre schlimmer als keine Ampel. Zu
jeder angezeigten Ampel gehört deshalb auch die Angabe „Stand: TT.MM.JJJJ".

### Nach einem Seminar-Import

Jeder CSV-Import mit Treffern fordert automatisch einen außerplanmäßigen
Abgleich an, der mit dem nächsten Taktschlag anläuft (also binnen Minuten statt
erst in der Nacht). Bis der Lauf durch ist – rund eine Stunde –, zeigen die
neuen Termine schlicht keine Ampel; die bereits gepflegten behalten
unverändert ihre.

Die Kennzahl **„Eigene Seminare abgedeckt"** im Einstellungs-Tab ist die
Kontrolle danach: bricht sie nach einem Import ein, passen die Seminarnummern
nicht mehr zusammen.

### Abgleich von Hand

*Einstellungen → Verfügbarkeits-Ampel → „Abgleich jetzt starten"*. Der Tab zeigt
dann einen Fortschrittsbalken und **treibt den Lauf selbst voran**, solange er
offen bleibt — rund fünf Minuten für den kompletten Katalog. Das ist der Weg auf
Test- und Redaktionssystemen: dort feuert WP-Cron mangels Besuchern kaum, ein
Lauf käme sonst über den ersten Schritt nicht hinaus.

Darunter steht eine **Stichprobe**: die nächsten zehn anstehenden Seminare mit
dem, was das Nachschlagen zu ihrer Nummer liefert. Fehlt auf einer Detailseite
die Ampel, steht hier der Grund — „Nummer steht nicht in den abgeglichenen
Daten", „noch kein Abgleich durchgelaufen", „Daten zu alt".

### Betrieb

Der Taktgeber läuft über WP-Cron und feuert damit nur bei Seitenaufrufen. Für
einen verlässlichen nächtlichen Abgleich gehört ein echter System-Cron auf
`wp-cron.php` eingerichtet und `DISABLE_WP_CRON` in die `wp-config.php`:

```cron
*/5 * * * * curl -s https://bildung.igmetall.de/wp-cron.php?doing_wp_cron > /dev/null
```

Das Backend meldet von sich aus, wenn ein Lauf keine Daten geliefert hat oder
die Quelle einen unbekannten Zustand schickt. Beides sind Frühwarnzeichen für
einen Relaunch von `igmetall.de`.

> **Besser als Scraping wäre ein Export.** `bildung.igmetall.de` und
> `www.igmetall.de` gehören zur selben Organisation, und die Seminarnummern
> stammen ohnehin aus derselben Quelle. Eine nächtliche CSV
> `Seminarnummer;Status` würde diesen ganzen Abgleich ersetzen und übersteht
> jeden Relaunch. Der Parser sitzt deshalb in einem eigenen Modul
> (`class-bi-ampel-parser.php`) und ist gegen eine CSV-Quelle austauschbar,
> ohne dass Anzeige oder Datenmodell davon berührt werden.

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

## Die Detailseiten

Seminardetail, Reihenseite und Reihenübersicht folgen seit 1.63.0 dem
IG Metall Design System. Sie teilen sich einen Aufbau, ein Stylesheet
(`assets/css/detailseiten.css`) und die Bausteine in `BI_Detail`:

| Baustein | Methode | Aussehen |
|---|---|---|
| Kopf | `BI_Detail::hero()` | Overline, H1 in Meta Head IGM Cond Black/Versalien/Rot, Badges, Beitragsbild mit rotem Dreieck |
| Zurück zur Suche | `BI_Detail::zurueck_link()` | Textlink mit Pfeil über dem Titel, auf beiden Detailseiten |
| Kennzahlenband | `BI_Detail::fakten()` | 4px roter Trennstrich, darunter graues Band mit bis zu vier Angaben (Piktogramm, Label, Wert) |
| Abschnitt | `BI_Detail::abschnitt()` | roter Titel, kurzer roter Strich, graue Unterzeile |
| Terminzeile | `BI_Detail::termin_zeile()` | eine Zeile über die volle Breite: Statuspunkt, Datum, Ort, Seminarnummer, Status |
| Aufzählung | `BI_Detail::themen_html()` | ▸-Marke; nimmt sowohl HTML-Listen als auch Fließtext mit Zeilenumbrüchen entgegen |

**Lange Wörter brechen mit Trennstrich um.** In der 320px-Sidebar bleiben der
Wertespalte rund 150px – „Schwerbehindertenvertretung" passt da nie in eine
Zeile. Bis 1.80.0 stand dort `Schwerbehindertenve / rtretung`: gebrochen, wo der
Platz endete. Jetzt übernimmt die Silbentrennung des Browsers (`hyphens: auto`
plus `lang="de"` an der Datenliste), und es steht `Schwerbehinderten- /
vertretung`. Für Zeichenketten ohne Trennstelle bleibt `overflow-wrap:
break-word` als Notnagel.

**Farben und Schriften** kommen aus dem Design System, sobald dessen Tokens auf
der Seite liegen (`--igm-rot`, `--font-head` …). Fehlen sie – etwa unter einem
anderen Theme –, greifen die Werte aus dem Design-Handoff als Rückfallebene.
Abgerundete Ecken gibt es nicht; weil viele Themes `border-radius` auf Buttons
und Bilder legen, setzt das Stylesheet ihn für die eigenen Bauteile ausdrücklich
auf 0.

**Piktogramme** stehen als Inline-SVG in `BI_Icons` statt als Dateien. Sie sitzen
direkt neben Text und sollen dessen Farbe annehmen; mit `currentColor` geht das
ohne CSS-Maske, ohne zusätzlichen Abruf und ohne die Gefahr, dass ein Pfad beim
Deployment nicht mitkommt.

### Zurück zur Suche

Über dem Titel steht auf Seminar- und Reihenseite ein Textlink zurück zur
Trefferliste. Der Browser-Knopf kann das auch, taugt aber nicht als einziger
Weg: Wer über einen geteilten Link kam, hat keine Vorgeschichte, und wer sich im
Seminar mehrere Termine angesehen hat, müsste ihn mehrfach drücken.

Das Ziel steht schon im HTML (`BI_Registration::uebersicht_url()`: Einstellung
*Seminarübersicht*, sonst die Seite mit `[bi_seminarsuche]`). **Führt die
Ermittlung auf die Startseite, erscheint gar kein Link** – „Zurück zur Suche",
das auf der Startseite endet, wäre eine falsche Auskunft.

Die **Filter** bleiben über `assets/js/zurueck.js` erhalten: `filterleiste.js`
legt auf der Ergebnisseite Pfad und Filter unter `sessionStorage['biSuche']` ab,
das Skript biegt den Link darauf um und beschriftet ihn dann *„Zurück zu den
Treffern"*. Übernommen wird nur ein eigener absoluter Pfad (`/…`, nicht `//…`).
Ohne JavaScript bleibt der Link auf der ungefilterten Übersicht – brauchbar,
nur weniger genau. Geprüft in `tests/test-zurueck-link.php`.

**Was die Seminardetailseite zeigt:** Format und Themenfeld über dem Titel und –
falls der Termin zu einer Reihe gehört – ein Hinweis-Badge mit Link auf die
Reihenseite. Die **Seminarnummer steht nicht unter dem Titel**, sondern in der
Box „Seminardetails": Sie ist eine Angabe wie Zeitraum oder Ort und entscheidet
über dem Titel nichts. Im Band stehen Zeitraum, Ort, Freistellung und
Zielgruppe. Links Beschreibung, Themen und die weiteren Termine desselben
Seminars; rechts die Box „Seminardetails" mit der Verfügbarkeits-Ampel als
farbig hinterlegtem Kasten, der Datenliste, der Kostenaufstellung und dem
Buchungsbereich, darunter die Box „Seminardetails als PDF" und die
Ansprechperson.

### Seminardetails als PDF herunterladen

Unter der Box „Seminardetails" steht eine eigene kleine Box mit dem Knopf
**„PDF herunterladen"**. Sie liefert **dasselbe Dokument, das auch den
Anmeldemails beiliegt** ([Anhänge](#anhänge)) – wer es vor der Anmeldung
mitnimmt oder in die Betriebsratssitzung legt, hat wortgleich das vor sich, was
später in der Bestätigung steckt.

- **Eigene Box statt Link unter dem Buchen-Knopf:** Der Buchungsbereich soll
  genau einen Handlungsaufruf haben. Der Download ist deshalb der sekundäre
  (weiße) Knopf in einer Box darunter.
- **Adresse:** `admin-post.php?action=bi_seminar_pdf&seminar=<ID>`
  (`BI_PDF::download_url()`). Öffentlich und **ohne Nonce** – der Link soll
  teilbar sein und nicht nach 24 Stunden ablaufen; er löst nichts aus und liest
  nur Angaben, die daneben ohnehin auf der Seite stehen. Ausgegeben wird nur,
  was **veröffentlicht** ist; Entwürfe bekommt nur, wer sie auch im Backend
  sehen dürfte.
- **Fehlt `vendor/fpdf`, entfällt die Box ersatzlos** statt einen Knopf zu
  zeigen, der in einen Fehler führt. Geprüft wird das mit `BI_PDF::vorhanden()`
  – anders als `available()` lädt es die Bibliothek nicht, sonst zöge jede
  Detailseite 50 KB PHP nach, nur um einen Link anzuzeigen.
- In der **Druckansicht** ist die Box ausgeblendet.

Geprüft in `tests/test-pdf-download.php`.

**„Weitere Termine zu diesem Seminar"** listet alle Termine mit gleichem Titel,
**die ab heute noch stattfinden** – auch solche, die *vor* dem gerade geöffneten
liegen. Maßstab ist das heutige Datum, nicht der gezeigte Termin: Wer über einen
Link auf den Dezember-Termin kommt, soll den freien Platz im September sehen.
Sortiert wird nach Startdatum, der früheste steht oben.

Die Liste steht als Zeilen statt als Tabelle – ohne Spaltenüberschriften, denn
über einer Handvoll Zeilen wäre eine Kopfzeile mehr Rahmen als Inhalt. Vier
Spalten in der gewohnten Reihenfolge:

| Spalte | Inhalt |
|---|---|
| **Seminarort** | Bildungszentrum bzw. Veranstalter\*in |
| **Zeitraum** | verlinkt auf den Termin |
| **Verfügbarkeit** | die [Ampel](#verfügbarkeits-ampel); entfällt ganz, wenn für keinen Termin etwas vorliegt |
| — | **„Jetzt buchen"** → führt auf die Detailseite dieses Termins, bei nicht buchbaren Terminen steht dort der Grund |

> **Der Button heißt in jeder Zeile gleich und führt immer aufs selbe Ziel.**
> Früher stand bei Terminen mit Geschäftsstellen-Anmeldung „Zur
> Geschäftsstellensuche" und sprang direkt auf igmetall.de – mitten im
> Vergleichen weg von der Seite, ohne dass man den Termin gesehen hätte. Jetzt
> geht es erst auf den Termin; dort steht die PLZ-Suche mit allem Drum und Dran.
#### Nicht buchbare Termine treten zurück

Ein Termin, zu dem es nichts zu buchen gibt, **bleibt in der Liste** und wird
ausgegraut gezeigt: grauer Grund, gedämpfte Schrift, **keine Ampel** und
**kein Button**. Wo sonst der Button steht, nennt grauer Text den Grund:

| Grund | Woher |
|---|---|
| **Ausgebucht** | Haken am Seminar – den setzt auch der [Verfügbarkeits-Abgleich](#der-abgleich-setzt-den-haken-ausgebucht) aus „Warteliste möglich" |
| **Keine Anmeldung** | Variante 3 der [Regel-Engine](#anmeldevarianten); der Text kommt aus *Einstellungen → Anmeldung* |

Drei Entscheidungen dahinter:

- **Zeigen statt verstecken.** Wer nicht buchen kann, will trotzdem wissen, dass
  es das Seminar gibt: wann, wo, zu welchen Kosten. Ein Termin, der einfach
  fehlt, wirft nur die Frage auf, ob er je existiert hat.
- **Keine Ampel.** Der rote Punkt stünde neben einer Zeile, die ihren Zustand
  schon im Klartext nennt – und bei „keine Anmeldung" wäre eine Verfügbarkeit
  schlicht die falsche Auskunft.
- **Kein ausgegrauter Button.** Der sieht aus wie ein Fehler und lädt zum
  Klicken ein. Text an derselben Stelle sagt dasselbe, ohne etwas zu versprechen.

Die Entscheidung fällt an einer Stelle – `BI_Detail::nicht_buchbar()` –, damit
Terminliste, Trefferliste und Reihenseite dieselbe Auskunft geben. In der
**Trefferliste** wirkt sie genauso: gedämpfte Zeile, grauer statt roter Balken
am linken Rand, der Grund als Chip neben dem Titel. Der Link auf die Detailseite
bleibt überall bestehen.

Anklickbar ist der **Zeitraum**, nicht die ganze Zeile: Am Ende steht ein Button,
und ein Button in einem Link ist weder gültiges HTML noch bedienbar – man träfe
beim Klicken mal das eine, mal das andere.

#### Warum die Zeile sich an der Liste misst, nicht am Fenster

Der Platz ist knapp: In der 656px breiten Hauptspalte müssen Ortsname, Datum,
Verfügbarkeit und Button nebeneinander — und diese 656px bleiben 656px, auch auf
einem 1600px-Bildschirm. Eine Fensterabfrage (`@media`) sieht das nicht. Genau
daran ist die Zeile aufgelaufen: Dem Ortsnamen blieben rund 96px, er brach um,
und weil die Zellen mittig standen, rutschte das Datum **zwischen** die beiden
Ortszeilen. Die Spalten lasen sich als ein Textblock.

Zwei Änderungen, beide in `assets/css/detailseiten.css`:

- **`align-items: start`** — alle vier Angaben beginnen auf derselben Höhe.
  Daran erkennt das Auge die Spalte, auch wenn eine davon umbricht. Trennlinien
  braucht es dafür nicht; Weißraum (20px) und die gemeinsame Oberkante genügen.
- **`@container bi-termine`** statt `@media` — die Liste misst ihre eigene
  Breite. Unter 620px wird aus der Tabelle eine Karte: links Ort und Zeitraum
  untereinander, rechts Verfügbarkeit und Aktion. Dieselben Regeln stehen
  zusätzlich in der 760px-Fensterabfrage, als Rückfallebene für Browser ohne
  Container-Queries (vor Chrome 105 / Safari 16).

Wem die Zeile trotzdem zu unruhig ist, kürzt unter *Einstellungen → Anmeldung*
den Button-Text der Geschäftsstellen-Variante (Standard „Zur
Geschäftsstellensuche" – ein Wort davon allein ist 170px breit).

## Ausbildungsreihen

Eine Reihenseite im gedruckten Programm (etwa „Aufgaben der VK-Leitung", S. 76/77
im Heft 2027) trägt Texte auf drei Ebenen:

| Ebene | Inhalt | Kommt her aus |
|---|---|---|
| **Reihe** | Einleitung, Zielgruppe, Voraussetzungen, Freistellung, Seminarleitung | Beitragstyp `bi_reihe` – wird von Hand geschrieben |
| **Teil** | Teil-Titel, „Themen im Seminar" | den Terminen selbst |
| **Termin** | Datum, Seminarnummer, Ort, Marker | den vorhandenen Seminaren |

Nur die oberste Ebene ist wirklich neu. Die mittlere steckt bereits in den
Terminen: Alle Termine eines Teils tragen denselben Titel und dieselben Themen.
Die Reihenseite liest sie deshalb aus dem ersten Termin, statt sie ein zweites
Mal zu pflegen – zwei Quellen für denselben Text laufen binnen eines Jahrgangs
auseinander.

### Das Feld „Teil | Reihe"

Jeder Termin trägt die Zuordnung im Feld `_bi_teil_reihe`:

```text
Teil 2 | Ausbildungsreihe Aufgaben der VK-Leitung
Reihe 1 - Teil 2 | Ausbildungsreihe Aufgaben der VK-Leitung
```

- Links steht **nur** `Teil <Zahl>`, davor optional `Reihe <Zahl> -` für eine
  feste Gruppe – kein Titel, kein Beschreibungstext.
- Rechts der Reihenname, **zeichengleich** bei allen Terminen derselben Reihe.
  Er ist der Schlüssel und wird mit dem Titel der Reihe verglichen.
- Termine ohne Reihe: Feld leer lassen.

> **„Reihe" heißt hier zweierlei.** Rechts vom Strich steht die Ausbildungsreihe
> als Ganzes. Die Zahl links bezeichnet den **Durchgang** – eine feste Gruppe,
> die alle Teile gemeinsam durchläuft; im Heft steht darüber „Termine Reihe 1".
> Im Code heißt das durchgehend *Durchgang*, in der Ausgabe steht wie im Heft
> „Reihe 1". Die nachgestellte Form `Teil 2 | Reihenname | Reihe 1` bedeutet
> dasselbe.

Der Durchgang ist keine Kosmetik: Ohne ihn wären „Reihe 1 – Teil 2" und
„Reihe 2 – Teil 2" nicht zu unterscheiden, beide landeten in einem Topf, und dem
einzelnen Termin sähe niemand mehr an, zu welcher Gruppe er gehört. Die
Reihenseite gruppiert deshalb zweistufig: erst Durchgang, dann Teil. Einzeln
buchbare Termine (Durchgang 0) stehen direkt beim Teil, feste Gruppen bekommen je
eine eigene Tabelle mit der vollständigen Abfolge.

Beim Lesen ist der Parser nachsichtig, wo es nichts kostet (`Teil2|`, `Teil 2: |`,
`TEIL 2 |`, `Reihe 3 – Teil 5 |` bedeuten dasselbe) und streng, wo Raten schadet:
Ein Wert ohne Reihennamen wird **nicht** zugeordnet. Aus dem Teil-Titel auf die
Reihe zu schließen wäre geraten, und eine falsche Zuordnung fällt niemandem auf –
der Termin stünde einfach auf der falschen Reihenseite.

Taucht ein Reihenname zum ersten Mal auf, entsteht die Reihe als **Entwurf**.
Ohne Einleitungstext ist eine Reihenseite wertlos, also wird sie erst öffentlich,
wenn jemand sie geschrieben hat.

### Die Reihenliste durchsuchen

Das Suchfeld **Reihen durchsuchen** findet eine Reihe seit 1.95.0 auch über die
**Seminarnummer eines ihrer Termine** — dieselbe Regel wie bei den Seminaren.
Eine Reihe trägt selbst keine Nummer, ihre Termine tragen sie; wer aus einer
Mail, einem Anmeldebogen oder vom Telefon eine Nummer vor sich hat und wissen
will, wohin sie gehört, suchte vorher vergeblich.

Gesucht wird per `EXISTS` über die Zuordnung `_bi_reihe_id`, nicht per JOIN: Der
Weg von der Reihe zum Termin ist 1:n, ein JOIN gäbe eine Zeile je Termin — also
dieselbe Reihe zwanzigmal in der Liste. Titel und Text der Reihe bleiben
unverändert durchsuchbar; die Nummer kommt mit ODER daneben. Geprüft in
`tests/test-suche-nummer.php`.

### Termine von der Reihe aus zuordnen

Der Weg über das Feld am Seminar ist der des **Imports** – dort steht der
Reihenname schon in der Datei. Wer eine Reihe **von Hand anlegt** oder eine
importierte im Entwurf ausbaut, geht den umgekehrten Weg: In der Bearbeiten-Maske
der Reihe steht die Box **„Zugeordnete Teile und Termine"**, und darin ein
Suchfeld.

1. Reihe anlegen, **Titel vergeben und speichern** — der Titel ist der Schlüssel;
   ohne ihn zeigt die Box nur diesen Hinweis.
2. Im Suchfeld nach **Titel oder Seminarnummer** suchen.
3. **Teil** (und bei festen Durchgängen die **Gruppe**) einstellen, dann bei der
   passenden Zeile auf *Zuordnen*.

Die Liste der zugeordneten Termine darunter aktualisiert sich sofort; jede Zeile
hat *bearbeiten* und *Zuordnung lösen*. Die Seite selbst wird dabei **nicht** neu
geladen — sonst wären die ungespeicherten Eingaben in den übrigen Feldern der
Reihe weg.

> **Es bleibt bei einer Quelle.** Die Box schreibt nichts Eigenes, sondern setzt
> am Seminar dasselbe Feld `Teil | Reihe` — nur eben mit dem Reihennamen
> zeichengleich aus dem Titel statt abgetippt. Genau daran scheitert die
> Handarbeit sonst: Eine abweichende Schreibweise erzeugt eine zweite Reihe.
> Dass `BI_Reihen::feldwert()` schreibt, was `parse()` wieder liest, hält
> `tests/test-reihen.php` fest.

Zwei Grenzen:

- **Entwürfe zählen mit.** Die Suche findet auch nicht veröffentlichte Seminare,
  und Reihen im Entwurf lassen sich genauso bestücken — das ist der Normalfall
  nach einem Import.
- **Seminare ohne Startdatum erscheinen nicht.** Ein Termin ohne Datum ließe sich
  in der Reihe weder einsortieren noch buchen. Erst das Datum eintragen, dann
  zuordnen.

Hängt ein Termin bereits an einer **anderen** Reihe, steht das rot in der
Trefferzeile: Zuordnen zöge ihn dort weg.

### Prüfliste

Darunter stehen zwei weitere Listen: die erkannten Reihen mit
Anzahl der Teile, Termine und dem Zustand ihres Textes – und die Termine, deren
Angabe nicht zur Zuordnung gereicht hat, jeweils mit dem Grund. Die zweite ist
die wichtigere: In den Quelldaten zum Programm 2027 tragen rund 300 Einträge nur
`Teil 1: <Titel>` ohne den Reihennamen dahinter. Nachzuarbeiten ist das in der
Quelldatei; beim nächsten Import greift die Zuordnung dann von selbst.

### Die Reihenseite

Der Kopf nennt die Reihe, ob sie nur komplett buchbar ist und aus wie vielen
Teilen und Gruppen sie besteht. Im Kennzahlenband stehen Aufbau („4 Teile, je
3 Tage" – die Dauer nur, wenn sie bei allen Teilen gleich ist), Buchungsform,
nächster Start und die Orte.

Links folgen zwei Blöcke:

**„Inhalte der Reihe"** – je Teil eine aufklappbare Zeile mit rotem Nummernblock,
Titel und den „Themen im Seminar". Sind Teile einzeln buchbar (Durchgang 0),
stehen ihre Termine gleich mit darin. Ohne JavaScript sind alle Teile offen;
erst `assets/js/reihe.js` klappt zu und lässt Teil 1 offen – die Inhalte sind der
Kern der Seite und dürfen nicht hinter einem Skript verschwinden.

**„Termine der festen Gruppen"** – je Durchgang ein Zeitstrahl. Aufgeführt sind
immer *alle* Teile der Reihe, auch die, für die in dieser Gruppe noch kein Termin
ausgeschrieben ist („Termine in Abstimmung"): Sonst sähe eine halb geplante
Gruppe aus wie eine kürzere Reihe. Dazu drei Bedienelemente:

| Element | Verhalten |
|---|---|
| Ortsfilter (Chips) | Erscheint ab zwei Orten. Blendet nur Terminzeilen aus, nie Teile – läuft ein Teil am gewählten Ort nicht, erscheint ein Hinweis und die Alternativen bleiben stehen. Ein Wechsel setzt die Auswahl zurück, denn eine unsichtbare Auswahl wäre eine unsichtbare Zusage. |
| Terminauswahl | Ein echtes Radio je Teil, damit Tastatur und Vorlesehilfe das Muster kennen. Ein zweiter Klick auf dieselbe Zeile hebt sie auf – das kann ein Radio von sich aus nicht, deshalb merkt sich das Skript den Zustand vor dem Klick. Trägt die Reihe **„Nur komplett buchbar"**, ist die Auswahl bereits vorbelegt – [siehe unten](#nur-komplett-buchbar-die-auswahl-ist-schon-getroffen). |
| Fuß der Gruppe | Fortschrittszähler („2 / 4", grün bei vollständig), Statuszeile und Buchen-Button. Der Button wird erst nutzbar, wenn *alle* Teile ausgeschrieben **und** gewählt sind. Wohin er führt, entscheidet die Freistellung – siehe [Sammelanmeldung](#sammelanmeldung-eine-reihe-in-einem-zug). |

Eine Terminzeile zeigt **Auswahlpunkt, Zeitraum, Bildungszentrum** und einen
Zusatz („Ausgebucht", „Kinderbetreuung") – **keine Seminarnummer**. Bei der
Terminwahl beantwortet sie keine Frage, nahm aber eine eigene Spalte: Dem
Ortsnamen blieb so wenig Platz, dass er umbrach und sich mit der Nummer
überlagerte. Wer sie braucht, findet sie auf der Detailseite des Termins; in die
Geschäftsstellen-Mail geht sie weiterhin mit.

Wie die Terminlisten der Detailseite misst sich auch dieser Kasten selbst
(`@container`): Unter 520px stapeln sich Zeitraum, Ort und Zusatz rechts vom
Auswahlpunkt, statt sich in zu enge Spalten zu schieben.

#### „Nur komplett buchbar": die Auswahl ist schon getroffen

Der Haken **Nur komplett buchbar** an der Reihe setzte bis 1.94.0 nur ein
Etikett: Badge im Kopf, Zeile im Kennzahlenband, und beim Absenden wies
`auswahl_pruefen()` eine unvollständige Auswahl zurück. Auf die Auswahl selbst
wirkte er nicht — man musste jeden Teil einzeln anklicken, bevor der Button rot
wurde.

Das war in den meisten Gruppen eine Hürde ohne Entscheidung: Im Jahrgang 2027
hat der Zeitstrahl in **23 von 35** (Gruppe, Teil)-Kombinationen genau *einen*
Termin. Vier Klicks, die nichts wählen, standen vor einer Reihe, die ohnehin nur
am Stück zu haben ist.

**Seit 1.95.0 setzt das Skript jeden Teil, für den es genau eine buchbare
Möglichkeit gibt, selbst.** Bei einem Termin je Teil ist die Gruppe damit sofort
vollständig und der Button trägt „Reihe 1 buchen". Vier Regeln halten das
ehrlich:

- **Es wird nie geraten.** Ein Teil mit zwei Terminen ist eine echte Frage nach
  Ort und Datum und bleibt offen. Ein ausgebuchter Termin wird nicht gesetzt.
- **Die Statuszeile sagt „vorausgewählt"**, nicht „gewählt" — solange niemand die
  Auswahl angefasst hat. Eine fertige Auswahl, von der man nicht weiß, wer sie
  getroffen hat, ist keine Erleichterung.
- **Der Ortsfilter arbeitet zu.** Er setzt die Auswahl zurück; danach greift die
  Vorbelegung erneut. Was zu dritt zur Wahl stand, ist nach „Lohr" oft eindeutig
  und rastet ein.
- **Nichts wird eingesperrt.** Ein zweiter Klick wählt ab, und der Termin kommt
  nicht von selbst zurück — die Vorbelegung läuft beim Aufbau und nach einem
  Ortswechsel, nicht bei jeder Änderung.

Ist der einzige Termin eines Teils **ausgebucht**, lässt sich die Gruppe nicht
vervollständigen. Statt eine Auswahl anzubieten, die nie fertig wird, sagt die
Statuszeile das: „In dieser Gruppe ist mindestens ein Teil ausgebucht."

Ohne den Haken bleibt alles wie bisher — die Vorbelegung hängt allein an ihm.
Geprüft in `tests/test-reihe-vorauswahl.js` (`node tests/test-reihe-vorauswahl.js`).

> **Ein offenes Ende.** Der Auswahl-Block erscheint nur bei **festen Gruppen**;
> im Jahrgang 2027 sind das 3 von 32 Reihen. Eine Reihe mit „nur komplett
> buchbar" **ohne** Durchgänge trägt das Etikett weiterhin ohne einen Weg, es
> einzulösen — und dort erscheint auch der Störer nicht, wenn eine Regel
> *Keine Anmeldung* sagt, weil es keinen Gruppen-Fuß gibt, in dem er stünde.

#### Der einzelne Teil führt zur Reihe, nicht zur Anmeldung (seit 1.125.0)

Bis 1.124.0 galt „nur komplett" ausschließlich für den Weg über die Reihenseite.
Ein Termin dieser Reihe war auf **seiner eigenen Detailseite weiterhin einzeln
buchbar** — mit dem gewohnten Buchen-Button, dem Anmeldeformular dahinter und
allem, was daran hängt.

Das war der wahrscheinlichste Weg auf diese Seite und zugleich der einzige, auf
dem der Haken nichts galt: Über die Suche kommt man nicht auf der Reihenseite an,
sondern auf einem Teil. Wer dort buchte, meldete sich zu einem Baustein an, den
es einzeln gar nicht gibt — und niemandem fiel es auf, weil die Seite völlig
normal aussah.

**Seit 1.125.0 tritt in der Box „Seminardetails" der Weg zur Reihe an die Stelle
des Buchen-Buttons:**

> Dieses Seminar ist **Teil 2** der Ausbildungsreihe „Aufgaben der VK-Leitung".
> Sie lässt sich nur vollständig buchen – alle Teile werden auf der Reihenseite
> in einem Zug angemeldet.
>
> **[ Zur Ausbildungsreihe ]**

Was dazugehört:

- **Kein Ausweichweg daneben.** Weder das Anmeldeformular noch die
  Geschäftsstellen-Anfrage erscheinen. Beide führten zu einer Anmeldung für den
  einzelnen Teil — genau das, was der Haken ausschließt.
- **Auch bei einem ausgebuchten Termin.** Der Hinweis „Dieser Termin ist
  ausgebucht" bleibt stehen, der Verweis auf die Reihe steht darunter: Dort
  stehen die übrigen Gruppen, in denen noch Plätze frei sein können.
- **Die Trefferliste verspricht nichts mehr.** Der Weiter-Link heißt dort
  „Details" statt „Details und Anmeldung", und in „Weitere Termine zu diesem
  Seminar" heißt der Knopf „Zum Termin" statt „Jetzt buchen".
- **Die Adresse ist ebenfalls dicht.** `?seminar=ID` von Hand getippt zeigt den
  Hinweis samt Link zur Reihe statt des Formulars, und ein abgesetztes POST wird
  abgewiesen. Bei einer Reihenanmeldung greift dieser Riegel nicht — dort hat
  `auswahl_pruefen()` die Vollzähligkeit schon geprüft.
- **Eine Reihe im Entwurf ändert nichts.** Auf eine unveröffentlichte
  Reihenseite lässt sich niemand verweisen; ein weggenommener Button ohne Ziel
  wäre eine Sackgasse. Dann bleibt es beim gewohnten Weg — dieselbe Regel wie
  beim Badge „Teil 2 der Reihe …" über dem Titel.

Geprüft in `tests/test-reihen-komplett.php`
(`php tests/test-reihen-komplett.php`).

Rechts stehen die Angaben, die für alle Teile gelten: Zielgruppe,
Voraussetzungen, Freistellung, Seminarleitung, weitere Informationen und ein
Hinweis zu den Kosten.

> **Keine Kontaktbox.** Bis 1.88.0 stand hier „Fragen zur Reihe" mit der
> Ansprechperson eines Termins — ersatzweise, weil die Reihe keine eigene hat.
> Dahinter steht aber kein Prozess: Wer eine Reihe verantwortet, ist nirgends
> erfasst, und wer die einzelnen Termine betreut, rechnet nicht mit Fragen zur
> Abfolge. Eine Adresse anzubieten, an der niemand zuständig ist, schickt Leute
> ins Leere; lieber kein Angebot als ein falsches. Der Weg zur Anmeldung steht im
> Fuß jeder Gruppe. Kommt ein solcher Prozess, gehört er an diese Stelle.

### Sammelanmeldung: eine Reihe in einem Zug

**Die Freistellung entscheidet, welcher Weg gilt** — und gefragt wird in dieser
Reihenfolge:

#### 1. Die Regeln aus den Einstellungen — auch für die Reihe (seit 1.95.0)

Die Regeln unter *Einstellungen → Anmeldung & Regeln* gelten **auch für
Ausbildungsreihen**. Verglichen wird dann das eigene Feld **Freistellung** der
Reihe. Der Grund ist schlicht: „§ 37,6 BetrVG → Direktanmeldung" ist eine
Aussage über eine Freistellung, nicht über einen Beitragstyp. Sie gilt für vier
Seminare hintereinander genauso wie für eines allein. Trifft eine Regel zu,
entscheidet sie — einschließlich *Keine Anmeldung*, dann steht auf der
Reihenseite der Störer statt eines Buttons.

An einer Reihe wirken nur **zwei** der acht Regelfelder: *Freistellung* und *Auf
der Website anzeigen*. Themenfeld, Zielgruppe und Bildungszentrum sind
Taxonomien der Seminare, für die die Reihe gar nicht registriert ist; die Haken
*Anmeldung möglich* und *Ausgebucht* gibt es an einer Abfolge nicht, und eine
Seminarform hat sie auch nicht.

> **Warum das ausdrücklich dasteht und nicht einfach „passt halt nicht".** Bei
> den Haken ist „leer" ein *ansprechbarer* Wert. Eine Regel „Haken ‚Anmeldung
> möglich' = leer → keine Anmeldung" träfe sonst auf **jede** Reihe zu, weil das
> Meta an ihr nie gesetzt wurde — über Nacht wären alle Reihen unbuchbar, ohne
> dass jemand etwas geändert hätte. Und `form_names()` hält alles für Präsenz,
> was nicht Online ist, also auch eine Reihe. Die Liste steht deshalb an einer
> Stelle: `BI_Settings::reihen_felder()`.

Eine Regel kann einem Termin allerdings **nichts erlauben, was er allein nicht
darf**: Ausgebucht, „Anmeldung möglich = nein" oder eine Regel, die das Seminar
selbst auf die Geschäftsstelle schickt, bleiben liegen. Die Reihen-Regel ersetzt
nur die Freistellungs-Prüfung, nicht den Boden darunter.

#### 2. Kein Teil ohne Anmeldung — sonst ist die Gruppe zu

Vor allem anderen steht seit 1.95.1 eine Frage an die **Termine**: Gibt es einen
Teil, für den **kein einziger** Termin eine Anmeldung vorsieht (Variante 3)? Dann
ist die ganze Gruppe zu — auch der Umweg über die Geschäftsstelle. Es ist dieselbe
Logik wie beim GS-Weg, eine Stufe strenger: Sobald ein Teil nicht geht, geht die
Gruppe nicht.

> **Warum das ein Fehler war.** Der Gruppenfuß kannte bis dahin nur zwei Ausgänge:
> Sammelanmeldung — oder eben nicht, und „eben nicht" hieß **immer**
> Geschäftsstelle. Der Grund wurde nie gefragt. Auf einer Reihe, deren Termine
> alle den Haken *Anmeldung möglich* nicht tragen — der Normalfall, solange ein
> Jahrgang noch nicht zur Buchung geöffnet ist —, stand deshalb die
> GS-Anfrage: Man konnte eine Mail über Seminare schreiben, für die es gar keine
> Anmeldung gibt. Die Regel sagte Variante 3, die Seite bot Variante 2 an.

Diese Frage steht **vor** der Regel der Reihe: Eine Reihe kann einem Seminar
nichts erlauben, was es allein nicht darf. Ausgebucht ist etwas anderes (den Weg
gäbe es, den Platz nicht), und ein Teil ganz ohne Termine auch („Termine in
Abstimmung") — beides schließt die Gruppe nicht.

#### 3. Trifft keine Regel zu: die Freistellungen der Termine

Ist das Freistellungsfeld der Reihe leer oder passt keine Regel, bleibt alles
wie bisher:

| Freistellung aller Teile | Weg |
|---|---|
| § 37,6 BetrVG bzw. § 179,4 SGB IX | **Sammelanmeldung** – ein Formular für die ganze Reihe |
| Bildungsurlaub, § 37,7 BetrVG, „keine Freistellung", keine Angabe | **Geschäftsstellen-Anfrage per E-Mail** |

Bewusst *nicht* der Normalfall der Seminare: Dort gilt ohne Regel
*Direktanmeldung*. Eine Reihe ohne Freistellungsangabe still direkt buchbar zu
machen wäre die falsche Richtung — lieber ein Mensch dazwischen als eine Zusage,
die niemand einlösen kann.

Geprüft in `tests/test-reihen-regeln.php` (welche Regel trifft) und
`tests/test-reihen-anmeldung.php` (was daraus folgt).

Der Grund steckt im Verfahren: Bei § 37,6 BetrVG und seinem SBV-Gegenstück
§ 179,4 SGB IX beschließt das Gremium und der Arbeitgeber trägt die Kosten –
dafür braucht es niemanden dazwischen. Bei Bildungsurlaub, § 37,7 und ohne
Freistellung hängt die Teilnahme an der Zustimmung des Arbeitgebers, und die
klärt die Geschäftsstelle.

**Sobald ein einziger Teil** die Geschäftsstelle braucht, geht die **ganze**
Gruppe diesen Weg. Eine Reihe halb im Formular und halb per Mail anzumelden wäre
für die anmeldende Person nicht mehr zu überblicken.

Ein Termin **ohne** Freistellungsangabe gilt als nicht direkt buchbar. Das ist
die vorsichtige Richtung: lieber ein Mensch dazwischen als eine Zusage, die
niemand einlösen kann.

> Welche Freistellungen diesen zweiten Weg erlauben, steht unter *Einstellungen →
> Anmeldung → Ausbildungsreihen* (eine je Zeile, Standard § 37,6 BetrVG und
> § 179,4 SGB IX). Verglichen wird nachsichtig: `§ 37,6 BetrVG`,
> `§ 37 Abs. 6 BetrVG`, `§ 37(6) BetrVG` und `§37.6 BetrVG` gelten als dasselbe –
> § 37,6 und § 37,7 bleiben aber unterscheidbar. Eine leere Liste schaltet die
> Sammelanmeldung überall ab.
>
> **Bis 1.95.0 galt das nur hier, nicht im Regelwerk.** Dort fielen lediglich
> Punkt, Komma und Leerraum weg — die Klammerschreibweise `§ 37(6) BetrVG` traf
> die Regel „enthält 37,6" also **nicht**, obwohl die Einstellungsseite das
> Gegenteil verspricht. Seit 1.95.1 normalisieren beide gleich
> (`BI_Settings::norm()`, an das `BI_Reihen::frei_schluessel()` delegiert):
> kleingeschrieben, ohne `§`/`Abs.`/`Nr.` und ohne jedes Zeichen, das kein
> Buchstabe und keine Ziffer ist. Umlaute bleiben — sonst müssten Regelwert und
> Begriff den Verlust zufällig gleich treffen.

#### Weg 1: Sammelanmeldung

Sind alle Teile einer Gruppe gewählt, führt der Button ins Anmeldeformular –
mit der ganzen Auswahl im Gepäck:

```text
/anmeldung/?reihe=42&termine=1201,1204,1209,1215
```

Das Formular wird **einmal** ausgefüllt. Beim Absenden entsteht daraus **eine
Anmeldung je Teil**.

#### Warum eine Zeile je Teil

Die naheliegende Alternative – eine Zeile mit einer Liste von Seminaren – wäre
an jeder Auswertung gescheitert: Mail-Platzhalter, PDF-Anhang,
Geschäftsstellen-Zuordnung, Export und Kampagnen-Auswertung gehen alle von genau
einem Seminar je Anmeldung aus. Fachlich stimmt die Zerlegung ohnehin besser:
Teil 1 in Lohr und Teil 2 in Berlin sind zwei Buchungen in zwei
Bildungszentren, die auch getrennt bestätigt und storniert werden.

Zusammengehalten werden die Zeilen über vier neue Spalten in
`wp_bi_anmeldungen` (Schema-Version 6, wird beim Update automatisch angelegt):

| Spalte | Inhalt |
|---|---|
| `sammel_id` | id der ersten Zeile – die Klammer über alle Teile einer Anmeldung |
| `reihe_id` | Post-ID der Ausbildungsreihe |
| `durchgang` | feste Gruppe (1, 2, 3 …) |
| `teil` | Teilnummer, zugleich die Reihenfolge |

In der Anmeldungsliste tragen solche Zeilen einen roten Vermerk („Reihe: … ·
Gruppe 1 · Teil 2"); die Detailansicht listet die Geschwisterzeilen mit
Sprungmarken. Ohne diesen Hinweis sähen vier Zeilen derselben Person wie vier
Einzelanmeldungen aus. Der CSV-Export führt dieselben Angaben als Spalten
*Sammel-ID*, *Ausbildungsreihe*, *Gruppe* und *Teil* – nach der Sammel-ID
sortiert stehen die Teile beieinander.

Für die **Kampagnen-Auswertung** zählt eine Sammelanmeldung als **ein** Erfolg,
nicht als vier: Die Frage lautet „wie viele Menschen hat dieser Link zur
Anmeldung gebracht?", und eine Person ist eine Antwort. Die Kampagne wird
trotzdem in jede Zeile kopiert, damit auch die einzelnen Teile ihr zuzuordnen
bleiben.

#### Mails: eine je Empfänger, nicht eine je Teil

Liefe der Versand je Zeile, bekäme die angemeldete Person vier Bestätigungen
für eine Anmeldung und die Geschäftsstelle vier gleiche Meldungen – die
Zerlegung, die intern richtig ist, schlüge nach außen als Wiederholung durch.

`BI_Mailer::dispatch_reihe()` ermittelt deshalb je Benachrichtigung den
Empfänger **pro Teil** – er kann sich unterscheiden – und bündelt dann nach
Adresse. Jede Adresse bekommt eine Mail mit genau den Teilen, die sie etwas
angehen:

| Empfänger | Ergebnis bei einer vierteiligen Reihe |
|---|---|
| Teilnehmer*in | **eine** Mail mit allen vier Teilen |
| Geschäftsstelle (aus der Betriebs-PLZ) | **eine** Mail mit allen vier Teilen |
| Bildungszentrum Lohr (2 Teile dort) | eine Mail mit **seinen zwei** Teilen |
| Ansprechperson je Seminar | je eine Mail mit ihrem Teil |

Umfasst eine Mail mehrere Teile, stehen die `{seminar_*}`-Platzhalter für die
Reihe statt für ein einzelnes Seminar: `{seminar_titel}` wird der Reihentitel,
`{seminar_nummer}` die Liste der Nummern, `{seminar_startdatum}` der früheste
Start. Angaben, die nur für ein einzelnes Seminar gelten – Anreise, Uhrzeiten,
Themen, Kosten –, bleiben leer statt eine Behauptung über alle Teile
aufzustellen. **Bei genau einem Teil ist das Ergebnis Zeichen für Zeichen
dasselbe wie bisher**; bestehende Vorlagen ändern ihr Verhalten also nur dort,
wo sie es müssen.

Neue Platzhalter:

| Platzhalter | Inhalt |
|---|---|
| `{termine}` | alle Termine dieser Mail als Liste (Teil, Titel, Zeitraum, Ort, Nummer) |
| `{reihe_titel}` | Titel der Ausbildungsreihe |
| `{reihe_gruppe}` | feste Gruppe, z. B. „Reihe 1" |
| `{reihe_teile}` | Anzahl der Teile in dieser Mail |
| `{teil}` | Nummer des Teils |

`{termine}` ist auch bei einer Einzelanmeldung gefüllt (dann mit der einen
Zeile) – eine Vorlage mit `{termine}` taugt damit für beides.

#### Was geprüft wird, bevor gespeichert wird

Die Auswahl entsteht im Browser und kommt als Liste von Post-IDs in der URL
zurück; wer sie von Hand tippt, kann dort alles hineinschreiben.
`BI_Reihen::auswahl_pruefen()` weist deshalb zurück:

- Termine, die nicht zu dieser Reihe gehören
- zwei Termine für denselben Teil
- Termine aus verschiedenen festen Gruppen
- ausgebuchte oder zurückgezogene Termine
- Termine ohne § 37,6-Freistellung – für sie gilt der Weg über die
  Geschäftsstelle, auch wenn jemand die URL von Hand zusammensetzt
- bei „nur komplett buchbar": eine unvollständige Auswahl
- Reihen, die nur Entwurf sind

Geprüft wird **zweimal**: beim Aufruf des Formulars und noch einmal beim
Absenden – dazwischen kann ein Termin ausgebucht worden sein. Jeder Fehlschlag
nennt den Grund im Klartext, damit im Formular nicht nur „ungültig" steht.
Die Fälle stehen als Test in `tests/test-reihen-anmeldung.php`.

Schlägt ein Insert mitten in der Sammelanmeldung fehl, werden die bereits
angelegten Zeilen wieder entfernt: Eine halb gebuchte Reihe wäre schlimmer als
gar keine, weil niemand ihr ansieht, dass sie unvollständig ist.

#### Weg 2: Geschäftsstellen-Anfrage per E-Mail

Braucht auch nur ein Teil die Geschäftsstelle, trägt der Fuß der Gruppe statt
des Buchen-Buttons **„Anfrage vorbereiten"**. Er klappt dasselbe Bauteil auf,
das die Seminar-Detailseite schon nutzt: PLZ eingeben → zuständige
Geschäftsstelle nachschlagen (`bi_plz_lookup`) → „Anfrage senden" öffnet das
Mailprogramm mit vorausgefülltem Text.

Der Unterschied zur Einzelanmeldung steckt nur im Text – er nennt die Reihe,
die feste Gruppe und **alle gewählten Termine**:

```text
Betreff: Anmeldung Ausbildungsreihe PowerPack für BRV – Reihe 1

Guten Tag,

hiermit möchte ich mich für folgende Ausbildungsreihe anmelden:

Ausbildungsreihe: PowerPack für Betriebsratsvorsitzende
Feste Gruppe: Reihe 1
Link: https://…/ausbildungsreihe/powerpack/

Gewählte Termine:
Teil 1: Rechtsgrundlagen …, 08.02.–10.02.2027, Bildungszentrum Lohr (L00027060)
Teil 2: Das Gremium leiten, 22.03.–24.03.2027, Bildungszentrum Lohr (L00027120)
…

Meine Daten:
Name: …
```

Zusammengesetzt wird der Text im Browser (`assets/js/reihe.js`), weil die
Terminauswahl dort entsteht; Kopf und Fuß kommen aus PHP, damit der deutsche
Text nicht im Skript verstreut liegt. Ändert jemand die Auswahl, **nachdem** die
Geschäftsstelle schon gefunden war – die letzte PLZ wird im Browser gemerkt –,
wird der fertige mailto-Link mitgezogen. Sonst verschickte er eine Auswahl, die
längst überholt ist.

Der Button bleibt gesperrt, solange nicht alle Teile gewählt sind; beim
Abwählen klappt das Feld wieder zu.

### Übersicht aller Reihen

`[bi_reihen]` zeigt alle **veröffentlichten** Reihen als Kacheln: Beitragsbild
mit rotem „N Teile"-Label, Titel, Textauszug und eine Fußzeile mit Gruppen,
frühestem Start und Orten. Entwürfe – also Reihen, die der Import angelegt hat,
für die aber noch niemand den Einleitungstext geschrieben hat – bleiben außen
vor.

```text
[bi_reihen overline="Bildungsprogramm 2027"]
[bi_reihen titel="" anzahl="3"]
```

Für dieselben Reihen **im Gewand der Marketing-Kacheln** – etwa neben anderen
Kacheln auf einer Startseite, oder als handverlesene Auswahl – gibt es
[`[bi_kachel_reihen]`](#mehrere-reihen-als-übersicht-bi_kachel_reihen). Der
Unterschied ist das Aussehen, nicht der Inhalt: `[bi_reihen]` ist die
Programmseite mit Überschrift und breiten Zeilen, `[bi_kachel_reihen]` das
Kachelgitter.

### Was das Grundmodell noch nicht kann

Nicht abgebildet sind **Varianten**: dieselbe Reihe einmal als drei einzeln
buchbare Teile und einmal als fünfteiliger Ausbildungsgang am Stück, mit je
eigener Teil-Struktur (im Programm 2027 genau einmal, S. 76/77). Solange beide
Varianten dieselben Teilnummern verwenden, laufen ihre Termine auf derselben
Reihenseite zusammen. Ein Ausweg ohne Modelländerung: die Varianten als zwei
Reihen mit unterschiedlichem Namen führen.

Feste Durchgänge sind dagegen abgebildet – siehe oben.

## Der Import-Lauf

Der eigentliche Import läuft nicht in einem Rutsch, sondern in Häppchen von
40 Zeilen über AJAX; die Seite zeigt einen Fortschrittsbalken, die Zeilenzahl und
laufend „x neu · y aktualisiert · z übersprungen".

Der Grund ist nicht Kosmetik: Über tausend Zeilen mit je einem `wp_insert_post`,
zwanzig Meta-Feldern, fünf Taxonomien und der Reihen-Zuordnung sprengen jedes
vernünftige Zeitlimit – und ein Browser, der minutenlang auf eine Antwort wartet,
sieht aus wie ein Absturz. So bleibt jeder einzelne Aufruf kurz.

Der Laufzustand (Dateizeiger, Zuordnung, Zähler) steht in einem Transient. Bricht
die Verbindung ab, setzt ein Neuladen der Seite dort fort, wo der Lauf stand –
die Byte-Position in der Datei ist mitgespeichert. Die Häppchengröße lässt sich
über den Filter `bi_import_haeppchen` ändern.

### Der Import blendet nichts aus

Der Haken **Anzeigen** kommt allein aus der gleichnamigen Spalte. Dass zu einem
Seminar keine Anmeldung möglich ist, nimmt es **nicht** von der Website —
solche Termine erscheinen [ausgegraut](#nicht-buchbare-termine-treten-zurück).
Wer nicht buchen kann, will trotzdem wissen, dass es das Seminar gibt: wann, wo,
zu welchen Kosten.

Festgehalten in `tests/test-import-flags.php`: Eine Zeile, die den Haken
„Anzeigen" beim Import setzt, nähme mit dem nächsten Lauf ganze Jahrgänge vom
Netz — und niemand sähe, woran es liegt.

### HTML-Codes in der Exportdatei

Die Exportdatei des Seminarverwaltungssystems maskiert Sonderzeichen als HTML:
Aus dem Gedankenstrich wird `&#8211;`, aus dem Und-Zeichen `&amp;`. In einem
**Titel oder Textfeld** ist das kein Zeichen, sondern Text — die Ausgabe schickt
ihn durch `esc_html()`, und auf der Detailseite stand dann wörtlich
„Übergang in den Ruhestand `&#8211;` Aufgaben des BR", ebenso in Mails und PDF.

Seit 1.77.0 löst der Import das auf (`BI_Import::entfessle()`), und zwar mit zwei
Grenzen:

- **Genau ein Durchgang.** `&amp;#8211;` meint den Text „`&#8211;`" und behält
  ihn. Wer bis zur Erschöpfung dekodiert, macht daraus einen Strich.
- **HTML-Felder bleiben unangetastet** (Beschreibung, „Themen im Seminar"). Dort
  ist die Maskierung richtig notiert und wird vom Browser ohnehin als
  Sonderzeichen gezeigt; ein Auflösen machte aus maskiertem Text echtes Markup.
  Diese Felder holen ihren Wert über `cell_roh()` statt über `cell()`.

Ein einzelnes „&" wie in „Arbeit & Gesundheit" ist kein Code und bleibt stehen.
Geprüft in `tests/test-import-entities.php`.

**Vor dem Update eingelesene Einträge** tragen den Code weiter mit sich. Dafür
steht unter *Datenpflege → Auswahl & Export* die Karte **„HTML-Codes in Titeln
und Textfeldern"**: Sie listet jede Fundstelle mit Vorher und Nachher und
schreibt sie auf Knopfdruck um — Titel, Klartext-Meta-Felder und
Taxonomie-Begriffe, in allen Seminaren und Reihen. Die Karte erscheint nur,
solange es etwas zu tun gibt.

## Kosten und Orte ab Programm 2027

Mit dem Jahrgang 2027 ändert sich die Datenlage an drei Stellen. Alle drei sind
rückwärtskompatibel: ältere Jahrgänge behalten ihr Verhalten unverändert.

### Aufgeschlüsselte Kosten

Statt einer Freitextzeile stehen sechs Posten in eigenen Feldern: **Seminarkosten**,
**Unterkunft**, **Verpflegung**, **Tagungspauschale**, **Kurbeitrag** und
**MwSt.** (als Betrag, nicht als Satz). Die **Gesamtkosten** sind ihre Summe und
werden bei der Ausgabe berechnet – sie werden bewusst *nicht* gespeichert, sonst
stünde nach der Korrektur eines Postens eine veraltete Summe daneben.

> **Die Verpflegung fehlte bis 1.117.0 ganz.** Ein Feld hieß „Übernachtung und
> Verpflegung" und versprach damit mehr, als drinstand: Der Betrag war immer
> nur der für die Unterkunft, die Verpflegung wurde nirgends erfasst. Die
> Beschriftung war also nicht bloß ungenau, sie war falsch – wer die
> Kostenaufstellung las, hielt das Essen für bezahlt. Seit 1.118.0 sind es zwei
> Felder.
>
> **Der Altbestand ist keine Summe.** Aus den vorhandenen Beträgen ist nichts
> herauszurechnen, wenn die Verpflegung danebentritt: Sie waren und bleiben
> reine Unterkunftsbeträge.
>
> Der **Schlüssel** des ersten bleibt `_bi_kosten_uev`, obwohl er jetzt
> „Unterkunft" heißt. Das ist keine Nachlässigkeit, sondern die Rücksicht auf
> den [Abgleich](#abgleich-mehrerer-websites): Drei Installationen tauschen
> ihre Seminare aus und werden nicht am selben Tag aktualisiert. Ein
> umbenannter Schlüssel wäre für die noch nicht aktualisierte Gegenstelle ein
> unbekanntes Feld – sie lieferte dafür nichts, und der Abgleich schriebe einen
> gepflegten Betrag mit Leere über. Ein *neuer* Schlüssel für einen *neuen*
> Posten (`_bi_kosten_verpflegung`) ist dagegen harmlos: Was die Gegenstelle
> nicht kennt, hat dort auch niemand gepflegt.
>
> Der vorhandene Betrag bleibt also stehen, wo er steht, und heißt ab sofort so,
> wie er immer gemeint war: Unterkunft.
>
> **Die Verpflegung bleibt vorerst leer, und das ist beabsichtigt.** Für die
> laufenden Jahrgänge gibt es die Zahlen nicht – sie wurden nie erfasst, es ist
> also nichts nachzutragen. Ab dem Programm 2028 bringt die Exportdatei eine
> eigene Spalte `Verpflegung` mit, die der Seminar-Import von allein auf das
> Feld legt (siehe die Spalten-Aliasse in `BI_Import::guess_mapping`). Bis
> dahin zeigt die Kostenaufstellung den Posten schlicht nicht an – leere Posten
> fallen heraus, ein nicht gepflegter Betrag taucht nie als „0,00 €" auf.
>
> Zwischen 1.118.0 und 1.119.0 gab es dafür einen einmaligen CSV-Nachtrag unter
> *Einstellungen → Verpflegung nachtragen*. Er wird nicht mehr gebraucht und ist
> wieder ausgebaut; das Feld und seine Anzeige bleiben.

Auf der Detailseite, im PDF-Anhang und über die Mail-Platzhalter
`{seminar_kostenaufstellung}` und `{seminar_gesamtkosten}` erscheinen nur die
tatsächlich gepflegten Posten; ein nicht erhobener Kurbeitrag taucht nicht als
„0,00 €" auf. Sind Posten gepflegt, wird das Freitextfeld zusätzlich als
„Hinweis zu den Kosten" ausgegeben; es trägt Angaben wie „Kostenübernahme durch
den Arbeitgeber", die keine Zahl sind.

### Die Jahrgänge davor: Kosten aus dem Freitext lesen

Bis 2026 steckt alles in **einem** Freitextfeld. Damit die Detailseite dort
dieselbe Aufstellung zeigen kann, liest `BI_CPT::kosten_aus_text()` den Text.
Rund 70 Schreibweisen kommen vor, die sich auf drei Muster zurückführen lassen:

```text
Seminarkosten: 1.460,-- € (USt.-frei) Unterkunft: 592,-- € (zzgl. USt.)
Übernachtung 600,00 € zzgl Ust.Verpflegung 475,00 € zzgl Ust.Seminarkosten …
Kat: E5 TageÜbernachtung 600,- € zzgl 7% Ust.Verpflegung 475,- € …
```

Erkannt werden Seminarkosten, Seminargebühr, Übernachtung, Unterkunft,
Verpflegung, Tagungspauschale, Kurbeitrag und Kurtaxe. Der Steuerhinweis hinter
dem Betrag wird mitgelesen und steht klein neben dem Posten; ein Vorspann wie
„Kat: E5 Tage" bleibt als Resttext übrig.

> **Die Preiskategorie wird nicht angezeigt.** „Kat: D5 Tage" ist eine Angabe der
> internen Kalkulation – Buchstabe für die Kostenkategorie, Zahl für die
> Seminartage. Auf der Detailseite beantwortet sie keine Frage: Die Dauer steht
> im Kennzahlenband, der Preis in der Aufstellung darüber. Wer die Systematik
> nicht kennt, hält „D5" womöglich für einen Raum oder einen Tarif. Der Parser
> lässt sie bewusst stehen (er trennt Beträge von Text, ohne zu bewerten),
> weggelassen wird sie erst bei der Ausgabe durch `BI_CPT::ohne_kategorie()`.
> Erkannt werden „Kat: E5 Tage", „Kat. D" und „Kategorie A"; ein Hinweis daneben
> („Kostenübernahme durch den Arbeitgeber") bleibt stehen. Der Buchstabe muss für
> sich stehen, sonst risse „Kategorie Bildungsurlaub" das B heraus.

> **Im Zweifel nichts tun.** Ein Betrag ist eine Zusage. Bleibt ein Text
> unaufgeschlüsselt, steht er da wie bisher und niemand verliert etwas – wird
> dagegen aus `€ 6.542 (Seminargebühr Teil 1-3), zzgl. Unterkunft` ein Posten
> „Seminargebühr 1,00 €", steht eine falsche Zahl auf der Website und sieht aus
> wie eine gepflegte Angabe. Deshalb zwei harte Bedingungen: Der Betrag muss
> **direkt** hinter dem Begriff stehen, und er muss ein **Währungszeichen**
> tragen. „Seminarkostenpauschale € 660" gilt nicht als „Seminarkosten" – hinter
> jedem Begriff steht eine Wortgrenze.

Von den 673 Terminen mit Kostenangabe aus 2026 werden so **601 (89 %)**
aufgeschlüsselt. Der Rest bleibt Fließtext, und das zu Recht: „kostenfrei",
„siehe Teil 1", „5 Tage (E)" oder Paketpreise über mehrere Teile lassen sich
nicht in Posten zerlegen. `tests/test-kosten-freitext.php` prüft beide Seiten –
was erkannt werden muss und was unangetastet bleiben muss – anhand der echten
Werte aus den Quelldateien.

**Eine Summe wird hier nur gezeigt, wenn kein Posten „zzgl." trägt.** In diesen
Jahrgängen kommen Übernachtung und Verpflegung netto, die Steuer obendrauf; eine
„Gesamt"-Zeile stünde sonst für einen Betrag, den am Ende niemand zahlt. Ab 2027
ist die MwSt. ein eigener Posten, dort ist die Summe der Bruttobetrag und wird
gezeigt.

> Bisher gilt das nur für die **Detailseite**. PDF-Anhang und Mail-Platzhalter
> geben für die Jahrgänge bis 2026 weiterhin den rohen Freitext aus.

Der Feldtyp `money` nimmt die Schreibweisen entgegen, die real vorkommen:
`€ 1.083`, `1.083,50`, `1083,50`, `1083.50`. Gespeichert wird ein reiner
Dezimalwert, ausgegeben wird deutsch formatiert. Die einzige Stolperstelle ist
der einzelne Punkt: `1.083` ist der Tausenderpunkt aus dem Programmheft,
`1083.50` der Dezimalpunkt einer maschinell erzeugten Datei. Unterschieden wird
an der Stellenzahl hinter dem letzten Punkt – drei Stellen gelten als
Tausenderpunkt. Festgehalten ist das in `tests/test-kosten-2027.php`.

### Zuständiges Bildungszentrum und Seminarort

Bisher gab es eine Angabe für beides. Ab 2027 sind es zwei:

| | Wo | Wozu |
|---|---|---|
| **Zuständiges Bildungszentrum** | Taxonomie `bi_ort` (unverändert) | trägt die E-Mail-Adresse für den Mail-Trigger, ist Filter-Chip |
| **Seminarort** | Meta-Feld `_bi_seminarort` | wo das Seminar tatsächlich stattfindet, etwa ein Hotel |

Welcher Ort einem Menschen angezeigt wird, entscheidet `BI_CPT::ort_anzeige()` –
eine Stelle für Detailseite, Termintabellen, Ergebnisliste, Reihenseite und PDF:

1. der tatsächliche Veranstaltungsort aus `_bi_seminarort`,
2. sonst das zuständige Bildungszentrum,
3. **niemals „Andere"** – der Sammelbegriff ist eine Ordnungshilfe für den Filter
   und als Ortsangabe wertlos („Wo findet das Seminar statt?" – „Andere."). Dann
   lieber keine Angabe.

Der hinterlegte Ort gewinnt auch gegen ein echtes Bildungszentrum: Ein Seminar
des Bildungszentrums Sprockhövel kann in einem Hotel stattfinden, und dann steht
das Hotel da.

Beim Import wird der Originalname immer als Seminarort gesichert, sobald ein
Eintrag unter „Andere" wandert und das Feld für **diese Zeile** noch leer ist.
Maßgeblich ist die Zeile, nicht die Datei: Eine Datei mit Seminarort-Spalte, die
in einzelnen Zeilen leer bleibt, hätte sonst genau dort den Ort verloren.

Die Zusammenfassung unter „Andere" gilt nur für **Präsenz**-Seminare. Bei
Online-Seminaren führt dieselbe Taxonomie die Veranstalter\*in – die ist kein
Bildungszentrum und bleibt trotzdem unangetastet.

In der Bearbeiten-Maske führt das Auswahlfeld *Zuständiges Bildungszentrum*
deshalb auch nur Bildungszentren und „Andere" auf. Hotels und
Verlegenheitseinträge aus den alten Jahrgängen stehen zwar noch als Begriffe in
der Datenbank, aber nicht mehr zur Auswahl – sonst entstünde der Mischmasch bei
jeder Bearbeitung neu.

Eine Ausnahme mit Absicht: Ist an einem Seminar bereits ein solcher Begriff
gesetzt, **bleibt er sichtbar** und wird als „kein Bildungszentrum" gekennzeichnet.
Verschwände er aus der Liste, stünde in der Maske ein anderer Wert als in der
Datenbank, und das nächste Speichern würde ihn stillschweigend ersetzen.

Die Taxonomie soll eine kurze, geschlossene Liste bleiben, sonst taugt sie nicht
als Filter-Chip. Steht dort etwas, das kein Bildungszentrum ist, wird es unter
**„Andere"** geführt. Was als Bildungszentrum gilt, steht in
`BI_CPT::bildungszentren()` – kanonischer Name je Haus, dahinter die
Erkennungswörter – und ist über den Filter `bi_bildungszentren` anpassbar:

> Sprockhövel · Berlin · Beverungen · Bad Orb · Lohr · Schliersee ·
> Kritische Akademie Inzell

`BI_CPT::zentrum_fuer()` liefert zu einem beliebigen Ortsnamen den kanonischen
Namen seines Hauses. Damit lassen sich auch die Schreibweisen zusammenführen, die
über die Jahrgänge entstanden sind – siehe unten. Ein Haus darf mehrere
Erkennungswörter haben: „Inzell" und „Kritische Akademie" meinen dasselbe und
dürfen nicht zu zwei Zentren werden.

Bewusst eine Liste und keine Prüfung auf das Wort „Bildungszentrum": Die
**Kritische Akademie** ist eines, ohne es im Namen zu führen, und das *DGB
Tagungszentrum Hattingen* ist keines, obwohl es so klingt. Der Name trägt die
Information nicht.

Beim Import wandert ein Nicht-Bildungszentrum unter „Andere" – und sein
Originalname wird als Seminarort gesichert, falls die Datei keine eigene Spalte
dafür hat. Die Angabe „Moxy Hotel Bochum" ist ja richtig, sie gehört nur nicht
in den Filter der Bildungszentren.

Für den Bestand gibt es dieselbe Umstellung als einmalige Aktion unter
*Datenpflege → Orte aufräumen*. Sie zeigt vorher, welche Begriffe betroffen sind,
sichert deren Namen als Seminarort und entfernt anschließend die leergeräumten
Begriffe – die Filterleiste lädt ihre Begriffe mit `hide_empty=false`, ein
zurückgelassener Begriff stünde also weiter als Filter ohne Treffer in der Liste.
Diese Aktion wirkt bewusst auf alle Seminare statt auf die Arbeitsmenge: eine
halb aufgeräumte Taxonomie wäre unübersichtlicher als eine gar nicht aufgeräumte.

### Reiter „Begriffe"

Die WordPress-eigenen Begriffsseiten liegen unter `edit-tags.php` und sind aus
dem Plugin heraus sonst nur über einen Link in der Bearbeiten-Maske eines
Seminars erreichbar. Für Datenpflege ist das der falsche Weg – man räumt
Begriffe nicht auf, indem man ein Seminar öffnet.

Der Reiter zeigt je Taxonomie alle Begriffe mit ihrer Trefferzahl, **getrennt
nach Präsenz und Online**. Diese Trennung ist der Punkt: Beide Seminarformen
teilen sich die Begriffe, und `$term->count` wirft sie zusammen – so entstand die
Verwirrung, dass ein Filter mehr versprach, als die Liste dann zeigte.

Drei Handgriffe, jeder mit eigenem Knopf:

| | Wirkung |
|---|---|
| **Namen speichern** | benennt Begriffe um. Reine Anzeige, keine Zuordnung wird berührt – harmlos und umkehrbar. |
| **Ausgewählte zusammenführen** | hängt die Seminare eines Begriffs auf ein Ziel um und entfernt ihn danach. Nicht umkehrbar, deshalb überall „nicht zusammenführen" voreingestellt. |
| **Unbenutzte Begriffe entfernen** | löscht alle ohne jede Zuordnung. |

Zusammenführen funktioniert auch bei Mehrfach-Taxonomien: Die übrigen Begriffe
eines Seminars bleiben stehen, nur der eine wird ersetzt. Trägt ein Seminar
Quelle und Ziel gleichzeitig, bleibt ein Begriff übrig statt zwei gleicher.

Für Titelform, Beschreibung und die E-Mail-Adresse der Bildungszentren verlinkt
der Reiter auf den vollständigen Begriffs-Editor von WordPress.

### Fehlende Bildungszentren nachtragen

Die dritte Aufräumhilfe repariert genau die Falle, vor der der Abschnitt oben
warnt. Bis 2026 trug die Importdatei nur eine Spalte *Seminarort*, und die
enthielt das Bildungszentrum. Ab 2027 gibt es beide Spalten getrennt. Wer eine
alte Datei nach der Umstellung erneut einliest, bekommt das Bildungszentrum
deshalb ins Feld *Seminarort* geschrieben – die Taxonomie bleibt leer, und die
Seminare fehlen im Filter und beim Mail-Trigger.

Die Karte zeigt, wie viele Präsenz-Seminare kein Bildungszentrum haben, obwohl
ihr Seminarort ausgefüllt ist, und was daraus würde:

- Benennt der Seminarort ein Bildungszentrum, wandert er in die Taxonomie und
  wird im Feld geleert – dort stünde sonst dieselbe Angabe ein zweites Mal, und
  die Detailseite zeigt das Bildungszentrum ohnehin, wenn kein abweichender Ort
  gepflegt ist.
- Benennt er etwas anderes, wird „Andere" gesetzt und der Ort bleibt stehen –
  dieselbe Regel wie beim Import.

Seminare ohne beides werden gezählt, aber nicht angefasst: Dort fehlt die Angabe
schlicht.

### Schreibweisen zusammenführen

Über die Jahrgänge sind für dieselben Häuser mehrere Schreibweisen entstanden –
im Bestand 2026 etwa fünf für Sprockhövel („Sprockhövel", „Bildungszentrum
Sprockhövel", „IG Metall-Bildungszentrum Sprockhövel", „…, Sprockhövel",
„IG Metall Bildungszentrum Sprockhövel und Bologna"). Im Filter stehen sie als
getrennte Einträge und zersplittern die Ergebnisse.

Zusammengeführt werden sie im Reiter **[Begriffe](#reiter-begriffe)** – dort für
jede Taxonomie, nicht nur für Bildungszentren.

> Bis 1.72.0 stand unter *Auswahl & Export* eine eigene Karte, die dasselbe tat;
> danach blieb dort ein Wegweiser stehen. Seit 1.92.0 ist auch der weg: Ein
> Hinweiskasten, der nur auf einen Reiter derselben Seite zeigt, ist keine
> Funktion, sondern Rauschen. Zwei Wege zum selben Ziel heißen ohnehin: Man
> pflegt an einer Stelle und wundert sich an der anderen.
>
> Mit ihr ist die **automatische Gruppierung** entfallen. Sie erkannte über
> `BI_CPT::zentrum_fuer()`, welche Schreibweisen zusammengehören, und schlug sie
> als Gruppe vor. Im Reiter *Begriffe* wählt man das Ziel je Begriff von Hand.
> Bei einem frisch importierten Jahrgang mit vielen Varianten ist das mehr
> Klickarbeit – dafür gibt es die Funktion nur noch einmal.

> **Beim Reimport älterer Dateien aufpassen.** Die Spaltenüberschrift
> „Seminarort" wird jetzt dem neuen Meta-Feld zugeordnet, denn so heißt sie in
> der 2027er Datei. In den Dateien bis 2026 stand unter derselben Überschrift
> das Bildungszentrum. Wer eine alte Datei erneut einliest, muss die Spalte in
> der Zuordnungsmaske von Hand auf *Bildungszentrum* zurückstellen – sonst
> verlieren die Seminare ihre Zuordnung und der Mail-Trigger läuft ins Leere.
> Die Maske zeigt den Vorschlag an, bevor irgendetwas geschrieben wird.

### Kinderbetreuung

Ein Ja/Nein-Feld; in der Importdatei markiert ein `x`, wo Kinderbetreuung
angeboten wird (leere Zelle = nein). Auf der Detailseite und im PDF steht die
Zeile nur, wenn Betreuung angeboten wird – die Abwesenheit ist keine Nachricht.

## Anmeldevarianten

Wie sich jemand zu einem Seminar anmeldet, entscheidet sich unter
*Einstellungen → Anmeldung & Regeln*. Es gibt **drei** Wege, und **alle drei
kommen aus den Regeln** — kein Weg ist fest verdrahtet:

| Variante | Auf der Detailseite | Wofür |
|---|---|---|
| **1 – Direktanmeldung** | Button „Jetzt buchen" → Anmeldeformular | Der Normalfall |
| **2 – Geschäftsstelle** | PLZ-Suche und vorausgefüllte Mail-Anfrage | Wenn der Arbeitgeber der Freistellung zustimmen muss |
| **3 – Keine Anmeldung möglich** | **Störer** statt Button, darunter der Hinweistext | Seminare, die über diese Website gar nicht gebucht werden |

Dieselben Regeln gelten seit 1.95.0 auch für **Ausbildungsreihen** — verglichen
wird dort das eigene Freistellungsfeld der Reihe. Was dabei anders ist und
warum, steht bei der
[Sammelanmeldung](#1-die-regeln-aus-den-einstellungen--auch-für-die-reihe-seit-1950).

### Variante 3: der Störer

Der Störer ist das runde Element des Design-Systems und die einzige Stelle, an
der das Corporate Design eine Rundung kennt. Zwei Texte lassen sich dafür
hinterlegen:

| Feld | Inhalt | Standard |
|---|---|---|
| **Text im Störer** | kurz, zwei bis drei Wörter | „Keine Anmeldung" |
| **Hinweistext** | der Satz darunter: warum – und wohin sonst | „Für dieses Seminar ist keine Anmeldung über die Website möglich." |

> **Warum ein Störer und kein ausgegrauter Button?** Ein Button, der nichts tut,
> sieht aus wie ein Fehler – man klickt ihn, wartet, klickt nochmal. Der Störer
> sagt, dass es so gemeint ist.

Die Variante wirkt überall gleich: `BI_CPT::is_bookable()` meldet `false`, das
Anmeldeformular weist den Aufruf mit dem Hinweistext ab, und eine
Ausbildungsreihe mit einem solchen Teil fällt auf die
[Geschäftsstellen-Anfrage](#weg-2-geschäftsstellen-anfrage-per-e-mail).

### Welche Variante gilt für welches Seminar?

Das entscheidet die **Regelliste** – sie ist die einzige Stelle, an der ein
Anmeldeweg festgelegt wird. Jede Regel liest ein Feld des Seminars:

| Regelfeld | Werte |
|---|---|
| Freistellung, Themenfeld, Zielgruppe, Bildungszentrum | ein **Teiltext** der Begriffe, z. B. `Bildungsurlaub` oder `37,6` |
| Haken **„Anmeldung möglich"** | `ja`, `nein`, `leer` |
| Haken **„Ausgebucht"** | `ja`, `nein`, `leer` |
| Haken **„Auf der Website anzeigen"** | `ja`, `nein`, `leer` |

```text
Wenn  Haken „Anmeldung möglich"  ist       „nein"              →   Keine Anmeldung möglich
Wenn  Freistellung               enthält   „Bildungsurlaub"    →   Anmeldung über die Geschäftsstelle
Wenn  Freistellung               enthält   „keine Freistellung"→   Keine Anmeldung möglich
```

Die Regeln werden von oben nach unten geprüft, die **erste zutreffende** gewinnt.
**Trifft keine zu, gilt der Normalfall Direktanmeldung.** Trägt eine gespeicherte
Regel eine Variante, die es nicht (mehr) gibt, fällt sie ebenfalls auf
*Direktanmeldung* zurück statt auf einen undefinierten Zustand – festgehalten in
`tests/test-anmeldevarianten.php`.

> **Die erste Regel ist schon da.** Eine frische Installation bringt
> `Haken „Anmeldung möglich" = nein → Keine Anmeldung möglich` mit. Damit wirkt
> der Haken so, wie ihn alle lesen – aber **sichtbar und änderbar in der Liste**
> statt fest verdrahtet im Code. Wer sie löscht, bekommt auf der
> Einstellungsseite eine Warnung mit der Zahl der Seminare, die dadurch buchbar
> würden.

**Die Haken sind Angaben zum Seminar, keine Anmeldewege.** Sie *können* den Weg
beeinflussen – über eine Regel, genau wie Freistellung oder Zielgruppe –, müssen
es aber nicht. „Ausgebucht" und „Anzeigen" wirken auch ohne jede Regel, nur eben
an anderer Stelle: auf die Darstellung und auf die Auffindbarkeit, siehe
[Die drei Haken am Seminar](#die-drei-haken-am-seminar).

### Reihenfolge: die Ausnahme gehört nach oben

Weil die erste zutreffende Regel gewinnt, kann eine Regel vollständig richtig
aussehen und trotzdem nie greifen. Aufgefallen ist das an
`Freistellung enthält 37,6 → Direktanmeldung` über
`Flag nein → keine Anmeldung`: Seminare mit beidem wurden buchbar, obwohl das
Feld am Seminar „nein" sagte. Aus den Regeltexten war das nicht zu sehen – sie
stehen auf verschiedenen Feldern, und ob sie sich überschneiden, hängt an den
Daten.

Die Tabelle zeigt deshalb unter jedem Wert, was die Regel **tatsächlich** tut,
gemessen an allen Seminaren (`BI_Settings::rule_stats()`):

| Anzeige | Bedeutung |
|---|---|
| „Entscheidet 412 von 500 zutreffenden Seminaren." | normal – die restlichen 88 hat eine Regel darüber abgefangen |
| „Bei 88 entscheidet Regel 1 zuerst." | Zusatz zur Zeile darüber, sobald es Überschneidungen gibt |
| **„Greift nie: Bei allen 88 zutreffenden Seminaren entscheidet Regel 1 zuerst."** | rot – die Regel muss höher |
| **„Trifft auf kein Seminar zu."** | rot – meist ein zusammengesetzter Wert wie `37,6 / 179,4`, der als **ein** Teiltext verglichen wird; je Wert eine eigene Zeile anlegen |

Dass eine Ausnahme oben der allgemeinen Regel darunter Seminare wegnimmt, ist der
Zweck der Reihenfolge und wird nicht als Fehler markiert. Verschoben wird mit den
Pfeilen in der ersten Spalte; sie sind gewöhnliche Absende-Schaltflächen und
speichern das Formular gleich mit (kein JavaScript). Ein Pfeil nennt die
Zeilennummer aus dem Formular, nicht die Position – nur so trifft er die gemeinte
Regel, wenn im selben Durchgang eine andere Zeile gelöscht wurde. Beides in
`tests/test-regel-reihenfolge.php`.

## Anmeldeformulare

Bis Version 1.89 gab es genau ein Anmeldeformular, und es stand im Code. Seit
1.90 gibt es beliebig viele – zusammengestellt aus **einem** Feld-Bestand, unter
*Anmeldeformulare* im Plugin-Menü.

> **Eine bestehende Installation merkt vom Update nichts.** Das mitgelieferte
> *Standardformular* ist Wort für Wort das alte: dieselben vier Seiten, dieselben
> zwanzig Felder in derselben Reihenfolge, dieselben Pflichtangaben. Festgehalten
> in `tests/test-formulare.php`, damit es das auch nach dem nächsten Umbau noch ist.

Die Trennung, um die sich alles dreht:

| | |
|---|---|
| **Feld-Bestand** | WELCHE Felder es überhaupt gibt – Schlüssel, Typ, Beschriftung |
| **Formular** | WELCHE davon abgefragt werden – auf welcher Seite, in welcher Reihenfolge, wie breit, ob Pflicht |

Ein Formular erfindet also keine Felder, es wählt aus. Dürfte es eigene anlegen,
gäbe es nach kurzer Zeit `betrieb`, `firma` und `arbeitgeber` nebeneinander: drei
Spalten im CSV-Export für eine Frage, drei Platzhalter in den Mailvorlagen und
keine Auswertung, die über alle Anmeldungen hinweg noch stimmt.

### Der Feld-Bestand

Zwei Klassen von Feldern, unterschieden allein durch das Präfix – dasselbe
Prinzip wie bei den [eigenen Datenfeldern](#eigene-datenfelder) der Seminare:

| | Schlüssel | Was geht |
|---|---|---|
| **Kernfelder** | ohne Präfix (`vorname`, `betrieb_plz` …) | Beschriftung, Platzhaltertext und Hinweis ändern. Nicht löschen. |
| **Eigene Felder** | `x_…` | anlegen, ändern, löschen |

Sieben Kernfelder füllen **eigene Tabellenspalten** und sind deshalb mehr als
eine Zeile im Formular:

| Feld | Spalte | Woran es hängt |
|---|---|---|
| `vorname`, `nachname`, `email` | `vorname`, `nachname`, `email` | **Lassen sich nicht entfernen.** Ohne sie gäbe es niemanden, an den die Bestätigung ginge |
| `betrieb_plz` | `plz` | `BI_PLZ::lookup()` → **die gesamte Geschäftsstellen-Benachrichtigung** |
| `telefon`, `betrieb`, `bemerkungen` | `telefon`, `betrieb`, `nachricht` | Anmeldeliste, Mailtexte |

Alle übrigen Felder – auch die eigenen – landen in der JSON-Spalte `data` der
Anmeldung. Ein neues Feld braucht also kein Datenbank-Update.

Ein eigenes Feld bekommt automatisch einen **Mail-Platzhalter**
`{anmeldung_<schlüssel>}`; `x_essen` wird zu `{anmeldung_essen}`. Kern-Platzhalter
werden dabei nie überschrieben. Fragt das benutzte Formular ein Feld nicht ab,
bleibt sein Platzhalter **leer**, statt stehen zu bleiben – dieselbe Mailvorlage
taugt damit für alle Formulare.

Als Typ stehen Text, mehrzeiliger Text, E-Mail, Telefon, PLZ, Datum,
Auswahlliste, Auswahl-Schaltflächen und Kästchen zur Verfügung. Nicht neu
anlegbar ist der Typ *Freistellung*: Dieses Feld holt seine Auswahl aus den
Freistellungen des jeweiligen Seminars und ergäbe zweimal keinen Sinn.

### Ein Formular zusammenstellen

Der Editor zeigt je Seite eine Tabelle der Felder. Alles ist eine gewöhnliche
Absende-Schaltfläche – auch die Pfeile –, und **jede von ihnen speichert den
ganzen Stand mit**. Wer eine Überschrift ändert und dann ein Feld verschiebt, hat
am Ende beides; JavaScript ist nirgends im Spiel.

| Was | Wie |
|---|---|
| Reihenfolge | ↑ ↓ in der letzten Spalte |
| **Feld auf eine andere Seite** | am oberen bzw. unteren Rand seiner Seite noch einen Schritt weiter – es wandert auf die Nachbarseite |
| Breite | ganze Zeile · zwei Drittel · halbe Zeile · ein Drittel |
| Pflicht | Kästchen je Feld (bei Vorname, Nachname, E-Mail steht dort „immer") |
| Seite entfernen | ihre Felder gehen **nicht** verloren, sie wandern auf die Nachbarseite |

**Die letzte Seite trägt immer den Abschluss:** die Zusammenfassung der
Eingaben, die Seminarangaben, die Einwilligung und den Absendeknopf – unabhängig
davon, wie sie heißt. Vorher hing das an einer Seite namens „Abschluss"; ein
Umbenennen hätte den Absendeknopf verschwinden lassen.

Über dem Editor steht, **was die Auswahl bedeutet** – kein Formular wird deshalb
abgelehnt, aber die Folge steht da:

> Ohne die **betriebliche PLZ** lässt sich keine Geschäftsstelle ermitteln: Die
> Benachrichtigung vom Typ „Geschäftsstelle" bleibt bei Anmeldungen über dieses
> Formular aus, und in der Anmeldeliste steht keine Zuständigkeit.

Das kann gewollt sein – ein reines Online-Seminar braucht keine Geschäftsstelle –,
still passieren darf es nicht.

### Zuordnung: welches Seminar bekommt welches Formular

Drei Stufen, die erste, die etwas sagt, gewinnt:

```text
1. Feld „Anmeldeformular" am Seminar      →  die Ausnahme von Hand
2. Regelliste, erste zutreffende Regel    →  der Normalfall
3. Standardformular                       →  alle übrigen
```

Die Regeln lesen dieselben Felder und vergleichen denselben **Teiltext** wie die
Regeln der [Anmeldevarianten](#anmeldevarianten) – dieselbe Vergleichslogik,
damit „enthält" nicht an zwei Stellen zweierlei heißt:

```text
Wenn  Seminarform    ist       „Online"          →  Kurzanmeldung
Wenn  Freistellung   enthält   „Bildungsurlaub"  →  Formular mit Arbeitgeberdaten
```

*Seminarform* ist dabei neu und steht beiden Regelwerken zur Verfügung; als Wert
zählt `Online` oder `Präsenz` (auch `Praesenz`). Wie bei den Anmeldevarianten
gilt: **Die Ausnahme gehört nach oben.**

Zeigt eine Regel auf ein gelöschtes Formular, wird sie übersprungen statt zu
blockieren; beim Löschen eines Formulars werden ihre Regeln und die
Zuweisungen an Seminaren gleich mit aufgeräumt.

**Bei einer Ausbildungsreihe entscheidet der erste Teil für alle.** Eine
Sammelanmeldung ist ein Vorgang mit einem Satz Angaben – für zwei verschiedene
Formulare in einem Durchgang gäbe es keine sinnvolle Reihenfolge.

Welches Formular tatsächlich benutzt wurde, wird **in der Anmeldung
mitgeschrieben**: Die Detailansicht gruppiert danach, und der CSV-Export hat
dafür eine eigene Spalte. Der Export führt außerdem den **ganzen Feld-Bestand**
als Spalten, nicht die Felder eines einzelnen Formulars – sonst wechselten die
Spalten je nachdem, welches Formular gerade Standard ist. Werte aus einem
zwischenzeitlich umgebauten Formular verschwinden nicht aus der Ansicht; sie
stehen unter *Weitere Angaben*.

## Die drei Haken am Seminar

In der Bearbeiten-Maske stehen unter **Teilnahme** drei Kästchen. Sie sehen
gleich aus, greifen aber an verschiedenen Stellen ein — und genau das wird leicht
verwechselt. Die Kurzfassung:

| Haken | Vorgabe | Wirkung | Wo |
|---|---|---|---|
| **Ausgebucht** | leer | *gesetzt:* **ausgegraut**, kein Anmeldelink | Detailseite, Terminlisten, Trefferliste |
| **Auf der Website anzeigen** | gesetzt | *entfernt:* **aus der Seminarsuche entfernt**, per Link weiter erreichbar | alle Listen; die Detailseite bleibt offen |
| **Anmeldung möglich** | gesetzt | *entfernt:* über die mitgelieferte **Regel** kein Buchen-Button, stattdessen der **Störer** | [Anmeldeweg](#anmeldevarianten) |

**Die Haken sind Angaben zum Seminar, keine Anmeldewege.** Den Weg bestimmen
allein die [Regeln](#welche-variante-gilt-für-welches-seminar) — dort stehen alle
drei Haken als Feld zur Verfügung und *können* den Weg beeinflussen, genau wie
Freistellung, Themenfeld, Zielgruppe oder Bildungszentrum. „Ausgebucht" und
„Anzeigen" wirken darüber hinaus auch ohne Regel: auf die Darstellung und auf die
Auffindbarkeit.

Die drei sind unabhängig voneinander und lassen sich kombinieren. Ein Seminar
kann angezeigt, ausgebucht und trotzdem grundsätzlich anmeldbar sein — dann sagt
die Zeile „Ausgebucht", und sobald der Haken fällt, ist der Button wieder da.

### Anmeldung möglich — Button oder Störer

Dieser Haken wirkt **nicht von sich aus**, sondern über die Regel, die eine
frische Installation mitbringt:

```text
Wenn  Haken „Anmeldung möglich"  ist  „nein"   →   Keine Anmeldung möglich
```

Mit ihr steht bei gesetztem Haken der rote Button **„Jetzt buchen"** und führt
ins Anmeldeformular; ohne Haken tritt an seine Stelle der **Störer**, das runde
Element des Design-Systems, mit dem Hinweistext darunter (beides unter
*Einstellungen → Anmeldung & Regeln* änderbar).

> **Kein ausgegrauter Button.** Ein Button, der nichts tut, sieht aus wie ein
> Fehler – man klickt ihn, wartet, klickt nochmal. Der Störer sagt, dass es so
> gemeint ist.

Das wirkt überall: `BI_CPT::is_bookable()` meldet `false`, in Terminlisten
entfällt der Button, und das Anmeldeformular weist einen direkten Aufruf mit dem
Hinweistext ab.

Drei Feinheiten:

- **`leer` ist nicht `nein`.** Ein Seminar, dessen Importdatei die Spalte nie
  hatte, trägt gar keinen Wert – die Regel auf `nein` greift dort nicht, das
  Seminar bleibt buchbar. Wer auch diese Fälle sperren will, legt eine zweite
  Regel auf `leer` an.
- **Der Weg lässt sich umwidmen.** Wer statt des Störers die Geschäftsstelle
  will, ändert in derselben Regel nur die Variante — der Haken bleibt, wie er ist.
- **Ohne die Regel passiert nichts.** Dann gilt der Normalfall Direktanmeldung;
  die Einstellungsseite warnt in diesem Fall mit der Zahl der betroffenen
  Seminare.

### Ausgebucht — sichtbar, aber ohne Weg

Der Termin ist voll. Er bleibt in allen Listen stehen und tritt zurück:
[ausgegraut, ohne Ampel, ohne Button](#nicht-buchbare-termine-treten-zurück),
mit „Ausgebucht" an der Stelle, wo sonst der Button steht. Wer nicht buchen kann,
will trotzdem wissen, dass es den Termin gibt.

Diesen Haken pflegt auch der
[Verfügbarkeits-Abgleich](#der-abgleich-setzt-den-haken-ausgebucht): „Warteliste
möglich" setzt ihn, „Verfügbar" und „Fast ausgebucht" nehmen ihn zurück. Wer ihn
von Hand setzt, muss wissen, dass der nächste Lauf ihn überschreibt, sofern die
Quelle den Termin kennt.

### Auf der Website anzeigen — aus der Suche, nicht aus der Welt

Nicht gesetzt verschwindet das Seminar aus **allen Listen**:

- der Trefferliste der Seminarsuche (samt Filterzahlen),
- „Weitere Termine zu diesem Seminar" auf den Detailseiten,
- den Terminen einer Ausbildungsreihe,
- der Seminarauswahl im Anmeldeformular.

**Seine Detailseite bleibt erreichbar.** Genau dafür ist der Haken da: Ein
Seminar lässt sich damit gezielt einer Zielgruppe anbieten — Link per Mail,
Rundschreiben oder von einer eigenen Seite aus —, ohne dass es im öffentlichen
Programm auftaucht. Anmelden kann sich über diesen Link jede\*r; der Haken ist
eine Frage der Auffindbarkeit, **kein Zugriffsschutz**.

Dasselbe gilt seit 1.82.0 für **Ausbildungsreihen**: Eine Reihe ohne diesen Haken
fehlt in der Übersicht `[bi_reihen]`, ihre Seite bleibt über den Link offen.

> **Nicht zum Aufräumen.** Ein Seminar, das gar nicht mehr angeboten wird, gehört
> nicht ausgeblendet, sondern **gelöscht** — in der Seminarliste im Backend über
> *In den Papierkorb verschieben*. Von dort ist es wiederherstellbar, bis der
> Papierkorb geleert wird. Ausgeblendete Seminare sammeln sich sonst über die
> Jahrgänge an: Sie tauchen in keiner Liste auf, stehen aber weiter im Bestand,
> in den Exporten und in den Zahlen der Datenpflege.

### Was in welcher Reihenfolge greift

Sind mehrere Haken gesetzt, entscheidet die Liste von oben:

1. **Nicht anzeigen** — das Seminar ist gar nicht erst in der Liste. Alles
   Weitere betrifft nur noch, wer den Link kennt.
2. **Nur komplett buchbar an der Reihe** — steht an der Reihe, nicht am Seminar,
   und geht allen Haken vor: An die Stelle des Buchungsbereichs tritt der Weg
   zur Reihe ([siehe oben](#der-einzelne-teil-führt-zur-reihe-nicht-zur-anmeldung-seit-11250)).
3. **Ausgebucht** — ausgegraut, kein Anmeldelink.
4. **Der Anmeldeweg aus den Regeln** — Buchen-Button, Geschäftsstellen-Anfrage
   oder Störer.

Die Punkte 1 und 3 sind Darstellung und Auffindbarkeit, Punkt 4 ist der Weg.
Deshalb liegt nur er in den Regeln — und deshalb kann ein ausgebuchtes
Seminar durchaus die Variante *Direktanmeldung* tragen: Sobald der Haken fällt,
ist der Button wieder da, ohne dass jemand eine Regel anfassen muss.

## Datenqualität

Ein Jahrgang bringt über tausend Seminare mit, und eine fehlende Angabe sieht man
dem einzelnen Eintrag nicht an – die Seite ist nur still schlechter. Auf der
**Übersicht** (*Bildungsprogramm → Übersicht*) steht deshalb unter den Kacheln
eine Bestandsaufnahme über **alle veröffentlichten Seminare**: was fehlt, wie
oft, was es bewirkt, und ein Knopf in die gefilterte Liste.

| Was geprüft wird | Wirkung | Im Batch? |
|---|---|---|
| Startdatum | Weder im Datumsfilter noch in der Ergebnisliste sichtbar, nicht buchbar | nein |
| Seminarnummer | Keine Verfügbarkeits-Ampel; dem Bildungszentrum fehlt die Zuordnung | nein |
| Beschreibung | Die Detailseite beginnt ohne Text | **ja, bis 50** |
| Themen im Seminar | Der Abschnitt entfällt; auf einer Reihenseite klappt der Teil leer auf | **ja, bis 50** |
| Ansprechpartner-E-Mail | Die Benachrichtigung findet keinen Empfänger | ja |
| Bildungszentrum | Ortszeile leer, Filter findet das Seminar nicht | ja |
| Themenfeld | Erscheint in keiner Themenauswahl | ja |
| Zielgruppe | Zielgruppenfilter übergeht es | ja |
| Freistellung | Gilt als nicht direkt buchbar – eine Ausbildungsreihe fällt ganz auf die [Geschäftsstellen-Anfrage](#weg-2-geschäftsstellen-anfrage-per-e-mail) | ja |
| Programmjahr | Keinem Jahrgang zuzuordnen, rutscht aus der gefilterten Suche | ja |

Geprüft werden **veröffentlichte** Seminare: Was im Entwurf fehlt, fällt
niemandem auf die Füße; was veröffentlicht ist, steht auf der Website. Über der
Tabelle schaltet ein Knopf zwischen *beiden Formen*, *nur Präsenz* und *nur
Online* um – die Links tragen die Auswahl mit.

Die Prozentangabe neben der Zahl ist der Anteil am geprüften Bestand: „12" bei
1.400 Seminaren ist ein Ausreißer, „340" ist ein Importproblem.

> Die **Hinweis-Box** darüber („Braucht Aufmerksamkeit") meldet nur noch, was
> *jetzt* etwas blockiert: aktiver Mail-Test-Modus, fehlender Cron-Termin,
> Bildungszentren ohne hinterlegte Adresse, Teams-Webinare ohne Anmeldelink.
> Die Zeile „veröffentlichte Seminare ohne Startdatum" ist dort entfallen – sie
> steht jetzt in dieser Tabelle. Zweimal dieselbe Zahl auf einer Seite ist keine
> doppelte Warnung, sondern eine, der man nicht mehr ansieht, wo sie herkommt.

### Den Seminartitel für viele Termine auf einmal setzen

WordPress bietet den Titel in der Massenbearbeitung bewusst **nicht** an:
Dreihundert Einträgen denselben Titel zu geben ist fast nie gewollt. Bei diesen
Seminaren ist es der Normalfall – ein Seminar hat zwanzig, dreißig, vierzig
Termine, und alle heißen gleich. Deshalb steht das Feld **Seminartitel** hier
doch in der Maske, im selben Block wie Beschreibung und Themen.

**Woran das hängt:** „Weitere Termine zu diesem Seminar" gruppiert über den
Titel (siehe `BI_Detail::weitere_termine`). Eine Schreibvariante trennt deshalb
Termine, die zusammengehören — aus *Betriebsrats* und *Betriebsrates* werden
zwei Seminare, und keines von beiden zeigt die Termine des anderen. Solche
Varianten für vierzig Termine einzeln zu berichtigen macht niemand.

Drei Absicherungen:

- **Dieselbe Mengengrenze** wie für die Fließtexte (50, siehe unten). Der Titel
  ist die Identität eines Eintrags; ihn für einen halben Jahrgang zu
  überschreiben wäre nicht rückgängig zu machen.
- **Kein Leeren.** Bei allen übrigen Feldern leert `--` den Wert – beim Titel
  gilt es als Titel. Ein Seminar ohne Titel wäre in der Liste „(kein Titel)", in
  der Suche unauffindbar und in der Terminegruppierung mit jedem anderen
  titellosen Eintrag verschmolzen.
- **Die Adresse bleibt.** Ein hier geänderter Titel lässt den Beitragsnamen
  unangetastet, bestehende Links bleiben gültig. WordPress berechnet `post_name`
  vor dem Filter, über den geschrieben wird, und nur für neue Beiträge aus dem
  Titel.

Zeilenumbrüche und doppelte Leerzeichen fallen dabei weg – ein Seminartitel ist
eine Zeile. Im Bestand stehen Titel mit einem echten Umbruch darin; die sehen in
der Liste aus wie zwei Einträge und trennen ebenfalls Termine, die
zusammengehören.

> **Einträge aus dem Abgleich holen sich ihren Titel zurück.** Wird ein Titel an
> einem Termin geändert, der über den [Abgleich](#abgleich-mehrerer-websites)
> hereinkam, steht er zwar sofort richtig da – beim nächsten Lauf schreibt die
> Quelle aber ihren eigenen wieder darüber. Die Massenbearbeitung sagt das
> hinterher und mit Zahl. Dauerhaft wird die Änderung nur, wenn sie in der
> Quell-Installation gemacht wird oder der Termin aus dem Abgleich gelöst ist.

### Fließtexte in der Massenbearbeitung: Grenze bei 50

**Beschreibung** und **Themen im Seminar** lassen sich massenhaft setzen – aber
nur für höchstens **50 markierte Seminare**. Für den **Seminartitel** gilt
dieselbe Grenze (siehe oben).

Ein Kurzwert wie eine E-Mail-Adresse gilt oft für ein ganzes Bildungszentrum;
dort ist „für alle setzen" der Normalfall. Ein Fließtext gilt für einen
Seminarinhalt. Ihn über den halben Bestand zu legen ist fast immer ein Versehen,
und rückgängig zu machen ist es nicht: Der vorherige Text ist dann weg. Die
Grenze macht die nützliche Menge möglich – die Termine eines Teils, ein
Durchgang, ein Bildungszentrum – und die schädliche unmöglich.

Durchgesetzt wird sie an drei Stellen:

- **Im Formular**: Sind mehr als 50 markiert, sind die Felder gesperrt und sagen
  warum. Sonst tippt jemand einen langen Text und erfährt erst nach dem
  Absenden, dass er nicht ankam.
- **Beim Speichern**: unabhängig vom Browser. Alle übrigen Felder der
  Massenbearbeitung werden dabei ganz normal gesetzt – nur Titel, Beschreibung
  und Themen entfallen.
- **Danach**: eine Meldung, die sagt, dass und warum die Texte nicht übernommen
  wurden. Stillschweigen wäre hier das Schlimmste.

Startdatum und Seminarnummer stehen weiterhin **gar nicht** in der Maske: Sie
sind je Termin verschieden, und was dort steht, wird irgendwann geklickt.

### Mehrfach-Taxonomien in der Massenbearbeitung

Freistellung und Zielgruppe fehlten dort lange, weil „hinzufügen oder ersetzen?"
mehrdeutig ist. Jetzt wird gefragt statt geraten: neben dem Begriff steht
*hinzufügen* / *ersetzen* / *entfernen*, voreingestellt **hinzufügen** – die
Antwort, die nichts wegnimmt. Einzelwert-Taxonomien (Bildungszentrum, Themenfeld,
Programm) kennen weiterhin nur das Ersetzen, auch wenn jemand ein `add` in die
Anfrage schreibt.

### Die Filter hinter den Links

| Parameter | Zeigt |
|---|---|
| `bi_missing_start`, `bi_missing_nummer`, `bi_missing_themen`, `bi_missing_text`, `bi_missing_ap`, `bi_missing_link` | Seminare, denen die jeweilige Angabe fehlt |
| `<taxonomie>=__ohne__` | Seminare ohne Begriff dieser Taxonomie |
| `bi_reihe=__mit__` / `bi_reihe=<ID>` | nur Termine einer Ausbildungsreihe bzw. genau einer |

Sie sind seit 1.66.0 **kombinierbar** – vorher schloss ein `elseif` sie
gegenseitig aus, „ohne Themen und aus einer Reihe" war damit nicht zu haben.
`bi_missing_text` läuft als Einzige über eine WHERE-Bedingung statt über eine
`meta_query`: Die Beschreibung steckt in `post_content`, nicht in einem
Meta-Feld. `tests/test-seminarliste.php` hält beides fest.

## Die Seminarliste im Backend

Präsenz- und Online-Seminare stehen **in einer Liste** unter *Bildungsprogramm →
Seminare*. Sie sind zwar zwei Beitragstypen, aber inhaltlich dasselbe: derselbe
Jahrgang, dieselben Taxonomien, dieselbe Arbeit. Zwei getrennte Menüpunkte
zwangen dazu, jede Suche zweimal zu machen.

Das erste Auswahlfeld über der Liste ist deshalb die **Seminarform** (*alle* /
*nur Präsenz* / *nur Online*). Es wirkt auf alles Weitere: Die Zahlen in den
übrigen Auswahlfeldern und die Status-Links über der Liste („Alle",
„Veröffentlicht", „Entwürfe") zählen genau das, was die Liste dann zeigt.
Online-Seminare tragen hinter dem Titel den Vermerk **— Online**, und neben
*Neues Seminar anlegen* steht ein zweiter Knopf *Neues Online-Seminar*.

Die alte Einzelliste `edit.php?post_type=bi_online` bleibt erreichbar – alte
Lesezeichen laufen also nicht ins Leere –, hat aber keinen Menüpunkt mehr.
Beim Bearbeiten eines Online-Seminars hebt das Menü *Seminare* hervor.

**Das Suchfeld *Seminare durchsuchen* findet auch die Seminarnummer** – neben
dem Titel, nicht statt seiner. Bis 1.94.0 galt das nur, solange die Liste auf
eine Seminarform eingegrenzt war: Im Normalfall (beide Formen) stand der
Beitragstyp der Abfrage als Liste statt als einzelner Wert, die Nummernsuche
fiel still aus, und wer eine Nummer eintippte, las „Keine Seminare gefunden" –
obwohl das Seminar zwei Zeilen tiefer stand. Anders als im Frontend gilt hier
keine Datumsgrenze: Auch vergangene, ausgeblendete und noch nicht
veröffentlichte Seminare sind über ihre Nummer auffindbar.

Massenbearbeitung über eine gemischte Auswahl bietet die Felder der
Präsenz-Seminare an; Online-Seminare übernehmen davon nur die Felder, die es bei
ihnen auch gibt. Für die reinen Online-Felder (Untertitel, Referent\*innen,
Webinar-Tool …) grenzt man vorher auf *nur Online* ein.

## Die Bearbeiten-Maske

Ein Seminar hat über zwanzig Angaben. Standardmäßig verteilt WordPress sie auf
zwei Orte: Meta-Felder in einen Kasten, Taxonomien in eigene Kästen daneben – ein
Unterschied, der aus der Technik stammt und den niemand beim Bearbeiten sehen
sollte. Deshalb steht in diesem Plugin alles in **einer** Maske, gegliedert in
Abschnitte:

| Abschnitt | Inhalt |
|---|---|
| Termin und Zeiten | Seminarnummer, Start, Ende, Anreise |
| Ort | Zuständiges Bildungszentrum, Seminarort |
| Zugang und Anmeldung | nur Online: Webinar-Tool, Anmelde- und Teilnahme-Link |
| Inhalt und Einordnung | Themenfeld, Zielgruppen, Themen im Seminar |
| Kosten | die fünf Posten, Freitext-Hinweis, laufend berechnete Summe |
| Teilnahme und Sichtbarkeit | Freistellungen, Plätze, Kinderbetreuung, die drei Schalter |
| Kontakt | Ansprechpartner und Adresse |
| Programm und Reihe | Programmjahr, Feld „Teil \| Reihe" |
| Weitere Angaben | eigene Felder aus der Datenpflege |

Leere Abschnitte entfallen, deshalb taugt dieselbe Gliederung für Präsenz- und
Online-Seminare. Einzelwert-Taxonomien erscheinen als Auswahlliste,
Mehrfachwerte als Kästchenliste; jedes Feld trägt seinen Abschnitt als `gruppe`
in der Definition, ein eigenes Feld ohne Angabe landet unter „Weitere Angaben".
Dass jeder Abschnittsname auch existiert, prüft `tests/test-felder.php` – ein
Tippfehler wäre lautlos, das Feld verschwände nicht, es rutschte nur ans Ende.

Zwei Kleinigkeiten, die den Alltag ausmachen: Bei den Kostenposten steht die
**Summe live** unter der Gruppe (berechnet, nicht gespeichert), und „Themen im
Seminar" hat einen kleinen Editor statt eines nackten Textfelds – es ist eine
Aufzählung, die niemand in HTML tippen können muss.

### Klassischer Editor

Die drei eigenen Beitragstypen nutzen bewusst den klassischen Editor. Der
Block-Editor zeigt Taxonomien als eigene Bereiche in der Seitenleiste, die sich
von PHP aus nicht entfernen lassen – die Angaben stünden also doppelt da. Zudem
ist keiner dieser Typen ein Ort für Block-Gestaltung: Die Beschreibung ist
Fließtext, alles andere sind Felder. Wer den Block-Editor doch will:

```php
add_filter( 'bi_klassischer_editor', '__return_false' );
```

## Eigene Datenfelder

*Bildungsprogramm → Datenpflege → Felder*. Es gibt zwei Klassen von Feldern, und
der Unterschied ist keine Bequemlichkeit, sondern eine Sicherung:

**Kernfelder** sind die in `BI_CPT::kernfelder()` und `BI_Online::meta_fields()`
deklarierten. Ihre Schlüssel stehen im übrigen Code – `_bi_startdatum` allein über
50-mal, in Datumsfilter, Verfügbarkeits-Ampel, „buchbar", Mail-Platzhaltern,
PDF-Anhang und Detailseite. Ein Löschen oder ein neuer Schlüssel würde dort nichts
sichtbar kaputtmachen, sondern **still ins Leere laufen**: Seminare verschwänden
aus dem Datumsfilter, die Ampel bliebe grau, Mails hätten Lücken. Deshalb liegen
Schlüssel und Typ fest, und löschen ist nicht möglich. Die **Beschriftung** ist
reine Anzeige und darf geändert werden.

**Eigene Felder** tragen das Präfix `_bix_` und gehören der Redaktion: frei
anlegbar, umbenennbar – auch der Schlüssel – und löschbar. Das Präfix ist die
ganze Unterscheidung; dadurch kann eine spätere Plugin-Version neue Kernfelder
einführen, ohne je mit einem selbst angelegten Feld zu kollidieren.

Ein neues Feld erscheint **von allein** in der Bearbeiten-Maske des Seminars, als
Zielspalte im CSV-Import sowie in Export und Filtern der Datenpflege – alle diese
Stellen lesen aus `BI_CPT::meta_fields()`, und dort hängt sich die Verwaltung ein.
Auf der Detailseite und als Mail-Platzhalter `{seminar_<schlüssel>}` steht es nur
mit dem jeweiligen Schalter. Ein Kern-Platzhalter wird dabei nie überschrieben.
Seit 1.107.0 ist ein neues Textfeld außerdem **von allein über die Volltextsuche
im Frontend auffindbar** (ausgenommen die Typen Ja/Nein, Datum, Uhrzeit, Zahl und
Betrag – deren Werte tragen keine Wörter). Ein Feld vom Typ *Text mit HTML*
landet dabei entkleidet im [Suchindex](#der-suchindex-seit-11090) statt roh in
der Abfrage.
Nicht möglich bleiben eigene Filter-**Chips** in der Suchleiste: filterbare
Facetten sind Taxonomien, keine Meta-Felder – ein Chip braucht Begriffe, keine
Freitextwerte.

### Umbenennen und Löschen

Beim **Schlüsselwechsel** wandern die gespeicherten Werte mit
(`UPDATE wp_postmeta SET meta_key = …`), die Zeilenzahl steht anschließend in der
Meldung. Ein Mail-Platzhalter in bestehenden Vorlagen heißt danach anders und muss
dort nachgezogen werden – das macht die Oberfläche nicht automatisch, weil sie die
Vorlagen sonst hinter dem Rücken ändern würde.

Beim **Löschen** bleiben die gespeicherten Werte standardmäßig in der Datenbank
stehen: Wer das Feld mit demselben Schlüssel wieder anlegt, hat sie zurück. Nur
mit ausdrücklichem Haken werden auch die Werte entfernt.

Eine geänderte **Beschriftung** eines Kernfelds wirkt auch auf die automatische
Spaltenzuordnung des CSV-Imports, denn die vergleicht die Kopfzeile mit genau
diesen Beschriftungen. Export und Import lesen dieselbe Quelle, der Rundlauf
bleibt also heil – nur eine von außen kommende Datei ordnet sich danach eventuell
anders zu. Die Oberfläche weist darauf hin, und
`tests/test-felder.php` hält den Fall fest.

## Datenpflege: Export und Umzug

*Bildungsprogramm → Datenpflege* arbeitet nach einem einzigen Prinzip: Erst wird
eine **Arbeitsmenge** gefiltert, dann wirkt jede Aktion auf genau diese Menge.
Vor dem Klick ist damit immer sichtbar, was betroffen ist – die Seite zeigt die
Trefferzahl und die ersten Einträge an.

Gefiltert wird nach Seminarform, Status, Freitext (Titel **und** Seminarnummer),
Startdatum-Zeitraum, allen Taxonomien sowie über zwei Sonderfilter, die für die
Pflege gedacht sind: *Feld ist leer* findet Lücken (etwa alle Seminare ohne
Ansprechpartner-Adresse), *Feld enthält* findet Altlasten und Schreibfehler.

### CSV

Eine Zeile je Eintrag, Semikolon als Trennzeichen, BOM für Excel. Die Kopfzeile
entspricht exakt den Feldnamen, die `BI_Import::guess_mapping()` kennt – wer die
Datei in einer anderen Installation unter *Einstellungen → Seminar-Import*
hochlädt, findet die Spaltenzuordnung deshalb bereits ausgefüllt vor. Zusammen
mit der Option *Duplikate* (Abgleich über die Seminarnummer) ist der Reimport
wiederholbar, ohne Einträge zu verdoppeln.

Ja/Nein-Felder stehen als `ja`/`nein` in der Datei, Auswahlfelder als Schlüssel,
Mehrfachbegriffe mit `|` getrennt. Ein CSV-Export braucht **eine** Seminarform:
Präsenz und Online haben unterschiedliche Feldsets.

> **Eine Grenze des CSV-Rückwegs.** Bei Mehrfach-Taxonomien (Zielgruppe,
> Freistellung) trennt der Import auch an „Komma + Leerzeichen", weil die
> Quelldaten mehrere Werte genau so in eine Zelle schreiben. Ein Begriff, der
> selbst ein `, ` enthält, zerfällt beim Reimport deshalb in mehrere – unabhängig
> davon, womit der Export trennt. Das lässt sich exportseitig nicht beheben, ohne
> den regulären Import zu brechen; die Oberfläche warnt stattdessen mit Nennung
> der betroffenen Begriffe und verweist auf das JSON-Paket. Festgehalten ist der
> Fall in `tests/test-datenpflege-export.php`.

### Seminare löschen

Nicht hier – in der WordPress-eigenen Seminarliste (*Bildungsprogramm →
Seminare*). Sie bringt Auswahlkästchen, Massenaktionen, Papierkorb, Suche und
Sortierung schon mit; ergänzt sind nur die Filter, die dem Datenmodell fehlen:
die fünf Taxonomien und ein Zeitraum über das **Startdatum**.

Die Zahl hinter jedem Begriff zählt genau das, was die Liste beim Klick zeigt:
denselben Beitragstyp, dieselben Post-Status. Nicht `$term->count` – der zählt
alle Beitragstypen, an denen die Taxonomie hängt, und nur veröffentlichte
Beiträge. Da Präsenz- und Online-Seminare sich die Begriffe teilen, stand dort
sonst eine Zahl, die auch Online-Seminare enthielt.

Ganz unten steht **„— ohne Angabe —"** mit der Zahl der Einträge, die zu dieser
Taxonomie gar keinen Begriff haben. Ohne diesen Eintrag ergeben die Zahlen im
Auswahlfeld nie die Gesamtzahl der Liste, und niemand kann sich erklären, wo der
Rest geblieben ist. Zugleich ist er der schnellste Weg zu den Lücken im Bestand.

Der Ablauf für einen Jahrgangswechsel:

1. In der Seminarliste filtern, etwa *Programm 2026* oder *Startdatum bis
   31.12.2026*.
2. Zum Sichern dieselbe Auswahl hier in der Datenpflege als **JSON-Paket**
   herunterladen – damit liest sich der Stand jederzeit wieder ein.
3. Oben rechts unter *Ansicht anpassen* die Anzahl pro Seite erhöhen, in der
   Titelzeile alle markieren, Massenaktion *„In den Papierkorb verschieben"*.

Bei aktivem Filter steht Schritt 3 auch als Hinweis über der Liste: Die
Massenaktion gilt immer nur für die aktuelle Seite, und ohne den Kniff mit
*Ansicht anpassen* blättert man sich durch Dutzende Seiten, ohne zu ahnen, dass
es in zwei Schritten ginge.

#### Zwei Grenzen bei großen Mengen

WordPress verschickt das Listenformular mit **GET**. Bei einer Massenaktion
wandert damit jede markierte ID in die Adresszeile – rund 18 Zeichen je Eintrag.
Ab etwa 450 Markierungen reißt das die Längengrenze des Servers
(*„Request-URI Too Long"*), und die Aktion bricht ab, bevor irgendetwas passiert
ist. Das Plugin schaltet das Formular deshalb beim Absenden auf **POST**, sobald
eine Massenaktion gewählt ist; ohne gewählte Aktion – also beim reinen Filtern –
bleibt es bei GET, damit die Filter in der Adresszeile stehen und verlinkbar
sind.

Die zweite Grenze ist die gefährlichere: PHP nimmt je Anfrage nur
`max_input_vars` Felder entgegen (üblich 1000) und verwirft den Rest **ohne
Fehlermeldung**. Eine Massenaktion darüber sähe erfolgreich aus und hätte doch
nur einen Teil erfasst. Deshalb prüft das Plugin vor dem Absenden die Zahl der
Markierungen gegen den Wert dieses Servers und bremst mit einer Meldung, statt
hinterher rätseln zu lassen. Wer regelmäßig mehr auf einmal braucht, hebt
`max_input_vars` in der PHP-Konfiguration an.

Anmeldungen bleiben in jedem Fall erhalten; sie hängen an der Seminar-ID und sind
Aufzeichnungen über Menschen, nicht über Seminare.

### Seminare massenhaft ändern

Dieselbe Liste, dieselben Filter, andere Massenaktion: **Bearbeiten**. WordPress
klappt dann einen Bereich auf, in dem Angaben für alle markierten Einträge
gesetzt werden – dort stehen die eigenen Felder mit dazu. Der gefragte Ablauf
lautet damit: nach *Bildungszentrum Sprockhövel* filtern, alle markieren,
*Bearbeiten*, die Ansprechpartner-Adresse eintragen, *Aktualisieren*.

- **Leer heißt unverändert.** Ohne diese Regel würde eine Massenbearbeitung, die
  nur eine Adresse setzen soll, alle übrigen Felder der markierten Einträge
  leeren.
- **Ein Feld leeren** statt setzen: `--` eintragen.
- Einzelwert-Taxonomien (Bildungszentrum, Themenfeld, Programm) lassen sich
  hier ebenfalls setzen. Mehrfachwerte nicht – bei Zielgruppen wäre
  „hinzufügen oder ersetzen?" mehrdeutig.

Angeboten werden nur Felder mit `'bulk' => true` in der Definition. Bewusst eine
Erlaubnis- und keine Verbotsliste: Startdatum, Seminarnummer oder „Teil | Reihe"
sind je Termin verschieden, und was in dieser Maske steht, wird irgendwann auch
geklickt. `tests/test-seminarliste.php` hält beide Seiten fest – die Felder, die
dort stehen müssen, und die, die dort nichts zu suchen haben.

### JSON-Paket

Für den 1:1-Umzug zwischen zwei Installationen dieses Plugins. Enthält alle
Meta-Felder (auch leere – das Paket ist ein Spiegel des Quellstands), die
tatsächlich verwendeten Taxonomie-Begriffe samt Slug, Beschreibung und der
Term-Meta `email` der Bildungszentren sowie die Adresse des Beitragsbilds.
Präsenz- und Online-Seminare dürfen gemeinsam im Paket liegen.

Das Ankreuzfeld **„Ausbildungsreihen mitnehmen"** (voreingestellt) legt zusätzlich
die Reihen ins Paket, zu denen die Termine der Auswahl gehören – mit
Einleitungstext, Auszug, Beitragsbild, Status und allen Reihen-Feldern. Ohne
Haken enthält das Paket nur die Seminare.

Beim Einlesen werden fehlende Begriffe **vorab** angelegt – `wp_set_object_terms()`
würde sie zwar auch erzeugen, dabei gingen aber Slug, Beschreibung und die
E-Mail-Adresse verloren, und ohne die läuft der Mail-Trigger „Bildungszentrum"
ins Leere. Eine bereits vorhandene Adresse wird nie überschrieben: die lokal
gepflegte ist aktueller als die aus dem Paket. Beitragsbilder holt der Import nur
auf ausdrücklichen Wunsch und nur dort, wo noch keines gesetzt ist – ein zweiter
Lauf desselben Pakets füllt die Mediathek sonst mit Kopien.

#### Ausbildungsreihen im Paket

Die Zuordnung eines Termins zu seiner Reihe steckt im Feld `Teil | Reihe` und
wandert deshalb ohnehin mit. Die **Reihe selbst** ist aber ein eigener Beitrag –
ohne sie entsteht in der Zielinstallation nur eine leere Hülle: Beim Einlesen
legt `BI_Reihen::zuordnen()` eine fehlende Reihe als **Entwurf mit dem bloßen
Namen** an, damit die Termine ihre Zuordnung behalten. Einleitungstext, Bild und
die sechs Reihen-Felder fehlen dann, und als Entwurf steht die Reihe weder in
`[bi_reihen]` noch öffentlich.

Mit dem Haken beim Export liegen sie mit im Paket. Beim Einlesen gilt:

| | |
|---|---|
| **Wiedererkannt am Namen** | derselbe Schlüssel, mit dem `Teil \| Reihe` seine Reihe findet. In diesem Datenmodell **ist** der Name die Identität der Reihe – eine ID wäre in der Zielinstallation eine andere |
| **Vorhandene werden überschrieben** | Text, Auszug, Status und alle Felder kommen aus dem Paket. Eine in der Zielinstallation überarbeitete Fassung geht dabei verloren |
| **Der Slug bleibt** | bei vorhandenen Reihen unverändert – er steckt in Links, die anderswo gesetzt sind. Nur eine neu angelegte Reihe übernimmt den Slug aus dem Paket |
| **Reihen zuerst** | sie werden vor den Seminaren geschrieben, sonst legte der erste Termin einen leeren Entwurf desselben Namens daneben |
| **Status** | die Auswahl der Import-Maske (*wie im Paket* / *alle als Entwurf* / *alle veröffentlichen*) gilt auch für Reihen |

Mitgenommen werden die Reihen der **Auswahl**: Wer nur ein Programmjahr
exportiert, bekommt genau die Reihen, zu denen dessen Termine gehören. Eine Reihe
ganz ohne Termine in der Auswahl ist im Paket nicht enthalten.

Festgehalten in `tests/test-paket-begriffe.php`.

#### Begriffe: was das Paket kann und was nicht

Das Paket überträgt die **Zuordnung** der Begriffe vollständig. Seit
Paketfassung 2 steht jede Taxonomie im Paket, **auch die leere** – der Import
setzt sie dann genau so, und die leere Liste löscht die bisherige Zuordnung.
Vorher fiel eine Taxonomie ohne Begriffe aus dem Paket heraus; ein in der Quelle
aufgeräumtes Seminar behielt in der Zielinstallation seine alten Begriffe, und
die ließen sich nicht einmal als „unbenutzt" entfernen, weil sie ja noch benutzt
waren. Ein Paket der Fassung 1 bleibt lesbar: Dort fehlt der Schlüssel, und die
Zuordnung bleibt wie bisher unberührt. Festgehalten in
`tests/test-paket-begriffe.php`.

**Der Import löscht keine Begriffe.** Er legt an, was fehlt, und ordnet zu, was
im Paket steht – aber ein Begriff, den es in der Quelle nicht mehr gibt, bleibt
in der Zielinstallation stehen. Deshalb ist die Begriffsliste nach dem Import oft
genauso lang wie vorher, obwohl in der Quelle längst aufgeräumt wurde. Zwei
Wege:

- Ankreuzfeld **„nach dem Import Begriffe entfernen, an denen kein Eintrag mehr
  hängt"** direkt in der Import-Maske. Es räumt über **alle** Taxonomien und
  betrifft auch Begriffe, die mit dem Paket nichts zu tun haben – deshalb ist es
  nicht vorausgewählt.
- Reiter **Begriffe** → *unbenutzte Begriffe entfernen*, je Taxonomie einzeln und
  mit den Zahlen daneben.

Ohne Haken nennt die Erfolgsmeldung die Zahl der verwaisten Begriffe, damit
niemand rätseln muss.

> **Ein Paket enthält immer nur die exportierte Seminarform.** Wer in der Quelle
> nur die Präsenz-Seminare exportiert, lässt in der Zielinstallation alle
> Online-Seminare unberührt – samt ihrer alten Begriffe, die damit weiter in
> Gebrauch sind und auch beim Aufräumen bleiben. Die Import-Maske weist darauf
> hin, wenn das Paket eine Form nicht enthält, die es hier gibt. Für den ganzen
> Bestand also **zwei Pakete** exportieren und beide einlesen.

Ob ein Import gewirkt hat, zeigt der Reiter *Begriffe*: Die Spalten **Präsenz**
und **Online** stehen je Begriff nebeneinander. Alte Begriffe mit `0 / 0` sind
nur noch Karteileichen – ein Fall fürs Aufräumen. Stehen dort noch Zahlen, hat
der Import die betreffenden Seminare nicht erreicht (andere Seminarform, oder
die Option *Duplikate* war aus, sodass alles doppelt angelegt wurde).

## Abgleich mehrerer Websites

Das Bildungsprogramm läuft an mehreren Orten: **bildung.igmetall.de** zeigt den
ganzen Bestand, **igmetall-sprockhoevel.de** und **igmetall-bildung-berlin.de**
je nur ihren eigenen. Gepflegt werden die Seminare dort, wo sie herkommen – und
genau dort entstand das Problem: Eine Korrektur in Sprockhövel blieb in
Sprockhövel.

Ein iframe wäre der schnelle Weg gewesen und war keiner. Die Satellitenseiten
sollen in ihrer Suche **wirklich nur ihre eigenen Seminare** finden; ein
eingebetteter zentraler Bestand ist entweder zu groß oder eine gefilterte
Ansicht, die sich beim ersten Filterklick der Besucher wieder öffnet. Jede
Website führt deshalb ihren Bestand selbst. Abgeglichen wird darunter.

### Die Rollen

Jede Installation hat **eine** Rolle, eingestellt unter
*Einstellungen → Abgleich*:

| Rolle | Wer | Was sie tut |
|---|---|---|
| **Quelle** | Sprockhövel, Berlin | gibt ihren Bestand über zwei geschützte Adressen heraus und meldet der Zentrale, wenn sich etwas geändert hat. Sie schreibt nie in eine andere Installation |
| **Zentrale** | bildung.igmetall.de | holt ab, schreibt, blendet Verschwundenes aus, führt Protokoll |
| **Aus** | alle übrigen | kein Abgleich; die Adressen sind nicht vorhanden |

### Warum Holen und nicht Schicken

Der naheliegende Weg wäre, die Quelle das geänderte Seminar beim Speichern an
die Zentrale schicken zu lassen. Das ist die fragilste aller Varianten: Ist die
Zentrale in dieser Sekunde nicht erreichbar – Wartung, Zeitüberschreitung, ein
hängender Datenbankserver –, ist die Änderung weg. Niemand merkt es, denn in der
Quelle sieht alles richtig aus.

Deshalb arbeitet der Abgleich zweistufig:

1. **Klopfen.** Die Quelle meldet beim Speichern nur, *dass* es Neues gibt –
   ein paar Bytes, kein Inhalt. Die Meldung ist um eine Minute verzögert, damit
   ein CSV-Import mit 500 Seminaren nicht 500 Meldungen auslöst.
2. **Holen.** Die Zentrale fragt daraufhin nach. Und unabhängig davon fragt sie
   ohnehin in ihrem eingestellten Takt (Voreinstellung: stündlich).

Damit ist der Abgleich **selbstheilend**: Er braucht kein einziges Ereignis, um
vollständig zu werden – die Ereignisse machen ihn nur schnell. Geht ein Klopfen
verloren, holt der nächste Takt alles nach.

### Einrichten in fünf Schritten

1. **In der Zentrale** (bildung.igmetall.de) *Einstellungen → Abgleich* öffnen,
   Rolle **Zentrale** wählen und in der Quellen-Tabelle eine Zeile ausfüllen:
   Bezeichnung (`Sprockhövel`), Adresse (`https://igmetall-sprockhoevel.de`) und
   einen **Schlüssel**. In der leeren Zeile steht bereits einer: *Kopieren*
   drücken und den Zwischenspeicher für Schritt 3 behalten. *Neu* erzeugt einen
   anderen, falls nötig.
2. **Speichern.** Die Spalte *Kennung* füllt sich; an ihr hängt von nun an jeder
   Herkunftsstempel in der Datenbank. **Die Kennung darf sich nie mehr ändern** –
   die Bezeichnung daneben schon.
3. **In der Quelle** (Sprockhövel) denselben Reiter öffnen, Rolle **Quelle**
   wählen und in der Zentralen-Tabelle die Adresse `https://bildung.igmetall.de`
   mit **demselben Schlüssel** eintragen. Zeichengleich; ein abgeschnittenes
   Leerzeichen genügt für ein `403`.
4. Bei *Herausgegeben werden* festlegen, welche Post-Status die Quelle hergibt
   (Voreinstellung: nur Veröffentlichte) und beim *Zeitfenster*, wie weit sie
   zurückschaut (Voreinstellung: was bevorsteht, mit 30 Tagen Karenz).
5. **Zurück in der Zentrale** auf *Jetzt abgleichen: Sprockhövel* klicken. Der
   Bericht steht darunter.

Für Berlin dasselbe mit einem **eigenen** Schlüssel.

### Der Erstlauf verdoppelt nichts

Die Zentrale hat die Seminare beider Quellen heute schon – per CSV importiert,
ohne Herkunftsstempel. Findet der Abgleich kein gestempeltes Gegenstück, sucht
er deshalb ein zweites Mal, nach der blanken **Seminarnummer**. Was er findet,
**übernimmt** er: Der vorhandene Eintrag bekommt den Stempel und wird von da an
abgeglichen. Im Bericht steht das als *übernommen*.

Ohne diesen zweiten Blick stünde nach dem ersten Lauf jedes Seminar doppelt.

**Der erste Lauf holt alles, was im Zeitfenster liegt.** In der Zentrale steht
noch keine Änderungsmarke, an der sich ablesen ließe, was neu ist – jedes
Seminar gilt deshalb als unbekannt. Ab dem **zweiten** Lauf vergleicht der
Abgleich die Marke der Quelle gegen die vermerkte (`_bi_sync_stand`) und holt
**nur, was sich dort wirklich geändert hat**. Eine Änderung an einem Seminar
bedeutet dann genau eine Zeile im Bericht, keinen Vollabgleich.

### Das Zeitfenster

Ein Seminar, dessen Start vorbei ist, erscheint in **keiner** Suche: Die
Anzeige-Regel dieses Plugins lautet überall `_bi_startdatum >= heute`
(`BI_CPT::bookable_clauses()`, `BI_Filter`). Es über die Leitung zu schicken
kostet Zeit und ändert an dem, was jemand zu sehen bekommt, nichts.

Die Quelle gibt deshalb nur heraus, was noch bevorsteht – voreingestellt mit
**30 Tagen Karenz**. Der erste Lauf schrumpft damit von „alles, was je
stattfand" auf „was noch kommt", und aus einem Kraftakt über viele Häppchen
wird eine Sache von Sekunden.

Die Karenz ist der Sicherheitsabstand: Quelle und Zentrale können in
verschiedenen Zeitzonen stehen (um 23:30 ist dort schon morgen), und ein gerade
vergangenes Seminar soll seine letzte Änderung noch mitbekommen. Abschalten
lässt sich das Fenster ganz – dann ist der Abgleich ein vollständiger Spiegel.

> **Seminare ohne Startdatum – oder mit einem krummen – wandern immer mit.**
> Ein Vergleich gegen leeren Text oder gegen `01.08.2026` ergibt lexikalisch
> „kleiner als der Stichtag"; das Seminar fiele aus dem Bestand, und der
> Aufräumschritt hielte es für gelöscht. Ein Datenfehler darf keine Einträge
> verschwinden lassen.

#### Warum die Quelle ihren Stichtag mitschickt

Das ist die heikelste Stelle des ganzen Moduls. Wenn die Quelle Vergangenes gar
nicht mehr **meldet**, fehlt es in ihrer Bestandsliste – nicht weil es gelöscht
wurde, sondern weil danach nie gefragt war. Ohne Gegenmaßnahme hielte der
[Aufräumschritt](#löschen-heißt-hier-ausblenden) es für verschwunden und
blendete es aus: Der erste Lauf mit eingeschaltetem Zeitfenster nähme den
**gesamten Altbestand der Zentrale** vom Netz.

Die Bestandsliste trägt deshalb den Stichtag mit sich, und der Aufräumschritt
beurteilt nur, was danach liegt. Maßgeblich ist dabei der Stichtag der
**Quelle**, nicht der eigene – beide Installationen können verschieden
eingestellt sein, und gültig ist, wonach tatsächlich gefragt wurde.

Ein Sonderfall, der sich von selbst richtig verhält: Verschiebt jemand in der
Quelle ein Seminar aus der Zukunft in die Vergangenheit, fällt es dort aus dem
Bestand. In der Zentrale steht noch das alte, künftige Datum – es wird also
beurteilt, gilt als verschwunden und wird ausgeblendet. Genau richtig: Es sollte
ohnehin nicht mehr erscheinen.

Festgehalten in `tests/test-sync-abgleich.php`, Abschnitte 14 bis 16.

### Woran ein Seminar wiedererkannt wird

An der **Seminarnummer**, wie überall in diesem Plugin. Post-IDs taugen nicht –
sie sind in jeder Installation andere.

Der Schlüssel ist aber nicht die Nummer allein, sondern **Quelle + Beitragstyp +
Nummer**. Ohne die Quelle im Schlüssel würden sich Berlin und Sprockhövel
gegenseitig überschreiben, sobald sie dieselbe Nummer vergeben – und dass zwei
unabhängig geführte Nummernkreise irgendwann kollidieren, ist keine Frage des
Ob. Trifft der Abgleich auf eine Nummer, die hier schon einer anderen Quelle
gehört, fasst er **nichts** an und schreibt es ins Protokoll: Welcher Eintrag
der richtige ist, kann nur ein Mensch entscheiden.

> **Ein Seminar ohne Seminarnummer wird nicht abgeglichen.** Es hat keine
> Identität, die den Sprung zwischen zwei Datenbanken überlebt; jeder Lauf
> legte es erneut an. Solche Einträge stehen namentlich im Protokoll.

### Schreibschutz in der Zentrale

Abgeglichene Seminare sind in der Zentrale **nur lesbar**. Das ist keine
Bevormundung, sondern die einzige ehrliche Konsequenz aus „die Quelle gewinnt":
Eine Korrektur, die der nächste Lauf ohne Nachfrage überschreibt, ist schlimmer
als eine, die gar nicht erst möglich ist – im ersten Fall glaubt jemand, sie sei
erledigt.

In der Seminarliste steht dafür die Spalte **Abgleich**: 🔒 mit dem Namen der
Quelle, verlinkt auf die Bearbeiten-Maske *dort*. Darunter, wenn vorhanden, die
Zahl der Anmeldungen, die in der Quelle liegen.

Wer einen Eintrag doch hier pflegen will, nimmt in der Zeile
*aus dem Abgleich lösen*. Er ist dann frei bearbeitbar und wird nicht mehr
angefasst – auch nicht wieder eingefangen. *wieder abgleichen* macht es
rückgängig; der nächste Lauf überschreibt ihn dann aus der Quelle.

Der Schreibschutz lässt sich in den Einstellungen ganz abschalten. Dann bleibt
nur ein Hinweis über der Bearbeiten-Maske – und die Gewissheit, dass jede
Änderung hier eine Halbwertszeit von höchstens einem Takt hat.

### Löschen heißt hier ausblenden

Verschwindet ein Seminar aus dem Bestand der Quelle – gelöscht, im Papierkorb
oder auf einen Status gesetzt, der nicht herausgegeben wird –, dann **bleibt der
Eintrag in der Zentrale stehen** und verliert nur seinen Platz in Suche und
Website (Haken *„Auf der Website anzeigen"* aus). In der Spalte *Abgleich* steht
er dann als **⚠ fehlt in …**.

Gelöscht wäre aufgeräumter und wäre falsch: An dem Eintrag hängen Anmeldungen,
auf ihn zeigen Links aus Newslettern, und ein Versehen in der Quelle (Papierkorb
statt Entwurf) wäre hier nicht mehr zurückzuholen. Taucht das Seminar in der
Quelle wieder auf, kommt es beim nächsten Lauf von selbst zurück.

Gemeldet wird ein Fehlen **einmal**. Sonst stünde in jedem Bericht dieselbe
Zahl, und niemand wüsste, ob gerade etwas Neues fehlt.

> **Ein ausgeblendetes Seminar wandert dagegen mit.** Wer in Sprockhövel nur den
> Haken *„Auf der Website anzeigen"* entfernt, das Seminar aber veröffentlicht
> lässt, überträgt den Haken – es ist in der Zentrale genauso ausgeblendet und
> gilt nicht als verschwunden.

### Was der Abgleich überträgt – und was nicht

**Mit dabei:** Präsenz- und Online-Seminare mit allen Feldern (auch leeren – das
Paket ist ein Spiegel des Quellstands), die benutzten Begriffe samt Slug,
Beschreibung und der Term-Meta `email` der Bildungszentren, auf Wunsch die
Ausbildungsreihen und die Beitragsbilder.

**Nicht dabei:** Anmeldungen, Anmeldeformulare, Mail-Trigger, PLZ-Tabelle,
Kampagnen, Einstellungen.

#### Anmeldungen liegen getrennt

Ist dasselbe Seminar auf zwei Seiten anmeldbar, gibt es **zwei Anmeldelisten und
zwei Platzzählungen**. Das ist so eingerichtet und hat eine Folge, die man
kennen muss: Die Zahl der freien Plätze in der Zentrale ist nicht die ganze
Wahrheit, und *Ausgebucht* kann auf beiden Seiten verschieden stehen.

Der Abgleich führt deshalb die Anmeldezahl der Quelle mit und zeigt sie in der
Seminarliste unter dem Herkunftsnamen an – damit niemand die hiesige Zahl für
den Gesamtstand hält.

#### Ausbildungsreihen

Wiedererkannt am **Namen** – derselbe Schlüssel, mit dem das Feld
`Teil | Reihe` am Seminar seine Reihe findet. Anders als beim Paket-Import wird
eine vorhandene Reihe hier **nicht** bedingungslos überschrieben: In der
Zentrale laufen mehrere Quellen zusammen, und eine Reihe gleichen Namens aus
Sprockhövel und Berlin würde sich sonst bei jedem Lauf gegenseitig
überschreiben – ein Datenstand, der je nach Reihenfolge der Läufe anders
aussieht. Überschrieben wird nur, was derselben Quelle gehört; alles andere
steht im Protokoll und gehört von Hand zusammengeführt.

### Wenn etwas nicht ankommt

Der Bericht unter *Einstellungen → Abgleich* nennt je Lauf, was passiert ist.
Die häufigsten Fälle:

| Meldung | Ursache |
|---|---|
| **HTTP 403.** Der Schlüssel wurde nicht anerkannt | Der Schlüssel steht nicht zeichengleich auf beiden Seiten. Häufig ein mitkopiertes Leerzeichen |
| **HTTP 404.** Adresse nicht gefunden | Die Quelle steht nicht auf der Rolle *Quelle*, oder die Zentrale ist dort nicht eingetragen. Ohne eingetragene Gegenstelle wird die Adresse bewusst gar nicht erst registriert |
| **Die Quelle meldet einen leeren Bestand** | Es wurde **nichts** geändert. Ein leerer Bestand sähe aus wie „dort wurde alles gelöscht", und der Aufräumschritt blendete daraufhin jedes Seminar dieser Quelle aus. Häufigere Ursache ist eine halb eingerichtete Quelle: falscher Status, Wartungsmodus, leere Datenbank nach einem Umzug |
| **Nach 3 Fehlversuchen abgebrochen** | Die Bestandsliste kam an, die Seminardaten nicht — meist blockt ein Sicherheits-Plugin auf der Quelle POST an `/wp-json/` |
| **Die Seminarnummer … gibt es hier schon aus einer anderen Quelle** | Zwei Nummernkreise sind kollidiert. Der Eintrag wurde nicht angefasst |
| **Ausbildungsreihe „…" gibt es hier schon aus der Quelle „…"** | Gleicher Reihenname in zwei Quellen – in der Zentrale von Hand zusammenführen |

Der Abgleich hängt am WordPress-eigenen Cron. Auf einer Website ohne Besucher
läuft der nicht – dann hilft der Knopf *Jetzt abgleichen* oder ein echter
System-Cron (`wp-cron.php`), wie er für die Verfügbarkeits-Ampel ohnehin
empfohlen ist.

### Ein Lauf, der noch unterwegs ist

Ein Lauf arbeitet in Häppchen von 25 Seminaren, mit einem Zeitbudget von 20
Sekunden (per Knopf: 45). Was nicht fertig wird, plant sich in einer halben
Minute selbst neu ein.

Solange ein Lauf unterwegs ist, steht oben auf der Seite ein blaues Feld mit dem
**Fortschritt** – wie viele von wie vielen geholt sind, die bisherigen Zahlen
und die letzten Hinweise. Dazu zwei Knöpfe:

- **Weiter** setzt den Lauf fort und holt ein größeres Stück auf einmal.
- **Abbrechen** verwirft ihn. Das verliert nichts Bleibendes: Was geschrieben
  ist, trägt seine Änderungsmarke, und der nächste Lauf holt genau den Rest.

Die *Jetzt abgleichen*-Knöpfe sind währenddessen gesperrt. Ein Lauf einer
anderen Quelle wird nie unterbrochen – er stünde sonst halb erledigt da, ohne
dass es jemand merkt.

> **Der Knopf startet nicht neu, er setzt fort.** Bis 1.114.1 warf er einen
> unterwegs befindlichen Lauf weg und fing von vorn an. Wer wartete und deshalb
> noch einmal drückte, sorgte damit genau dafür, dass der Abgleich nie fertig
> wurde.

### Wenn die Seminardaten nicht ankommen, die Bestandsliste aber schon

Der häufigste Fall dahinter: Auf der Quelle blockt ein Sicherheits-Plugin, eine
WAF oder eine Server-Regel **POST-Anfragen an `/wp-json/`**. Die Bestandsliste
kommt per `GET` durch, das Paket per `POST` nicht.

Nach **drei** Fehlversuchen in Folge bricht der Lauf deshalb ab und schreibt
einen Bericht mit genau diesem Hinweis. Die nicht geholten Schlüssel wandern
zurück in die Schlange, statt als erledigt zu gelten – sonst würden die
betroffenen Seminare bis zur nächsten Änderung in der Quelle nie wieder geholt.

> **Warum das lange unsichtbar war.** Ohne diese Grenze arbeitete sich der Lauf
> durch *alle* Häppchen, jedes mit voller Zeitüberschreitung, wurde nie fertig –
> und ein Bericht entstand nur am Ende eines fertigen Laufs. Die Oberfläche
> meldete „gelaufen" und zeigte darunter nichts. Festgehalten in
> `tests/test-sync-abgleich.php`.

### Sicherheit

Jede Verbindung hat ein gemeinsames Geheimnis, das auf beiden Seiten steht. Es
geht im Kopf `X-BI-Sync-Key` mit und wird mit `hash_equals()` verglichen –
zeitkonstant, damit sich der Schlüssel nicht Zeichen für Zeichen erraten lässt.
Dazu ein Kontingent gegen Rateversuche (60 Versuche je fünf Minuten und
Absender). Ohne eingetragene Gegenstelle wird die Adresse gar nicht erst
registriert: Was es nicht gibt, kann nicht angegriffen werden.

Die Adressen geben ausschließlich Seminardaten heraus – nie Anmeldungen, nie
personenbezogene Daten. Die Anmeldezahl im Paket ist eine Zahl, keine Liste.

> **Der Kopieren-Knopf über eine unverschlüsselte Verbindung.** `navigator.clipboard`
> gibt es im Browser nur über HTTPS (und auf `localhost`); über HTTP ist es
> schlicht nicht da. Der Knopf fällt dort auf den alten Weg (`execCommand`)
> zurück und, wenn auch der versagt, auf „Text ist markiert, Strg+C tut es".
> Ohne diesen Rückfall bliebe der Knopf auf einer HTTP-Seite wirkungslos, ohne
> zu sagen, warum.

Ein Schlüssel lässt sich jederzeit über *Neu* austauschen. Er muss danach auf
**beiden** Seiten stehen – dazwischen meldet der Bericht `HTTP 403`.

Festgehalten in `tests/test-sync-abgleich.php`.

## Datenmodell

| Element | Speicherort |
|---|---|
| Seminartitel / Beschreibung | Post (`post_title`, `post_content`) |
| Präsenz-Seminare / Online-Seminare | Beitragstypen `bi_seminar` / `bi_online` |
| Startdatum, Enddatum, Seminarnummer, Plätze, Kosten … | Post-Meta `_bi_*` (für beide Beitragstypen dieselben Schlüssel) |
| Untertitel, Referent\*innen, Webinar-Tool, Anmelde-/Online-Link | Post-Meta `_bi_*` (nur `bi_online`) |
| Zustelladresse der Benachrichtigungen | Post-Meta `_bi_bz_email`, ersatzweise Term-Meta `email` an `bi_ort` |
| Ansprechperson: Name, E-Mail, Telefon | Post-Meta `_bi_ansprechpartner`, `_bi_ansprechpartner_email`, `_bi_ansprechpartner_telefon` (steuern keinen Versand) |
| Bildungszentrum bzw. Veranstalter\*in, Themenfeld | Taxonomien `bi_ort`, `bi_handlungsfeld` (an beiden Beitragstypen) |
| Zielgruppe, Freistellung (mehrfach) | Taxonomien `bi_zielgruppe`, `bi_freistellung` |
| Programmjahr (Jahrgang) | Taxonomie `bi_programm` |
| Suchindex der Volltextsuche | Tabelle `wp_bi_suchindex` (eine Zeile je Seminar, `FULLTEXT`) |
| Stand des Index-Nachbaus | Option `bi_suchindex` |
| Letztes Leeren des Seiten-Caches | Option `bi_cache_zuletzt` |
| PLZ-Zuordnung | Tabelle `wp_bi_plz` |
| Anmeldungen | Tabelle `wp_bi_anmeldungen` |
| Benachrichtigungen (inkl. Kopie-Adresse) | Option `bi_mail_triggers` |
| Verfügbarkeits-Ampel je Seminarnummer | Tabelle `wp_bi_ampel` |
| Gültige Generation der Ampeldaten | Option `bi_ampel_live` |
| Zustand des laufenden Abgleichs | Option `bi_ampel_lauf` |
| Ergebnis des letzten Abgleichs | Option `bi_ampel_protokoll` |
| Kampagnen (Newsletter-Links) | Tabelle `wp_bi_kampagnen` |
| Kampagnen-Ereignisse | Tabelle `wp_bi_events` |
| Kampagne einer Anmeldung | Spalte `kampagne` in `wp_bi_anmeldungen` |
| Ausbildungsreihe, Zuordnung eines Termins | Beitragstyp `bi_reihe`; Post-Meta `_bi_reihe_id`, `_bi_teil`, `_bi_durchgang` am Seminar |
| Sammelanmeldung zu einer Reihe | Spalten `sammel_id`, `reihe_id`, `durchgang`, `teil` in `wp_bi_anmeldungen` |
| Warteschlange Wochenzusammenfassung | Option `bi_mail_queue` |
| Termin des Wochenversands | Option `bi_mail_schedule` |
| Einstellungen | Option `bi_settings` |
| Abgleich: Rolle, Gegenstellen, Schlüssel, Zeitfenster | Option `bi_sync` |
| Abgleich: Zustand des laufenden Laufs | Option `bi_sync_lauf` |
| Abgleich: Berichte der letzten Läufe | Option `bi_sync_protokoll` |
| Herkunft eines abgeglichenen Seminars | Post-Meta `_bi_sync_quelle`, `_bi_sync_schluessel`, `_bi_sync_stand` |
| Änderungsmarke in der Quelle | Post-Meta `_bi_sync_geaendert` |

Die Filter-Facetten sind bewusst Taxonomien: WordPress filtert darüber mit
echtem ODER (`tax_query`, `operator IN`), auch bei Mehrfachwerten pro Seminar.

## Anpassung

- **Akzentfarbe**: alle Frontend-Komponenten nutzen die CSS-Variable
  `--bi-accent` (Standard `#e2001a`) und lassen sich per Theme-CSS umfärben.
- **Anmeldevarianten**: siehe [eigenes Kapitel](#anmeldevarianten).
- **Anmeldeformulare**: Seiten und Felder unter *Anmeldeformulare*, siehe
  [eigenes Kapitel](#anmeldeformulare).
- **Logo im PDF-Kopf**: Filter `bi_pdf_logo_path` – Serverpfad zurückgeben oder
  `''` für gar kein Logo.
- **Geschäftsstellensuche**: Filter `bi_gs_suche_url`.
- **Häppchengröße des Imports**: Filter `bi_import_haeppchen`.
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
│  ├─ class-bi-suche.php        Suchindex (wp_bi_suchindex), Wortschatz, Tippfehler-Korrektur
│  ├─ class-bi-cache.php        Seiten-Cache leeren, wenn sich Seminardaten ändern
│  ├─ class-bi-kacheln.php      Marketing-Kacheln [bi_kachel] + Backend-Builder
│  ├─ class-bi-detail.php       Seminar-Detailseite + Bausteine beider Detailansichten
│  ├─ class-bi-icons.php        Piktogramme der Detailseiten als Inline-SVG
│  ├─ class-bi-registration.php Anmeldeformular [bi_anmeldung] + Tabelle
│  ├─ class-bi-mailer.php       Mail-Trigger-Engine + Einstellungsseite
│  ├─ class-bi-tracking.php     Kampagnen-Links + Auswertung Klick → Anmeldung
│  ├─ class-bi-import.php       Seminar-CSV-Import mit Spalten-Mapping
│  ├─ class-bi-felder.php       eigene Datenfelder anlegen/umbenennen/löschen
│  ├─ class-bi-reihen.php       Ausbildungsreihen: CPT bi_reihe, Zuordnung, Reihenseite, [bi_reihen]
│  ├─ class-bi-datenpflege.php  Arbeitsmenge filtern, CSV-/JSON-Export, Paket-Import
│  ├─ class-bi-sync.php        Abgleich mehrerer Installationen (Quelle ↔ Zentrale)
│  ├─ class-bi-ampel.php        Verfügbarkeits-Ampel: Tabelle, Lookup, Ausgabe, Cron
│  ├─ class-bi-ampel-crawler.php  holt die Ampelzustände von modules.igmetall.de
│  ├─ class-bi-ampel-parser.php   reines HTML-Parsing der Termintabelle (ohne WordPress)
│  ├─ class-bi-plz.php          PLZ→Geschäftsstelle: Tabelle, Lookup, Import
│  ├─ class-bi-anmeldefelder.php Feld-Bestand des Anmeldeformulars (Kern + eigene)
│  ├─ class-bi-formulare.php    Anmeldeformulare: Seiten, Feldauswahl, Zuordnung
│  ├─ class-bi-settings.php     Einstellungen (Anmeldevarianten, Regeln, Seiten, PDF)
│  ├─ class-bi-pdf.php          Seminardetails als PDF (Mail-Anhang + Download)
│  ├─ class-bi-pdf-doc.php      Grundgerüst der PDFs (lädt erst mit FPDF nach)
│  ├─ class-bi-beschluss.php    Beschlussvorlage § 37 Abs. 6 BetrVG als Word-Datei
│  ├─ class-bi-docx.php         minimaler .docx-Schreiber (ZipArchive, keine Bibliothek)
│  └─ class-bi-admin.php        Admin-Menü + Mini-Dashboard
├─ templates/                    Detailseiten-Template
├─ vendor/fpdf/                  FPDF 1.86 (PDF-Erzeugung, freie Lizenz)
└─ assets/                       CSS, JS, flatpickr (gebündelt), img/igm-logo.png (PDF-Kopf)
```

## Lizenz

[GPL-2.0-or-later](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html) –
wie WordPress selbst.

Mitgeliefert: **FPDF 1.86** von Olivier Plathey (`vendor/fpdf/`) für die
PDF-Anhänge – freie Lizenz ohne Einschränkungen, siehe `vendor/fpdf/license.txt`.
