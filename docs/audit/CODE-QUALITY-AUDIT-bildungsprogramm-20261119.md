# Codequalitätsaudit · Bildungsprogramm

## Management-Zusammenfassung

**Prüfstand 19.11.2026** · Plugin 1.155.0 · main · `7e46b50b8d0d15c4900c180bfb3996b7c8132ac9`.

Die größten Wartungsrisiken liegen in der Reihenbuchung und Mailqueue, deren Fehlerpfade neben Rendering und WordPress-Einstiegslogik liegen. Zuerst die bestehenden Verträge mit kleinen Regressionstests beschreiben. Anschließend Buchung und Queue jeweils in einem eigenen Review ändern. Der [Security- und Datenschutzbericht](SECURITY-DATA-PRIVACY-AUDIT-bildungsprogramm-20261119.md) dokumentiert Prüfstand, Abdeckung und die zugehörigen Risiken.

## Prüfstand und Abdeckung

Stichprobe an öffentlicher Anmeldung, Formularregeln, Gruppenbuchung, Digest, CSV-Import und öffentlicher Suche. Rollen und Datenformate aus den Aufrufern abgeleitet. Keine versionierte Suite, kein Runner und keine CI-Workflows im Commitbaum gefunden. JavaScript-Syntaxprüfung für alle 17 versionierten JavaScript-Dateien bestanden. PHP-, WordPress-, Browser- und Datenbankverhalten erfordert eine getrennte Testinstallation. Produktionsbuilds wurden nicht abgeglichen. Der lokale Git-Whitespace-Check gegen den Altcommit mit CRLF-Toleranz meldete zwei kosmetische Abweichungen in `assets/css/fahrplan.css:372` und `assets/fonts/OFL.txt:21`.

## Refactoring-Pakete

Alle Dateistellen beziehen sich auf den Zielcommit. Abnahmetests sind Teil des jeweiligen zukünftigen Pakets.

| ID | Priorität | Befund | Evidenz |
| --- | --- | --- | --- |
| CQ-002 | P0 | Ausführbare Basisverträge fehlen | Git-Baum und fehlender Testeinstieg |
| CQ-003 | P1 | Formularvalidierung verteilt Datenvertrag über mehrere Aufrufer | im Code bestätigt |
| CQ-004 | P0 | Reihenbuchung und Folgeaktionen teilen keinen verlässlichen Abschluss | im Code bestätigt |
| CQ-005 | P1 | Digest-Queue hängt an einem gemeinsamen Optionsarray | im Code bestätigt |
| CQ-006 | P2 | Importvorschau, Parsing und Schreibschritte sind eng gekoppelt | im Code bestätigt |
| CQ-007 | P2 | Such-Rendering und AJAX hängen an umgebogenen Request-Werten | im Code bestätigt |
| CQ-008 | P2 | Formularmigration braucht einen expliziten Wiederholungsvertrag | im Code bestätigt |

### CQ-002 · Regressionsbasis für Einzel- und Reihenanmeldung

- **Beobachtung** · Verhalten ist hauptsächlich in Pluginquellen und Dokumentation beschrieben. Gegen Änderungen an Formularauswahl, CSV und Gruppenbuchung fehlt ein nachweisbarer Testlauf.
- **Evidenz** · `git ls-tree -r --name-only HEAD`; `includes/class-bi-registration.php:194,300–340,1088–1293,1719–1787`.
- **Maßnahme** · Einen dokumentierten WordPress-Testeinstieg mit synthetischen Datensätzen und Mail-Sink einführen. WordPress-Mindestversion und eine unterstützte aktuelle Kombination als Testmatrix festlegen. Tests für die bestehenden Hooks und Rückgaben vor dem ersten Umbau schreiben.
- **Vertrag** · Einzel- und Reihenanmeldung, Fehlerwiederanzeige, bestehende Exportspalten und veröffentlichte Routen; alte Datensätze ohne neuere JSON-Felder.
- **Abhängigkeiten** · Keine anderen Refactorings. Lokale DB, WordPress und PHP als Testumgebung.
- **Abnahme** · Ein dokumentierter Befehl startet aus sauberem Checkout mit Fixtures. Geprüft werden gültige Einzelbuchung, ungültige Reihenwahl, Sichtbarkeitsregeln, bestehender CSV-Header und Berechtigungsablehnung. Testdaten und Mail verbleiben in der Testumgebung.

### CQ-003 · Formularregeln und Normalisierung aus dem Submit-Handler lösen

- **Beobachtung** · `handle_submit()` verknüpft Felddefinition, bedingte Sichtbarkeit, Normalisierung und Fehlerwiederanzeige. Darstellungs- und Submit-Vertrag hängen an impliziten Felder-Arrays.
- **Evidenz** · `includes/class-bi-registration.php:194–233,444,605,1126–1206`; `includes/class-bi-formulare.php:154–220,339–435`.
- **Maßnahme** · Einen reinen Validierungsschritt mit typisiertem Ergebnis für Werte und Fehler einziehen. WordPress-Redirect und Speicherung im Handler belassen. Auswertung der Wenn-dann-Regeln aus vorhandener Logik wiederverwenden.
- **Vertrag** · Server ermittelt Formular aus Seminar; bedingt verdeckte Werte bleiben leer; Feldschlüssel, dynamische Felder, Mehrfachwerte, gespeicherte Altwerte und Fehleranzeige erhalten.
- **Abhängigkeiten** · CQ-002 als Ausgangspunkt; unabhängig von Buchung und Queue.
- **Abnahme** · Kaskadierte Bedingungen, veraltete Formularoption, skalar/Array-Eingaben, Grenzlängen und alte Felder prüfen. Erfolgsdaten und Fehlerdarstellung bleiben für gültige Bestandsfälle gleich.

### CQ-004 · Persistenz der Reihenanmeldung kapseln

- **Beobachtung** · Gruppierung, Insert, Kompensation und Tracking liegen im selben Schleifenabschnitt. Der Aufrufer erhält kein eindeutiges Ergebnis für alle Teilschritte.
- **Evidenz** · `includes/class-bi-registration.php:1242–1293,1304–1359`; [SEC-008](SECURITY-DATA-PRIVACY-AUDIT-bildungsprogramm-20261119.md#sec-008--reihenbuchung-kann-trotz-fehler-inkonsistent-bleiben--mittel).
- **Maßnahme** · Speicherung der Einzel- und Reihenbuchung hinter einem gemeinsamen Vertrag ausführen. Erfolgsresultat erst nach vollständigem Commit. Die bestehende Gruppen-ID und Reihenfolge erhalten, Folgeaktionen nach dem Commit aufrufen.
- **Vertrag** · Eine Zeile je Termin, erste Zeile als Gruppenanker, bestehende Sammlung/CSV, ein Trackingevent je vollständiger Anmeldung, bestehende Weiterleitungen.
- **Abhängigkeiten** · CQ-002; Transaktionsfähigkeit der tatsächlichen DB-Engine prüfen. CQ-003 und CQ-005 können unabhängig erfolgen.
- **Abnahme** · Fehler bei Insert und Gruppierungsupdate hinterlassen weder Teilbuchung noch Tracking. Wiederholung kann nach Fehler buchen. Erfolgsfall erhält Reihenfolge und Gruppenauswertung für alte und neue Zeilen.

### CQ-005 · Wochenqueue aus statischem Mailer-Zustand lösen

- **Beobachtung** · Das Optionsarray ist gemeinsamer Zustand für Producer und Cron. Fehler, Versuchszahl und Übernahme werden je Job nicht geführt.
- **Evidenz** · `includes/class-bi-mailer.php:393,528,987–996,1021–1087`; Security-Befund SEC-005.
- **Maßnahme** · Queue-Vertrag für Einreihen, Übernehmen, Abschließen und Wiederholen mit begrenzter Aufbewahrung einführen. Altoption einmalig und wiederanlaufbar migrieren. Bestehendes Rendering in diesem Paket verwenden.
- **Vertrag** · Trigger- und Empfängergruppierung, konfigurierter Wochenversand, Testmodus und Zusammenfassung im Backend erhalten. CC/Reply-To gemäß abgestimmter Empfängermatrix aus DS-001 prüfen.
- **Abhängigkeiten** · CQ-002; SMTP-Testsenke, Migrationsfixture und Empfängerentscheidung.
- **Abnahme** · Parallele Übernahme, Mailfehler, Wiederholung und unterbrochene Migration prüfen. Alt- und Neujobs werden nachvollziehbar genau einem Verarbeitungslauf zugeordnet; mögliche Wiederholung nach unklarem Zustellstatus ist dokumentiert.

### CQ-006 · CSV-Vorschau vom Schreiblauf entkoppeln

- **Beobachtung** · Teilimport erstellt einen Plan aus CSV und Zuordnung. Der Hauptimport zählt und verarbeitet zeilenweise in AJAX-Schritten. Beide Pfade brauchen einen klaren Parsing- und Fehlervertrag, bevor Logik zusammengelegt wird.
- **Evidenz** · `includes/class-bi-import.php:404–640,1262–1275,1373–1490,1550–1639,1669–1715`.
- **Maßnahme** · Einen kleinen Fixture-Katalog für beide Pfade ergänzen. Die gemeinsam nachgewiesenen CSV-Leseregeln extrahieren und fachliche Importentscheidungen in ihren jeweiligen Pfaden lassen. Einen expliziten Ergebnisstatus je angewandter Zeile führen.
- **Vertrag** · Trennzeichen, BOM, Header, leere Werte, Mapping, gesperrte Seminare, Vorschau, bestehende Datumseingaben und Fortsetzen eines Laufs.
- **Abhängigkeiten** · CQ-002; Staging-Hardening SEC-009 kann als separates Paket umgesetzt werden.
- **Abnahme** · Vorschau und Anwendung stimmen auf denselben Fixtures überein. Abbruch zwischen Batches und Wiederholung verändern bereits abgeschlossene Zeilen nur gemäß dokumentierter Importsemantik.

### CQ-007 · Filterzustand ausdrücklich an Suche und Rendering übergeben

- **Beobachtung** · Öffentliche AJAX-Aktualisierung übersetzt Eingaben in den für den Shortcode erwarteten Request-Kontext. Einstieg, Suche und Ausgabe teilen dadurch versteckten Zustand.
- **Evidenz** · `includes/class-bi-filter.php:1203,1215–1291,1461–1495,1649–1675`.
- **Maßnahme** · Normalisierten Filterzustand einmal am Einstieg bilden und an Query und Rendering weiterreichen. Kurzcode und AJAX als Adapter behalten.
- **Vertrag** · Bestehende Query-Parameter und Links, programmbezogene Vorgaben, Pagination, Such-Fallback und heutige HTML-Teilantwort.
- **Abhängigkeiten** · CQ-002; Such- und Render-Fixtures können parallel erstellt werden.
- **Abnahme** · Kurzcode und AJAX liefern für denselben Zustand dieselben Treffer und Seitengrenzen. Aufeinanderfolgende Renderaufrufe mit verschiedenen Filtern beeinflussen einander nicht.

### CQ-008 · Formularmigration mit Altbestand und Wiederanlauf absichern

- **Beobachtung** · Die Initialisierung aktualisiert Formularoption und Migrationsmarker. Das Zusammenspiel mit bestehenden Formularen und einer Unterbrechung zwischen Schreibvorgängen ist ein eigener Datenvertrag.
- **Evidenz** · `includes/class-bi-formulare.php:95–146,285–309,377–435`.
- **Maßnahme** · Eingabe-/Ausgabeformat der Migration isolieren, unveränderte Bestandswerte erhalten und den Marker erst nach bestätigter Persistenz setzen. Die alte Option und erfüllte Marker als Fixtures vorsehen.
- **Vertrag** · Benutzerdefinierte Formulare, Feldreihenfolge und bereits gespeicherte Auswahl respektieren; bei leerer Installation gelten bestehende Defaults.
- **Abhängigkeiten** · CQ-002; kann vor oder nach CQ-003 umgesetzt werden.
- **Abnahme** · Frischinstallation, Altstand, bereits migrierter Stand und Abbruch vor dem Marker. Ein zweiter Lauf erzeugt keine Verdopplung und überschreibt keine gültige individuelle Konfiguration.

## Nachprüfung der Altberichte

Altbericht Codequalität vom 10.08.2026 (lokaler Altbericht `CODE-QUALITY-AUDIT-Bildungsprogramm.md`), Commit `f1dbc9f6694de4e1a54e22e8f3c37b3452e05b2c`. Die Stichprobe bewertet die unten genannten Aufrufpfade. Übrige Empfehlungen innerhalb der Befundfamilien sind **nicht geprüft**. Laufzeitnachweise für alle Pakete ausstehend.

| Alt-ID | Umsetzung | Aktueller Nachweis |
| --- | --- | --- |
| CQ-001 | offen in der Stichprobe | Registrierung vereint weiterhin Form, Speicherung und Verwaltung; CQ-003/004/007 beschreiben trennbare Schritte |
| CQ-002 | offen | Im Git-Baum keine Suite/CI sichtbar |
| CQ-003 | teilweise | Scalar-Adapter eingeführt, implizite Formularverträge bleiben |
| CQ-004 | teilweise | Insert-Fehler erkannt; Gruppenupdate, Kompensation und Queue offen |
| CQ-005 | offen in der Stichprobe | Digest-Optionsarray und Filter-Request-Zustand |
| CQ-006 | offen in der Stichprobe | Submit und CSV-Wege mit mehreren Aufgaben, siehe CQ-003/006 |
| CQ-007 | nicht geprüft | Umfassende Trennung von Templates, Skripten und CSS außerhalb der Stichprobe; CQ-007 prüft den Suchaufrufweg |
| CQ-008 | offen in der Stichprobe | Initialisierung migriert Formularoption und Marker; CQ-008 |
| CQ-009 | nicht geprüft | Keine systematische Duplikatmessung oder Bestandsaufnahme aller Steuerwerte |
| CQ-010 | nicht geprüft | Kein WPCS-Lauf und keine I18n-Inventur |
| CQ-011 | teilweise | Budget-Hinweis und Retention-Zähler vorhanden. Digest-Misserfolge ohne persistierten Jobstatus, siehe SEC-005 |
| CQ-012 | offen | Kein versionierter ausführbarer Vertrag gefunden |

## Reihenfolge und Abnahme

CQ-002 bildet das Sicherheitsnetz. CQ-004 und CQ-005 folgen als eigenständige Änderungen. CQ-003, CQ-006, CQ-007 und CQ-008 werden jeweils gegen ihren eigenen Testbestand reviewt. Entwicklung verantwortet die Pakete, Betrieb liefert DB-, Cron- und Mail-Nachweise. Release-Verantwortliche gleichen bei der Auslieferung Ziel-SHA, Plugin-Version und paketierte Bibliotheken ab.
