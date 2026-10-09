<?php

declare(strict_types=1);

class OchsMobility extends IPSModule
{
    private const DB_TIMETABLES_BASE = 'https://apis.deutschebahn.com/db-api-marketplace/apis/timetables/v1';
    private const MOBIDATA_ROADWORKS = 'https://api.mobidata-bw.de/datasets/traffic/roadworks/roadworks_geojson.json';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('FromStopName', '');
        $this->RegisterPropertyString('ToStopName', '');
        $this->RegisterPropertyString('FromStopId', ''); // EVA preferred
        $this->RegisterPropertyString('ToStopId', '');   // EVA preferred
        $this->RegisterPropertyInteger('WalkToStopMinutes', 8);
        $this->RegisterPropertyInteger('SafetyBufferMinutes', 5);
        $this->RegisterPropertyInteger('DefaultArrivalLeadMinutes', 60);
        $this->RegisterPropertyInteger('LookAheadHours', 4);
        $this->RegisterPropertyInteger('UpdateIntervalSeconds', 120);

        $this->RegisterPropertyString('DBClientId', '');
        $this->RegisterPropertyString('DBApiKey', '');

        $this->RegisterPropertyBoolean('EnableRoadworks', true);
        $this->RegisterPropertyFloat('HomeLatitude', 0.0);
        $this->RegisterPropertyFloat('HomeLongitude', 0.0);
        $this->RegisterPropertyFloat('DestinationLatitude', 0.0);
        $this->RegisterPropertyFloat('DestinationLongitude', 0.0);
        $this->RegisterPropertyInteger('RoadCorridorKm', 5);

        $this->RegisterAttributeInteger('TargetArrival', 0);
        $this->RegisterAttributeString('ResolvedFromEva', '');
        $this->RegisterAttributeString('ResolvedToEva', '');
        $this->RegisterAttributeString('ResolvedFromName', '');
        $this->RegisterAttributeString('ResolvedToName', '');

        $this->RegisterVariableInteger('TargetArrival', 'Gewünschte Ankunft', '~UnixTimestamp', 10);
        $this->RegisterVariableInteger('LeaveHomeAt', 'Haus verlassen', '~UnixTimestamp', 20);
        $this->RegisterVariableInteger('MinutesToLeave', 'Noch bis Abfahrt', '', 30);
        $this->RegisterVariableString('MobilityStatus', 'Mobilitätsstatus', '', 40);
        $this->RegisterVariableString('Recommendation', 'Empfehlung', '', 50);
        $this->RegisterVariableString('RoutingProvider', 'Bahn-Provider', '', 60);
        $this->RegisterVariableString('ProviderDiagnostics', 'Provider-Diagnose', '', 70);

        $this->RegisterVariableString('ResolvedFrom', 'Startbahnhof', '', 80);
        $this->RegisterVariableString('ResolvedTo', 'Zielbahnhof', '', 90);
        $this->RegisterVariableString('JourneySummary', 'Direktverbindung', '', 100);
        $this->RegisterVariableInteger('JourneyDeparture', 'Abfahrt', '~UnixTimestamp', 110);
        $this->RegisterVariableInteger('JourneyArrival', 'Ankunft', '~UnixTimestamp', 120);
        $this->RegisterVariableInteger('DelayMinutes', 'Verspätung', '', 130);
        $this->RegisterVariableString('Platform', 'Gleis', '', 140);
        $this->RegisterVariableBoolean('Cancelled', 'Ausgefallen', '~Switch', 150);
        $this->RegisterVariableString('Disruptions', 'Hinweise / Störungen', '', 160);

        $this->RegisterVariableString('DBTimetablesStatus', 'DB Timetables Status', '', 180);
        $this->RegisterVariableString('DBTimetablesMatch', 'DB Timetables Treffer', '', 181);

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
        $this->SetValue('RoutingProvider', 'DB Timetables');
        $this->SetStatus($this->HasBasicConfiguration() ? 102 : 201);
    }

    public function SetTargetArrival(int $timestamp): void
    {
        $timestamp = max(0, $timestamp);
        $this->WriteAttributeInteger('TargetArrival', $timestamp);
        $this->SetValue('TargetArrival', $timestamp);
    }

    public function ClearTargetArrival(): void
    {
        $this->SetTargetArrival(0);
    }

    public function ResetJourney(): void
    {
        $this->SetValue('JourneySummary', '');
        $this->SetValue('Recommendation', 'Verbindungsauswahl wurde zurückgesetzt');
    }

    public function ResolveStops(): bool
    {
        $this->SetValue('ProviderDiagnostics', 'DB Timetables: Bahnhofssuche läuft …');
        try {
            [$fromEva, $fromName] = $this->ResolveStation('From');
            [$toEva, $toName] = $this->ResolveStation('To');
            $this->SetValue('ResolvedFrom', $fromName . ' [' . $fromEva . ']');
            $this->SetValue('ResolvedTo', $toName . ' [' . $toEva . ']');
            $this->SetValue('ProviderDiagnostics', 'DB Timetables Bahnhofssuche: OK');
            $this->SetValue('LastError', '');
            $this->SetStatus(102);
            return true;
        } catch (Throwable $e) {
            $this->SetValue('ProviderDiagnostics', 'DB Timetables Bahnhofssuche: ' . $e->getMessage());
            $this->SetValue('LastError', $e->getMessage());
            $this->SetStatus(202);
            return false;
        }
    }

    public function Update(): bool
    {
        if (!$this->HasBasicConfiguration()) {
            $this->SetValue('LastError', 'DB Client ID/API Key sowie Start und Ziel müssen konfiguriert sein.');
            $this->SetStatus(201);
            return false;
        }

        $this->SetValue('ProviderDiagnostics', 'DB Timetables: Aktualisierung läuft …');

        $target = $this->ReadAttributeInteger('TargetArrival');
        if ($target <= time()) {
            $target = time() + max(15, $this->ReadPropertyInteger('DefaultArrivalLeadMinutes')) * 60;
            $this->SetTargetArrival($target);
        }

        try {
            [$fromEva, $fromName] = $this->ResolveStation('From');
            [$toEva, $toName] = $this->ResolveStation('To');
            $this->SetValue('ResolvedFrom', $fromName . ' [' . $fromEva . ']');
            $this->SetValue('ResolvedTo', $toName . ' [' . $toEva . ']');

            $journey = $this->FindDirectJourney($fromEva, $fromName, $toEva, $toName, $target);
            $this->ApplyJourney($journey, $target);
            $this->SetValue('DBTimetablesStatus', 'Offizielle DB-Daten aktiv');
            $this->SetValue('RoutingProvider', 'DB Timetables');
            $this->SetValue('ProviderDiagnostics', 'DB Timetables: OK · direkte Fahrt gefunden');

            try {
                if ($this->ReadPropertyBoolean('EnableRoadworks')) {
                    $this->UpdateRoadworks();
                }
            } catch (Throwable $e) {
                $this->SetValue('RoadworksSummary', 'Straßenlage derzeit nicht verfügbar: ' . $e->getMessage());
            }

            $this->SetValue('LastUpdate', time());
            $this->SetValue('LastError', '');
            $this->SetStatus(102);
            return true;
        } catch (Throwable $e) {
            $this->SetValue('DBTimetablesStatus', 'Fehler');
            $this->SetValue('ProviderDiagnostics', 'DB Timetables: ' . $e->getMessage());
            $this->SetValue('LastError', $e->getMessage());
            $this->SetValue('MobilityStatus', 'DATENFEHLER');
            $this->SetStatus(202);
            return false;
        }
    }

    private function ResolveStation(string $side): array
    {
        $id = trim($this->ReadPropertyString($side . 'StopId'));
        $name = trim($this->ReadPropertyString($side . 'StopName'));
        $cachedEvaAttr = 'Resolved' . $side . 'Eva';
        $cachedNameAttr = 'Resolved' . $side . 'Name';

        if ($id !== '') {
            $xml = $this->HttpXml(self::DB_TIMETABLES_BASE . '/station/' . rawurlencode($id));
        } elseif ($name !== '') {
            $xml = $this->HttpXml(self::DB_TIMETABLES_BASE . '/station/' . rawurlencode($name));
        } else {
            throw new RuntimeException($side === 'From' ? 'Startbahnhof fehlt.' : 'Zielbahnhof fehlt.');
        }

        $bestEva = '';
        $bestName = '';
        $queryNorm = $this->NormalizeText($name !== '' ? $name : $id);
        $bestScore = -1;

        foreach ($xml->station as $station) {
            $eva = trim((string) $station['eva']);
            $stationName = trim((string) $station['name']);
            if ($eva === '') {
                continue;
            }
            $candidateNorm = $this->NormalizeText($stationName);
            $score = $candidateNorm === $queryNorm ? 100 : (strpos($candidateNorm, $queryNorm) !== false ? 70 : 10);
            if ($id !== '' && $eva === $id) {
                $score = 120;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestEva = $eva;
                $bestName = $stationName !== '' ? $stationName : ($name !== '' ? $name : $eva);
            }
        }

        if ($bestEva === '') {
            throw new RuntimeException('DB Timetables konnte ' . ($side === 'From' ? 'Startbahnhof' : 'Zielbahnhof') . ' nicht auflösen.');
        }

        $this->WriteAttributeString($cachedEvaAttr, $bestEva);
        $this->WriteAttributeString($cachedNameAttr, $bestName);
        return [$bestEva, $bestName];
    }

    private function FindDirectJourney(string $fromEva, string $fromName, string $toEva, string $toName, int $targetArrival): array
    {
        $hours = max(1, min(8, $this->ReadPropertyInteger('LookAheadHours')));
        $start = time() - 3600;
        $end = max($targetArrival + 3600, time() + ($hours * 3600));
        $plans = [];

        for ($t = strtotime(date('Y-m-d H:00:00', $start)); $t <= $end; $t += 3600) {
            $xml = $this->HttpXml(self::DB_TIMETABLES_BASE . '/plan/' . rawurlencode($fromEva) . '/' . date('ymd', $t) . '/' . date('H', $t));
            foreach ($xml->s as $stop) {
                if (!isset($stop->dp)) {
                    continue;
                }
                $path = (string) $stop->dp['ppth'];
                if (!$this->PathContainsStation($path, $toName)) {
                    continue;
                }
                $plannedDeparture = $this->IrisTimeToTs((string) $stop->dp['pt']);
                if ($plannedDeparture <= time() - 300) {
                    continue;
                }
                $plans[] = [
                    'id' => (string) $stop['id'],
                    'plannedDeparture' => $plannedDeparture,
                    'plannedPlatform' => (string) $stop->dp['pp'],
                    'path' => $path,
                    'category' => isset($stop->tl) ? (string) $stop->tl['c'] : '',
                    'number' => isset($stop->tl) ? (string) $stop->tl['n'] : ''
                ];
            }
        }

        if (count($plans) === 0) {
            throw new RuntimeException('Keine direkte DB-Fahrt von ' . $fromName . ' nach ' . $toName . ' im Suchfenster gefunden.');
        }

        $changes = $this->HttpXml(self::DB_TIMETABLES_BASE . '/fchg/' . rawurlencode($fromEva));
        $changeMap = [];
        foreach ($changes->s as $stop) {
            $changeMap[(string) $stop['id']] = $stop;
        }

        $best = null;
        foreach ($plans as $plan) {
            $actualDeparture = $plan['plannedDeparture'];
            $platform = $plan['plannedPlatform'];
            $cancelled = false;
            $remarks = [];

            if (isset($changeMap[$plan['id']])) {
                $change = $changeMap[$plan['id']];
                if (isset($change->dp)) {
                    $ct = (string) $change->dp['ct'];
                    if ($ct !== '') {
                        $actualDeparture = $this->IrisTimeToTs($ct);
                    }
                    $cp = (string) $change->dp['cp'];
                    if ($cp !== '') {
                        $platform = $cp;
                    }
                    $cancelled = strtolower((string) $change->dp['cs']) === 'c';
                    foreach ($change->dp->m as $m) {
                        $cat = trim((string) $m['cat']);
                        if ($cat !== '') {
                            $remarks[] = $cat;
                        }
                    }
                }
            }

            $estimatedArrival = $this->EstimateArrivalAtDestination($plan, $toEva, $toName, $targetArrival);
            if ($estimatedArrival <= 0) {
                $estimatedArrival = $actualDeparture;
            }

            $candidate = [
                'departure' => $actualDeparture,
                'plannedDeparture' => $plan['plannedDeparture'],
                'arrival' => $estimatedArrival,
                'platform' => $platform,
                'cancelled' => $cancelled,
                'delay' => max(0, (int) round(($actualDeparture - $plan['plannedDeparture']) / 60)),
                'category' => $plan['category'],
                'number' => $plan['number'],
                'remarks' => implode(' | ', array_unique($remarks)),
                'path' => $plan['path']
            ];

            if ($cancelled) {
                continue;
            }
            if ($best === null) {
                $best = $candidate;
                continue;
            }
            $candFits = $candidate['arrival'] <= $targetArrival;
            $bestFits = $best['arrival'] <= $targetArrival;
            if ($candFits && !$bestFits) {
                $best = $candidate;
            } elseif ($candFits === $bestFits && $candidate['departure'] > $best['departure']) {
                $best = $candidate;
            }
        }

        if ($best === null) {
            throw new RuntimeException('Alle gefundenen direkten DB-Fahrten sind ausgefallen.');
        }

        return $best;
    }

    private function EstimateArrivalAtDestination(array $originPlan, string $toEva, string $toName, int $targetArrival): int
    {
        $fromTs = $originPlan['plannedDeparture'];
        $limit = min($targetArrival + 7200, $fromTs + 21600);
        for ($t = strtotime(date('Y-m-d H:00:00', $fromTs)); $t <= $limit; $t += 3600) {
            $xml = $this->HttpXml(self::DB_TIMETABLES_BASE . '/plan/' . rawurlencode($toEva) . '/' . date('ymd', $t) . '/' . date('H', $t));
            foreach ($xml->s as $stop) {
                if (!isset($stop->ar)) {
                    continue;
                }
                $category = isset($stop->tl) ? (string) $stop->tl['c'] : '';
                $number = isset($stop->tl) ? (string) $stop->tl['n'] : '';
                if ($category !== $originPlan['category'] || $number !== $originPlan['number']) {
                    continue;
                }
                $path = (string) $stop->ar['ppth'];
                if (!$this->PathContainsStation($path, $this->ReadAttributeString('ResolvedFromName'))) {
                    continue;
                }
                $arrival = $this->IrisTimeToTs((string) $stop->ar['pt']);
                if ($arrival >= $fromTs) {
                    return $arrival;
                }
            }
        }
        return 0;
    }

    private function ApplyJourney(array $journey, int $targetArrival): void
    {
        $leave = $journey['departure'] - (($this->ReadPropertyInteger('WalkToStopMinutes') + $this->ReadPropertyInteger('SafetyBufferMinutes')) * 60);
        $minutes = (int) floor(($leave - time()) / 60);
        $status = 'OK';
        $recommendation = 'Planmäßig losfahren';

        if ($journey['cancelled']) {
            $status = 'AUSFALL';
            $recommendation = 'Direkte DB-Fahrt ist ausgefallen';
        } elseif ($journey['arrival'] > $targetArrival) {
            $status = 'ZU SPÄT';
            $recommendation = 'Direkte Fahrt erreicht das Ziel nach der Wunschzeit';
        } elseif ($minutes <= 0) {
            $status = 'JETZT LOS';
            $recommendation = 'Jetzt das Haus verlassen';
        } elseif ($minutes <= 10) {
            $status = 'BALD LOS';
            $recommendation = 'In ' . $minutes . ' min das Haus verlassen';
        } elseif ($journey['delay'] >= 10) {
            $status = 'VERSPÄTET';
            $recommendation = 'Direkte Fahrt +' . $journey['delay'] . ' min';
        }

        $label = trim($journey['category'] . ' ' . $journey['number']);
        $this->SetValue('LeaveHomeAt', $leave);
        $this->SetValue('MinutesToLeave', $minutes);
        $this->SetValue('MobilityStatus', $status);
        $this->SetValue('Recommendation', $recommendation);
        $this->SetValue('JourneySummary', $label);
        $this->SetValue('JourneyDeparture', $journey['departure']);
        $this->SetValue('JourneyArrival', $journey['arrival']);
        $this->SetValue('DelayMinutes', $journey['delay']);
        $this->SetValue('Platform', $journey['platform']);
        $this->SetValue('Cancelled', $journey['cancelled']);
        $this->SetValue('Disruptions', $journey['remarks']);
        $this->SetValue('DBTimetablesMatch', $label . ' · ' . date('H:i', $journey['departure']));
    }

    private function PathContainsStation(string $path, string $stationName): bool
    {
        if ($path === '' || $stationName === '') {
            return false;
        }
        $needle = $this->NormalizeText($stationName);
        foreach (explode('|', $path) as $part) {
            $candidate = $this->NormalizeText($part);
            if ($candidate === $needle || strpos($candidate, $needle) !== false || strpos($needle, $candidate) !== false) {
                return true;
            }
        }
        return false;
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
        $data = $this->HttpJson(self::MOBIDATA_ROADWORKS);
        $this->SetValue('RoadworksCount', is_array($data['features'] ?? null) ? count($data['features']) : 0);
        $this->SetValue('RoadworksSummary', 'MobiData BW geladen');
    }

    private function HttpXml(string $url): SimpleXMLElement
    {
        $headers = [
            'Accept: application/xml',
            'DB-Client-Id: ' . trim($this->ReadPropertyString('DBClientId')),
            'DB-Api-Key: ' . trim($this->ReadPropertyString('DBApiKey'))
        ];
        $body = $this->HttpRequest($url, $headers);
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        if ($xml === false) {
            throw new RuntimeException('DB Timetables lieferte ungültiges XML.');
        }
        return $xml;
    }

    private function HttpJson(string $url): array
    {
        $body = $this->HttpRequest($url, ['Accept: application/json']);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Ungültiges JSON.');
        }
        return $data;
    }

    private function HttpRequest(string $url, array $headers): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('HTTP-Client konnte nicht initialisiert werden.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 18,
            CURLOPT_USERAGENT => 'IP-Symcon OchsMobility/0.6',
            CURLOPT_HTTPHEADER => $headers
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new RuntimeException('HTTP-Fehler: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('HTTP ' . $status);
        }
        return (string) $body;
    }

    private function IrisTimeToTs(string $value): int
    {
        if (!preg_match('/^\d{10}$/', $value)) {
            return 0;
        }
        $dt = DateTime::createFromFormat('ymdHi', $value);
        return $dt === false ? 0 : $dt->getTimestamp();
    }

    private function NormalizeText(string $value): string
    {
        $value = trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function HasBasicConfiguration(): bool
    {
        $from = trim($this->ReadPropertyString('FromStopId')) !== '' || trim($this->ReadPropertyString('FromStopName')) !== '';
        $to = trim($this->ReadPropertyString('ToStopId')) !== '' || trim($this->ReadPropertyString('ToStopName')) !== '';
        return $from && $to && trim($this->ReadPropertyString('DBClientId')) !== '' && trim($this->ReadPropertyString('DBApiKey')) !== '';
    }
}
