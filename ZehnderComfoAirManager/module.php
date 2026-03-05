<?php

declare(strict_types=1);

class ZehnderComfoAirManager extends IPSModule
{
    // ─────────────────────────────────────────
    // Protokollkonstanten
    // ─────────────────────────────────────────
    private const START = "\x07\xF0";
    private const END   = "\x07\x0F";
    private const ACK   = "\x07\xF3";

    // Kommandos
    private const CMD_GET_FILTER_STATUS    = 0x00D9;
    private const CMD_RESET_FILTER         = 0x00DB;
    private const CMD_GET_OPERATING_HOURS  = 0x00DD;
    private const CMD_SET_FAN_LEVEL        = 0x0099;
    private const CMD_GET_FAN_LEVEL        = 0x00CD;
    private const CMD_SET_FAN_PERCENT      = 0x00CF; // NEU: Prozent pro Stufe setzen
    private const CMD_GET_FAN_PERCENT      = 0x00CE; // Response: Prozent pro Stufe

    // Timer
    private const HEAT_CONTROL_INTERVAL    = 15 * 60 * 1000;
    private const FILTER_CHECK_INTERVAL    =  6 * 60 * 60 * 1000;

    // Standard-Prozentsätze pro Stufe (Defaults)
    // Abluft: Abwesend=15%, Niedrig=47%, Mittel=60%, Hoch=90%
    // Zuluft: Abwesend=15%, Niedrig=47%, Mittel=60%, Hoch=90%
    private const DEFAULT_PERCENT = [
        'AbluftAbwesend' => 15,
        'AbluftNiedrig'  => 47,
        'AbluftMittel'   => 60,
        'AbluftHoch'     => 90,
        'ZuluftAbwesend' => 15,
        'ZuluftNiedrig'  => 47,
        'ZuluftMittel'   => 60,
        'ZuluftHoch'     => 90,
    ];

    // ─────────────────────────────────────────
    // Create
    // ─────────────────────────────────────────
    public function Create(): void
    {
        parent::Create();

        // Gateway (Client Socket)
        $this->RequireParent('{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}');

        // ── Allgemeine Properties ──
        $this->RegisterPropertyBoolean('active',           true);
        $this->RegisterPropertyInteger('AutoReadInterval', 30);

        // ── Filter Properties ──
        $this->RegisterPropertyBoolean('ShowFilterStatus', true);
        $this->RegisterPropertyBoolean('ShowFilterHours',  true);
        $this->RegisterPropertyInteger('FilterWarnHours',  2000);
        $this->RegisterPropertyBoolean('FilterNotify',     false);

        // ── Hitzesteuerung Properties ──
        $this->RegisterPropertyInteger('InsideTempVarID',  0);
        $this->RegisterPropertyInteger('OutsideTempVarID', 0);
        $this->RegisterPropertyFloat('ComfortTemp',        24.0);

        // ── Ventilationsstufen % Properties (NEU) ──
        // Abluft
        $this->RegisterPropertyInteger('PercentAbluftAbwesend', self::DEFAULT_PERCENT['AbluftAbwesend']);
        $this->RegisterPropertyInteger('PercentAbluftNiedrig',  self::DEFAULT_PERCENT['AbluftNiedrig']);
        $this->RegisterPropertyInteger('PercentAbluftMittel',   self::DEFAULT_PERCENT['AbluftMittel']);
        $this->RegisterPropertyInteger('PercentAbluftHoch',     self::DEFAULT_PERCENT['AbluftHoch']);
        // Zuluft
        $this->RegisterPropertyInteger('PercentZuluftAbwesend', self::DEFAULT_PERCENT['ZuluftAbwesend']);
        $this->RegisterPropertyInteger('PercentZuluftNiedrig',  self::DEFAULT_PERCENT['ZuluftNiedrig']);
        $this->RegisterPropertyInteger('PercentZuluftMittel',   self::DEFAULT_PERCENT['ZuluftMittel']);
        $this->RegisterPropertyInteger('PercentZuluftHoch',     self::DEFAULT_PERCENT['ZuluftHoch']);

        // ── Attribute ──
        $this->RegisterAttributeInteger('HeatControlPrevStage',          -1);
        $this->RegisterAttributeBoolean('HeatControlPrevScheduleActive', false);
        $this->RegisterAttributeInteger('FilterResetTimestamp',            0);
        $this->RegisterAttributeString('RxBuffer',                        '');

        // ── Timer ──
        $this->RegisterTimer('AutoReadTimer',
            0, 'ZCAM_AutoRead($_IPS[\'TARGET\']);');
        $this->RegisterTimer('HeatControlTimer',
            0, 'ZCAM_HeatControl($_IPS[\'TARGET\']);');
        $this->RegisterTimer('FilterCheckTimer',
            0, 'ZCAM_CheckFilterHours($_IPS[\'TARGET\']);');
    }

    // ─────────────────────────────────────────
    // Destroy
    // ─────────────────────────────────────────
    public function Destroy(): void
    {
        parent::Destroy();
    }

    // ─────────────────────────────────────────
    // ApplyChanges
    // ─────────────────────────────────────────
    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterVariables();
        $this->RegisterProfiles();

        // ── AutoRead Timer ──
        $interval = $this->ReadPropertyInteger('AutoReadInterval');
        $active   = $this->ReadPropertyBoolean('active');
        $this->SetTimerInterval(
            'AutoReadTimer',
            ($active && $interval > 0) ? $interval * 1000 : 0
        );

        // ── Filter Check Timer ──
        $this->SetTimerInterval('FilterCheckTimer', self::FILTER_CHECK_INTERVAL);

        // ── Hitzesteuerung Timer ──
        $insideVar  = $this->ReadPropertyInteger('InsideTempVarID');
        $outsideVar = $this->ReadPropertyInteger('OutsideTempVarID');
        $enabled    = $this->GetValue('Hitzesteuerung');

        if ($enabled && $insideVar >= 10000 && $outsideVar >= 10000) {
            $this->SetTimerInterval('HeatControlTimer', self::HEAT_CONTROL_INTERVAL);
        } else {
            $this->SetTimerInterval('HeatControlTimer', 0);
        }

        $this->CreateWochenplan();
        $this->EnsureFilterResetScript();

        $this->SendDebug(__FUNCTION__, 'ApplyChanges abgeschlossen', 0);
    }

    // ─────────────────────────────────────────
    // Variablen registrieren
    // ─────────────────────────────────────────
    private function RegisterVariables(): void
    {
        // ── Lüftungsstufe ──
        $this->RegisterVariableInteger(
            'vsAktuelleStufe', 'Lüftungsstufe', 'ZCAM.Stufe', 10);
        $this->EnableAction('vsAktuelleStufe');

        $this->RegisterVariableInteger(
            'vsAbluftAktuell', 'Abluft aktuell', 'ZCAM.Percent', 20);
        $this->RegisterVariableInteger(
            'vsZuluftAktuell', 'Zuluft aktuell', 'ZCAM.Percent', 21);

        // ── Ventilationsstufen % – Abluft (NEU) ──
        $this->RegisterVariableInteger(
            'vsPercentAbluftAbwesend',
            'Abluft % – Stufe 0 (Abwesend)',
            'ZCAM.Percent', 30);
        $this->EnableAction('vsPercentAbluftAbwesend');

        $this->RegisterVariableInteger(
            'vsPercentAbluftNiedrig',
            'Abluft % – Stufe 1 (Niedrig)',
            'ZCAM.Percent', 31);
        $this->EnableAction('vsPercentAbluftNiedrig');

        $this->RegisterVariableInteger(
            'vsPercentAbluftMittel',
            'Abluft % – Stufe 2 (Mittel)',
            'ZCAM.Percent', 32);
        $this->EnableAction('vsPercentAbluftMittel');

        $this->RegisterVariableInteger(
            'vsPercentAbluftHoch',
            'Abluft % – Stufe 3 (Hoch)',
            'ZCAM.Percent', 33);
        $this->EnableAction('vsPercentAbluftHoch');

        // ── Ventilationsstufen % – Zuluft (NEU) ──
        $this->RegisterVariableInteger(
            'vsPercentZuluftAbwesend',
            'Zuluft % – Stufe 0 (Abwesend)',
            'ZCAM.Percent', 34);
        $this->EnableAction('vsPercentZuluftAbwesend');

        $this->RegisterVariableInteger(
            'vsPercentZuluftNiedrig',
            'Zuluft % – Stufe 1 (Niedrig)',
            'ZCAM.Percent', 35);
        $this->EnableAction('vsPercentZuluftNiedrig');

        $this->RegisterVariableInteger(
            'vsPercentZuluftMittel',
            'Zuluft % – Stufe 2 (Mittel)',
            'ZCAM.Percent', 36);
        $this->EnableAction('vsPercentZuluftMittel');

        $this->RegisterVariableInteger(
            'vsPercentZuluftHoch',
            'Zuluft % – Stufe 3 (Hoch)',
            'ZCAM.Percent', 37);
        $this->EnableAction('vsPercentZuluftHoch');

        // ── Filter ──
        if ($this->ReadPropertyBoolean('ShowFilterStatus')) {
            $this->RegisterVariableBoolean(
                'filterWechsel', 'Filter wechseln', 'ZCAM.FilterStatus', 50);
        }
        if ($this->ReadPropertyBoolean('ShowFilterHours')) {
            $this->RegisterVariableInteger(
                'filterBetriebsstunden', 'Betriebsstunden Filter', 'ZCAM.Stunden', 51);
        }
        $this->RegisterVariableString(
            'filterLetzterReset', 'Filter letzter Reset', '', 52);

        // ── Temperaturen ──
        $this->RegisterVariableFloat(
            'tempZuluft',    'Temperatur Zuluft',    'ZCAM.Temperatur', 60);
        $this->RegisterVariableFloat(
            'tempAbluft',    'Temperatur Abluft',    'ZCAM.Temperatur', 61);
        $this->RegisterVariableFloat(
            'tempAussenluft','Temperatur Außen',     'ZCAM.Temperatur', 62);
        $this->RegisterVariableFloat(
            'tempFortluft',  'Temperatur Fortluft',  'ZCAM.Temperatur', 63);

        // ── Hitzesteuerung ──
        $this->RegisterVariableBoolean(
            'Hitzesteuerung', 'Hitzesteuerung aktiv', '~Switch', 70);
        $this->EnableAction('Hitzesteuerung');

        // ── Default-Werte für %-Schieberegler setzen ──
        // (nur beim ersten Mal, wenn noch 0)
        $defaults = [
            'vsPercentAbluftAbwesend' => $this->ReadPropertyInteger('PercentAbluftAbwesend'),
            'vsPercentAbluftNiedrig'  => $this->ReadPropertyInteger('PercentAbluftNiedrig'),
            'vsPercentAbluftMittel'   => $this->ReadPropertyInteger('PercentAbluftMittel'),
            'vsPercentAbluftHoch'     => $this->ReadPropertyInteger('PercentAbluftHoch'),
            'vsPercentZuluftAbwesend' => $this->ReadPropertyInteger('PercentZuluftAbwesend'),
            'vsPercentZuluftNiedrig'  => $this->ReadPropertyInteger('PercentZuluftNiedrig'),
            'vsPercentZuluftMittel'   => $this->ReadPropertyInteger('PercentZuluftMittel'),
            'vsPercentZuluftHoch'     => $this->ReadPropertyInteger('PercentZuluftHoch'),
        ];

        foreach ($defaults as $ident => $default) {
            if ($this->GetValue($ident) === 0) {
                $this->SetValue($ident, $default);
            }
        }
    }

    // ─────────────────────────────────────────
    // Profile registrieren
    // ─────────────────────────────────────────
    private function RegisterProfiles(): void
    {
        $profiles = [
            'ZCAM.Stufe' => [
                'type' => VARIABLETYPE_INTEGER,
                'icon' => 'Ventilation',
                'associations' => [
                    [0, 'Auto',           '', 0x808080],
                    [1, 'Aus',            '', 0xFF0000],
                    [2, 'Stufe 1 (low)',  '', 0x6DBEE4],
                    [3, 'Stufe 2 (med)',  '', 0x1869E2],
                    [4, 'Stufe 3 (high)', '', 0x1E407D],
                ],
            ],
            'ZCAM.Percent' => [
                'type'   => VARIABLETYPE_INTEGER,
                'icon'   => 'Repeat',
                'suffix' => ' %',
                'min'    => 0,
                'max'    => 100,
                'step'   => 1,
            ],
            'ZCAM.Stunden' => [
                'type'   => VARIABLETYPE_INTEGER,
                'icon'   => 'Clock',
                'suffix' => ' h',
                'min'    => 0,
                'max'    => 99999,
                'step'   => 1,
            ],
            'ZCAM.Temperatur' => [
                'type'   => VARIABLETYPE_FLOAT,
                'icon'   => 'Temperature',
                'suffix' => ' °C',
                'min'    => -20.0,
                'max'    =>  60.0,
                'step'   =>   0.5,
                'digits' => 1,
            ],
            'ZCAM.FilterStatus' => [
                'type' => VARIABLETYPE_BOOLEAN,
                'icon' => 'Alert',
                'associations' => [
                    [false, 'OK',             '', 0x00CC00],
                    [true,  'Wechsel fällig', '', 0xFF6600],
                ],
            ],
        ];

        foreach ($profiles as $name => $cfg) {
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, $cfg['type']);
            }

            IPS_SetVariableProfileIcon($name, $cfg['icon'] ?? '');

            if (!empty($cfg['suffix'])) {
                IPS_SetVariableProfileText($name, '', $cfg['suffix']);
            }

            if (isset($cfg['min'])) {
                IPS_SetVariableProfileValues($name, $cfg['min'], $cfg['max'], $cfg['step']);
            }

            if (isset($cfg['digits'])) {
                IPS_SetVariableProfileDigits($name, $cfg['digits']);
            }

            if (!empty($cfg['associations'])) {
                foreach ($cfg['associations'] as $assoc) {
                    IPS_SetVariableProfileAssociation(
                        $name,
                        $assoc[0],
                        $assoc[1],
                        $assoc[2] ?? '',
                        $assoc[3] ?? -1
                    );
                }
            }
        }
    }

    // ─────────────────────────────────────────
    // RequestAction
    // ─────────────────────────────────────────
    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {

            // ── Lüftungsstufe ──
            case 'vsAktuelleStufe':
                $this->SetFanLevel((int)$Value);
                break;

            // ── Ventilationsstufen % Abluft ──
            case 'vsPercentAbluftAbwesend':
            case 'vsPercentAbluftNiedrig':
            case 'vsPercentAbluftMittel':
            case 'vsPercentAbluftHoch':
            // ── Ventilationsstufen % Zuluft ──
            case 'vsPercentZuluftAbwesend':
            case 'vsPercentZuluftNiedrig':
            case 'vsPercentZuluftMittel':
            case 'vsPercentZuluftHoch':
                $this->SetValue($Ident, (int)$Value);
                $this->SendFanPercent();
                break;

            // ── Hitzesteuerung ──
            case 'Hitzesteuerung':
                $this->SetValue('Hitzesteuerung', (bool)$Value);
                $this->ApplyChanges();
                break;

            default:
                $this->SendDebug('RequestAction', "Unbekannter Ident: $Ident", 0);
        }
    }

    // ─────────────────────────────────────────
    // Lüftungsstufe setzen
    // ─────────────────────────────────────────
    public function SetFanLevel(int $level): void
    {
        if ($level < 0 || $level > 4) {
            $this->SendDebug('SetFanLevel', "Ungültige Stufe: $level", 0);
            return;
        }

        $this->SendDebug('SetFanLevel', "Setze Stufe: $level", 0);
        $this->SendCommand(self::CMD_SET_FAN_LEVEL, [$level]);
        $this->SetValue('vsAktuelleStufe', $level);
    }

    // ─────────────────────────────────────────────────────────────────
    // Ventilationsstufen % an Gerät senden (CMD 0x00CF)
    //
    // Byte-Reihenfolge laut Dokumentation:
    //   Byte 1: Abluft Abwesend (Stufe 0)
    //   Byte 2: Abluft Niedrig  (Stufe 1)
    //   Byte 3: Abluft Mittel   (Stufe 2)
    //   Byte 4: Zuluft Abwesend (Stufe 0)
    //   Byte 5: Zuluft Niedrig  (Stufe 1)
    //   Byte 6: Zuluft Mittel   (Stufe 2)
    //   Byte 7: Abluft Hoch     (Stufe 3)
    //   Byte 8: Zuluft Hoch     (Stufe 3)
    // ─────────────────────────────────────────────────────────────────
    public function SendFanPercent(): void
    {
        // Aktuelle Werte aus den Variablen lesen
        $abluftAbwesend = $this->GetValue('vsPercentAbluftAbwesend');
        $abluftNiedrig  = $this->GetValue('vsPercentAbluftNiedrig');
        $abluftMittel   = $this->GetValue('vsPercentAbluftMittel');
        $abluftHoch     = $this->GetValue('vsPercentAbluftHoch');
        $zuluftAbwesend = $this->GetValue('vsPercentZuluftAbwesend');
        $zuluftNiedrig  = $this->GetValue('vsPercentZuluftNiedrig');
        $zuluftMittel   = $this->GetValue('vsPercentZuluftMittel');
        $zuluftHoch     = $this->GetValue('vsPercentZuluftHoch');

        $data = [
            $abluftAbwesend,  // Byte 1
            $abluftNiedrig,   // Byte 2
            $abluftMittel,    // Byte 3
            $zuluftAbwesend,  // Byte 4
            $zuluftNiedrig,   // Byte 5
            $zuluftMittel,    // Byte 6
            $abluftHoch,      // Byte 7
            $zuluftHoch,      // Byte 8
        ];

        $this->SendDebug(
            'SendFanPercent',
            sprintf(
                'Abluft: %d/%d/%d/%d%% | Zuluft: %d/%d/%d/%d%%',
                $abluftAbwesend, $abluftNiedrig, $abluftMittel, $abluftHoch,
                $zuluftAbwesend, $zuluftNiedrig, $zuluftMittel, $zuluftHoch
            ),
            0
        );

        $this->SendCommand(self::CMD_SET_FAN_PERCENT, $data);
    }

    // ─────────────────────────────────────────
    // AutoRead (Timer-Callback)
    // ─────────────────────────────────────────
    public function AutoRead(): void
    {
        $this->SendDebug('AutoRead', 'Starte Auto-Abfrage', 0);
        $this->SendCommand(self::CMD_GET_FAN_LEVEL,       []);
        $this->SendCommand(self::CMD_GET_FILTER_STATUS,   []);
        $this->SendCommand(self::CMD_GET_OPERATING_HOURS, []);
    }

    // ─────────────────────────────────────────
    // Betriebsstunden abfragen
    // ─────────────────────────────────────────
    public function GetOperatingHours(): void
    {
        $this->SendDebug('GetOperatingHours', 'Abfrage gesendet', 0);
        $this->SendCommand(self::CMD_GET_OPERATING_HOURS, []);
    }

    // ─────────────────────────────────────────
    // Filter zurücksetzen
    // ─────────────────────────────────────────
    public function ResetFilter(): bool
    {
        try {
            $this->SendDebug('ResetFilter', 'Reset wird gesendet', 0);
            $this->SendCommand(self::CMD_RESET_FILTER, [0, 0, 0, 1]);

            $now = time();
            $this->WriteAttributeInteger('FilterResetTimestamp', $now);
            $this->SetValue('filterLetzterReset', date('d.m.Y H:i:s', $now));
            $this->SetValue('filterBetriebsstunden', 0);
            $this->SetValue('filterWechsel', false);

            $this->SendCommand(self::CMD_GET_FILTER_STATUS, []);

            if ($this->ReadPropertyBoolean('FilterNotify')) {
                $this->LogMessage(
                    'Filter zurückgesetzt am ' . date('d.m.Y H:i:s', $now),
                    KL_NOTIFY
                );
            }

            return true;

        } catch (\Throwable $e) {
            $this->SendDebug('ResetFilter', 'Fehler: ' . $e->getMessage(), 0);
            return false;
        }
    }

    // ─────────────────────────────────────────
    // Filter-Stunden prüfen (Timer-Callback)
    // ─────────────────────────────────────────
    public function CheckFilterHours(): void
    {
        $hours  = $this->GetValue('filterBetriebsstunden');
        $warnAt = $this->ReadPropertyInteger('FilterWarnHours');

        $this->SendDebug(
            'CheckFilterHours',
            "Betriebsstunden: $hours h / Warnschwelle: $warnAt h",
            0
        );

        $fällig = $hours >= $warnAt;
        $this->SetValue('filterWechsel', $fällig);

        if ($fällig && $this->ReadPropertyBoolean('FilterNotify')) {
            $this->LogMessage(
                "Filter-Wechsel fällig! Betriebsstunden: $hours h",
                KL_WARNING
            );
        }
    }

    // ─────────────────────────────────────────
    // Hitzesteuerung (Timer-Callback)
    // ─────────────────────────────────────────
    public function HeatControl(): void
    {
        $insideVarID  = $this->ReadPropertyInteger('InsideTempVarID');
        $outsideVarID = $this->ReadPropertyInteger('OutsideTempVarID');
        $comfortTemp  = $this->ReadPropertyFloat('ComfortTemp');

        if ($insideVarID < 10000 || $outsideVarID < 10000) {
            $this->SendDebug('HeatControl', 'Sensoren nicht konfiguriert', 0);
            return;
        }

        $inside  = GetValue($insideVarID);
        $outside = GetValue($outsideVarID);

        $this->SendDebug(
            'HeatControl',
            "Innen: $inside °C | Außen: $outside °C | Komfort: $comfortTemp °C",
            0
        );

        if ($inside > $comfortTemp && $outside > $inside) {
            $prev = $this->ReadAttributeInteger('HeatControlPrevStage');
            if ($prev === -1) {
                $this->WriteAttributeInteger(
                    'HeatControlPrevStage',
                    $this->GetValue('vsAktuelleStufe')
                );
                $this->SendDebug('HeatControl', 'Hitzebetrieb → Stufe 1 (Aus)', 0);
                $this->SetFanLevel(1);
            }
        } else {
            $prev = $this->ReadAttributeInteger('HeatControlPrevStage');
            if ($prev !== -1) {
                $stage = max(2, $prev);
                $this->SendDebug(
                    'HeatControl',
                    "Normalbetrieb → Stufe $stage wiederherstellen",
                    0
                );
                $this->SetFanLevel($stage);
                $this->WriteAttributeInteger('HeatControlPrevStage', -1);
            }
        }
    }

    // ─────────────────────────────────────────
    // ReceiveData
    // ─────────────────────────────────────────
    public function ReceiveData(string $JSONString): void
    {
        $data = json_decode($JSONString);
        if (!isset($data->Buffer)) {
            return;
        }

        $chunk  = utf8_decode($data->Buffer);
        $buffer = $this->ReadAttributeString('RxBuffer') . $chunk;
        $buffer = $this->ProcessRxBuffer($buffer);
        $this->WriteAttributeString('RxBuffer', $buffer);
    }

    // ─────────────────────────────────────────
    // Empfangspuffer verarbeiten
    // ─────────────────────────────────────────
    private function ProcessRxBuffer(string $buffer): string
    {
        while (true) {
            // ACK konsumieren
            if (str_starts_with($buffer, self::ACK)) {
                $this->SendDebug('RxBuffer', 'ACK empfangen', 0);
                $buffer = substr($buffer, strlen(self::ACK));
                continue;
            }

            $start = strpos($buffer, self::START);
            $end   = strpos($buffer, self::END);

            if ($start === false || $end === false || $end <= $start) {
                break;
            }

            $frameLen = $end - $start + strlen(self::END);
            $frame    = substr($buffer, $start, $frameLen);
            $buffer   = substr($buffer, $start + $frameLen);

            $this->ParseFrame($frame);
        }

        return $buffer;
    }

    // ─────────────────────────────────────────
    // Frame parsen
    // ─────────────────────────────────────────
    private function ParseFrame(string $frame): void
    {
        if (strlen($frame) < 7) {
            $this->SendDebug('ParseFrame', 'Frame zu kurz', 0);
            return;
        }

        $payload = substr($frame, strlen(self::START), -strlen(self::END));
        $cmd     = (ord($payload[0]) << 8) | ord($payload[1]);
        $len     = ord($payload[2]);
        $data    = substr($payload, 3, $len);

        $this->SendDebug(
            'ParseFrame',
            sprintf('CMD: 0x%04X | LEN: %d', $cmd, $len),
            0
        );

        $this->DispatchResponse($cmd, $data);
    }

    // ─────────────────────────────────────────
    // Response dispatcher
    // ─────────────────────────────────────────
    private function DispatchResponse(int $cmd, string $data): void
    {
        switch ($cmd) {
            case 0x00CE: // Lüftungsstufen-Response
                $this->HandleFanLevelResponse($data);
                break;
            case 0x00DA: // Filterstatus-Response
                $this->HandleFilterStatusResponse($data);
                break;
            case 0x00DE: // Betriebsstunden-Response
                $this->HandleOperatingHoursResponse($data);
                break;
            default:
                $this->SendDebug(
                    'DispatchResponse',
                    sprintf('Unbekanntes CMD: 0x%04X', $cmd),
                    0
                );
        }
    }

    // ─────────────────────────────────────────
    // Response: Lüftungsstufen + % (0x00CE)
    // ─────────────────────────────────────────
    private function HandleFanLevelResponse(string $data): void
    {
        if (strlen($data) < 9) {
            $this->SendDebug('FanLevelResponse', 'Zu wenig Daten', 0);
            return;
        }

        // Prozent-Werte zurückschreiben (Abgleich vom Gerät)
        $this->SetValue('vsPercentAbluftAbwesend', ord($data[0]));
        $this->SetValue('vsPercentAbluftNiedrig',  ord($data[1]));
        $this->SetValue('vsPercentAbluftMittel',   ord($data[2]));
        $this->SetValue('vsPercentZuluftAbwesend', ord($data[3]));
        $this->SetValue('vsPercentZuluftNiedrig',  ord($data[4]));
        $this->SetValue('vsPercentZuluftMittel',   ord($data[5]));
        $this->SetValue('vsAbluftAktuell',         ord($data[6]));
        $this->SetValue('vsZuluftAktuell',         ord($data[7]));
        $this->SetValue('vsAktuelleStufe',         ord($data[8]));

        // Byte 10+11 = Hoch-Stufe (falls vorhanden)
        if (strlen($data) >= 12) {
            $this->SetValue('vsPercentAbluftHoch', ord($data[10]));
            $this->SetValue('vsPercentZuluftHoch', ord($data[11]));
        }

        $this->SendDebug(
            'FanLevelResponse',
            sprintf(
                'Stufe: %d | Abluft: %d%% | Zuluft: %d%%',
                ord($data[8]),
                ord($data[6]),
                ord($data[7])
            ),
            0
        );
    }

    // ─────────────────────────────────────────
    // Response: Filterstatus (0x00DA)
    // ─────────────────────────────────────────
    private function HandleFilterStatusResponse(string $data): void
    {
        if (strlen($data) < 1) {
            return;
        }

        $filterFlag = (ord($data[0]) & 0x04) > 0;
        $this->SetValue('filterWechsel', $filterFlag);

        $this->SendDebug(
            'FilterStatus',
            'Filter fällig: ' . ($filterFlag ? 'JA' : 'NEIN'),
            0
        );

        if ($filterFlag) {
            $this->CheckFilterHours();
        }
    }

    // ─────────────────────────────────────────
    // Response: Betriebsstunden (0x00DE)
    // ─────────────────────────────────────────
    private function HandleOperatingHoursResponse(string $data): void
    {
        if (strlen($data) < 4) {
            $this->SendDebug('OperatingHours', 'Zu wenig Daten', 0);
            return;
        }

        $filterHours = (ord($data[0]) << 8) | ord($data[1]);
        $this->SetValue('filterBetriebsstunden', $filterHours);

        $this->SendDebug('OperatingHours', "Filter: $filterHours h", 0);

        $this->CheckFilterHours();
    }

    // ─────────────────────────────────────────
    // Kommando senden
    // ─────────────────────────────────────────
    private function SendCommand(int $cmd, array $data): void
    {
        $checksum = ($cmd >> 8) ^ ($cmd & 0xFF) ^ count($data);
        foreach ($data as $byte) {
            $checksum ^= ($byte & 0xFF);
        }

        $payload = '';
        foreach ($data as $byte) {
            $payload .= chr($byte & 0xFF);
        }

        $frame = self::START
            . chr(($cmd >> 8) & 0xFF)
            . chr($cmd & 0xFF)
            . chr(count($data))
            . $payload
            . chr($checksum & 0xFF)
            . self::END;

        $this->SendDebug(
            'SendCommand',
            sprintf('CMD: 0x%04X | Frame: %d Bytes', $cmd, strlen($frame)),
            0
        );

        $this->SendDataToParent(json_encode([
            'DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA1}',
            'Buffer' => utf8_encode($frame),
        ]));
    }

    // ─────────────────────────────────────────
    // Wochenplan erstellen
    // ─────────────────────────────────────────
    private function CreateWochenplan(): void
    {
        $planID = @IPS_GetObjectIDByIdent('Wochenplan', $this->InstanceID);

        if ($planID === false) {
            $eventID = IPS_CreateEvent(2);
            IPS_SetParent($eventID, $this->InstanceID);
            IPS_SetIdent($eventID, 'Wochenplan');
            IPS_SetName($eventID, 'Lüftungs-Wochenplan');
            IPS_SetEventActive($eventID, false);

            IPS_SetEventScheduleAction($eventID, 0, 'Aus',
                0xFF0000, 'ZCAM_SetFanLevel($_IPS[\'TARGET\'], 1);');
            IPS_SetEventScheduleAction($eventID, 1, 'Stufe 1 (low)',
                0x6DBEE4, 'ZCAM_SetFanLevel($_IPS[\'TARGET\'], 2);');
            IPS_SetEventScheduleAction($eventID, 2, 'Stufe 2 (med)',
                0x1869E2, 'ZCAM_SetFanLevel($_IPS[\'TARGET\'], 3);');
            IPS_SetEventScheduleAction($eventID, 3, 'Stufe 3 (high)',
                0x1E407D, 'ZCAM_SetFanLevel($_IPS[\'TARGET\'], 4);');

            $this->SendDebug('Wochenplan', 'Erstellt', 0);
        }
    }

    // ─────────────────────────────────────────
    // Filter-Reset Script anlegen
    // ─────────────────────────────────────────
    private function EnsureFilterResetScript(): void
    {
        $ident    = 'FilterResetScript';
        $scriptID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);

        if ($scriptID === false) {
            $scriptID = IPS_CreateScript(0);
            IPS_SetParent($scriptID, $this->InstanceID);
            IPS_SetIdent($scriptID, $ident);
            IPS_SetName($scriptID, 'Filter zurücksetzen');
            IPS_SetHidden($scriptID, true);

            $content = "<?php\n"
                . "\$instanceID = IPS_GetParent(\$_IPS['SELF']);\n"
                . "\$result = ZCAM_ResetFilter(\$instanceID);\n"
                . "echo \$result ? 'Filter-Reset gesendet.' : 'Fehler!';\n";

            IPS_SetScriptContent($scriptID, $content);
            $this->SendDebug('EnsureFilterResetScript', 'Script erstellt', 0);
        }
    }

    // ─────────────────────────────────────────
    // Helper für externe Aufrufe
    // ─────────────────────────────────────────
    public function SetRequestActionInt(string $Ident, int $Value): void
    {
        $this->RequestAction($Ident, $Value);
    }

    public function SetRequestActionBool(string $Ident, bool $Value): void
    {
        $this->RequestAction($Ident, $Value);
    }

    public function SetRequestActionFloat(string $Ident, float $Value): void
    {
        $this->RequestAction($Ident, $Value);
    }

    public function SetRequestActionString(string $Ident, string $Value): void
    {
        $this->RequestAction($Ident, $Value);
    }

    // ─────────────────────────────────────────
    // GetConfigurationForm
    // ─────────────────────────────────────────
    public function GetConfigurationForm(): string
    {
        $form = [
            'elements' => [

                // ── Allgemein ──────────────────────────────
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '⚙️ Allgemein',
                    'expanded' => true,
                    'items'    => [
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'active',
                            'caption' => 'Modul aktiv',
                        ],
                        [
                            'type'    => 'NumberSpinner',
                            'name'    => 'AutoReadInterval',
                            'caption' => 'Auto-Read Intervall',
                            'minimum' => 5,
                            'maximum' => 3600,
                            'suffix'  => 's',
                        ],
                    ],
                ],

                // ── Ventilationsstufen % (NEU) ─────────────
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '💨 Ventilationsstufen % einstellen',
                    'expanded' => true,
                    'items'    => [
                        [
                            'type'    => 'Label',
                            'bold'    => true,
                            'caption' => 'Diese Werte werden als Standard-Prozentwerte gespeichert und beim nächsten Neustart automatisch gesetzt.',
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => 'Die Schieberegler im WebFront erlauben das direkte Ändern zur Laufzeit. Hier können die Default-Werte festgelegt werden.',
                        ],

                        // ── Abluft ──
                        [
                            'type'    => 'Label',
                            'bold'    => true,
                            'caption' => '── Abluft ──',
                        ],
                        [
                            'type'    => 'RowLayout',
                            'items'   => [
                                [
                                    'type'    => 'Label',
                                    'width'   => '200px',
                                    'caption' => 'Stufe 0 – Abwesend',
                                ],
                                [
                                    'type'    => 'NumberSpinner',
                                    'name'    => 'PercentAbluftAbwesend',
                                    'caption' => '',
                                    'minimum' => 0,
                                    'maximum' => 100,
                                    'suffix'  => '%',
                                ],
                            ],
                        ],
                        [
                            'type'    => 'RowLayout',
                            'items'   => [
                                [
                                    'type'    => 'Label',
                                    'width'   => '200px',
                                    'caption' => 'Stufe 1 – Niedrig',
                                ],
                                [
                                    'type'    => 'NumberSpinner',
                                    'name'    => 'PercentAbluftNiedrig',
                                    'caption' => '',
                                    'minimum' => 0,
                                    'maximum' => 100,
                                    'suffix'  => '%',
                                ],
                            ],
                        ],
                        [
                            'type'    => 'RowLayout',
                            'items'   => [
                                [
                                    'type'    => 'Label',
                                    'width'   => '200px',
                                    'caption' => 'Stufe 2 – Mittel',
                                ],
                                [
                                    'type'    => 'NumberSpinner',
                                    'name'    => 'PercentAbluftMittel',
                                    'caption' => '',
                                    'minimum' => 0,
                                    'maximum' => 100,
                                    'suffix'  => '%',
                                ],
                            ],
                        ],
                        [
                            'type'    => 'RowLayout',
                            'items'   => [
                                [
                                    'type'    => 'Label',
                                    'width'   => '200px',
                                    'caption' => 'Stufe 3 – Hoch',
                                ],
                                [
                                    'type'    => 'NumberSpinner',
                                    'name'    => 'PercentAbluftHoch',
                                    'caption' => '',
                                    'minimum' => 0,
                                    'maximum' => 100,
                                    'suffix'  => '%',
                                ],
                            ],
                        ],

                        // ── Zuluft ──
                        [
                            'type'    => 'Label',
                            'bold'    => true,
                            'caption' => '── Zuluft ──',
                        ],
                        [
                            'type'    => 'RowLayout',
                            'items'   => [
                                [
                                    'type'    => 'Label',
                                    'width'   => '200px',
                                    'caption' => 'Stufe 0 – Abwesend',
                                ],
                                [
                                    'type'    => 'NumberSpinner',
                                    'name'    => 'PercentZuluftAbwesend',
                                    'caption' => '',
                                    'minimum' => 0,
                                    'maximum' => 100,
                                    'suffix'  => '%',
                                ],
                            ],
                        ],
                        [
                            'type'    => 'RowLayout',
                            'items'   => [
                                [
                                    'type'    => 'Label',
                                    'width'   => '200px',
                                    'caption' => 'Stufe 1 – Niedrig',
                                ],
                                [
                                    'type'    => 'NumberSpinner',
                                    'name'    => 'PercentZuluftNiedrig',
                                    'caption' => '',
                                    'minimum' => 0,
                                    'maximum' => 100,
                                    'suffix'  => '%',
                                ],
                            ],
                        ],
                        [
                            'type'    => 'RowLayout',
                            'items'   => [
                                [
                                    'type'    => 'Label',
                                    'width'   => '200px',
                                    'caption' => 'Stufe 2 – Mittel',
                                ],
                                [
                                    'type'    => 'NumberSpinner',
                                    'name'    => 'PercentZuluftMittel',
                                    'caption' => '',
                                    'minimum' => 0,
                                    'maximum' => 100,
                                    'suffix'  => '%',
                                ],
                            ],
                        ],
                        [
                            'type'    => 'RowLayout',
                            'items'   => [
                                [
                                    'type'    => 'Label',
                                    'width'   => '200px',
                                    'caption' => 'Stufe 3 – Hoch',
                                ],
                                [
                                    'type'    => 'NumberSpinner',
                                    'name'    => 'PercentZuluftHoch',
                                    'caption' => '',
                                    'minimum' => 0,
                                    'maximum' => 100,
                                    'suffix'  => '%',
                                ],
                            ],
                        ],

                        // ── Sende-Button ──
                        [
                            'type'    => 'Label',
                            'caption' => ' ',
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => '📤 Prozent-Werte jetzt senden',
                            'onClick' => 'ZCAM_SendFanPercent($id); echo "✅ Prozentwerte gesendet!";',
                        ],
                    ],
                ],

                // ── Filter ─────────────────────────────────
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '🔧 Filter-Verwaltung',
                    'expanded' => false,
                    'items'    => [
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'ShowFilterStatus',
                            'caption' => 'Variable "Filter wechseln" anzeigen',
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'ShowFilterHours',
                            'caption' => 'Variable "Betriebsstunden Filter" anzeigen',
                        ],
                        [
                            'type'    => 'NumberSpinner',
                            'name'    => 'FilterWarnHours',
                            'caption' => 'Warnschwelle Betriebsstunden',
                            'minimum' => 100,
                            'maximum' => 99999,
                            'suffix'  => 'h',
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'FilterNotify',
                            'caption' => 'Benachrichtigung bei Filter-Wechsel / Reset',
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => ' ',
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => '🔄 Filter jetzt zurücksetzen',
                            'onClick' => '
                                if (ZCAM_ResetFilter($id)) {
                                    echo "✅ Filter-Reset erfolgreich!";
                                } else {
                                    echo "❌ Filter-Reset fehlgeschlagen!";
                                }
                            ',
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => '📊 Betriebsstunden abfragen',
                            'onClick' => 'ZCAM_GetOperatingHours($id); echo "✅ Abfrage gesendet.";',
                        ],
                    ],
                ],

                // ── Hitzesteuerung ─────────────────────────
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '🌡️ Hitzesteuerung',
                    'expanded' => false,
                    'items'    => [
                        [
                            'type'    => 'Label',
                            'caption' => 'Wenn Innentemperatur > Komforttemperatur UND Außentemperatur > Innentemperatur, wird die Anlage auf Stufe 0 gesetzt. Intervall: 15 Minuten.',
                        ],
                        [
                            'type'    => 'SelectVariable',
                            'name'    => 'InsideTempVarID',
                            'caption' => 'Innentemperatur',
                        ],
                        [
                            'type'    => 'SelectVariable',
                            'name'    => 'OutsideTempVarID',
                            'caption' => 'Außentemperatur',
                        ],
                        [
                            'type'    => 'NumberSpinner',
                            'name'    => 'ComfortTemp',
                            'caption' => 'Komforttemperatur',
                            'minimum' => 15.0,
                            'maximum' => 35.0,
                            'suffix'  => '°C',
                            'digits'  => 1,
                        ],
                    ],
                ],
            ],

            'actions' => [
                [
                    'type'    => 'Label',
                    'bold'    => true,
                    'caption' => '── Direktbefehle ──',
                ],
                [
                    'type'    => 'Button',
                    'caption' => '📡 Status abfragen',
                    'onClick' => 'ZCAM_AutoRead($id); echo "✅ Abfrage gesendet.";',
                ],
                [
                    'type'    => 'Button',
                    'caption' => '📤 Prozentwerte senden',
                    'onClick' => 'ZCAM_SendFanPercent($id); echo "✅ Prozentwerte gesendet!";',
                ],
                [
                    'type'    => 'Button',
                    'caption' => '🔄 Filter zurücksetzen',
                    'onClick' => '
                        if (ZCAM_ResetFilter($id)) {
                            echo "✅ Filter-Reset gesendet!";
                        } else {
                            echo "❌ Fehler beim Reset!";
                        }
                    ',
                ],
                [
                    'type'    => 'Button',
                    'caption' => '📊 Betriebsstunden abfragen',
                    'onClick' => 'ZCAM_GetOperatingHours($id); echo "✅ Abfrage gesendet.";',
                ],
            ],

            'status' => [
                ['code' => 102, 'icon' => 'Active',   'caption' => 'Modul aktiv'],
                ['code' => 104, 'icon' => 'Inactive', 'caption' => 'Modul inaktiv'],
                ['code' => 201, 'icon' => 'Error',    'caption' => 'Gateway nicht verbunden'],
            ],
        ];

        return json_encode($form);
    }
}
