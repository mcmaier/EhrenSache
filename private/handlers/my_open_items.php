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

/**
 * GET ?resource=my_open_items -- offene Punkte des angemeldeten Mitglieds (FI-17).
 *
 * Nur eigene Punkte, auch fuer Admin und Manager: Das ist keine Arbeitsliste.
 * Ohne verknuepftes Mitglied 200 mit member:false, damit die Oberflaechen
 * still ausblenden statt einen Fehler zu zeigen.
 */
declare(strict_types=1);

function handleMyOpenItems($db, $database, string $method, ?string $authUserRole, ?int $authMemberId): void
{
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($authUserRole === 'device') {
        http_response_code(403);
        echo json_encode(['message' => 'Geräte haben keine offenen Punkte'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($authMemberId === null) {
        echo json_encode(['member' => false, 'items' => [],
                          'counts' => ['open' => 0, 'pending' => 0, 'rejected' => 0]], JSON_UNESCAPED_UNICODE);
        return;
    }

    $result = openItemsForMember($db, $database, $authMemberId, date('Y-m-d H:i:s'));
    echo json_encode(['member' => true] + $result, JSON_UNESCAPED_UNICODE);
}
