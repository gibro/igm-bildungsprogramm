# Security- und Datenschutz-Audit · Bildungsprogramm

## Management-Zusammenfassung

**Prüfstand 19.11.2026** · WordPress-Plugin IGM Bildungsprogramm 1.155.0 · main · `7e46b50b8d0d15c4900c180bfb3996b7c8132ac9`.

Seit dem August-Audit wurden direkte personenbezogene Daten in Fehler-URLs entfernt, der CSV-Export abgesichert und eine Löschroutine eingeführt. Priorität haben heute die Begrenzung öffentlicher Schreibvorgänge, konsistente Buchung und Benachrichtigung sowie die Löschung über alle Speicherorte. Die Befunde sind statisch belegt. Proxy-, Cron-, Mail-, Cache- und Datenschutzkonfiguration einer laufenden Installation bleiben offen.

## Prüfstand und Methode

| Merkmal | Stand |
| --- | --- |
| Repository | gibro/igm-bildungsprogramm, lokaler Checkout; sämtliche Pfade und Zeilen beziehen sich auf den Zielcommit |
| Plattform | Plugin-Header fordert WordPress ≥ 5.8 und PHP ≥ 7.4. Tatsächliche WordPress-, PHP- und MySQL/MariaDB-Version sowie Begleitplugins unbekannt |
| Werkzeuge | Lokales Codex-Quellreview entlang WordPress-Hooks, Rollenprüfungen und Datenflüssen; git, ripgrep und Node 24.22.3. Der verfügbare CLI-Review ist modellgestützt und benötigt einen Dienstaufruf; gemäß Lokalvorgabe war er kein separater Prüflauf. Codex Security ist nicht installiert. GitHub-PR-Review betrifft spätere Fixes |
| Tests | Kein Test-Runner, keine versionierte Test-Suite bzw. CI-Workflows im Zielbaum gefunden. `node --check` für 17 versionierte JavaScript-Dateien bestanden. PHP/Composer nicht auf dem PATH; kein lokales WordPress-Testsystem eingesetzt. Keine HTTP-, Parallel-, Mail-, Last- oder DB-Laufzeittests |
| Maßstäbe | WordPress Capability- und Nonce-Prüfung, kontextgerechte Ausgabe, serverseitige Grenzen, Fehler- und Zustandskonsistenz sowie DSGVO Art. 5, 6, 13, 15, 17 und 32 als Prüffragen für die zuständige Stelle |
| Lieferkette | FPDF 1.86 (`vendor/fpdf/fpdf.php:12`, PDF-Erzeugung) und flatpickr 4.6.13 (`assets/vendor/flatpickr/flatpickr.min.js:1`, Browser) im Baum identifiziert. Hersteller-Advisories und tatsächlich installierte Builds wegen Lokalvorgabe offen |
| Altberichte | Security (lokaler Altbericht `SECURITY-AUDIT-GESAMTBERICHT-Bildungsprogramm.md`) und Codequalität (lokaler Altbericht `CODE-QUALITY-AUDIT-Bildungsprogramm.md`) vom 10.08.2026, `f1dbc9f6694de4e1a54e22e8f3c37b3452e05b2c`, Vorfahr des Zielstands |

## Bedrohungsmodell und Abdeckung

| Ablauf | Erwartete Rolle und Grenze | Statischer Umfang und Ergebnis |
| --- | --- | --- |
| Seminar, Suche, Fahrplan, PDF | Gäste lesen veröffentlichte Inhalte; Entwürfe rollenabhängig | Öffentliche Suche und Vorschläge stichprobenartig (`class-bi-filter.php:789,1649,1743,1905`). PDF prüft bei unveröffentlichten Inhalten `read_post` (`class-bi-pdf.php:445–453`). Fahrplan unterscheidet Status anhand `BI_CAP` (`class-bi-fahrplan.php:1238`). Theme, Medien-Direktzugriff und Browser-Caching offen |
| Anmeldung und Reihe | Gäste dürfen nur gültige Termine buchen; personenbezogene Ausgabe an berechtigte Empfänger | Handler, Wiederanzeige, Kontingente, Speicherung und Versandübergang geprüft. Reihenwahl serverseitig geprüft (`class-bi-registration.php:1114–1123)); siehe SEC-001/002/008 |
| Redaktion und Administration | WordPress-Rollen trennen Inhalts- und Verwaltungsvorgänge | `BI_CAP` umfasst Admin und Redaktion (`igm-bildungsprogramm.php:82–123`). CSV und manuelle Retention verlangen Rolle und Nonce. Site-spezifische Capabilities und fachliche Freigabe für Redaktionen offen |
| PLZ | Gäste dürfen zuständige Geschäftsstelle finden | Öffentlich mit IP-Kontingent und Rückgabe der Mailadresse (`class-bi-plz.php:19–42)); siehe Altstatus SEC-007 |
| Import | Redaktion verwaltet Seminardaten; Staging von öffentlichem Download getrennt | Upload mit Rolle/Nonce; Speicherort und Dateilebenszyklus gesichtet. Serverregel offen, siehe SEC-009 |
| Kampagnen / Cron | Gäste folgen Links; interne Auswertung ohne unnötige Personenverknüpfung | Öffentlicher Klick, Cookie und DB-Schreibpfad gesichtet; siehe SEC-004/012, DS-002 |
| Synchronisation | Quell- und Zentralsites, Partner mit Schlüssel | Routen und Schlüsselabgleich stichprobenartig (`class-bi-sync.php:505–575,1020–1034`); Schlüssel werden auch beim Klopfen geprüft. Vollständige Paket-/Objektberechtigung, Partnerbetrieb und Rotation offen |
| Einbettung | Nur vorgesehene Inhalte und Frame-Ursprünge | Header-/Origin-Regeln gesichtet (`class-bi-embed.php:224–289`); Reverse Proxy, CSP im Browser und interne Aufrufer offen |
| Externe Online-Anmeldung | Übergang zum jeweiligen Anbieter | Linkauswahl im Plugin (`class-bi-online.php:236–278`); externe Verarbeitung und Berechtigungen außerhalb des lokalen Quellstands |

## Security-Befunde und Evidenz

Alle Einstufungen beruhen auf dem Zielcommit. Laufzeitabnahmen sind offen.

### SEC-001 · Öffentliche Buchungskontingente ohne atomare Reservierung · hoch

- **Beobachtung** · Mehrere parallele gültige Einreichungen können dieselben Zählerstände lesen. Das Stundenbudget betrifft Versand, während Buchungszeilen vorher angelegt werden. Betroffen sind öffentliche Anmeldungen mit gültigen Formularvoraussetzungen und die Verfügbarkeit des Betriebs.
- **Evidenz** · `igm-bildungsprogramm.php:633–651` liest und schreibt Transients getrennt. `includes/class-bi-registration.php:1028–1040,1233–1248,1282–1290` verwendet zwei Kontingente und ein separat geführtes Mailbudget.
- **Maßnahme** · Buchungs- und Versandkontingente atomar führen, begrenzte globale Aufnahme vor teurer Verarbeitung einrichten und den Zeitpunkt der Quittierung dokumentieren.
- **Abnahme** · Isoliert mit synthetischen Anmeldungen und Mail-Sink prüfen, dass gleichzeitige erlaubte Vorgänge korrekt gezählt werden und Grenzüberschreitungen ohne Buchung bzw. Versand bleiben, je nach fachlichem Budget.

### SEC-002 · Fehlerzustand per URL-Token abrufbar · mittel

- **Beobachtung** · Die URL enthält einen Zufallstoken. Wer einen gültigen Token in dessen zehnminütiger Lebensdauer erhält, kann den damit adressierten Formularzustand ohne zusätzliche Bindungsprüfung abrufen. Risiko besteht bei persistierten URLs, Telemetrie oder Referer-Weitergabe.
- **Evidenz** · `includes/class-bi-registration.php:955,969–1002`. Der Shortcode setzt im Fehlerzustand No-Cache-Header (um Zeile 369). Wirkung am Proxy offen.
- **Maßnahme** · Fehlerzustand auf Minimalkontext begrenzen, an den Formularvorgang binden und nach Gebrauch entfernen. Token aus Betriebsprotokollen fernhalten.
- **Abnahme** · Eigener Vorgang kann nach Validierungsfehler fortgesetzt werden. Andere Browsersitzung, Wiederverwendung und abgelaufener Zustand liefern keine Eingaben.

### SEC-004 · Öffentliche Klicks erzeugen persistente Ereignisse · mittel

- **Beobachtung** · Für jeden aktiven Kampagnenaufruf außerhalb der Bot-Heuristik wird synchron ein Ereignis gespeichert. Bei öffentlichen Kampagnen erhöht das ohne anwendungsseitiges Schreibbudget DB-Last und verzerrt Auswertungen.
- **Evidenz** · `includes/class-bi-tracking.php:186–218,259–269,307–320`; Bereinigung nach 365 Tagen in `:33,77–83`.
- **Maßnahme** · Kampagnenmessung begrenzen oder aggregieren, Redirect unabhängig von der Aufnahme des Ereignisses halten.
- **Abnahme** · Integrationstest mit fester Uhr und gemessenem Schreiblimit; Redirect funktioniert auch bei erschöpftem Kontingent und bei DB-Fehlern.

### SEC-005 · Digest-Queue verliert Arbeit bei Fehler und Parallelität · mittel

- **Beobachtung** · Die Wochenqueue wird als einzelne Option gelesen und überschrieben. Der Versand leert sie vor dem Mailversuch. Ein Mailfehler kann zu dauerhaftem Verlust führen; konkurrierende Läufe können sich überschreiben oder dieselbe Auswahl abarbeiten. Voraussetzung ist aktivierter Wochenversand.
- **Evidenz** · `includes/class-bi-mailer.php:987–996,1021–1035,1083–1087`.
- **Maßnahme** · Begrenzte persistente Jobs mit atomarer Übernahme, Status, Ablaufdatum und kontrollierten Wiederholungen.
- **Abnahme** · Mail-Sink und künstlicher Zustellfehler; paralleler Producer/Consumer; alte Queue-Einträge unter Erhalt der Empfängerzuordnung übernehmen.

### SEC-008 · Reihenbuchung kann trotz Fehler inkonsistent bleiben · mittel

- **Beobachtung** · Insert-Fehler werden erkannt. Die Reihe verwendet kompensierende Deletes, während Gruppierungsupdate und Deletes ohne Wirkungskontrolle erfolgen. Das Trackingereignis kann entstehen, bevor alle Teilbuchungen erfolgreich sind. Voraussetzung ist ein DB-Fehler oder Prozessabbruch.
- **Evidenz** · `includes/class-bi-registration.php:1247–1289,1342–1359`.
- **Maßnahme** · Gruppierung und Speicherung als zusammenhängende Operation abschließen. Tracking und Mail erst nach dauerhafter Speicherung auslösen. Fehlerstatus ohne personenbezogene Inhalte protokollieren.
- **Abnahme** · DB-Fault-Injection beim ersten/späteren Insert und Gruppenupdate; kein verwaister Teil oder Trackingevent, erneuter Versuch möglich.

### SEC-009 · Import-Staging liegt unter dem Upload-Stamm · niedrig

- **Beobachtung** · Temporäre CSV können bei entsprechend konfiguriertem Webserver statisch ausgeliefert werden; der Code legt das Verzeichnis unter dem Upload-Stamm an. Voraussetzung ist erreichbarer Upload-Pfad und eine verbliebene Datei. Ein produktiver Download wurde nicht geprüft.
- **Evidenz** · `includes/class-bi-import.php:93–99,929–937,1162–1184,1490`.
- **Maßnahme** · Nicht öffentliches Staging mit Ablaufbereinigung für beide Importwege.
- **Abnahme** · Erfolgreicher Import, Abbruch und abgelaufener Lauf; für jede temporäre Datei statische Auslieferung serverseitig verweigert.

### SEC-012 · Kampagnenzuordnung vertraut auf Cookie-Format · mittel

- **Beobachtung** · Tracking akzeptiert clientseitigen Zustand nach Syntaxprüfung. Auswertungen können durch unzuverlässige Zuordnung verfälscht werden. Betroffen ist die Integrität der Statistik.
- **Evidenz** · `includes/class-bi-tracking.php:223–248,271–299`.
- **Maßnahme** · Zuordnung an einen serverseitig ausgestellten Zustand koppeln, Ablauf und aktive Kampagne prüfen.
- **Abnahme** · Gültiger Zustand bleibt messbar; ungültiger und abgelaufener Zustand erzeugt keine neuen Zuordnungen.

### SEC-014 · Sync-Ziel und Transportgrenze sind zu breit · mittel

- **Beobachtung** · Die administrierbare Partner-URL fließt in serverseitige HTTP-Requests mit Schlüsselheader. Die Schreibberechtigung liegt bei `BI_CAP`. Protokoll, erlaubte Ziele, Weiterleitungen und Antwortgröße sollten zusätzlich als Vertrauensgrenze abgesichert sein; Konfiguration und Erreichbarkeit bestimmen die Auswirkung.
- **Evidenz** · `includes/class-bi-sync.php:482–496,2153–2189,2491–2545`.
- **Maßnahme** · Partnerendpunkte explizit freigeben, HTTPS erzwingen, Redirects sperren und Antworten begrenzen. Anforderungen an bewusst interne Partner separat festlegen.
- **Abnahme** · Isoliertes HTTP-Mock erlaubt nur konfigurierte Ziele; unzulässige Zieländerungen und Weiterleitungen werden vor Daten- oder Schlüsselweitergabe abgelehnt.

## Datenschutz

| Daten und Zweck | Speicher / Empfänger und Sichtbarkeit | Frist, Auskunft und Restkopien |
| --- | --- | --- |
| Kontaktdaten, Betrieb, Nachricht und konfigurierte Eingaben für Einzel- und Reihenbuchung | Anmeldetabelle mit JSON und Spalten; BI_CAP-Verwaltung und CSV, je Trigger Mail und Dokumentanhänge | 90 Tage konfigurierbare Vorgabe ab Seminarende mit Fallbacks; Löschung/Anonymisierung in Batches. CSV, Postfächer und Backup bleiben eigene Kopien |
| Eingabewiederherstellung | Transient im WP-Cache oder DB, Formular über URL-Token | TTL 600 s; tatsächliche Entfernung aus Backup/Cache und Referer-Verhalten offen; SEC-002 |
| Mail / Anhänge / Wochenqueue | Konfigurierte Empfänger, Kopien und Reply-To, WordPress-Option, Mail-Transport | Abarbeitung der Queue ist keine durchgängige Löschfrist; Postfach-/Backup-Betreiber und lokale Exportempfänger klären; DS-001 |
| Kampagnenzuordnung | Cookie, Ereignistabelle inklusive Anmeldebezug, BI-Verwaltung | Cookie 30 Tage, Ereignisse 365 Tage mit Cron; Zuordnung/indirekte Erkennbarkeit getrennt bewerten; DS-002 |
| Import und Sync | CSV-Staging und konfigurierte Partner; Umfang der übertragenen Inhalte paketabhängig | Staging-Bereinigung für normale Pfade teilweise vorhanden. Abläufe bei Abbruch, Partner-Aufbewahrung und lokale Kopien offen |

### DS-001 · Löschroutine deckt Anmeldetabelle ab · mittel

- **Beobachtung** · Die eingeführte Löschroutine verarbeitet Anmeldezeilen. Digest-Kontext und Mail-/Exportkopien fallen außerhalb dieser Routine; im Digest wird die erste nichtleere CC-Adresse für die Gruppe verwendet, während Reply-To gesammelt wird. Die Empfängerkonfiguration bestimmt, wer Gruppendaten sieht.
- **Evidenz** · `includes/class-bi-retention.php:92–114,160–233`; `includes/class-bi-settings.php:93`; `includes/class-bi-mailer.php:393–410,528–545,1056–1086`; `includes/class-bi-registration.php:1727–1787`. In den Pluginquellen wurden keine WordPress-Hooks für persönliche Exporte oder Erasure gefunden.
- **Maßnahme** · Zweckgebundene Auskunfts-/Löschroutine über Tabelle, Queue und logische Verknüpfungen festlegen. Berechtigungen, CC/Reply-To, Export- und Postfachfristen fachlich freigeben.
- **Abnahme** · Einen synthetischen Datensatz über alle Speicherorte verfolgen und die Entfernung bzw. dokumentierte Aufbewahrung prüfen. Nachweis zur Backup-Wiederherstellung und zur Empfängermatrix durch den Betrieb.

### DS-002 · Kampagnenbindung überlebt Anonymisierung · mittel

- **Beobachtung** · Die Anonymisierung entfernt direkte Identifikatoren und JSON. Zeit, Seminar und Kampagnenbezug bleiben auswertbar, Trackingereignisse besitzen einen separaten Löschzyklus. Ob eine Kombination in kleinen Gruppen mit weiteren betrieblichen Kopien zur Wiedererkennung ausreicht, ist offen.
- **Evidenz** · `includes/class-bi-retention.php:198–225`, `includes/class-bi-tracking.php:31–33,77–83,259–269,398–409`.
- **Maßnahme** · Verknüpfungen beim Löschen/Anonymisieren lösen oder früh aggregieren. Zweck, Cookie-Einsatz, Freigabezeitpunkt, Rechtsgrundlage, Empfänger und Fristen für die Datenschutzhinweise belegen.
- **Abnahme** · Test mit Einzelfällen in kleiner Gruppe und Vergleich vor/nach dem Job. Datenschutzstelle bestätigt verbleibende Felder, Transparenz und Betroffenenprozess.

## Nachprüfung früherer Empfehlungen

Die Tabelle bewertet die Befundfamilien der Altberichte. Einzelne Spike-Tests, Betriebskonfigurationen und fachfremde Empfehlungen außerhalb der Stichprobe sind **nicht geprüft**. Laufzeit für alle Zeilen ausstehend.

| Alt-ID | Umsetzung im Zielstand | Nachweis / Restfrage |
| --- | --- | --- |
| SEC-001 | teilweise | Honeypot, Zeitfalle, Kontingente, Mailbudget; Atomarität offen, siehe SEC-001 |
| SEC-002 | teilweise | Direkte Formularwerte aus Redirect entfernt; Token-Lebenszyklus siehe SEC-002 |
| SEC-003 | teilweise | Feldbezogene maximale Länge in `class-bi-registration.php:1182`; vollständige Feldabdeckung, Body- und Mengenlimits offen |
| SEC-004 | offen | synchroner Klick-Insert bleibt |
| SEC-005 | offen | Option und Queue-Entleerung vor Versand bleiben |
| SEC-006 | umgesetzt für geprüften Pfad | CSV-Zellen mit üblichen Formelpräfixen behandelt, `class-bi-registration.php:1719–1725,1787`; Excel/LibreOffice-Importverhalten offen |
| SEC-007 | teilweise | PLZ-Endpunkt drosselt auf 60 pro Stunde/IP; öffentliche Rückgabe bleibt. Freigabe des tatsächlich importierten Adressbestands offen |
| SEC-008 | teilweise | Insert-Fehlerpfad vorhanden; Konsistenz der Reihe siehe SEC-008 |
| SEC-009 | offen | Upload-Stamm unverändert; Bereinigungslogik für Teilimport vorhanden |
| SEC-010 | nicht geprüft | Vorschlags-Cache und Seitenlimits sichtbar; ursprüngliche Facettenlast ohne Query-Messung unbewertet |
| SEC-011 | teilweise | planbare Löschung/Anonymisierung der Tabelle; DS-001/002 und Cron-Betrieb offen |
| SEC-012 | offen | unverifizierte Cookie-Zuordnung bleibt |
| SEC-013 | teilweise | Scalar-Adapter `igm-bildungsprogramm.php:600–619`, u. a. öffentliche Suche, PLZ und Anmeldung umgestellt; vollständige Eingangsflächen ungetestet |

## Maßnahmenplan und Abnahme

| Priorität | Befunde | Zuständigkeit | Nachprüfung |
| --- | --- | --- | --- |
| P0 | SEC-001, SEC-008 | Plugin-Entwicklung und Hosting | Atomare Kontingente und vollständige Buchung mit synthetischen Fixtures, DB-Fault-Injection und isolierter Mail-Senke |
| P1 | SEC-005, DS-001/002, SEC-002 | Entwicklung, Mailbetrieb, Datenschutzstelle | Queue- und Löschlauf, Empfängerfreigabe, Token-Ablauf, SMTP/Backup-Prozesse |
| P1 | SEC-004/012, SEC-014 | Entwicklung und Partnerbetrieb | Kampagnenbudget, Integrität der Zuordnung und zugelassene Sync-Grenzen |
| P2 | SEC-009 und offene Betriebsnachweise | Entwicklung/Hosting | Staging-Lebenszyklus, WordPress-Rollenmatrix, Cron-Ausführung, Herstellerhinweise und installierte Versionen |

Die zugehörigen Korrektheits- und Refactoring-Pakete stehen im [Codequalitätsbericht](CODE-QUALITY-AUDIT-bildungsprogramm-20261119.md).
