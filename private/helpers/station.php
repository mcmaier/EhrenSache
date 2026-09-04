<?php

/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

declare(strict_types=1);

/**
 * Fachlogik der Stations-Anmeldung (Kiosk): Einstellungen, PIN-Regeln,
 * Prüfung von Mitgliedsnummer + PIN mit Sperre.
 *
 * Spec: docs/superpowers/specs/2026-09-04-station-pin-kiosk-design.md
 */

const STATION_PIN_MIN_LENGTH_FLOOR    = 4;
const STATION_PIN_MAX_LENGTH          = 8;
const STATION_PIN_MEMBER_MAX_ATTEMPTS = 5;
const STATION_PIN_DEVICE_MAX_ATTEMPTS = 30;
const STATION_PIN_LOCK_SECONDS        = 900;

/** Ist die PIN-Anmeldung an Stationen freigeschaltet? (E13) */
function isStationPinEnabled($db, $database): bool
{
    return systemSetting($db, $database, 'station_pin_enabled', '0') === '1';
}

/** Klemmt eine PIN-Laenge auf den erlaubten Bereich 4..8. */
function stationClampPinLength(int $length): int
{
    return max(STATION_PIN_MIN_LENGTH_FLOOR, min(STATION_PIN_MAX_LENGTH, $length));
}

/** Mindestlänge der PIN, auf 4..8 begrenzt — egal, was in der Einstellung steht. */
function stationPinMinLength($db, $database): int
{
    $value = (int) systemSetting($db, $database, 'station_pin_min_length', '4');

    return stationClampPinLength($value);
}

/**
 * Prüft eine PIN gegen die Regeln aus E11: nur Ziffern, Mindestlänge
 * (4..8, aus der Einstellung), höchstens 8, keine Einheitsziffern, keine
 * auf- oder absteigende Folge.
 *
 * Reine Funktion — Unit-Test in tests/suites/station_unit.php.
 *
 * @return string|null Fehlertext für die Oberfläche, null wenn gültig
 */
function validateStationPin(string $pin, int $minLength): ?string
{
    $minLength = stationClampPinLength($minLength);

    if (!ctype_digit($pin)) {
        return 'Die PIN darf nur Ziffern enthalten';
    }
    $length = strlen($pin);
    if ($length < $minLength) {
        return "Die PIN muss mindestens {$minLength} Ziffern haben";
    }
    if ($length > STATION_PIN_MAX_LENGTH) {
        return 'Die PIN darf höchstens ' . STATION_PIN_MAX_LENGTH . ' Ziffern haben';
    }
    if (preg_match('/^(\d)\1+$/', $pin)) {
        return 'Die PIN darf nicht aus lauter gleichen Ziffern bestehen';
    }
    if (stationPinIsSequence($pin)) {
        return 'Die PIN darf keine auf- oder absteigende Zahlenfolge sein';
    }

    return null;
}

/**
 * 1234, 456789, 9876 — Schrittweite genau +1 oder genau −1 über die ganze Länge.
 *
 * Strings kürzer als 2 Zeichen sind keine Folge (nichts zu vergleichen) und
 * liefern immer false.
 */
function stationPinIsSequence(string $pin): bool
{
    if (strlen($pin) < 2) {
        return false;
    }

    $ascending  = true;
    $descending = true;

    for ($i = 1, $n = strlen($pin); $i < $n; $i++) {
        $step = (int) $pin[$i] - (int) $pin[$i - 1];
        if ($step !== 1) {
            $ascending = false;
        }
        if ($step !== -1) {
            $descending = false;
        }
    }

    return $ascending || $descending;
}

/**
 * Prüft Mitgliedsnummer + PIN an einem Kiosk (E12).
 *
 * Reihenfolge: Kiosk-Zähler → Mitglied laden → Mitglieds-Zähler →
 * password_verify → bei Erfolg beide Zähler löschen. Der Limiter zählt jeden
 * Versuch (Muster canAttemptLogin()); ein Erfolg setzt zurück.
 *
 * Die Prüfdauer ist bewusst gleich lang, egal woran es scheitert: auch bei
 * unbekannter Nummer, fehlender PIN oder inaktivem Mitglied läuft
 * password_verify() gegen einen Dummy-Hash.
 *
 * isStationPinEnabled() wird hier NICHT geprüft — ist die Funktion
 * abgeschaltet, antwortet der Handler bereits mit 409, bevor diese Funktion
 * aufgerufen wird.
 *
 * Mehrdeutige Nummern (mehrfach vergeben) zählen bewusst nicht gegen den
 * Mitglieds-Zähler — es gibt keine eindeutige member_id, gegen die man
 * zählen könnte —, wohl aber gegen den Kiosk-Zähler.
 *
 * $failure: null | 'invalid' | 'ambiguous' | 'locked' | 'device_locked' — nur
 * für Logging und die Wahl des HTTP-Status. Die Meldung an den Kiosk ist für
 * 'invalid' und 'ambiguous' dieselbe. 'device_locked' (Kiosk-Sperre) ist
 * nicht mitgliedsbezogen und wird der Oberfläche daher mit einer eigenen
 * Meldung ("Station gesperrt") angezeigt statt mit der Meldung für 'locked'
 * (Mitglieds-Sperre) — sonst würde eine Kiosk-Sperre faelschlich einzelnen
 * Mitgliedern angelastet.
 *
 * @return array{member_id: int, name: string, surname: string, member_number: string}|null
 */
function stationAuthenticate($db, $database, RateLimiter $limiter, int $deviceId,
                             string $memberNumber, string $pin, ?string &$failure = null): ?array
{
    $failure = null;
    $prefix  = $database->table('');

    $deviceKey = 'station_device_' . $deviceId;
    if (!$limiter->check($deviceKey, 'station_pin', STATION_PIN_DEVICE_MAX_ATTEMPTS, STATION_PIN_LOCK_SECONDS)) {
        password_verify($pin, stationDummyHash());
        $failure = 'device_locked';
        return null;
    }

    $stmt = $db->prepare("SELECT member_id, name, surname, member_number, pin_hash, active
                          FROM {$prefix}members WHERE member_number = ?");
    $stmt->execute([$memberNumber]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $member = count($rows) === 1 ? $rows[0] : null;
    $usable = $member !== null && !empty($member['pin_hash']) && (int) $member['active'] === 1;

    if ($member !== null) {
        $memberKey = 'station_member_' . (int) $member['member_id'];
        if (!$limiter->check($memberKey, 'station_pin', STATION_PIN_MEMBER_MAX_ATTEMPTS, STATION_PIN_LOCK_SECONDS)) {
            password_verify($pin, stationDummyHash());
            $failure = 'locked';
            return null;
        }
    }

    $verified = password_verify($pin, $usable ? $member['pin_hash'] : stationDummyHash());

    if (!$usable || !$verified) {
        $failure = count($rows) > 1 ? 'ambiguous' : 'invalid';
        return null;
    }

    $limiter->reset($deviceKey, 'station_pin');
    $limiter->reset('station_member_' . (int) $member['member_id'], 'station_pin');

    return [
        'member_id'     => (int) $member['member_id'],
        'name'          => (string) $member['name'],
        'surname'       => (string) $member['surname'],
        'member_number' => (string) $member['member_number'],
    ];
}

/**
 * Bcrypt-Hash (Kosten 10, wie PASSWORD_DEFAULT) zu einem Zufallswert, der nie
 * gespeichert wurde und zu keiner PIN gehört — konstant statt zur Laufzeit
 * erzeugt, damit ein Fehlversuch genau eine bcrypt-Prüfung kostet, exakt wie
 * ein Erfolg.
 */
const STATION_DUMMY_HASH = '$2y$10$JeG.2hXLikjfi0cIWTn8s.7CJ4a6VyVEwU5yQygAphk8BlSyThhgS';

/** Ein gültiger Hash, der zu keiner PIN gehört — für die konstante Prüfdauer. */
function stationDummyHash(): string
{
    return STATION_DUMMY_HASH;
}
