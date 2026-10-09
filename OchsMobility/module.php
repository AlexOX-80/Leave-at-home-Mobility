<?php

declare(strict_types=1);

class OchsMobility extends IPSModule
{
    private const TRANSPORT_BASE = 'https://v6.db.transport.rest';
    private const MOBIDATA_ROADWORKS = 'https://api.mobidata-bw.de/datasets/traffic/roadworks/roadworks_geojson.json';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('FromStopId', '');
        $this->RegisterPropertyString('ToStopId', '');
        $this->RegisterPropertyInteger('WalkToStopMinutes', 8);
        $this->RegisterPropertyInteger('SafetyBufferMinutes', 5);
        $this->RegisterPropertyInteger('DefaultArrivalLeadMinutes', 60);
        $this->RegisterPropertyBoolean('EnableRoadworks', true);
        $this->RegisterPropertyFloat('HomeLatitude', 0.0);
        $this->RegisterPropertyFloat('HomeLongitude', 0.0);
        $this->RegisterPropertyFloat('DestinationLatitude', 0.0);
        $this->RegisterPropertyFloat('DestinationLongitude', 0.0);
        $this->RegisterPropertyInteger('RoadCorridorKm', 5);
        $this->RegisterPropertyInteger('UpdateIntervalSeconds', 120);

        $this->RegisterAttributeInteger('TargetArrival', 0);
        $this->RegisterAttributeString('JourneyRefreshToken', '');

        $this->RegisterVariableInteger('TargetArrival', 'Gewünschte Ankunft', '~UnixTimestamp', 10);
        $this->RegisterVariableInteger('LeaveHomeAt', 'Haus verlassen', '~UnixTimestamp', 20);
        $this->RegisterVariableInteger('MinutesToLeave', 'Noch bis Abfahrt', '', 30);
        $this->RegisterVariableString('MobilityStatus', 'Mobilitätsstatus', '', 40);
        $this->RegisterVariableString('Recommendation', 'Empfehlung', '', 50);

        $this->RegisterVariableString('JourneySummary', 'Verbindung', '', 100);
        $this->RegisterVariableInteger('JourneyDeparture', 'Abfahrt Verbindung', '~UnixTimestamp', 110);
        $this->RegisterVariableInteger('JourneyArrival', 'Ankunft Verbindung', '~UnixTimestamp', 120);
        $this->RegisterVariableInteger('DelayMinutes', 'Verspätung', '', 130);
        $this->RegisterVariableString('Platform', 'Gleis / Steig', '', 140);
        $this->RegisterVariableBoolean('Cancelled', 'Verbindung ausgefallen', '~Switch', 150);
        $this->RegisterVariableString('Disruptions', 'Hinweise / Störungen', '', 160);

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

        if ($this->ReadPropertyString('FromStopId') === '' || $this->ReadPropertyString('ToStopId') === '') {
            $this->SetStatus(201);
        } else {
            $this->SetStatus(102);
        }
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

    public function Update(): bool
    {
        $from = trim($this->ReadPropertyString('FromStopId'));
        $to = trim($this->ReadPropertyString('ToStopId'));

        if ($from === '' || $to === '') {
            $this->SetStatus(201);
            $this->SetValue('LastError', 'Start- oder Ziel-Haltestelle fehlt.');
            return false;
        }

        $target = $this->ReadAttributeInteger('TargetArrival');
        if ($target <= time()) {
            $target = time() + ($this->ReadPropertyInteger('DefaultArrivalLeadMinutes') * 60);
            $this->SetValue('TargetArrival', $target);
        }

        try {
            $journey = $this->FetchJourney($from, $to, $target);
            $this->ApplyJourney($journey, $target);

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
            $cancelled = $this->JourneyCancelled($candidate);
            if (!$cancelled && $times['arrival'] <= $arrivalTs && $times['arrival'] >= $bestArrival) {
                $best = $candidate;
                $bestArrival = $times['arrival'];
            }
        }
        if ($best === null) {
            $best = $journeys[0];
        }

        return $best;
    }

    private function ApplyJourney(array $journey, int $targetArrival): void
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

        $leave = $departure
            - ($this->ReadPropertyInteger('WalkToStopMinutes') * 60)
            - ($this->ReadPropertyInteger('SafetyBufferMinutes') * 60);
        $minutesToLeave = (int) floor(($leave - time()) / 60);

        $status = 'OK';
        $recommendation = 'Planmäßig losfahren';
        if ($cancelled) {
            $status = 'AUSFALL';
            $recommendation = 'Alternative Verbindung oder anderes Verkehrsmittel prüfen';
        } elseif ($minutesToLeave <= 0) {
            $status = 'JETZT LOS';
            $recommendation = 'Jetzt das Haus verlassen';
        } elseif ($minutesToLeave <= 10) {
            $status = 'BALD LOS';
            $recommendation = 'In ' . $minutesToLeave . ' min das Haus verlassen';
        } elseif ($arrival > $targetArrival) {
            $status = 'ZU SPÄT';
            $recommendation = 'Diese Verbindung erreicht das Ziel nach der Wunschzeit';
        } elseif ($delay >= 10) {
            $status = 'VERSPÄTET';
            $recommendation = 'Verspätung beobachten; Abfahrtszeit wurde neu berechnet';
        }

        $this->WriteAttributeString('JourneyRefreshToken', (string) ($journey['refreshToken'] ?? ''));
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
        foreach (($journey['legs'] ?? []) as $leg) {
            if (($leg['walking'] ?? false) === true) {
                continue;
            }
            $delay = $leg['departureDelay'] ?? $leg['arrivalDelay'] ?? null;
            if (is_numeric($delay)) {
                return (int) round(((int) $delay) / 60);
            }
        }
        return 0;
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
        $texts = array_values(array_unique($texts));
        return implode(' | ', array_slice($texts, 0, 8));
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
            $distance = $this->DistancePointToSegmentKm($lat, $lon, $hLat, $hLon, $dLat, $dLon);
            if ($distance > $corridorKm) {
                continue;
            }

            $p = $feature['properties'] ?? [];
            $text = $this->FirstNonEmpty($p, ['description', 'title', 'comment', 'reason', 'street', 'road', 'name']);
            if ($text === '') {
                $text = 'Baustelle/Ereignis';
            }
            $hits[] = $text;
            if (count($hits) >= 10) {
                break;
            }
        }

        $this->SetValue('RoadworksCount', count($hits));
        $this->SetValue('RoadworksSummary', count($hits) > 0 ? implode(' | ', array_values(array_unique($hits))) : 'Keine Baustelle im konfigurierten Korridor erkannt');
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
        $px = $lon * $kx; $py = $lat * $ky;
        $x1 = $lon1 * $kx; $y1 = $lat1 * $ky;
        $x2 = $lon2 * $kx; $y2 = $lat2 * $ky;
        $dx = $x2 - $x1; $dy = $y2 - $y1;
        $len2 = ($dx * $dx) + ($dy * $dy);
        if ($len2 <= 0.000001) {
            return sqrt((($px - $x1) ** 2) + (($py - $y1) ** 2));
        }
        $t = (($px - $x1) * $dx + ($py - $y1) * $dy) / $len2;
        $t = max(0.0, min(1.0, $t));
        $cx = $x1 + $t * $dx; $cy = $y1 + $t * $dy;
        return sqrt((($px - $cx) ** 2) + (($py - $cy) ** 2));
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
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('HTTP-Client konnte nicht initialisiert werden.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: OchsSmartHome-Mobility/0.1'
            ]
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('HTTP-Fehler ' . $status . ($error !== '' ? ': ' . $error : ''));
        }
        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            throw new RuntimeException('Ungültige JSON-Antwort von Datenquelle.');
        }
        return $json;
    }

    private function IsoToTs(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        $ts = strtotime($value);
        return $ts === false ? 0 : $ts;
    }
}
