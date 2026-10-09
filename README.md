# Ochs Mobility v0.3

IP-Symcon-8-Modul für den Smart-Home-Baustein **„Haus verlassen“**.

## Ziel

Das Modul berechnet aus einer Echtzeit-ÖPNV-Verbindung, wann das Haus verlassen werden sollte. Die Routensuche und stabile Journey-Verfolgung laufen über `transport.rest`. Optional wird die gewählte Zugabfahrt zusätzlich gegen die **offizielle DB Timetables / IRIS API** geprüft. Straßenbaustellen in Baden-Württemberg können ergänzend über MobiData BW einbezogen werden.

## Datenquellen

- ÖPNV-Routing/Echtzeit: `https://v6.db.transport.rest`
- Haltestellensuche: `GET /locations`
- Aktualisierung einer einmal gewählten Verbindung: `GET /journeys/:ref` über den `refreshToken`
- Offizielle DB-Abfahrtsdaten: DB Timetables / IRIS (`/station`, `/plan`, `/fchg`)
- Straßenbaustellen BW: MobiData BW
- DB RIS bleibt optional für spätere zusätzliche Störungsinformationen.

## Neu in v0.3

- **DB Timetables** als kostenlose offizielle Verifikationsschicht.
- Aktivierung optional über `EnableDBTimetables`.
- Zugang über `DB-Client-Id` und `DB-Api-Key` aus dem DB API Marketplace.
- EVA-Nummer des Startbahnhofs kann automatisch über `/station/{pattern}` ermittelt werden.
- Alternativ kann eine EVA-Nummer manuell vorgegeben werden.
- Die gewählte Verbindung wird gegen den Sollfahrplan `/plan/{evaNo}/{date}/{hour}` gematcht.
- Echtzeitänderungen werden über `/fchg/{evaNo}` eingelesen.
- Offizielle DB-Abfahrtszeit, Verspätung, Gleis und Ausfall können die Werte der Journey-Verbindung ergänzen bzw. überschreiben.
- **Fail-open:** Ist DB Timetables nicht aktiviert, fehlen Credentials oder ist die DB API vorübergehend nicht erreichbar, läuft die bestehende transport.rest-Verbindung weiter.
- Neue Variablen: `DBTimetablesStatus`, `DBTimetablesMatch`, `DBTimetablesDelay`, `DBTimetablesPlatform`, `DBTimetablesCancelled`.

## Installation

1. Repository im IP-Symcon Module Control hinzufügen bzw. aktualisieren.
2. Instanz **Ochs Mobility / Haus verlassen** anlegen.
3. Start- und Ziel-Haltestelle als Namen eintragen.
4. Wegzeit vom Haus zur Haltestelle und Sicherheitspuffer setzen.
5. Optional **DB Timetables** im DB API Marketplace kostenlos abonnieren.
6. `DB Client ID` und `DB API Key` eintragen und `DB Timetables aktivieren` einschalten.
7. EVA-Nummer normalerweise leer lassen; das Modul versucht sie automatisch zu bestimmen. Bei Mehrdeutigkeiten kann sie manuell gesetzt werden.
8. `Haltestellen jetzt auflösen` drücken.
9. `Jetzt aktualisieren` ausführen und die `DBTimetables*`-Variablen prüfen.

## Öffentliche Modulmethoden

```php
OMOB_SetTargetArrival($instanceID, $timestamp);
OMOB_ClearTargetArrival($instanceID);
OMOB_ResolveStops($instanceID);
OMOB_ResetJourney($instanceID);
OMOB_Update($instanceID);
```

## Wichtige Variablen

- `TargetArrival` – gewünschte Ankunft
- `LeaveHomeAt` – berechnete Zeit zum Hausverlassen
- `MinutesToLeave` – Minuten bis zum Losgehen
- `MobilityStatus` – OK / BALD LOS / JETZT LOS / VERSPÄTET / AUSFALL / ZU SPÄT
- `Recommendation` – verständliche Handlungsempfehlung
- `ResolvedFrom`, `ResolvedTo` – tatsächlich verwendete Haltestellen
- `JourneySummary`, `JourneyDeparture`, `JourneyArrival`, `DelayMinutes`, `Platform`, `Cancelled`, `Disruptions`
- `JourneyTracked` – bestehender Reiseplan wird per Refresh weiterverfolgt
- `DBTimetablesStatus` – Status der offiziellen DB-Verifikation
- `DBTimetablesMatch` – gematchte DB-Fahrt inklusive EVA
- `DBTimetablesDelay` – offizielle Verspätung in Minuten
- `DBTimetablesPlatform` – offizielles aktuelles Gleis
- `DBTimetablesCancelled` – offizieller Ausfallstatus
- `RoadworksCount`, `RoadworksSummary`

## Provider-Logik

### 1. Verbindung auswählen

Bei einem neuen Zieltermin sucht `transport.rest` mehrere Verbindungen. Bevorzugt wird die späteste nicht ausgefallene Verbindung, die noch vor der gewünschten Zielzeit ankommt.

### 2. Verbindung stabil verfolgen

Der `refreshToken` wird gespeichert. Weitere Updates verfolgen dieselbe Verbindung, statt bei jeder Verspätung neu zu routen.

### 3. DB Timetables verifizieren

Ist DB Timetables aktiv:

1. Startbahnhof → EVA-Nummer bestimmen.
2. Passenden Sollfahrplan rund um die geplante Abfahrt laden.
3. Fahrt anhand Uhrzeit und Zugnummer matchen.
4. `/fchg/{evaNo}` nach Änderungen für exakt diesen Halt durchsuchen.
5. Geänderte Abfahrtszeit, Gleis und Ausfallstatus in die Haus-verlassen-Entscheidung übernehmen.

Damit bleibt das Routing unabhängig von der DB-API, während die konkreten Bahn-Echtzeitdaten möglichst aus einer offiziellen Quelle kommen.

## Architektur

Die Entscheidungslogik bleibt von den Providern getrennt:

`transport.rest → Routing + Journey-Tracking`

`DB Timetables → offizielle Zug-Echtzeitverifikation`

`MobiData BW → Straßenereignisse`

`DB RIS → später optional zusätzliche Störungs-/Reisendeninformation`

Symcon bleibt die operative Schicht für die Frage **„Wann muss ich das Haus verlassen?“**.

## Nächste Schritte

- Timetables-Matching im Realbetrieb mit mehreren Regional-/Fernverkehrsfällen testen und verfeinern
- Anschlussgefährdung aus mehreren Legs ableiten
- echte Straßen-Reisezeit ergänzen
- Kalenderadapter
- Kopplung an FamilyAlarmClock
- Push nur bei relevanter Änderung von `LeaveHomeAt`, Gleis oder Ausfall
- Übergabe an Hausassistent / Decision Log
