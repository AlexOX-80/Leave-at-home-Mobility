<?php

declare(strict_types=1);

class OchsMobility extends IPSModule
{
    private const TRANSPORT_BASE = 'https://v6.db.transport.rest';
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

        // Official DB Timetables / IRIS (free plan, credentials required)
        $this->RegisterPropertyBoolean('EnableDBTimetables', false);
        $this->RegisterPropertyString('DBClientId', '');
        $this->RegisterPropertyString('DBApiKey', '');
        $this->RegisterPropertyString('DBEvaNumber', '');

        // Roadworks
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

        $this->RegisterVariableInteger('TargetArrival', 'Gewünschte Ankunft', '~UnixTimestamp', 10);
        $this->RegisterVariableInteger('LeaveHomeAt', 'Haus verlassen', '~UnixTimestamp', 20);
        $this->RegisterVariableInteger('MinutesToLeave', 'Noch bis Abfahrt', '', 30);
        $this->RegisterVariableString('MobilityStatus', 'Mobilitätsstatus', '', 40);
        $this->RegisterVariableString('Recommendation', 'Empfehlung', '', 50);

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

        $interval = max(60, $this->ReadPropertyInteger('UpdateIntervalSeconds'));
        $this->SetTimerInterval('UpdateTimer', $interval * 1000);

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

    public function ResolveStops(): bool
    {
        try {
            [$fromId, $toId] = $this->ResolveConfiguredStops(true);
            if ($this->ReadPropertyBoolean('EnableDBTimetables') && $this->HasDBCredentials()) {
                $this->ResolveDBEva(true);
            }
            $this->SetValue('LastError', '');
            $this->SetStatus(102);
            return $fromId !== '' && $toId !== '';
        } catch (Throwable $e) {
            $this->SetValue('LastError', $e->getMessage());
            $this->SetStatus(202);
            return false;
        }
    }

    public function ResetJourney(): void
    {
        $this->ClearTrackedJourney();
        $this->SetValue('Recommendation', 'Verbindungsauswahl wurde zurückgesetzt');
    }

    public function Update(): bool
    {
        if (!$this->HasStopConfiguration()) {
            $this->SetStatus(201);
            $this->SetValue('LastError', 'Start- oder Ziel-Haltestelle fehlt. Name oder ID eintragen.');
            return false;
        }

        $target = $this->ReadAttributeInteger('TargetArrival');
        if ($target <= time()) {
            $target = time() + ($this->ReadPropertyInteger('DefaultArrivalLeadMinutes') * 60);
            $this->WriteAttributeInteger('TargetArrival', $target);
            $this->SetValue('TargetArrival', $target);
            $this->ClearTrackedJourney();
        }

        try {
            [$from, $to] = $this->ResolveConfiguredStops(false);

            $journey = null;
            $tracked = false;
            $refreshToken = $this->ReadAttributeString('JourneyRefreshToken');

            if ($refreshToken !== '') {
                try {
                    $journey = $this->RefreshJourney($refreshToken);
                    $tracked = true;
                } catch (Throwable $refreshError) {
                    $this->SendDebug('Journey refresh failed', $refreshError->getMessage(), 0);
                    $this->ClearTrackedJourney();
                }
            }

            if ($journey === null) {
                $journey = $this->FetchJourney($from, $to, $target);
            }

            $official = null;
            if ($this->ReadPropertyBoolean('EnableDBTimetables')) {
                if ($this->HasDBCredentials()) {
                    try {
                        $official = $this->VerifyWithDBTimetables($journey);
                        $this->SetValue('DBTimetablesStatus', $official === null ? 'Kein passender DB-Treffer' : 'Offiziell verifiziert');
                    } catch (Throwable $dbError) {
                        $this->SendDebug('DB Timetables failed', $dbError->getMessage(), 0);
                        $this->SetValue('DBTimetablesStatus', 'Fehler: ' . $dbError->getMessage());
                    }
                } else {
                    $this->SetValue('DBTimetablesStatus', 'Aktiviert, aber Zugangsdaten fehlen');
                }
            } else {
                $this->SetValue('DBTimetablesStatus', 'Deaktiviert');
            }

            $this->ApplyJourney($journey, $target, $tracked, $official);

            if ($this->ReadPropertyBoolean('EnableRoadworks')) {
                $this->UpdateRoadworks();
            } else {
                $this->SetValue('RoadworksCount', 0);
                $this->SetValue('RoadworksSummary', 'Straßenlage deaktiviert');
            }

            $this->SetValue('LastUpdate', time());
            $this->SetValue('LastError', '');
            $this->SetStatus(102);
            return true;
        } catch (Throwable $e) {
            $this->SendDebug('Update error', $e->getMessage(), 0);
            $this->SetValue('LastError', $e->getMessage());
            $this->SetValue('MobilityStatus', 'DATENFEHLER');
            $this->SetStatus(202);
            return false;
        }
    }

    private function HasStopConfiguration(): bool
    {
        $from = trim($this->ReadPropertyString('FromStopId')) !== '' || trim($this->ReadPropertyString('FromStopName')) !== '';
        $to = trim($this->ReadPropertyString('ToStopId')) !== '' || trim($this->ReadPropertyString('ToStopName')) !== '';
        return $from && $to;
    }

    private function HasDBCredentials(): bool
    {
        return trim($this->ReadPropertyString('DBClientId')) !== ''
            && trim($this->ReadPropertyString('DBApiKey')) !== '';
    }

    private function ResolveConfiguredStops(bool $force): array
    {
        $fromOverride = trim($this->ReadPropertyString('FromStopId'));
        $toOverride = trim($this->ReadPropertyString('ToStopId'));
        $fromName = trim($this->ReadPropertyString('FromStopName'));
        $toName = trim($this->ReadPropertyString('ToStopName'));

        $resolvedFrom = $force ? '' : $this->ReadAttributeString('ResolvedFromStopId');
        $resolvedTo = $force ? '' : $this->ReadAttributeString('ResolvedToStopId');

        if ($fromOverride !== '') {
            $resolvedFrom = $fromOverride;
            $resolvedFromName = $fromName !== '' ? $fromName : $fromOverride;
        } elseif ($resolvedFrom === '') {
            $hit = $this->ResolveStopName($fromName);
            $resolvedFrom = $hit['id'];
            $resolvedFromName = $hit['name'];
        } else {
            $resolvedFromName = $this->ReadAttributeString('ResolvedFromStopName');
        }

        if ($toOverride !== '') {
            $resolvedTo = $toOverride;
            $resolvedToName = $toName !== '' ? $toName : $toOverride;
        } elseif ($resolvedTo === '') {
            $hit = $this->ResolveStopName($toName);
            $resolvedTo = $hit['id'];
            $resolvedToName = $hit['name'];
        } else {
            $resolvedToName = $this->ReadAttributeString('ResolvedToStopName');
        }

        if ($resolvedFrom === '' || $resolvedTo === '') {
            throw new RuntimeException('Start- oder Ziel-Haltestelle konnte nicht aufgelöst werden.');
        }

        $this->WriteAttributeString('ResolvedFromStopId', $resolvedFrom);
        $this->WriteAttributeString('ResolvedToStopId', $resolvedTo);
        $this->WriteAttributeString('ResolvedFromStopName', $resolvedFromName);
        $this->WriteAttributeString('ResolvedToStopName', $resolvedToName);
        $this->SetValue('ResolvedFrom', $resolvedFromName . ' [' . $resolvedFrom . ']');
        $this->SetValue('ResolvedTo', $resolvedToName . ' [' . $resolvedTo . ']');

        return [$resolvedFrom, $resolvedTo];
    }

    private function ResolveStopName(string $query): array
    {
        if ($query === '') {
            throw new RuntimeException('Haltestellenname ist leer.');
        }

        $params = http_build_query([
            'query' => $query,
            'results' => 5,
            'stops' => 'true',
            'addresses' => 'false',
            'poi' => 'false',
            'linesOfStops' => 'false',
            'language' => 'de',
            'profile' => 'dbnav',
            'pretty' => 'false'
        ]);

        $data = $this->HttpJson(self::TRANSPORT_BASE . '/locations?' . $params);
        if (!is_array($data) || count($data) === 0) {
            throw new RuntimeException('Keine Haltestelle für „' . $query . '“ gefunden.');
        }

        $best = null;
        $queryNorm = $this->NormalizeText($query);
        foreach ($data as $candidate) {
            if (($candidate['type'] ?? '') !== 'stop' || empty($candidate['id']) || empty($candidate['name'])) {
                continue;
            }
            $candidateNorm = $this->NormalizeText((string) $candidate['name']);
            $score = $candidateNorm === $queryNorm ? 100
                : (strpos($candidateNorm, $queryNorm) === 0 ? 80
                : (strpos($candidateNorm, $queryNorm) !== false ? 60 : 10));

            if ($best === null || $score > $best['score']) {
                $best = ['id' => (string) $candidate['id'], 'name' => (string) $candidate['name'], 'score' => $score];
            }
        }

        if ($best === null) {
            throw new RuntimeException('Keine verwertbare Haltestelle für „' . $query . '“ gefunden.');
        }
        return ['id' => $best['id'], 'name' => $best['name']];
    }

    private function FetchJourney(string $from, string $to, int $arrivalTs): array
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
            'routingMode' => 'HYBRID',
            'pretty' => 'false'
        ]);

        $data = $this->HttpJson(self::TRANSPORT_BASE . '/journeys?' . $query);
        $journeys = $data['journeys'] ?? [];
        if (!is_array($journeys) || count($journeys) === 0) {
            throw new RuntimeException('Keine ÖPNV-Verbindung gefunden.');
        }

        $best = null;
        $bestArrival = 0;
        foreach ($journeys as $candidate) {
            $times = $this->JourneyTimes($candidate);
            if ($times['departure'] <= 0 || $times['arrival'] <= 0) {
                continue;
            }
            if (!$this->JourneyCancelled($candidate) && $times['arrival'] <= $arrivalTs && $times['arrival'] >= $bestArrival) {
                $best = $candidate;
                $bestArrival = $times['arrival'];
            }
        }
        return $best ?? $journeys[0];
    }

    private function RefreshJourney(string $refreshToken): array
    {
        $url = self::TRANSPORT_BASE . '/journeys/' . rawurlencode($refreshToken) . '?remarks=true&language=de&profile=dbnav&pretty=false';
        $data = $this->HttpJson($url);
        $journey = $data['journey'] ?? $data;
        if (!is_array($journey) || !isset($journey['legs'])) {
            throw new RuntimeException('Journey-Refresh lieferte keine verwertbare Verbindung.');
        }
        return $journey;
    }

    private function ApplyJourney(array $journey, int $targetArrival, bool $wasTracked, ?array $official): void
    {
        $times = $this->JourneyTimes($journey);
        $departure = $times['departure'];
        $arrival = $times['arrival'];
        if ($departure <= 0 || $arrival <= 0) {
            throw new RuntimeException('Verbindung enthält keine verwertbaren Zeiten.');
        }

        $cancelled = $this->JourneyCancelled($journey);
        $delay = $this->JourneyDelayMinutes($journey);
        $platform = $this->JourneyPlatform($journey);
        $summary = $this->JourneySummary($journey);
        $remarks = $this->JourneyRemarks($journey);

        if ($official !== null) {
            if (($official['changedDeparture'] ?? 0) > 0) {
                $departure = (int) $official['changedDeparture'];
            }
            $delay = max($delay, (int) ($official['delayMinutes'] ?? 0));
            if ((string) ($official['platform'] ?? '') !== '') {
                $platform = (string) $official['platform'];
            }
            $cancelled = $cancelled || (bool) ($official['cancelled'] ?? false);

            $this->SetValue('DBTimetablesMatch', (string) ($official['match'] ?? ''));
            $this->SetValue('DBTimetablesDelay', (int) ($official['delayMinutes'] ?? 0));
            $this->SetValue('DBTimetablesPlatform', (string) ($official['platform'] ?? ''));
            $this->SetValue('DBTimetablesCancelled', (bool) ($official['cancelled'] ?? false));
        } else {
            $this->SetValue('DBTimetablesMatch', '');
            $this->SetValue('DBTimetablesDelay', 0);
            $this->SetValue('DBTimetablesPlatform', '');
            $this->SetValue('DBTimetablesCancelled', false);
        }

        $leave = $departure
            - ($this->ReadPropertyInteger('WalkToStopMinutes') * 60)
            - ($this->ReadPropertyInteger('SafetyBufferMinutes') * 60);
        $minutesToLeave = (int) floor(($leave - time()) / 60);

        $status = 'OK';
        $recommendation = 'Planmäßig losfahren';
        if ($cancelled) {
            $status = 'AUSFALL';
            $recommendation = 'Gewählte Verbindung ist ausgefallen – Alternative prüfen';
        } elseif ($arrival > $targetArrival) {
            $status = 'ZU SPÄT';
            $recommendation = 'Gewählte Verbindung erreicht das Ziel nach der Wunschzeit';
        } elseif ($minutesToLeave <= 0) {
            $status = 'JETZT LOS';
            $recommendation = 'Jetzt das Haus verlassen';
        } elseif ($minutesToLeave <= 10) {
            $status = 'BALD LOS';
            $recommendation = 'In ' . $minutesToLeave . ' min das Haus verlassen';
        } elseif ($delay >= 10) {
            $status = 'VERSPÄTET';
            $recommendation = 'Gewählte Verbindung +' . $delay . ' min; Abfahrtszeit neu berechnet';
        }

        $newToken = (string) ($journey['refreshToken'] ?? '');
        if ($newToken !== '') {
            $this->WriteAttributeString('JourneyRefreshToken', $newToken);
        }

        $this->SetValue('LeaveHomeAt', $leave);
        $this->SetValue('MinutesToLeave', $minutesToLeave);
        $this->SetValue('MobilityStatus', $status);
        $this->SetValue('Recommendation', $recommendation);
        $this->SetValue('JourneySummary', $summary);
        $this->SetValue('JourneyDeparture', $departure);
        $this->SetValue('JourneyArrival', $arrival);
        $this->SetValue('DelayMinutes', $delay);
        $this->SetValue('Platform', $platform);
        $this->SetValue('Cancelled', $cancelled);
        $this->SetValue('Disruptions', $remarks);
        $this->SetValue('JourneyTracked', $wasTracked || $newToken !== '');
    }

    private function VerifyWithDBTimetables(array $journey): ?array
    {
        $leg = $this->FirstTransitLeg($journey);
        if ($leg === null) {
            return null;
        }

        $plannedTs = $this->IsoToTs((string) ($leg['plannedDeparture'] ?? $leg['departure'] ?? ''));
        if ($plannedTs <= 0) {
            return null;
        }

        $eva = $this->ResolveDBEva(false);
        if ($eva === '') {
            return null;
        }

        $planStops = [];
        foreach ([-3600, 0, 3600] as $offset) {
            $t = $plannedTs + $offset;
            $url = self::DB_TIMETABLES_BASE . '/plan/' . rawurlencode($eva) . '/' . date('ymd', $t) . '/' . date('H', $t);
            try {
                $xml = $this->HttpXml($url, true);
                foreach ($xml->s as $stop) {
                    $planStops[] = $stop;
                }
            } catch (Throwable $e) {
                $this->SendDebug('DB plan hour failed', $e->getMessage(), 0);
            }
        }

        if (count($planStops) === 0) {
            return null;
        }

        $lineNumber = $this->LegLineNumber($leg);
        $best = null;
        $bestScore = PHP_INT_MAX;

        foreach ($planStops as $stop) {
            if (!isset($stop->dp)) {
                continue;
            }
            $pt = (string) $stop->dp['pt'];
            $ptTs = $this->IrisTimeToTs($pt);
            if ($ptTs <= 0) {
                continue;
            }

            $candidateNumber = isset($stop->tl) ? trim((string) $stop->tl['n']) : '';
            $delta = abs($ptTs - $plannedTs);
            if ($lineNumber !== '' && $candidateNumber !== '' && $candidateNumber !== $lineNumber) {
                $delta += 1800;
            }

            if ($delta < $bestScore && $delta <= 5400) {
                $best = $stop;
                $bestScore = $delta;
            }
        }

        if ($best === null) {
            return null;
        }

        $stopId = (string) $best['id'];
        $plannedDeparture = $this->IrisTimeToTs((string) $best->dp['pt']);
        $plannedPlatform = (string) $best->dp['pp'];
        $category = isset($best->tl) ? (string) $best->tl['c'] : '';
        $number = isset($best->tl) ? (string) $best->tl['n'] : '';

        $changedDeparture = 0;
        $changedPlatform = '';
        $cancelled = false;

        $changesXml = $this->HttpXml(self::DB_TIMETABLES_BASE . '/fchg/' . rawurlencode($eva), true);
        foreach ($changesXml->s as $changeStop) {
            if ((string) $changeStop['id'] !== $stopId || !isset($changeStop->dp)) {
                continue;
            }
            $ct = (string) $changeStop->dp['ct'];
            $cp = (string) $changeStop->dp['cp'];
            $cs = strtolower((string) $changeStop->dp['cs']);

            if ($ct !== '') {
                $changedDeparture = $this->IrisTimeToTs($ct);
            }
            if ($cp !== '') {
                $changedPlatform = $cp;
            }
            $cancelled = in_array($cs, ['c', 'a'], true);
            break;
        }

        $actualDeparture = $changedDeparture > 0 ? $changedDeparture : $plannedDeparture;
        $delayMinutes = ($plannedDeparture > 0 && $actualDeparture > 0)
            ? max(0, (int) round(($actualDeparture - $plannedDeparture) / 60))
            : 0;

        return [
            'changedDeparture' => $actualDeparture,
            'delayMinutes' => $delayMinutes,
            'platform' => $changedPlatform !== '' ? $changedPlatform : $plannedPlatform,
            'cancelled' => $cancelled,
            'match' => trim($category . ' ' . $number . ' · ' . date('H:i', $plannedDeparture) . ' · EVA ' . $eva)
        ];
    }

    private function ResolveDBEva(bool $force): string
    {
        $override = trim($this->ReadPropertyString('DBEvaNumber'));
        if ($override !== '') {
            $this->WriteAttributeString('ResolvedDBEva', $override);
            return $override;
        }

        if (!$force) {
            $cached = $this->ReadAttributeString('ResolvedDBEva');
            if ($cached !== '') {
                return $cached;
            }
        }

        $name = trim($this->ReadAttributeString('ResolvedFromStopName'));
        if ($name === '') {
            $name = trim($this->ReadPropertyString('FromStopName'));
        }
        if ($name === '') {
            return '';
        }

        $pattern = rawurlencode($name);
        $xml = $this->HttpXml(self::DB_TIMETABLES_BASE . '/station/' . $pattern, true);

        $bestEva = '';
        $bestScore = -1;
        $queryNorm = $this->NormalizeText($name);

        $stations = isset($xml->station) ? $xml->station : $xml;
        foreach ($stations as $station) {
            $eva = trim((string) $station['eva']);
            $stationName = trim((string) $station['name']);
            if ($eva === '') {
                continue;
            }
            $candidateNorm = $this->NormalizeText($stationName);
            $score = $candidateNorm === $queryNorm ? 100
                : (strpos($candidateNorm, $queryNorm) !== false ? 60 : 10);
            if ($score > $bestScore) {
                $bestEva = $eva;
                $bestScore = $score;
            }
        }

        if ($bestEva !== '') {
            $this->WriteAttributeString('ResolvedDBEva', $bestEva);
        }
        return $bestEva;
    }

    private function FirstTransitLeg(array $journey): ?array
    {
        foreach (($journey['legs'] ?? []) as $leg) {
            if (($leg['walking'] ?? false) === true) {
                continue;
            }
            if (isset($leg['line'])) {
                return $leg;
            }
        }
        return null;
    }

    private function LegLineNumber(array $leg): string
    {
        foreach (['fahrtNr', 'name'] as $key) {
            $value = trim((string) ($leg['line'][$key] ?? ''));
            if ($value !== '') {
                if (preg_match('/(\d{1,6})/', $value, $m)) {
                    return ltrim($m[1], '0');
                }
                return $value;
            }
        }
        return '';
    }

    private function JourneyTimes(array $journey): array
    {
        $legs = $journey['legs'] ?? [];
        if (!is_array($legs) || count($legs) === 0) {
            return ['departure' => 0, 'arrival' => 0];
        }
        $first = $legs[0];
        $last = $legs[count($legs) - 1];
        return [
            'departure' => $this->IsoToTs((string) ($first['departure'] ?? $first['plannedDeparture'] ?? '')),
            'arrival' => $this->IsoToTs((string) ($last['arrival'] ?? $last['plannedArrival'] ?? ''))
        ];
    }

    private function JourneyCancelled(array $journey): bool
    {
        foreach (($journey['legs'] ?? []) as $leg) {
            if (($leg['cancelled'] ?? false) === true) {
                return true;
            }
        }
        return false;
    }

    private function JourneyDelayMinutes(array $journey): int
    {
        $maxDelay = 0;
        foreach (($journey['legs'] ?? []) as $leg) {
            if (($leg['walking'] ?? false) === true) {
                continue;
            }
            foreach (['departureDelay', 'arrivalDelay'] as $key) {
                $delay = $leg[$key] ?? null;
                if (is_numeric($delay)) {
                    $maxDelay = max($maxDelay, (int) round(((int) $delay) / 60));
                }
            }
        }
        return $maxDelay;
    }

    private function JourneyPlatform(array $journey): string
    {
        foreach (($journey['legs'] ?? []) as $leg) {
            if (($leg['walking'] ?? false) === true) {
                continue;
            }
            $platform = $leg['departurePlatform'] ?? $leg['plannedDeparturePlatform'] ?? '';
            if ($platform !== null && (string) $platform !== '') {
                return (string) $platform;
            }
        }
        return '';
    }

    private function JourneySummary(array $journey): string
    {
        $parts = [];
        foreach (($journey['legs'] ?? []) as $leg) {
            if (($leg['walking'] ?? false) === true) {
                $parts[] = 'Fußweg';
                continue;
            }
            $line = $leg['line']['name'] ?? $leg['line']['fahrtNr'] ?? null;
            if ($line !== null && $line !== '') {
                $parts[] = (string) $line;
            }
        }
        return implode(' → ', array_values(array_unique($parts)));
    }

    private function JourneyRemarks(array $journey): string
    {
        $texts = [];
        foreach (($journey['legs'] ?? []) as $leg) {
            foreach (($leg['remarks'] ?? []) as $remark) {
                $text = trim((string) ($remark['text'] ?? $remark['summary'] ?? ''));
                if ($text !== '') {
                    $texts[] = $text;
                }
            }
        }
        return implode(' | ', array_slice(array_values(array_unique($texts)), 0, 8));
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
        $features = $geo['features'] ?? [];
        $corridorKm = (float) $this->ReadPropertyInteger('RoadCorridorKm');
        $hits = [];

        foreach ($features as $feature) {
            $point = $this->RepresentativePoint($feature['geometry'] ?? []);
            if ($point === null) {
                continue;
            }
            [$lon, $lat] = $point;
            if ($this->DistancePointToSegmentKm($lat, $lon, $hLat, $hLon, $dLat, $dLon) > $corridorKm) {
                continue;
            }

            $p = $feature['properties'] ?? [];
            $text = $this->FirstNonEmpty($p, ['description', 'title', 'comment', 'reason', 'street', 'road', 'name']);
            $hits[] = $text !== '' ? $text : 'Baustelle/Ereignis';
            if (count($hits) >= 10) {
                break;
            }
        }

        $hits = array_values(array_unique($hits));
        $this->SetValue('RoadworksCount', count($hits));
        $this->SetValue('RoadworksSummary', count($hits) > 0 ? implode(' | ', $hits) : 'Keine Baustelle im konfigurierten Korridor erkannt');
    }

    private function RepresentativePoint(array $geometry): ?array
    {
        $coords = $geometry['coordinates'] ?? null;
        if (!is_array($coords)) {
            return null;
        }
        while (is_array($coords) && isset($coords[0]) && is_array($coords[0])) {
            $coords = $coords[0];
        }
        if (!is_array($coords) || count($coords) < 2 || !is_numeric($coords[0]) || !is_numeric($coords[1])) {
            return null;
        }
        return [(float) $coords[0], (float) $coords[1]];
    }

    private function DistancePointToSegmentKm(float $lat, float $lon, float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $refLat = deg2rad(($lat1 + $lat2 + $lat) / 3.0);
        $kx = 111.320 * cos($refLat);
        $ky = 110.574;
        $px = $lon * $kx;
        $py = $lat * $ky;
        $x1 = $lon1 * $kx;
        $y1 = $lat1 * $ky;
        $x2 = $lon2 * $kx;
        $y2 = $lat2 * $ky;
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        if ($dx == 0.0 && $dy == 0.0) {
            return hypot($px - $x1, $py - $y1);
        }
        $t = (($px - $x1) * $dx + ($py - $y1) * $dy) / (($dx * $dx) + ($dy * $dy));
        $t = max(0.0, min(1.0, $t));
        return hypot($px - ($x1 + $t * $dx), $py - ($y1 + $t * $dy));
    }

    private function FirstNonEmpty(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $value = trim((string) $data[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    private function HttpJson(string $url): array
    {
        $body = $this->HttpRequest($url, ['Accept: application/json']);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Datenquelle lieferte ungültiges JSON.');
        }
        return $decoded;
    }

    private function HttpXml(string $url, bool $dbAuth = false): SimpleXMLElement
    {
        $headers = ['Accept: application/xml'];
        if ($dbAuth) {
            $headers[] = 'DB-Client-Id: ' . trim($this->ReadPropertyString('DBClientId'));
            $headers[] = 'DB-Api-Key: ' . trim($this->ReadPropertyString('DBApiKey'));
        }

        $body = $this->HttpRequest($url, $headers);
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        if ($xml === false) {
            libxml_clear_errors();
            throw new RuntimeException('DB Timetables lieferte ungültiges XML.');
        }
        libxml_clear_errors();
        return $xml;
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
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'IP-Symcon OchsMobility/0.3',
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
            throw new RuntimeException('Datenquelle antwortet mit HTTP ' . $status . '.');
        }
        return (string) $body;
    }

    private function IsoToTs(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? 0 : $timestamp;
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

    private function ConfigurationSignature(): string
    {
        return sha1(implode('|', [
            trim($this->ReadPropertyString('FromStopName')),
            trim($this->ReadPropertyString('ToStopName')),
            trim($this->ReadPropertyString('FromStopId')),
            trim($this->ReadPropertyString('ToStopId')),
            trim($this->ReadPropertyString('DBEvaNumber'))
        ]));
    }

    private function ClearResolvedStops(): void
    {
        $this->WriteAttributeString('ResolvedFromStopId', '');
        $this->WriteAttributeString('ResolvedToStopId', '');
        $this->WriteAttributeString('ResolvedFromStopName', '');
        $this->WriteAttributeString('ResolvedToStopName', '');
        $this->SetValue('ResolvedFrom', '');
        $this->SetValue('ResolvedTo', '');
    }

    private function ClearTrackedJourney(): void
    {
        $this->WriteAttributeString('JourneyRefreshToken', '');
        $this->SetValue('JourneyTracked', false);
    }
}
