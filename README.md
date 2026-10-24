# Coach-Pool und Vergabe für Contao 4.9

Lokales Composer-Paket `koboldsoft/coach-pool-bundle`, Namespace `Koboldsoft\CoachPoolBundle`, eingebunden über das Path-Repository des Projekts. Direkte HubSpot-Kontakt-API; keine Make-Webhooks. Der vorhandene Zugang wird ausschließlich über `HUBSPOT_API_KEY` gelesen. Geheimnisse gehören nicht in den Bundle-Quelltext.

## Was geändert wurde

- Die Coach-Karten verwenden fest `pool_kurzbeschreibung`. Das frühere frei konfigurierbare Beschreibungsfeld wird nicht mehr ausgewertet. `weitere_informationen`, Namen, E-Mail und Telefon der anfragenden Person werden weder abgefragt noch angezeigt. Von HubSpot automatisch gelieferte Default-Properties werden vor der Ansicht entfernt.
- Darstellung nach `construct/coach-pool-ansicht-vorschlag.html`: responsive Karten, Förderkennzeichen, gekürzte Beschreibung, Ort/Format, Start, Kontaktweg, Gutscheindatum und Chips für Coachingtyp, Zielgruppen und Branchen. „Alle Angaben“ zeigt die vollständige Kurzbeschreibung und strukturierten Angaben. Es werden keine Testanfragen fest eingebaut. Layoutregeln sind auf `.coach-pool-view` beschränkt; keine zusätzliche globale Bulma-Einbindung.
- Die HubSpot-Suche enthält die vorgegebenen strukturierten Felder und `sorts: [{propertyName: "gutscheingultigkeit", direction: "ASCENDING"}]`, mit 100 Ergebnissen pro Seite und vollständiger Paginierung. Zusätzlich wird das HubSpot-Erstellungsdatum für „eingegangen“ verwendet. Fehlende Gutscheindaten werden lokal nach hinten sortiert. Dieses Erstellungsdatum ist das Kontakt-Erstellungsdatum, kein gesondertes Formulareingangsdatum.
- Neuer Frontend-Modultyp **Coach-Pool: Vergabe** mit Backend-Auswahl einzelner C-Pro-Coaches als Prüfer. Leere Auswahl verweigert allen den Zugriff. Die Vergabeansicht zeigt die interessierten Coaches und ermöglicht die Freigabe eines ausgewählten Coaches.
- Bei Interesse werden `pool_status = interesse_eingegangen` und die Login-E-Mail in `pool_interesse_von` gemeinsam per PATCH gespeichert. Bestehende Interessenten bleiben erhalten; doppelte E-Mail-Adressen werden vermieden. Anschließend entsteht wie bisher eine unzugewiesene HubSpot-TODO-Aufgabe zur Teamprüfung, verknüpft mit Anfrage und Coach.
- Bei Freigabe werden `pool_status = freigegeben` und die ausgewählte Coach-E-Mail in `pool_freigegeben_fuer` gemeinsam per PATCH gespeichert. Danach erscheint die Anfrage nicht mehr in der Coach-Ansicht. Die Vergabeansicht zeigt weiterhin, an wen sie freigegeben ist.
- Das Bundle verschickt selbst keine Mails. Der HubSpot-Workflow muss erst auf die Freigabe reagieren; ein Workflow auf `interesse_eingegangen` darf noch keine Zusage versenden. Ein gemeinsamer PATCH stellt Status und ausgewählten Coach zusammen bereit, konfiguriert aber keinen HubSpot-Workflow.

## Inbetriebnahme nach diesem Update

1. Contao-Datenbankaktualisierung im Installtool ausführen: neue Felder `coachPoolReviewers` und `coachPoolIncludeInterested` in `tl_module` sowie neue Tabelle `tl_coach_pool_assignment`. Das bisherige Interessenjournal bleibt bestehen. Alte Spalten `coachPoolOwner` und `coachPoolDescription` sind absichtlich weiterhin definiert und werden nicht gelöscht.
2. Contao-Anwendungscache erneuern.
3. Bestehendes Modul **Coach-Pool** öffnen, Team **C-Pro / Coaching Pro** prüfen und speichern. Der neue Schalter **Anfragen mit gemeldetem Interesse weiterhin anzeigen** ist standardmäßig aktiv, damit mehrere Coaches Interesse melden können. Deaktiviert entspricht der Filter exakt `offen / zurueck_im_pool`; nach dem ersten Interesse verschwindet die Anfrage dann aus der normalen Ansicht.
4. Neues Frontend-Modul **Coach-Pool: Vergabe** erstellen, dasselbe Team wählen und speichern. Unter **Coaches mit Vergaberecht** die gewünschten Prüfer auswählen. Die Auswahl enthält nur aktuelle Mitglieder dieses Teams; die Mitgliedschaft wird auch zur Laufzeit erneut geprüft.
5. Vergabemodul auf einer eigenen geschützten Seite „Anfragen vergeben“ einbinden. Seiten-Cache ausschalten und die Seite auf die passende Mitgliedergruppe begrenzen. Auch ein direkter POST gewährt ohne serverseitige Prüferberechtigung keine Freigabe. Normale Coaches sehen weder diese Tabelle noch die Interessen anderer Coaches.
6. Die unten genannten HubSpot-Felder und die Private-App-Berechtigungen für Kontakte lesen/schreiben und Aufgabenanlage prüfen. **Bei der rein lesenden Feldprüfung am 23.10.2026 fehlte `pool_freigegeben_fuer` (HTTP 404).** Dieses Kontaktfeld vor der ersten Freigabe als Textfeld anlegen; das Bundle legt keine HubSpot-Felder automatisch an.
7. Den Workflow für den Mailversand nach Freigabe in HubSpot einrichten bzw. prüfen. Eine erste echte Freigabe bewusst mit dem Team testen: Sie kann diesen Workflow auslösen. Lokale Tests erzeugen keine produktiven Meldungen oder Freigaben.

### HubSpot-Feldformat

| Kontaktfeld | Format / Inhalt |
| --- | --- |
| `pool_status` | Auswahl: `offen`, `zurueck_im_pool`, `interesse_eingegangen`, `freigegeben` |
| `pool_kurzbeschreibung` | Redaktionell geprüfter, anonymisierter Beschreibungstext |
| `pool_interesse_von` | Textfeld mit eindeutigen E-Mail-Adressen, durch Semikolon getrennt; z. B. `coach.a@digi.camp;coach.b@digi.camp` |
| `pool_freigegeben_fuer` | Textfeld mit der einzelnen E-Mail-Adresse des freigegebenen Coaches |

Die Coach-E-Mail kommt bei einer Interessenmeldung ausschließlich aus dem eingeloggten `tl_member`-Konto. Vor dem Schreiben muss dazu genau ein HubSpot-Kontakt mit übereinstimmender E-Mail gefunden werden. Die gewählte Vergabeadresse wird serverseitig gegen die aktuellen HubSpot-Interessenten, einen eindeutigen lokalen Mitgliedsdatensatz, dessen C-Pro-Mitgliedschaft und den HubSpot-Kontakt geprüft. Namen statt E-Mail-Adressen oder unbekannte Altwerte im Interessenfeld werden nicht still überschrieben.

Die beiden Freigabefelder müssen für den HubSpot-Workflow dieselbe Bedeutung haben: gespeichert werden **E-Mail-Adressen, keine HubSpot-Kontakt-IDs**. Der API-Token benötigt auch Schreibzugriff auf Kontakte. Bei einem ungültigen Token (HTTP 401) funktioniert weder Abruf noch Speicherung.

## Schutz vor doppelten und parallelen Aktionen

- Alle mutierenden Aktionen erfordern Contao-CSRF-Prüfung und eine aktuelle serverseitige Berechtigungsprüfung. Die normale Kartenansicht und die Vergabeansicht werden privat mit `no-store` ausgeliefert und nicht indexiert.
- Eine MySQL-Sitzungssperre pro HubSpot-Kontakt serialisiert Interesse und Vergabe innerhalb dieser Anwendung. So überschreiben zwei gleichzeitig klickende Coaches nicht die Interessen des anderen. Andere Anwendungen, manuelle HubSpot-Änderungen und HubSpot-Workflows müssen dieselben Felder koordiniert nutzen; die lokale Sperre schützt nicht vor externen Schreibern.
- Das bestehende `tl_coach_pool_interest` verhindert doppelte Aufgaben. `syncing` bedeutet Kontakt-Synchronisierung; ein erneuter Versuch liest den HubSpot-Stand und kann die identische Feldaktualisierung sicher fortsetzen. `sending` bedeutet unbestätigte Aufgabenanlage und wird nie automatisch wiederholt; `received` bedeutet bestätigte Anlage oder bereits in HubSpot vorhandenes Interesse. Bestehende Aufgaben aus früheren Versionen werden nicht erneut angelegt.
- `tl_coach_pool_assignment` enthält Kontakt-ID, Prüfer-ID, gewählte Coach-E-Mail/ID, Zeitstempel und Freigabestatus. Wiederholte Freigabe desselben Coaches führt nicht zu einem zweiten PATCH. Ein anderer Coach kann eine bereits freigegebene Anfrage nicht übernehmen.
- Bei unklarem Freigabe-Timeout bleibt `sending` gesperrt. Erst in HubSpot prüfen, ob die Freigabe bereits erfolgt ist. Erfolgreiche identische Freigaben lassen sich durch erneutes Absenden ohne zweiten PATCH bestätigen. Explizite HTTP-400/401/403/404-Ablehnungen geben den lokalen Versuch wieder frei, damit die Konfiguration korrigiert werden kann.
- Beim erneuten Öffnen derselben bereits vergebenen Kontakt-ID sind die bisherigen Interessen und Journale weiterhin vorhanden. Eine neue Vergaberunde erfordert eine bewusste Bereinigung/Abstimmung dieser alten Daten; das Bundle löscht keine alten Freigaben automatisch. Journal und HubSpot-Felder nicht pauschal löschen.
- Die lokale Berechtigung vermittelt keine HubSpot-Benutzerrechte. Für zusätzliche Prüfung direkt in HubSpot müssen dort die passenden Zugriffsrechte bestehen.

## Validierung

```sh
php tests/coach-pool/run.php
# Optionale, vollständig lokale HTML-Vorschau mit erfundenen Daten:
php tests/coach-pool/run.php --preview
# Ergebnis: /tmp/coach-pool-vorschau.html
```

Die Tests verwenden Fake-DB und Mock-HTTP. Geprüft werden Statusfilter/Sortierung/Paginierung, E-Mail-Abgleich, getrennte Sichtbarkeit, Anhängen mehrerer Interessenten, Wiederholungen, Berechtigungen, Freigabe nur an interessierte aktuelle C-Pro-Coaches, API-Ablehnungen/Timeouts, Geheimnisschutz und das Rendern der echten HTML-Vorlage. Der private Symfony-Logger wird per Dependency Injection verwendet. Die relevanten Abläufe sind im Quelltext kommentiert.

Die HTML-Vorlage wurde zusätzlich lokal im Headless-Browser mit erfundenen Daten geprüft. Produktive Kontakte, Aufgaben, Freigaben und Mail-Workflows wurden während der Implementierung nicht verändert oder ausgelöst.

API-Referenzen:
- https://developers.hubspot.com/docs/api-reference/legacy/crm/objects/contacts/guide
- https://developers.hubspot.com/docs/api-reference/legacy/crm/properties/guide
- https://developers.hubspot.com/docs/api-reference/legacy/crm/activities/tasks/guide

## Installation aus diesem Repository

Das Repository enthält das Composer-Bundle im Wurzelverzeichnis. Die mitgelieferte Datei `files/themes/webapp/css/coach-pool.css` in denselben relativen Pfad des Contao-Projekts kopieren; das Frontend-Modul lädt sie von dort.

Die Tests unter `tests/coach-pool/run.php` sind Integrationstests für die oben beschriebene Projektstruktur: zum Ausführen in denselben Pfad des Contao-Projekts kopieren. Sie verwenden dessen `vendor/autoload.php` und erwarten das Bundle unter `src/Koboldsoft/CoachPoolBundle/coach-pool-bundle`.
