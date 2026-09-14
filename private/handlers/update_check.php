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
 * Ressource update_check, nur admin.
 *
 * GET  liefert den gespeicherten Stand, ohne nach außen zu gehen.
 * POST fragt GitHub nach dem neuesten Release und speichert das Ergebnis.
 *
 * Es gibt bewusst keinen automatischen Abruf: Ohne Klick verlässt nichts den
 * Server (Spezifikation, Abschnitt 5).
 */
declare(strict_types=1);

function handleUpdateCheck($db, $database, string $method): void
{
    requireAdmin();

    if ($method !== 'GET' && $method !== 'POST') {
        http_response_code(405);
        echo json_encode(['message' => 'Methode nicht erlaubt']);
        return;
    }

    $installiert = updateReadVersionFile(dirname(__DIR__, 2)) ?? '0.0.0';

    if ($method === 'POST') {
        try {
            $release = updateFetchLatest();
        } catch (RuntimeException $e) {
            http_response_code(502);
            echo json_encode(['message' => $e->getMessage()]);
            return;
        }
        updateStoreCheck($db, $database, $release, date('Y-m-d H:i:s'));
    }

    echo json_encode(updateCheckStatus($db, $database, $installiert));
}
