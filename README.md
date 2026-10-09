# Ochs Mobility v0.6

IP-Symcon-8-Modul für den Smart-Home-Baustein **„Haus verlassen“**.

## Ziel

Das Modul nutzt für Bahnverbindungen ausschließlich die offizielle **DB Timetables / IRIS API**. Es ermittelt direkte Fahrten zwischen zwei Bahnhöfen, überwacht Verspätung, Gleis und Ausfall und berechnet daraus die Zeit zum Hausverlassen.

## Datenquellen

- Bahn: DB Timetables / IRIS
  - `/station/{pattern}` – Bahnhof/EVA auflösen
  - `/plan/{evaNo}/{date}/{hour}` – Sollfahrplan
  - `/fchg/{evaNo}` – aktuelle Änderungen
- Straßenlage: optional MobiData BW

`transport.rest` und Transitous/MOTIS werden nicht mehr verwendet.

## Einschränkung

Die kostenlose DB Timetables API ist keine allgemeine A→B-Routing-API. Deshalb unterstützt v0.6 zunächst **Direktverbindungen**. Das Modul erkennt über den geplanten Fahrweg `ppth`, ob der Zielbahnhof auf der Fahrt liegt.

Sobald RIS::Journeys freigeschaltet ist, kann echtes DB-only-Routing mit Umsteigeverbindungen ergänzt werden.

## Installation / Update

1. Repository im IP-Symcon Module Control aktualisieren.
2. Instanz **Ochs Mobility / Haus verlassen** öffnen.
3. `DB Client ID` und `DB API Key` aus dem DB API Marketplace eintragen.
4. Start- und Zielbahnhof als Namen eintragen.
5. Optional die EVA-Nummern eintragen, z. B. `8001684` für Ehingen (Donau) oder `8000014` für Aulendorf.
6. `DB-Bahnhöfe auflösen` drücken.
7. `Testtermin: Ankunft in 60 Minuten` oder `Jetzt aktualisieren` ausführen.

## Öffentliche Methoden

```php
OMOB_SetTargetArrival($instanceID, $timestamp);
OMOB_ClearTargetArrival($instanceID);
OMOB_ResolveStops($instanceID);
OMOB_ResetJourney($instanceID);
OMOB_Update($instanceID);
```

## Wichtige Variablen

- `TargetArrival`
- `LeaveHomeAt`
- `MinutesToLeave`
- `MobilityStatus`
- `Recommendation`
- `RoutingProvider` – immer `DB Timetables`
- `ProviderDiagnostics`
- `ResolvedFrom`, `ResolvedTo`
- `JourneySummary`
- `JourneyDeparture`, `JourneyArrival`
- `DelayMinutes`
- `Platform`
- `Cancelled`
- `Disruptions`
- `DBTimetablesStatus`, `DBTimetablesMatch`
- `LastUpdate`, `LastError`

## Auswahl einer Direktverbindung

1. Start- und Zielbahnhof werden über `/station` in EVA-Nummern aufgelöst.
2. Für den Startbahnhof werden mehrere Stunden Sollfahrplan geladen.
3. Eine Fahrt gilt als direkt passend, wenn der Zielbahnhof im geplanten Fahrweg `ppth` der Abfahrt vorkommt.
4. `/fchg` ergänzt geänderte Abfahrtszeit, Gleis und Ausfallstatus.
5. Am Zielbahnhof wird anhand Zugkategorie/Zugnummer und Fahrweg die geplante Ankunft gesucht.
6. Daraus berechnet Symcon `LeaveHomeAt` inklusive Fußweg und Sicherheitspuffer.

## Nächster Ausbau

- RIS::Journeys als offizieller DB-Routing-Layer nach Freischaltung
- Umsteigeverbindungen
- Anschlussrisiko
- Kalenderintegration
- Push nur bei relevanten Änderungen
- Kopplung an FamilyAlarmClock
