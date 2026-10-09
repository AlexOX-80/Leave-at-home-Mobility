# Ochs Mobility v0.4

IP-Symcon-8-Modul für den Smart-Home-Baustein **„Haus verlassen“**.

## Ziel

Das Modul berechnet aus einer Echtzeit-ÖPNV-Verbindung, wann das Haus verlassen werden sollte. Es verwendet mehrere Datenquellen so, dass ein temporärer Ausfall eines Providers nicht sofort den gesamten Ablauf lahmlegt.

## Datenquellen und Priorität

1. `transport.rest` – primäres Routing und Journey-Refresh.
2. `Transitous / MOTIS` – Fallback-Routing bei Fehlern des primären Providers.
3. `DB Timetables / IRIS` – optionale offizielle Verifikation für Zugabfahrt, Verspätung, Gleis und Ausfall.
4. `MobiData BW` – Straßenmeldungen in Baden-Württemberg.

## Neu in v0.4

- automatische Wiederholungsversuche bei HTTP `429`, `502`, `503` und `504`
- Haltestellensuche bevorzugt über den statischen `/stations`-Index von transport.rest
- Transitous/MOTIS als optionaler Routing-Fallback
- letzte gültige Verbindung wird gecacht
- wenn beide Routing-Provider vorübergehend ausfallen, bleibt die letzte Verbindung sichtbar und der Status wechselt auf **DATEN VERALTET**
- neue Variablen `RoutingProvider` und `DataStale`
- konfigurierbare Cache-Gültigkeit über `StaleCacheMinutes`
- DB Timetables bleibt fail-open

## Warum Transitous nur Fallback ist

Transitous ist ein communitybetriebener Dienst für freie, offene und nicht-kommerzielle Anwendungen. Das Modul nutzt ihn daher nur bei Bedarf und mit einem identifizierbaren User-Agent. Für dauerhaft höhere Last wäre eine eigene MOTIS-Instanz die robustere Variante.

## Installation / Update

1. Repository im IP-Symcon Module Control aktualisieren.
2. Instanz **Ochs Mobility / Haus verlassen** öffnen.
3. Start und Ziel eintragen; Stations-IDs können optional direkt gesetzt werden.
4. Unter **Provider / Ausfallsicherheit** Transitous-Fallback aktiv lassen.
5. Cache-Gültigkeit festlegen, Standard 120 Minuten.
6. Optional DB Timetables konfigurieren.
7. `Haltestellen jetzt auflösen` ausführen.
8. `Testtermin: Ankunft in 60 Minuten` oder `Jetzt aktualisieren` verwenden.

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
- `RoutingProvider` – `transport.rest`, `Transitous` oder `Cache`
- `DataStale` – zeigt an, dass nur noch die letzte gültige Verbindung verwendet wird
- `ResolvedFrom`, `ResolvedTo`
- `JourneySummary`, `JourneyDeparture`, `JourneyArrival`
- `DelayMinutes`, `Platform`, `Cancelled`, `Disruptions`
- `JourneyTracked`
- `DBTimetablesStatus`, `DBTimetablesMatch`, `DBTimetablesDelay`, `DBTimetablesPlatform`, `DBTimetablesCancelled`
- `RoadworksCount`, `RoadworksSummary`
- `LastUpdate`, `LastError`

## Provider-Logik

```text
transport.rest
   │
   ├─ erfolgreich → Verbindung verwenden / Journey weiterverfolgen
   │
   └─ Fehler → Retry
          │
          ├─ erfolgreich → weiter
          │
          └─ weiterhin Fehler → Transitous/MOTIS
                                  │
                                  ├─ erfolgreich → Fallback-Verbindung
                                  │
                                  └─ Fehler → letzter gültiger Cache
                                               └─ Status DATEN VERALTET
```

DB Timetables läuft davon unabhängig als zusätzliche Verifikationsschicht. Ein Fehler bei DB Timetables blockiert das Routing nicht.

## Architektur

Symcon bleibt die operative Schicht für die Frage **„Wann muss ich das Haus verlassen?“**. Die Provider liefern Daten, aber die Hauslogik entscheidet selbst über Status, Puffer, Abfahrtszeit und spätere Benachrichtigungen.
