<?php
declare(strict_types=1);

/**
 * Statische Gegenprobe des Benutzerdialogs.
 *
 * Der Dialog fuehrt drei Felder fuer dieselbe Mitgliedsverknuepfung, je nach
 * Modus ist ein anderes sichtbar. `saveUser()` las lange nur die beiden des
 * Bearbeiten-Modus — die Auswahl beim Anlegen ging damit still verloren.
 * Diese Suite haelt fest, dass das sichtbare Feld auch gelesen wird.
 */

$ufRoot = dirname(__DIR__, 2);

/** Der Rumpf einer Funktion aus einer JS-Datei, grob bis zur naechsten Deklaration. */
function ufFunctionBody(string $quelle, string $signatur): string
{
    $start = strpos($quelle, $signatur);
    assertTrue($start !== false, "{$signatur} nicht gefunden");

    $rest  = substr($quelle, $start + strlen($signatur));
    $ende  = strpos($rest, "\nexport ");
    $ende2 = strpos($rest, "\nfunction ");

    if ($ende === false || ($ende2 !== false && $ende2 < $ende)) {
        $ende = $ende2;
    }

    return $ende === false ? $rest : substr($rest, 0, $ende);
}

test('Benutzerdialog: das Modal hat die Auswahl fuers Anlegen', function () use ($ufRoot) {
    $html = (string) file_get_contents($ufRoot . '/public/index.html');

    assertTrue(str_contains($html, 'id="userMemberGroup"'), 'Gruppe des Auswahlfelds fehlt');
    assertTrue(str_contains($html, 'id="user_member"'), 'Auswahlfeld fehlt');
});

test('saveUser liest beim Anlegen das sichtbare Auswahlfeld', function () use ($ufRoot) {
    $js   = (string) file_get_contents($ufRoot . '/public/js/modules/users.js');
    $body = ufFunctionBody($js, 'export async function saveUser()');

    assertTrue(
        str_contains($body, "getElementById('user_member')"),
        'saveUser() liest #user_member nicht — die Verknuepfung beim Anlegen geht verloren'
    );
    assertTrue(str_contains($body, 'userData.member_id'), 'member_id wird nicht gesetzt');
});

test('openUserModal zeigt das Auswahlfeld beim Anlegen', function () use ($ufRoot) {
    $js   = (string) file_get_contents($ufRoot . '/public/js/modules/users.js');
    $body = ufFunctionBody($js, 'export async function openUserModal(userId = null)');

    assertTrue(
        str_contains($body, "userMemberGroup.style.display = 'block'"),
        'Ohne sichtbares Feld kann beim Anlegen nichts gewaehlt werden'
    );
});
