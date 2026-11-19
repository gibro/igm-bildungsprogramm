# Audit-Verbesserungen · Bildungsprogramm · 19.11.2026

Ausgangspunkt ist `7e46b50b8d0d15c4900c180bfb3996b7c8132ac9` (Plugin 1.155.0). Die beiden Auditberichte dokumentieren den damaligen Stand. Diese Datei beschreibt den Stand auf `codex/audit-bildungsprogramm-20261119` und die separat prüfbaren Folge-PRs.

- [Security- und Datenschutzbericht](SECURITY-DATA-PRIVACY-AUDIT-bildungsprogramm-20261119.md)
- [Codequalitätsbericht](CODE-QUALITY-AUDIT-bildungsprogramm-20261119.md)

## Vorbereitete Code-PRs

| Befund | Commit | Änderung und zu prüfende Auswirkungen |
| --- | --- | --- |
| SEC-008 / CQ-004, Teilfix | `7670626` | Sammel-ID prüfen, Tracking/Mail erst nach vollständigem Schreiben, Duplikatmarkierung nach abgelehntem Vorgang lösen. Ein DB-Abbruch oder fehlgeschlagener Ausgleich kann weiterhin Teilzeilen hinterlassen. |
| SEC-002 | `76a25cd` | Fehlerzustand erfordert ein vorgangsspezifisches Browser-Cookie und wird bei Wiederanzeige verbraucht. Geteilte URL allein genügt nicht. Beim Update verfallen alte Fehlervorgänge gemäß ihrer bisherigen TTL. Prüfen, ob der unterstützte Einbettungsmodus Cookies und Wiedervorlage erwartet. Getrennte Admin-/Frontend-Hosts oder blockierte Cookies zeigen den Fehler ohne Wiederanzeige der Eingaben. |
| SEC-012 | `715a250` | Site, Pfad, Kampagne und Ablauf im Cookie signiert, nur aktive Kampagnen zuordnen. Bereits laufende Pfade mit altem Cookie enden bei der Umstellung. Berichte behalten gespeicherte Ereignisse. Ein neuer Klick startet einen neuen Pfad. Das Schreibbudget bleibt offen. |
| SEC-013, Teilfix | `e87db50` | Öffentlicher PDF-Download akzeptiert skalare Seminar-IDs über `bi_get()`. Array-Eingaben werden als fehlende ID behandelt. |
| CQ-008, Teilfix | `376fcdc` | Formularmigration markiert einen Lauf erst nach bestätigtem gespeicherten Zustand. Bestehende Formulare und Feldreihenfolge bleiben maßgeblich. |

Jeder Commit enthält die Begründung, die getesteten Fälle und die offenen Abnahmen. Die Änderungen wurden isoliert mit PHP 8.4.24 und synthetischen Fixtures geprüft. Die Fixtures sind bisher keine versionierte Suite. Die ergänzende WordPress-/MariaDB-Prüfung ist im folgenden Abschnitt dokumentiert. Git hat für jeden Code-Commit bestätigt, dass sein Patch einzeln auf den Audit-Ausgangscommit anwendbar ist. Für den Test-PR kann der Zweig als Ganzes dienen. Für die Übernahme jeweils die passenden Code-Commits auf dem bestätigten Zielbranch übernehmen und separat reviewen. Die Report-Dateien bilden einen eigenen Commit.

## Gegenprüfung vor dem Push

Der Code-Stand `e46696d` wurde aus einem Git-Archiv in einer frischen WordPress-Installation aktiviert. WordPress 7.0.2, PHP 8.4.24 und MariaDB 11.8.8 liefen in einem internen Docker-Netz mit synthetischen Daten. Die fünf vorbereiteten Code-Commits sind oben den Befunden zugeordnet.

| Prüfweg | Beobachtetes Ergebnis |
| --- | --- |
| WordPress und DB | Pluginaktivierung, Einzelbuchung und Speicherung einer zweiteiligen Reihe erfolgreich. Beide Teilzeilen ließen sich unter dem Anker der ersten Zeile lesen. |
| Fehlerzustand | Mit echten WordPress-Transients wurde die Wiederanzeige ohne Cookie verweigert. Mit passendem Nachweis war sie einmal möglich. Ein separater HTTP-Abruf des Fehlerzustands enthielt `Cache-Control: private, no-store`. |
| Kampagne und Migration | Der signierte Cookie ergab bei einer aktiven DB-Kampagne den erwarteten Slug. Nach Deaktivierung entfiel die Zuordnung. Im Formular-Altbestand folgte der Migrationsmarker dem eingefügten Feld. |
| Defensive Regression | Die isolierten PHP-Prüfungen für Insert-/Updatefehler, Rücknahme, erneute Einreichung, Tokenvalidierung und PDF-ID bestanden. Der DB-Integrationstest rief die Speicherfunktionen auf. Die vollständige Benutzerreise im Browser bleibt offen. |
| Abgleich der Datenschutzhinweise | Der bisherige Text überging das Fehler-Cookie und die Verbindung des Kampagnenereignisses mit der Anmelde-ID. Der Review-Commit beschreibt beide Datenflüsse im Plugin. Die historischen Berichte beziehen sich weiter auf den oben genannten Ausgangscommit. |

**Offene Abnahme für Claude.** Parallele Buchungen und Prozessabbrüche aus SEC-001/008 mit der vereinbarten Quoten- und Transaktionssemantik prüfen. Die aktuelle Gegenprüfung belegt sequenzielle Abläufe. Das Formular zusätzlich im Browser mit mehreren Tabs, Cache/CDN und gegebenenfalls eingebettet testen. Empfänger, Retention und Einwilligung mit dem Betrieb abgleichen. Der Prüfstand ist für den Audit-Branch bestimmt.

## P0 · Buchungsaufnahme und Regressionsbasis

### SEC-001 · Kontingent und Duplikatstatus zuverlässig führen

**Anfassen.** `igm-bildungsprogramm.php::bi_rate_hit()` und `BI_Registration::handle_submit()` / `mail_budget_ok()`.

**Vorschlag und Grund.** Ein eng begrenztes persistentes Zählermodell mit eindeutiger Bucket-/Fenster-ID und bedingter atomarer Reservierung einführen. Quoten für Anmeldung, Versand und öffentliches PLZ-Lookup als getrennte Verträge behandeln. Für die Anmelde-Deduplizierung Zustände für laufend und abgeschlossen vorsehen. Nur eine gespeicherte Buchung erhält bei erneutem Aufruf eine Erfolgsmeldung. Ein abgebrochener Versuch muss nach Ablauf oder expliziter Freigabe wieder buchbar sein. Die IP-Ermittlung erst mit dokumentierter Proxy-Vertrauensgrenze konfigurieren. Aktuelle Filter und getrennte Fehlerzustände beibehalten.

**Abnahme.** Gleichzeitige Reservierungen mit DB-Integrationstest und synthetischen Daten; korrektes Ende des Zeitfensters; Duplikat nach Abschluss gegenüber laufender Buchung; Fehler einer Buchung mit anschließendem Wiederanlauf. Schwellen und die Semantik des Mailbudgets (Buchung oder einzelne Nachricht) durch Betrieb bestätigen. Rohadressen gehören nicht in Betriebsprotokolle.

### SEC-008 / CQ-004 · Speichern und Quittieren als zusammenhängenden Vorgang

**Anfassen.** `BI_Registration::buchung_speichern()`, Tabelleninitialisierung und `sammlung()`.

**Vorschlag und Grund.** Nach Bestätigung der eingesetzten Tabellenengine alle Teilzeilen samt Sammel-ID in einer Transaktion speichern. Commit, Rollback und Verbindungsverlust gesondert behandeln. Die App soll erst nach bestätigtem Abschluss eine Buchung quittieren und Folgeaktionen aufrufen. Bestehende IDs, Snapshots, CSV-Spalten und alte Einzelanmeldungen bleiben lesbar. Bei unbekanntem Ergebnis erneute Buchung erst nach Abgleich des Vorgangsstatus zulassen. Eine Änderung der Tabellenengine wäre eine gesonderte Migration mit Backup- und Rückkehrplan.

**Abnahme.** Synthetische Reihe mit Ausfall beim ersten und späteren Insert, beim Ankerupdate und beim Commit. Nachprüfen, dass Verwaltung und Mail bei Erfolg alle Teile in Reihenfolge erhalten. DB-Engine und Verhalten bei Wiederverbindung müssen vor Umsetzung feststehen.

### CQ-002 / CQ-012 · Ausführbaren Testeinstieg liefern

**Anfassen.** Git-Baum, neue Testkonfiguration und kurze Testanleitung.

**Vorschlag und Grund.** Mit einer isolierten WordPress-Datenbank und Mail-Sink starten. Synthetische Fixtures für Einzel-/Reihenanmeldung, Alt-Datensätze, Fehlerzustand, Rollenmatrix und CSV-Vertrag anlegen. PHP- und JavaScript-Syntaxschritte vor die Integrationstests setzen. Den Projektvertrag aus README anhand der Versions-/Umgebungsmatrix prüfen. CI erst nach Freigabe einer Laufzeitmatrix und eines Runner-Konzepts anbinden. Keine Verbindung zu produktiven Datenbanken und Postfächern.

**Abnahme.** Ein dokumentierter lokaler Startbefehl erzeugt dieselben Ergebnisse aus einem sauberen Checkout. Die Baseline schlägt bei mindestens einem nachgewiesenen alten Fehlerpfad erwartungsgemäß fehl.

## P1 · Öffentliche Eingaben, Mail und Datenlebenszyklus

### SEC-002 · Browser- und Cache-Abnahme sowie Datensparsamkeit

**Anfassen.** `BI_Registration::protect_error_response()`, `store_error_state()` und `read_error_state()`; Proxy-/Cache-Regeln.

**Vorschlag und Grund.** Den vorbereiteten Commit im normalen Anmeldefluss testen. Nur Felder wiederherstellen, deren Wiederanzeige fachlich erforderlich ist. Feld-IDs und erlaubte Fehlerzustände am vorhandenen Formularvertrag ausrichten. Mit Betrieb klären, wie die Vorgangskennung in Zugriffsdaten und Referern behandelt wird und welcher Host das Admin-POST bedient. Cookie-/Cache-Grenzen hängen auch vom tatsächlichen Einbettungs- und Proxybetrieb ab.

**Abnahme.** Zwei offene Tabs, zweiter Browser, verbrauchter/abgelaufener Zustand, Fehler beim Transient-Speichern, Buchung in unterstützten Einbettungen und Seiten-Cache aus/an. Die Fehleranzeige funktioniert auch ohne Wiederanzeige der Eingaben. Browser und Cache geben das personalisierte HTML nicht als gemeinsame Antwort weiter.

### SEC-003 / SEC-013 · Eingabegrößen und Typen am Handler begrenzen

**Anfassen.** `BI_Registration::termine_aus_request()`, `handle_submit()`, `BI_Formulare`, `BI_Filter::ajax_refresh()` / `ajax_vorschlag()` und die mit `nopriv` registrierten Einstiege.

**Vorschlag und Grund.** Den bestehenden Skalaradapter an allen geprüften skalaren Eingängen einsetzen. Rohwertlänge bei Vertragsgrenzen vor Parsing messen. Feldobergrenzen aus dem Formularkatalog dokumentieren. Für dynamische Felder ohne deklarierte Grenze und Terminanzahl zuerst die größte fachlich gültige Konfiguration bestimmen, dann eine serverseitig abgeleitete Grenze ergänzen. Fehlerpfade sollen keine Werte oberhalb dieser Grenzen zwischenspeichern. Allgemeine Body-Grenze und Timeouts separat durch Hosting bestimmen; bestehende Formulare und AJAX-Antworten bleiben kompatibel.

**Abnahme.** Grenzwerte, Unicode, definierte Mehrfachwerte, fehlende Eingaben, Array statt Skalar und maximaler gültiger Reihenplan. Ungültige Eingaben enden an der Eingangsgrenze ohne Persistenz, Mail oder Fehlerseite mit personenbezogenen Werten. Für PDF sind skalare ID und Zugriffsschutz als eigener Testfall zu halten.

### SEC-004 / SEC-012 · Klickpersistenz vom Redirect trennen

**Anfassen.** `BI_Tracking::maybe_handle_link()`, `log()` und `prune_old()`.

**Vorschlag und Grund.** Nach Freigabe der Statistiksemantik ein kampagnenbezogenes und globales Schreibbudget mit dem atomaren Reservierungsverfahren aus SEC-001 anwenden. Die Funktion soll die Zielseite auch bei erschöpftem Budget oder Trackingfehler erreichen. Entscheiden, ob unterdrückte Klicks aggregiert werden und ob nur persistierte Klicks einen neuen Pfad erhalten. Den vorbereiteten signierten Zustand ohne Tabellenumbau testen und nach Rollout im Bericht die Übergangsphase kennzeichnen.

**Abnahme.** Kontrollierte Uhr, genehmigte Budgets, unverändertes Redirect-Ziel, Ausfall der Ereignisspeicherung und regulärer Kampagnenpfad. Prüfen, dass deaktivierte Kampagnen und alte Cookies keine neue Anmeldung attribuieren. Keine IP-Adressen in Ereignisse aufnehmen.

### SEC-005 / CQ-005 / CQ-011 · Zustell-Zustand statt gemeinsamem Queue-Array

**Anfassen.** `BI_Mailer::queue_add()`, `flush_queue()` und die beiden Producer im Mailer.

**Vorschlag und Grund.** Begrenzte permanente Jobs mit Schlüssel, Empfängergruppe, Status, Lease, Retry-Zeitpunkt und Verfallszeit einführen. Beim Lauf darf ein Job nur atomar übernommen und nach erfolgreichem `wp_mail()`-Aufruf als an den Transport übergeben markiert werden. Unklare Ergebnisse gesondert behandeln. Bestehende Optionsjobs idempotent migrieren und Gruppierung, Testmodus, Terminplan und Mailinhalt erhalten. Bei Empfänger- oder CC-Abweichung Zustellung erst anhand der freigegebenen Regeln entscheiden. Diagnosezähler und Fehlerklasse erfassen, ohne Nachrichtentext oder Adressen in Logs zu schreiben.

**Abnahme.** Zwei Worker, neue Jobs während eines Laufs, Transportfehler, Wiederanlauf nach Abbruch, Ablauf, wiederholte Migration aus dem Altformat und Versand zu genehmigten Gruppen. Fachentscheidung zu Wiederholung nach unklarer Übergabe und maximale Aufbewahrung sind Voraussetzungen.

### DS-001 / SEC-011 · Löschumfang und Empfängerkreis nachvollziehbar abbilden

**Anfassen.** `BI_Retention::run()`, Registrierungsexport und Digest-Jobmodell aus SEC-005. Speicherkarte in der Security-/Datenschutzprüfung.

**Vorschlag und Grund.** Technisch eine adressierbare Zuordnung zwischen Anmeldung und noch nicht gesendeten Jobs vorsehen. Die Queue soll beim Ablauf gemäß festgelegter Regel Feldinhalte entfernen oder den Job verwerfen. Auskunft und Löschung als begrenzte, rollen- und zweckgebundene Abläufe spezifizieren, einschließlich Auftragsprotokoll ohne Rohdaten. Die WordPress-Exporter-/Eraser-Anbindung erst auf Basis des bestätigten Identitätsabgleichs planen. Bei Gruppendigest Empfänger und CC pro zulässiger Gruppe validieren. Reply-To mit mehreren Adressen und Exportspalten erst nach Empfängerfreigabe umstellen.

**Abnahme.** Mit einem synthetischen Alt- und Neudatensatz nachweisen, welche Daten vor/nach dem Lauf in DB und Queue bestehen. Betrieb bestätigt separat Fristen, Mail-Empfänger, Rechte, Export-/Backupkopien und Prozess nach Wiederherstellung. Keine rechtlich wirksame Anonymisierung allein aus dem Leeren direkter Felder ableiten.

### DS-002 · Kampagnenbezug bei Löschung entkoppeln

**Anfassen.** `BI_Retention::purge()` / `anonymize()`, `BI_Tracking::anmeldungen()`, Conversion-Auswertung.

**Vorschlag und Grund.** Direkte Verknüpfung über `anmeldung_id` für löschfällige Vorgänge entfernen, sobald Berichtszweck und Aufbewahrung bestätigt sind. Für benötigte Statistik einen zeitlich und fachlich zulässigen Aggregationsschritt gestalten. Für kleine Gruppen vor der Abnahme klären, ob Zeit, Seminar und Kampagne gemeinsam sichtbar bleiben dürfen. Genaue Frist, Kohortengröße und Statistikfolgen bleiben fachliche Entscheidungen.

**Abnahme.** Synthetische Kampagne mit wenigen Buchungen vor/nach dem Retention-Lauf; kein auflösbarer Bezug auf gelöschte Zeilen. Freigegebene aggregierte Zähler und zulässige Berichtsansichten bleiben korrekt.

### SEC-014 · Zielprüfung für alle Sync-Aufrufe vereinheitlichen

**Anfassen.** `BI_Sync::handle_speichern()`, `abrufen()` und `klopfen_senden()`.

**Vorschlag und Grund.** Einen gemeinsamen Transport für beide Aufrufrichtungen einsetzen. Freigegebene HTTPS-Ziele bei Konfigurationsänderung und vor dem Abruf prüfen. Keine automatische Weiterleitung für Requests mit Schlüssel. URL-Regeln, Größenlimit und Fehlerantwort pro Pakettyp definieren und in Logs nur Partner-ID und Fehlerklasse halten. Vor Umsetzung die tatsächlich benötigten öffentlichen und internen Partner, Zertifikate, Port-/Proxy-Anforderungen und größten zulässigen Pakete inventarisieren.

**Abnahme.** Isolierte HTTP-Mocks für freigegebenes Ziel, nicht freigegebenes Ziel, Redirect, zu große Antwort und Fehler/Rückfall. Schlüssel erreicht nur freigegebene Hosts. Alle bestehenden freigegebenen Aufrufrichtungen haben einen Positivtest.

## P2 · Import, Formular und Suche

### SEC-009 / CQ-006 · Import-Staging und Schreibvertrag getrennt verbessern

**Anfassen.** `BI_Import::dir()`, Uploads, `teil_plan()`, `teil_anwenden()`, `ajax_step()` und Abschlusswege.

**Vorschlag und Grund.** PR A führt ein durch Hosting bestätigtes nicht öffentliches Staging für beide Importwege ein. Datei nur mit passendem Lauf und Nutzerkontext öffnen, temporäre Zustände nach erfolgreichem Abschluss und per begrenzter TTL-Bereinigung entfernen. PR B charakterisiert Parser und Zeilenentscheidungen, extrahiert gemeinsame Normalisierung erst nach Vergleich der Fixtures und gibt pro Zeile ein überprüfbares Ergebnis zurück. Struktur von Mapping, Vorschau und Batch-Fortsetzung beibehalten. Größe, Zeilenlimit, Besitzwechsel und Aufbewahrung mit dem größten gültigen Import und dem Webserver abgleichen.

**Abnahme.** Haupt- und Teilimport mit BOM, Trennzeichen, alten Datumswerten, Vorschau und Anwendung. Abbruch, Fortsetzen, Ablauf und Rollenwechsel prüfen. Hosting weist nach, dass keine Staging-Datei statisch ausgeliefert wird.

### CQ-003 / CQ-001 · Formularregeln vom WordPress-Controller lösen

**Anfassen.** `BI_Registration::handle_submit()`, `all_fields()`, `form_steps()` und `BI_Formulare::wenn_erfuellt()`.

**Vorschlag und Grund.** Schrittweise zunächst Validierungsdaten und Ergebnisarray klar spezifizieren, danach die reine Verarbeitung extrahieren. Vorhandene Wenn-dann-Auswertung wiederverwenden. Redirect, Tracking und Persistenz aus dem Validator heraushalten. Die Auswahl des Formulars bleibt serverseitig aus dem Seminar abgeleitet, versteckte Felder bleiben leer. Alte Feldschlüssel, Export- und Speicherformate bleiben lesbar.

**Abnahme.** Kaskadierte Regeln, gelöschtes oder früheres Formular, dynamisches Feld, Pflichtfeldfehler sowie gültige Einzel- und Reihenbuchung. Diese Änderung kann unabhängig von Queue und Sync reviewt werden.

### CQ-007 / CQ-001 · Suchzustand ohne globale Request-Umschreibung

**Anfassen.** `BI_Filter::ajax_refresh()`, Shortcode, `count_for_params()`, `zeilen_fuer_params()` und Vorschläge.

**Vorschlag und Grund.** Eingangsadapter normalisieren GET, POST und die internen Kachelparameter in dieselbe Filterrepräsentation. Query und Rendering erhalten sie explizit. Reihenfolge und HTML-Teilausgabe bleiben zunächst gleich. Globale `$_GET`-Umschreibungen erst entfernen, sobald alle zugehörigen Aufrufer auf dem neuen Vertrag laufen.

**Abnahme.** Dieselben Treffer, Fallback-Reihenfolge, Facetten und Seitenlimits für Kurzcode, AJAX und Kachel-Fixtures. Zwei Renderdurchläufe mit unterschiedlichem Zustand bleiben unabhängig.

### CQ-008 · Wiederanlauf in der WordPress-Integration absichern

**Anfassen.** `BI_Formulare::einwilligung_nachruesten()` und die Tests aus CQ-002.

**Vorschlag und Grund.** Den kleinen Marker-Fix als eigenständigen PR reviewen. Ergänzend die Transformation gegen gespeicherte Alt-Optionen abnehmen und anhand realer Options-/Cache-Semantik entscheiden, ob parallele Redaktionsänderungen separat geschützt werden müssen. Vorhandene Migrationsmarker allein belegen den Vollzug des früheren Schreibvorgangs nicht. Kein rückwirkendes erneutes Einfügen bei bereits markierten Formularen ohne fachliche Freigabe.

**Abnahme.** Frischinstallation, individueller Altbestand, fehlgeschlagenes Speichern, automatischer Wiederanlauf, vorhandene individuelle Einwilligungsregel und bereits gesetzter Marker.

## Abgrenzung zu Altbefunden und Betrieb

| Punkt | Stand und Übergabe |
| --- | --- |
| SEC-006 | Der aktuelle Export behandelt die im Audit geprüften Formelpräfixe. Einen Regressionstest zu Präfixen, Spaltengrenzen und repräsentativen Export-/Import-Clients in CQ-002 hinzufügen. Änderungen am Dateiformat erst nach Client-Abnahme. |
| SEC-007 | Das IP-Limit bleibt Teil von SEC-001. Abweichende Rückgabe oder Adressfreigabe setzt Kenntnis des produktiven Adressbestands, der Funktion des Mailto-Buttons und der vertrauenswürdigen Proxy-Kette voraus. |
| SEC-010 | Keine Änderung für den unbestätigten Facettenbefund. Erst Query-Zahlen und Antwortzeiten lokal mit repräsentativer Größe messen, möglichst zusammen mit den Such-Fixtures aus CQ-007. |
| CQ-009 / CQ-010 | Keine pauschale Werte-, Formatier- oder Übersetzungsänderung aus einer unvollständigen Stichprobe. Nach Testbasis eigene konkrete Fundstellen mit eigenem Review erfassen. |
| Lieferkette und Builds | Vor einer Aktualisierung eingesetzte Vendor-/Runtime-Versionen, Herstellerhinweise und tatsächlich ausgelieferte Artefakte abgleichen. Lokale Bestandsdaten belegen keinen Produktionsstand. |
| Rollen, Einbettung, externe Dienste | Produktive Capabilities, Frame-/Cache-Regeln, externe Online-Anmeldung und Partner-/Mail-Betrieb erfordern Bestätigung. Produktivwerte und Schlüssel gehören nicht in Fixtures oder Berichte. |

Die Altberichte werden aus dem lokalen Auditarchiv referenziert.
