# Ochs Mobility v0.1

IP-Symcon-8-Modul für den Smart-Home-Baustein **„Haus verlassen“**.

## Ziel

Das Modul berechnet aus einer Echtzeit-ÖPNV-Verbindung, wann das Haus verlassen werden sollte. Optional liest es Baustellen/Ereignisse aus MobiData BW und filtert sie in einem Korridor zwischen Start und Ziel.

## Datenquellen v0.1

- ÖPNV-Routing/Echtzeit: `https://v6.db.transport.rest`
- Straßenbaustellen BW: `https://api.mobidata-bw.de/datasets/traffic/roadworks/roadworks_geojson.json`
- DB RIS ist als bevorzugte offizielle Echtzeit-/Störungsschicht für eine Folgeversion vorgesehen; v0.1 kapselt die Routensuche bewusst, damit der Provider später austauschbar bleibt.

## Installation

1. Repository im IP-Symcon Module Control hinzufügen.
2. Instanz **Ochs Mobility / Haus verlassen** anlegen.
3. Start- und Ziel-Haltestellen-ID eintragen.
4. Wegzeit vom Haus zur Haltestelle und Sicherheitspuffer setzen.
5. Optional Start-/Zielkoordinaten für den Straßenkorridor eintragen.
6. `Jetzt aktualisieren` ausführen.

## Öffentliche Modulmethoden

```php
OMOB_SetTargetArrival($instanceID, $timestamp);
OMOB_ClearTargetArrival($instanceID);
OMOB_Update($instanceID);
```

Damit kann später der Familienkalender oder das Wecker-Modul eine gewünschte Ankunftszeit vorgeben.

## Wichtige Variablen

- `TargetArrival` – gewünschte Ankunft
- `LeaveHomeAt` – berechnete Zeit zum Hausverlassen
- `MinutesToLeave` – Minuten bis zum Losgehen
- `MobilityStatus` – OK / BALD LOS / JETZT LOS / VERSPÄTET / AUSFALL / ZU SPÄT
- `Recommendation` – verständliche Handlungsempfehlung
- `JourneySummary`, `JourneyDeparture`, `JourneyArrival`, `DelayMinutes`, `Platform`, `Cancelled`, `Disruptions`
- `RoadworksCount`, `RoadworksSummary`

## Architektur

Die Entscheidungslogik ist von den Providern getrennt. Das ist wichtig, weil die Routensuche (A→B) und offizielle Betriebs-/Störungsinformationen nicht zwingend aus derselben API kommen. In einer Folgeversion können DB RIS Boards/Journeys/Disruptions sowie weitere Straßen-/Reisezeitprovider ergänzt werden, ohne die Symcon-Variablen und die Hauslogik zu ändern.

## v0.2 vorgesehen

- Haltestellensuche direkt im Konfigurationsformular
- Journey-Refresh statt kompletter Neuberechnung
- DB RIS als ergänzende offizielle Störungsschicht
- Straßen-Verkehrszeit statt nur Baustellen-Korridor
- Kalenderadapter
- Kopplung an FamilyAlarmClock
- Push nur bei relevanter Änderung der `LeaveHomeAt`-Zeit oder bei Ausfall
- Übergabe an Hausassistent / Decision Log
