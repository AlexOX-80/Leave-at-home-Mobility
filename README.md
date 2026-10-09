# Ochs Mobility v0.2

IP-Symcon-8-Modul für den Smart-Home-Baustein **„Haus verlassen“**.

## Ziel

Das Modul berechnet aus einer Echtzeit-ÖPNV-Verbindung, wann das Haus verlassen werden sollte. Optional liest es Baustellen/Ereignisse aus MobiData BW und filtert sie in einem Korridor zwischen Start und Ziel.

## Datenquellen

- ÖPNV-Routing/Echtzeit: `https://v6.db.transport.rest`
- Haltestellensuche: `GET /locations`
- Aktualisierung einer einmal gewählten Verbindung: `GET /journeys/:ref` über den `refreshToken`
- Straßenbaustellen BW: `https://api.mobidata-bw.de/datasets/traffic/roadworks/roadworks_geojson.json`
- DB RIS bleibt als ergänzende offizielle Echtzeit-/Störungsschicht für eine Folgeversion vorgesehen.

## Neu in v0.2

- Start und Ziel können als **Haltestellenname** angegeben werden, z. B. `Ehingen (Donau)` und `Ulm Hbf`.
- Die dazugehörigen IDs werden automatisch über `/locations` aufgelöst und gecacht.
- IDs können weiterhin optional als manueller Override gesetzt werden.
- Sobald eine Verbindung gewählt wurde, wird sie über ihren `refreshToken` weiterverfolgt.
- Verspätung, Gleisänderung oder Ausfall führen deshalb nicht automatisch dazu, dass die Verbindung durch eine andere ersetzt wird.
- Erst bei einem neuen Zieltermin oder nach manuellem Reset wird neu geroutet.
- Neue Statusvariable `JourneyTracked` zeigt an, ob die Verbindung stabil verfolgt wird.

## Installation

1. Repository im IP-Symcon Module Control hinzufügen.
2. Instanz **Ochs Mobility / Haus verlassen** anlegen.
3. Start- und Ziel-Haltestelle als Namen eintragen.
4. Optional IDs eintragen; sie überschreiben die automatische Namensauflösung.
5. Wegzeit vom Haus zur Haltestelle und Sicherheitspuffer setzen.
6. Optional Start-/Zielkoordinaten für den Straßenkorridor eintragen.
7. `Haltestellen jetzt auflösen` drücken und Ergebnisvariablen prüfen.
8. `Jetzt aktualisieren` ausführen.

## Öffentliche Modulmethoden

```php
OMOB_SetTargetArrival($instanceID, $timestamp);
OMOB_ClearTargetArrival($instanceID);
OMOB_ResolveStops($instanceID);
OMOB_ResetJourney($instanceID);
OMOB_Update($instanceID);
```

Damit kann später der Familienkalender oder das Wecker-Modul eine gewünschte Ankunftszeit vorgeben.

## Wichtige Variablen

- `TargetArrival` – gewünschte Ankunft
- `LeaveHomeAt` – berechnete Zeit zum Hausverlassen
- `MinutesToLeave` – Minuten bis zum Losgehen
- `MobilityStatus` – OK / BALD LOS / JETZT LOS / VERSPÄTET / AUSFALL / ZU SPÄT
- `Recommendation` – verständliche Handlungsempfehlung
- `ResolvedFrom`, `ResolvedTo` – tatsächlich verwendete Haltestelle inklusive ID
- `JourneySummary`, `JourneyDeparture`, `JourneyArrival`, `DelayMinutes`, `Platform`, `Cancelled`, `Disruptions`
- `JourneyTracked` – zeigt, ob der bestehende Reiseplan per Refresh weiterverfolgt wird
- `RoadworksCount`, `RoadworksSummary`

## Logik zur Verbindungsauswahl

Bei einem neuen Zieltermin sucht das Modul mehrere passende Verbindungen und wählt bevorzugt die späteste nicht ausgefallene Verbindung, die noch vor der gewünschten Ankunftszeit ankommt. Der `refreshToken` dieser Verbindung wird gespeichert.

Bei den folgenden Aktualisierungen wird nicht erneut frei geroutet, sondern exakt diese Verbindung aktualisiert. So bleiben echte Änderungen sichtbar:

- Zug wird verspätet → `DelayMinutes` und `LeaveHomeAt` ändern sich.
- Gleis ändert sich → `Platform` ändert sich.
- Verbindung fällt aus → `Cancelled = true`, Status `AUSFALL`.

Mit `OMOB_ResetJourney()` kann bewusst eine neue Verbindungsauswahl erzwungen werden.

## Architektur

Die Entscheidungslogik ist von den Providern getrennt. Routensuche, Betriebsinformationen und Straßenlage können daher später aus unterschiedlichen Quellen stammen. Das Modul bleibt die Symcon-Schnittstelle für den Hauszustand **„Wann muss ich los?“**.

## Nächste Schritte

- DB RIS als zusätzliche offizielle Störungsschicht
- echte Straßen-Reisezeit statt nur Baustellen-Korridor
- Kalenderadapter
- Kopplung an FamilyAlarmClock
- Push nur bei relevanter Änderung der `LeaveHomeAt`-Zeit, Gleisänderung oder Ausfall
- Übergabe an Hausassistent / Decision Log
