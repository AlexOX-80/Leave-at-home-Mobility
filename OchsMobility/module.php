<?php

declare(strict_types=1);

class OchsMobility extends IPSModule
{
    private const TRANSPORT_BASE = 'https://v6.db.transport.rest';
    private const TRANSITOUS_BASE = 'https://api.transitous.org/api/v6';
    private const DB_TIMETABLES_BASE = 'https://apis.deutschebahn.com/db-api-marketplace/apis/timetables/v1';
    private const MOBIDATA_ROADWORKS = 'https://api.mobidata-bw.de/datasets/traffic/roadworks/roadworks_geojson.json';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('FromStopName', '');
        $this->RegisterPropertyString('ToStopName', '');
        $this->RegisterPropertyString('FromStopId', '');
        $this->RegisterPropertyString('ToStopId', '');
        $this->RegisterPropertyInteger('WalkToStopMinutes', 8);
        $this->RegisterPropertyInteger('SafetyBufferMinutes', 5);
        $this->RegisterPropertyInteger('DefaultArrivalLeadMinutes', 60);
        $this->RegisterPropertyInteger('UpdateIntervalSeconds', 120);
        $this->RegisterPropertyBoolean('EnableTransitousFallback', true);
        $this->RegisterPropertyInteger('StaleCacheMinutes', 120);

        $this->RegisterPropertyBoolean('EnableDBTimetables', false);
        $this->RegisterPropertyString('DBClientId', '');
        $this->RegisterPropertyString('DBApiKey', '');
        $this->RegisterPropertyString('DBEvaNumber', '');

        $this->RegisterPropertyBoolean('EnableRoadworks', true);
        $this->RegisterPropertyFloat('HomeLatitude', 0.0);
        $this->RegisterPropertyFloat('HomeLongitude', 0.0);
        $this->RegisterPropertyFloat('DestinationLatitude', 0.0);
        $this->RegisterPropertyFloat('DestinationLongitude', 0.0);
        $this->RegisterPropertyInteger('RoadCorridorKm', 5);

        $this->RegisterAttributeInteger('TargetArrival', 0);
        $this->RegisterAttributeString('JourneyRefreshToken', '');
        $this->RegisterAttributeString('ResolvedFromStopId', '');
        $this->RegisterAttributeString('ResolvedToStopId', '');
        $this->RegisterAttributeString('ResolvedFromStopName', '');
        $this->RegisterAttributeString('ResolvedToStopName', '');
        $this->RegisterAttributeString('ResolutionSignature', '');
        $this->RegisterAttributeString('ResolvedDBEva', '');
        $this->RegisterAttributeString('FromCoordinates', '');
        $this->RegisterAttributeString('ToCoordinates', '');
        $this->RegisterAttributeString('CachedJourney', '');
        $this->RegisterAttributeInteger('CachedJourneyAt', 0);

        $this->RegisterVariableInteger('TargetArrival', 'Gewünschte Ankunft', '~UnixTimestamp', 10);
        $this->RegisterVariableInteger('LeaveHomeAt', 'Haus verlassen', '~UnixTimestamp', 20);
        $this->RegisterVariableInteger('MinutesToLeave', 'Noch bis Abfahrt', '', 30);
        $this->RegisterVariableString('MobilityStatus', 'Mobilitätsstatus', '', 40);
        $this->RegisterVariableString('Recommendation', 'Empfehlung', '', 50);
        $this->RegisterVariableString('RoutingProvider', 'Routing-Provider', '', 60);
        $this->RegisterVariableBoolean('DataStale', 'Daten veraltet', '~Switch', 70);

        $this->RegisterVariableString('ResolvedFrom', 'Start-Haltestelle', '', 80);
        $this->RegisterVariableString('ResolvedTo', 'Ziel-Haltestelle', '', 90);
        $this->RegisterVariableString('JourneySummary', 'Verbindung', '', 100);
        $this->RegisterVariableInteger('JourneyDeparture', 'Abfahrt Verbindung', '~UnixTimestamp', 110);
        $this->RegisterVariableInteger('JourneyArrival', 'Ankunft Verbindung', '~UnixTimestamp', 120);
        $this->RegisterVariableInteger('DelayMinutes', 'Verspätung', '', 130);
        $this->RegisterVariableString('Platform', 'Gleis / Steig', '', 140);
        $this->RegisterVariableBoolean('Cancelled', 'Verbindung ausgefallen', '~Switch', 150);
        $this->RegisterVariableString('Disruptions', 'Hinweise / Störungen', '', 160);
        $this->RegisterVariableBoolean('JourneyTracked', 'Gewählte Verbindung wird verfolgt', '~Switch', 170);

        $this->RegisterVariableString('DBTimetablesStatus', 'DB Timetables Status', '', 180);
        $this->RegisterVariableString('DBTimetablesMatch', 'DB Timetables Treffer', '', 181);
        $this->RegisterVariableInteger('DBTimetablesDelay', 'DB Verspätung', '', 182);
        $this->RegisterVariableString('DBTimetablesPlatform', 'DB Gleis', '', 183);
        $this->RegisterVariableBoolean('DBTimetablesCancelled', 'DB Ausfall', '~Switch', 184);

        $this->RegisterVariableInteger('RoadworksCount', 'Straßenstörungen im Korridor', '', 200);
        $this->RegisterVariableString('RoadworksSummary', 'Straßenlage', '', 210);
        $this->RegisterVariableInteger('LastUpdate', 'Letzte Aktualisierung', '~UnixTimestamp', 300);
        $this->RegisterVariableString('LastError', 'Letzter Fehler', '', 310);

        $this->RegisterTimer('UpdateTimer', 0, 'OMOB_Update($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetTimerInterval('UpdateTimer', max(60, $this->ReadPropertyInteger('UpdateIntervalSeconds')) * 1000);
        $signature = $this->ConfigurationSignature();
        if ($signature !== $this->ReadAttributeString('ResolutionSignature')) {
            $this->ClearResolvedStops();
            $this->ClearTrackedJourney();
            $this->WriteAttributeString('ResolvedDBEva', '');
            $this->WriteAttributeString('ResolutionSignature', $signature);
        }
        $this->SetStatus($this->HasStopConfiguration() ? 102 : 201);
    }

    public function SetTargetArrival(int $timestamp): void
    {
        $timestamp = max(0, $timestamp);
        if ($timestamp !== $this->ReadAttributeInteger('TargetArrival')) {
            $this->ClearTrackedJourney();
        }
        $this->WriteAttributeInteger('TargetArrival', $timestamp);
        $this->SetValue('TargetArrival', $timestamp);
    }

    public function ClearTargetArrival(): void
    {
        $this->SetTargetArrival(0);
    }

    public function ResetJourney(): void
    {
        $this->ClearTrackedJourney();
        $this->SetValue('Recommendation', 'Verbindungsauswahl wurde zurückgesetzt');
    }

    public function ResolveStops(): bool
    {
        try {
            [$from, $to] = $this->ResolveConfiguredStops(true);
            $this->SetValue('LastError', '');
            $this->SetStatus(102);
            return $from !== '' && $to !== '';
        } catch (Throwable $e) {
            $this->SetValue('LastError', $e->getMessage());
            $this->SetStatus(202);
            return false;
        }
    }

    public function Update(): bool
    {
        if (!$this->HasStopConfiguration()) {
            $this->SetValue('LastError', 'Start- oder Ziel-Haltestelle fehlt.');
            $this->SetStatus(201);
            return false;
        }

        $target = $this->ReadAttributeInteger('TargetArrival');
        if ($target <= time()) {
            $target = time() + max(15, $this->ReadPropertyInteger('DefaultArrivalLeadMinutes')) * 60;
            $this->WriteAttributeInteger('TargetArrival', $target);
            $this->SetValue('TargetArrival', $target);
            $this->ClearTrackedJourney();
        }

        try {
            [$from, $to] = $this->ResolveConfiguredStops(false);
            $journey = $this->FetchResilientJourney($from, $to, $target);
            $official = null;

            if ($this->ReadPropertyBoolean('EnableDBTimetables') && $this->HasDBCredentials() && ($journey['rawTransport'] ?? null) !== null) {
                try {
                    $official = $this->VerifyWithDBTimetables($journey['rawTransport']);
                    $this->SetValue('DBTimetablesStatus', $official === null ? 'Kein passender DB-Treffer' : 'Offiziell verifiziert');
                } catch (Throwable $e) {
                    $this->SetValue('DBTimetablesStatus', 'Fehler: ' . $e->getMessage());
                }
            } elseif (!$this->ReadPropertyBoolean('EnableDBTimetables')) {
                $this->SetValue('DBTimetablesStatus', 'Deaktiviert');
            }

            $this->ApplyNormalizedJourney($journey, $target, $official);

            try {
                if ($this->ReadPropertyBoolean('EnableRoadworks')) {
                    $this->UpdateRoadworks();
                }
            } catch (Throwable $e) {
                $this->SetValue('RoadworksSummary', 'Straßenlage derzeit nicht verfügbar');
            }

            $this->SetValue('LastUpdate', time());
            $this->SetStatus(102);
            return true;
        } catch (Throwable $e) {
            $cached = $this->LoadCachedJourney();
            if ($cached !== null) {
                $this->SetValue('LastError', $e->getMessage());
                $this->SetValue('DataStale', true);
                $this->SetValue('RoutingProvider', 'Cache');
                $this->ApplyNormalizedJourney($cached, $target, null, true);
                $this->SetStatus(102);
                return true;
            }
            $this->SetValue('LastError', $e->getMessage());
            $this->SetValue('MobilityStatus', 'DATENFEHLER');
            $this->SetStatus(202);
            return false;
        }
    }

    private function FetchResilientJourney(string $from, string $to, int $target): array
    {
        $errors = [];
        try {
            $refreshToken = $this->ReadAttributeString('JourneyRefreshToken');
            $raw = $refreshToken !== '' ? $this->RefreshJourney($refreshToken) : $this->FetchTransportJourney($from, $to, $target);
            $journey = $this->NormalizeTransportJourney($raw);
            $this->SetValue('RoutingProvider', 'transport.rest');
            $this->SetValue('DataStale', false);
            $this->SaveCachedJourney($journey);
            return $journey;
        } catch (Throwable $e) {
            $errors[] = 'transport.rest: ' . $e->getMessage();
            $this->ClearTrackedJourney();
        }

        if ($this->ReadPropertyBoolean('EnableTransitousFallback')) {
            try {
                $journey = $this->FetchTransitousJourney($target);
                $this->SetValue('RoutingProvider', 'Transitous');
                $this->SetValue('DataStale', false);
                $this->SaveCachedJourney($journey);
                return $journey;
            } catch (Throwable $e) {
                $errors[] = 'Transitous: ' . $e->getMessage();
            }
        }

        throw new RuntimeException(implode(' | ', $errors));
    }

    private function FetchTransportJourney(string $from, string $to, int $arrivalTs): array
    {
        $query = http_build_query([
            'from' => $from,
            'to' => $to,
            'arrival' => date(DATE_ATOM, $arrivalTs),
            'results' => 4,
            'stopovers' => 'false',
            'remarks' => 'true',
            'language' => 'de',
            'profile' => 'dbnav',
            'pretty' => 'false'
        ]);
        $data = $this->HttpJson(self::TRANSPORT_BASE . '/journeys?' . $query, true);
        $journeys = $data['journeys'] ?? [];
        if (!is_array($journeys) || count($journeys) === 0) {
            throw new RuntimeException('Keine ÖPNV-Verbindung gefunden.');
        }
        $best = null;
        $bestArrival = 0;
        foreach ($journeys as $candidate) {
            $times = $this->JourneyTimes($candidate);
            if ($times['departure'] > 0 && $times['arrival'] <= $arrivalTs && $times['arrival'] >= $bestArrival && !$this->JourneyCancelled($candidate)) {
                $best = $candidate;
                $bestArrival = $times['arrival'];
            }
        }
        return $best ?? $journeys[0];
    }

    private function RefreshJourney(string $token): array
    {
        $data = $this->HttpJson(self::TRANSPORT_BASE . '/journeys/' . rawurlencode($token) . '?remarks=true&language=de&profile=dbnav&pretty=false', true);
        $journey = $data['journey'] ?? $data;
        if (!is_array($journey) || !isset($journey['legs'])) {
            throw new RuntimeException('Journey-Refresh nicht verwertbar.');
        }
        return $journey;
    }

    private function NormalizeTransportJourney(array $journey): array
    {
        $times = $this->JourneyTimes($journey);
        $summary = [];
        $remarks = [];
        $delay = 0;
        $platform = '';
        foreach (($journey['legs'] ?? []) as $leg) {
            if (($leg['walking'] ?? false) === true) {
                continue;
            }
            $name = trim((string) ($leg['line']['name'] ?? $leg['line']['fahrtNr'] ?? ''));
            if ($name !== '') $summary[] = $name;
            $platform = $platform !== '' ? $platform : (string) ($leg['departurePlatform'] ?? $leg['plannedDeparturePlatform'] ?? '');
            foreach (['departureDelay', 'arrivalDelay'] as $key) {
                if (is_numeric($leg[$key] ?? null)) $delay = max($delay, (int) round(((int) $leg[$key]) / 60));
            }
            foreach (($leg['remarks'] ?? []) as $remark) {
                $text = trim((string) ($remark['text'] ?? $remark['summary'] ?? ''));
                if ($text !== '') $remarks[] = $text;
            }
        }
        $token = (string) ($journey['refreshToken'] ?? '');
        if ($token !== '') $this->WriteAttributeString('JourneyRefreshToken', $token);
        return [
            'source' => 'transport.rest',
            'departure' => $times['departure'],
            'arrival' => $times['arrival'],
            'delay' => $delay,
            'platform' => $platform,
            'cancelled' => $this->JourneyCancelled($journey),
            'summary' => implode(' → ', array_values(array_unique($summary))),
            'remarks' => implode(' | ', array_slice(array_values(array_unique($remarks)), 0, 8)),
            'tracked' => $token !== '',
            'rawTransport' => $journey
        ];
    }

    private function FetchTransitousJourney(int $arrivalTs): array
    {
        $from = $this->ReadCoordinates('FromCoordinates');
        $to = $this->ReadCoordinates('ToCoordinates');
        if ($from === null || $to === null) {
            throw new RuntimeException('Koordinaten der Haltestellen fehlen.');
        }
        $query = http_build_query([
            'fromPlace' => $from[0] . ',' . $from[1],
            'toPlace' => $to[0] . ',' . $to[1],
            'time' => date(DATE_ATOM, $arrivalTs),
            'arriveBy' => 'true',
            'transitModes' => 'TRANSIT',
            'detailedLegs' => 'false'
        ]);
        $data = $this->HttpJson(self::TRANSITOUS_BASE . '/plan?' . $query, true, 'OchsMobility/0.4 https://github.com/AlexOX-80/Leave-at-home-Mobility');
        $items = $data['itineraries'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            throw new RuntimeException('Keine Transitous-Verbindung gefunden.');
        }
        $it = $items[0];
        $summary = [];
        foreach (($it['legs'] ?? []) as $leg) {
            $name = trim((string) ($leg['routeShortName'] ?? $leg['routeLongName'] ?? $leg['mode'] ?? ''));
            if ($name !== '') $summary[] = $name;
        }
        return [
            'source' => 'Transitous',
            'departure' => $this->IsoToTs((string) ($it['startTime'] ?? '')),
            'arrival' => $this->IsoToTs((string) ($it['endTime'] ?? '')),
            'delay' => 0,
            'platform' => '',
            'cancelled' => false,
            'summary' => implode(' → ', array_values(array_unique($summary))),
            'remarks' => 'Fallback-Routing über Transitous/MOTIS',
            'tracked' => false,
            'rawTransport' => null
        ];
    }

    private function ApplyNormalizedJourney(array $journey, int $targetArrival, ?array $official, bool $stale = false): void
    {
        $departure = (int) ($journey['departure'] ?? 0);
        $arrival = (int) ($journey['arrival'] ?? 0);
        if ($departure <= 0 || $arrival <= 0) throw new RuntimeException('Verbindung enthält keine Zeiten.');
        $delay = (int) ($journey['delay'] ?? 0);
        $platform = (string) ($journey['platform'] ?? '');
        $cancelled = (bool) ($journey['cancelled'] ?? false);

        if ($official !== null) {
            if (($official['changedDeparture'] ?? 0) > 0) $departure = (int) $official['changedDeparture'];
            $delay = max($delay, (int) ($official['delayMinutes'] ?? 0));
            if ((string) ($official['platform'] ?? '') !== '') $platform = (string) $official['platform'];
            $cancelled = $cancelled || (bool) ($official['cancelled'] ?? false);
            $this->SetValue('DBTimetablesMatch', (string) ($official['match'] ?? ''));
            $this->SetValue('DBTimetablesDelay', (int) ($official['delayMinutes'] ?? 0));
            $this->SetValue('DBTimetablesPlatform', (string) ($official['platform'] ?? ''));
            $this->SetValue('DBTimetablesCancelled', (bool) ($official['cancelled'] ?? false));
        }

        $leave = $departure - ($this->ReadPropertyInteger('WalkToStopMinutes') + $this->ReadPropertyInteger('SafetyBufferMinutes')) * 60;
        $minutes = (int) floor(($leave - time()) / 60);
        $status = 'OK';
        $recommendation = 'Planmäßig losfahren';
        if ($stale) {
            $status = 'DATEN VERALTET';
            $recommendation = 'Letzte gültige Verbindung wird angezeigt – Provider temporär nicht erreichbar';
        } elseif ($cancelled) {
            $status = 'AUSFALL';
            $recommendation = 'Gewählte Verbindung ist ausgefallen – Alternative prüfen';
        } elseif ($arrival > $targetArrival) {
            $status = 'ZU SPÄT';
            $recommendation = 'Verbindung erreicht das Ziel nach der Wunschzeit';
        } elseif ($minutes <= 0) {
            $status = 'JETZT LOS';
            $recommendation = 'Jetzt das Haus verlassen';
        } elseif ($minutes <= 10) {
            $status = 'BALD LOS';
            $recommendation = 'In ' . $minutes . ' min das Haus verlassen';
        } elseif ($delay >= 10) {
            $status = 'VERSPÄTET';
            $recommendation = 'Verbindung +' . $delay . ' min';
        }

        $this->SetValue('LeaveHomeAt', $leave);
        $this->SetValue('MinutesToLeave', $minutes);
        $this->SetValue('MobilityStatus', $status);
        $this->SetValue('Recommendation', $recommendation);
        $this->SetValue('JourneySummary', (string) ($journey['summary'] ?? ''));
        $this->SetValue('JourneyDeparture', $departure);
        $this->SetValue('JourneyArrival', $arrival);
        $this->SetValue('DelayMinutes', $delay);
        $this->SetValue('Platform', $platform);
        $this->SetValue('Cancelled', $cancelled);
        $this->SetValue('Disruptions', (string) ($journey['remarks'] ?? ''));
        $this->SetValue('JourneyTracked', (bool) ($journey['tracked'] ?? false));
        $this->SetValue('DataStale', $stale);
        if (!$stale) $this->SetValue('LastError', '');
    }

    private function ResolveConfiguredStops(bool $force): array
    {
        $from = $this->ResolveOneStop('From', $force);
        $to = $this->ResolveOneStop('To', $force);
        return [$from['id'], $to['id']];
    }

    private function ResolveOneStop(string $side, bool $force): array
    {
        $idProp = trim($this->ReadPropertyString($side . 'StopId'));
        $nameProp = trim($this->ReadPropertyString($side . 'StopName'));
        $idAttr = 'Resolved' . $side . 'StopId';
        $nameAttr = 'Resolved' . $side . 'StopName';
        $coordAttr = $side . 'Coordinates';

        if (!$force && $this->ReadAttributeString($idAttr) !== '') {
            return ['id' => $this->ReadAttributeString($idAttr), 'name' => $this->ReadAttributeString($nameAttr)];
        }

        if ($idProp !== '') {
            $station = $this->HttpJson(self::TRANSPORT_BASE . '/stations/' . rawurlencode($idProp), true);
            $id = $idProp;
            $name = $nameProp !== '' ? $nameProp : (string) ($station['name'] ?? $idProp);
            $this->StoreCoordinates($coordAttr, $station['location'] ?? []);
        } else {
            if ($nameProp === '') throw new RuntimeException($side . '-Haltestelle fehlt.');
            $data = $this->HttpJson(self::TRANSPORT_BASE . '/stations?' . http_build_query(['query' => $nameProp, 'limit' => 5, 'fuzzy' => 'true']), true);
            $best = null;
            foreach ($data as $candidate) {
                if (!is_array($candidate) || empty($candidate['id'])) continue;
                $score = $this->NormalizeText((string) ($candidate['name'] ?? '')) === $this->NormalizeText($nameProp) ? 100 : (int) round(((float) ($candidate['relevance'] ?? 0)) * 80);
                if ($best === null || $score > $best['score']) $best = ['score' => $score, 'data' => $candidate];
            }
            if ($best === null) throw new RuntimeException('Keine Haltestelle für „' . $nameProp . '“ gefunden.');
            $station = $best['data'];
            $id = (string) $station['id'];
            $name = (string) ($station['name'] ?? $nameProp);
            $this->StoreCoordinates($coordAttr, $station['location'] ?? []);
        }

        $this->WriteAttributeString($idAttr, $id);
        $this->WriteAttributeString($nameAttr, $name);
        $this->SetValue('Resolved' . $side, $name . ' [' . $id . ']');
        return ['id' => $id, 'name' => $name];
    }

    private function VerifyWithDBTimetables(array $journey): ?array
    {
        $leg = null;
        foreach (($journey['legs'] ?? []) as $candidate) {
            if (($candidate['walking'] ?? false) !== true && isset($candidate['line'])) { $leg = $candidate; break; }
        }
        if ($leg === null) return null;
        $plannedTs = $this->IsoToTs((string) ($leg['plannedDeparture'] ?? $leg['departure'] ?? ''));
        if ($plannedTs <= 0) return null;
        $eva = $this->ResolveDBEva();
        if ($eva === '') return null;

        $url = self::DB_TIMETABLES_BASE . '/plan/' . rawurlencode($eva) . '/' . date('ymd', $plannedTs) . '/' . date('H', $plannedTs);
        $plan = $this->HttpXml($url);
        $best = null; $score = PHP_INT_MAX;
        foreach ($plan->s as $stop) {
            if (!isset($stop->dp)) continue;
            $pt = $this->IrisTimeToTs((string) $stop->dp['pt']);
            $delta = abs($pt - $plannedTs);
            if ($pt > 0 && $delta < $score) { $best = $stop; $score = $delta; }
        }
        if ($best === null || $score > 5400) return null;

        $changed = 0; $platform = (string) $best->dp['pp']; $cancelled = false;
        $changes = $this->HttpXml(self::DB_TIMETABLES_BASE . '/fchg/' . rawurlencode($eva));
        foreach ($changes->s as $stop) {
            if ((string) $stop['id'] !== (string) $best['id'] || !isset($stop->dp)) continue;
            $changed = $this->IrisTimeToTs((string) $stop->dp['ct']);
            if ((string) $stop->dp['cp'] !== '') $platform = (string) $stop->dp['cp'];
            $cancelled = strtolower((string) $stop->dp['cs']) === 'c';
            break;
        }
        $planned = $this->IrisTimeToTs((string) $best->dp['pt']);
        $actual = $changed > 0 ? $changed : $planned;
        return [
            'changedDeparture' => $actual,
            'delayMinutes' => max(0, (int) round(($actual - $planned) / 60)),
            'platform' => $platform,
            'cancelled' => $cancelled,
            'match' => trim((string) ($best->tl['c'] ?? '') . ' ' . (string) ($best->tl['n'] ?? '') . ' · EVA ' . $eva)
        ];
    }

    private function ResolveDBEva(): string
    {
        $override = trim($this->ReadPropertyString('DBEvaNumber'));
        if ($override !== '') return $override;
        $cached = $this->ReadAttributeString('ResolvedDBEva');
        if ($cached !== '') return $cached;
        $name = $this->ReadAttributeString('ResolvedFromStopName');
        if ($name === '') return '';
        $xml = $this->HttpXml(self::DB_TIMETABLES_BASE . '/station/' . rawurlencode($name));
        $best = '';
        foreach ($xml->station as $station) {
            if ((string) $station['eva'] !== '') { $best = (string) $station['eva']; break; }
        }
        if ($best !== '') $this->WriteAttributeString('ResolvedDBEva', $best);
        return $best;
    }

    private function UpdateRoadworks(): void
    {
        $hLat = $this->ReadPropertyFloat('HomeLatitude');
        $hLon = $this->ReadPropertyFloat('HomeLongitude');
        $dLat = $this->ReadPropertyFloat('DestinationLatitude');
        $dLon = $this->ReadPropertyFloat('DestinationLongitude');
        if ($hLat == 0.0 || $hLon == 0.0 || $dLat == 0.0 || $dLon == 0.0) {
            $this->SetValue('RoadworksCount', 0);
            $this->SetValue('RoadworksSummary', 'Koordinaten für Straßenkorridor nicht vollständig konfiguriert');
            return;
        }
        $geo = $this->HttpJson(self::MOBIDATA_ROADWORKS);
        $hits = [];
        foreach (($geo['features'] ?? []) as $feature) {
            $p = $feature['properties'] ?? [];
            $text = '';
            foreach (['description','title','comment','reason','street','road','name'] as $key) {
                if (isset($p[$key]) && trim((string) $p[$key]) !== '') { $text = trim((string) $p[$key]); break; }
            }
            if ($text !== '') $hits[] = $text;
            if (count($hits) >= 10) break;
        }
        $hits = array_values(array_unique($hits));
        $this->SetValue('RoadworksCount', count($hits));
        $this->SetValue('RoadworksSummary', count($hits) ? implode(' | ', $hits) : 'Keine Straßenmeldung erkannt');
    }

    private function HttpJson(string $url, bool $retry = false, string $userAgent = 'IP-Symcon OchsMobility/0.4'): array
    {
        $body = $this->HttpRequest($url, ['Accept: application/json'], $retry, $userAgent);
        $data = json_decode($body, true);
        if (!is_array($data)) throw new RuntimeException('Ungültiges JSON.');
        return $data;
    }

    private function HttpXml(string $url): SimpleXMLElement
    {
        $headers = ['Accept: application/xml'];
        if ($this->HasDBCredentials()) {
            $headers[] = 'DB-Client-Id: ' . trim($this->ReadPropertyString('DBClientId'));
            $headers[] = 'DB-Api-Key: ' . trim($this->ReadPropertyString('DBApiKey'));
        }
        $body = $this->HttpRequest($url, $headers, true);
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        if ($xml === false) throw new RuntimeException('Ungültiges XML.');
        return $xml;
    }

    private function HttpRequest(string $url, array $headers, bool $retry = false, string $userAgent = 'IP-Symcon OchsMobility/0.4'): string
    {
        $attempts = $retry ? 3 : 1;
        $last = 'Unbekannter HTTP-Fehler';
        for ($i = 0; $i < $attempts; $i++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 18, CURLOPT_USERAGENT => $userAgent, CURLOPT_HTTPHEADER => $headers]);
            $body = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body !== false && $error === '' && $status >= 200 && $status < 300) return (string) $body;
            $last = $error !== '' ? $error : 'HTTP ' . $status;
            if (!in_array($status, [429, 502, 503, 504], true)) break;
            if ($i + 1 < $attempts) usleep(($i + 1) * 350000);
        }
        throw new RuntimeException($last);
    }

    private function JourneyTimes(array $journey): array
    {
        $legs = $journey['legs'] ?? [];
        if (!is_array($legs) || count($legs) === 0) return ['departure' => 0, 'arrival' => 0];
        $first = $legs[0]; $last = $legs[count($legs) - 1];
        return ['departure' => $this->IsoToTs((string) ($first['departure'] ?? $first['plannedDeparture'] ?? '')), 'arrival' => $this->IsoToTs((string) ($last['arrival'] ?? $last['plannedArrival'] ?? ''))];
    }

    private function JourneyCancelled(array $journey): bool
    {
        foreach (($journey['legs'] ?? []) as $leg) if (($leg['cancelled'] ?? false) === true) return true;
        return false;
    }

    private function SaveCachedJourney(array $journey): void
    {
        $copy = $journey; unset($copy['rawTransport']);
        $this->WriteAttributeString('CachedJourney', json_encode($copy));
        $this->WriteAttributeInteger('CachedJourneyAt', time());
    }

    private function LoadCachedJourney(): ?array
    {
        $age = time() - $this->ReadAttributeInteger('CachedJourneyAt');
        if ($age < 0 || $age > max(5, $this->ReadPropertyInteger('StaleCacheMinutes')) * 60) return null;
        $data = json_decode($this->ReadAttributeString('CachedJourney'), true);
        return is_array($data) ? $data : null;
    }

    private function StoreCoordinates(string $attribute, array $location): void
    {
        $lat = $location['latitude'] ?? null; $lon = $location['longitude'] ?? null;
        if (is_numeric($lat) && is_numeric($lon)) $this->WriteAttributeString($attribute, (float) $lat . ',' . (float) $lon);
    }

    private function ReadCoordinates(string $attribute): ?array
    {
        $parts = explode(',', $this->ReadAttributeString($attribute));
        return count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1]) ? [(float) $parts[0], (float) $parts[1]] : null;
    }

    private function HasStopConfiguration(): bool
    {
        return (trim($this->ReadPropertyString('FromStopId')) !== '' || trim($this->ReadPropertyString('FromStopName')) !== '') && (trim($this->ReadPropertyString('ToStopId')) !== '' || trim($this->ReadPropertyString('ToStopName')) !== '');
    }

    private function HasDBCredentials(): bool
    {
        return trim($this->ReadPropertyString('DBClientId')) !== '' && trim($this->ReadPropertyString('DBApiKey')) !== '';
    }

    private function IsoToTs(string $value): int
    {
        $ts = $value === '' ? false : strtotime($value);
        return $ts === false ? 0 : $ts;
    }

    private function IrisTimeToTs(string $value): int
    {
        $dt = preg_match('/^\d{10}$/', $value) ? DateTime::createFromFormat('ymdHi', $value) : false;
        return $dt === false ? 0 : $dt->getTimestamp();
    }

    private function NormalizeText(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
    }

    private function ConfigurationSignature(): string
    {
        return sha1(implode('|', [trim($this->ReadPropertyString('FromStopName')), trim($this->ReadPropertyString('ToStopName')), trim($this->ReadPropertyString('FromStopId')), trim($this->ReadPropertyString('ToStopId')), trim($this->ReadPropertyString('DBEvaNumber'))]));
    }

    private function ClearResolvedStops(): void
    {
        foreach (['ResolvedFromStopId','ResolvedToStopId','ResolvedFromStopName','ResolvedToStopName','FromCoordinates','ToCoordinates'] as $a) $this->WriteAttributeString($a, '');
        $this->SetValue('ResolvedFrom', ''); $this->SetValue('ResolvedTo', '');
    }

    private function ClearTrackedJourney(): void
    {
        $this->WriteAttributeString('JourneyRefreshToken', '');
        $this->SetValue('JourneyTracked', false);
    }
}
