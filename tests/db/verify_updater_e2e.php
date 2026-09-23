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
 * EhrenSache - Durchstich des Updaters gegen Wegwerf-Installationen.
 *
 * Installation und Paket entstehen per git archive; nichts im Arbeitsverzeichnis
 * wird angefasst. Beide Phasen laufen in eigenen PHP-Prozessen mit dem Code der
 * Installation -- die zweite also, wie im Assistenten, mit dem getauschten Code.
 *
 * Die Versionen leitet der Pruefer aus HEAD ab: installiert ist die Version aus
 * HEAD, das Paket traegt die naechste Patch-Version.
 *
 * Faelle:
 *   A  gueltiges Paket                -> getauscht, Kette mit neuem Code geprueft
 *   B  Paket ohne Manifest-Eintrag    -> vor dem Tausch abgewiesen (Spec 8.1)
 *   C  neue Kettenlogik strenger      -> nach dem Tausch abgewiesen, Rueckweg (Spec 8.2)
 *   D  Schreibhindernis               -> Preflight weist ab, nichts veraendert
 *   E  Paket verlangt PHP 99          -> vor dem Tausch abgewiesen (1.6.1)
 *   F  wie E, Installation aus v1.6.0 -> alter Updater tauscht, neuer Code rollt zurueck (1.6.1)
 *
 * Aufruf: php tests/db/verify_updater_e2e.php [--keep]
 * Braucht git im PATH, den Tag v1.6.0 und einen committeten Stand der Updater-Dateien.
 */
declare(strict_types=1);

$repo  = realpath(__DIR__ . '/../..');
$basis = str_replace('\\', '/', sys_get_temp_dir()) . '/es_upd_e2e_' . uniqid();
$keep  = in_array('--keep', $argv, true);
mkdir($basis);

$fehler = 0;
$melde  = static function (bool $ok, string $text) use (&$fehler): void {
    echo ($ok ? '  OK   ' : '  FEHL ') . $text . "\n";
    if (!$ok) {
        $fehler++;
    }
};

function e2eRun(array $befehl): string
{
    $prozess = proc_open($befehl, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre);
    $aus  = stream_get_contents($rohre[1]);
    $err  = stream_get_contents($rohre[2]);
    fclose($rohre[1]);
    fclose($rohre[2]);
    $code = proc_close($prozess);
    if ($code !== 0) {
        throw new RuntimeException(implode(' ', $befehl) . " endete mit {$code}:\n{$err}{$aus}");
    }
    return (string) $aus;
}

function e2eWrite(string $pfad, string $inhalt): void
{
    @mkdir(dirname($pfad), 0777, true);
    file_put_contents($pfad, $inhalt);
}

function e2eRemoveTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $e) {
        $e->isDir() ? @rmdir($e->getPathname()) : @unlink($e->getPathname());
    }
    @rmdir($dir);
}

/** $ref als Verzeichnis; mit $prefix wie GitHubs zipball in einem Wrapper-Ordner. */
function e2eArchiveTo(string $repo, string $ziel, string $prefix = '', string $ref = 'HEAD'): void
{
    $zip = $ziel . '.zip';
    $befehl = ['git', '-C', $repo, 'archive', '--format=zip', '-o', $zip];
    if ($prefix !== '') {
        $befehl[] = '--prefix=' . $prefix;
    }
    $befehl[] = $ref;
    e2eRun($befehl);

    $archiv = new ZipArchive();
    $archiv->open($zip);
    $archiv->extractTo($ziel);   // eigenes Archiv aus git, vertrauenswuerdig
    $archiv->close();
    unlink($zip);
}

function e2eZipDir(string $dir, string $zip): void
{
    $basis  = rtrim(str_replace('\\', '/', $dir), '/');
    $archiv = new ZipArchive();
    $archiv->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $datei) {
        if ($datei->isFile()) {
            $archiv->addFile($datei->getPathname(), substr(str_replace('\\', '/', $datei->getPathname()), strlen($basis) + 1));
        }
    }
    $archiv->close();
}

/** Installation aus $ref plus Dateien, die ein echter Betrieb mitbringt. */
function e2eInstallation(string $repo, string $basis, string $name, string $ref = 'HEAD'): string
{
    $root = "{$basis}/{$name}";
    e2eArchiveTo($repo, $root, '', $ref);
    copy("{$repo}/.gitattributes", "{$root}/.gitattributes");     // wie ein Git-Klon
    e2eWrite("{$root}/CLAUDE.md", 'lokal');
    e2eWrite("{$root}/docs/notiz.md", 'lokal');
    e2eWrite("{$root}/private/migrations/_probe.php", '<?php // export-ignore');
    e2eWrite("{$root}/public/google1234.html", 'Verifizierung');
    e2eWrite("{$root}/private/config/config.php", "<?php return ['db' => []];");
    e2eWrite("{$root}/public/uploads/logo.png", 'png');
    e2eWrite("{$root}/private/handlers/veraltet.php", '<?php // gibt es im Paket nicht');
    return $root;
}

function e2eAppendManifest(string $wurzel, string $eintrag): void
{
    $datei = "{$wurzel}/private/migrations/manifest.php";
    $text  = str_replace("\r\n", "\n", (string) file_get_contents($datei));
    $pos   = strrpos($text, '];');
    file_put_contents($datei, substr($text, 0, $pos) . $eintrag . substr($text, $pos));
}

function e2eNextPatch(string $version): string
{
    [$haupt, $neben, $patch] = array_map('intval', explode('.', $version));
    return "{$haupt}.{$neben}." . ($patch + 1);
}

/** Setzt requires.php in der version.json des Pakets. */
function e2eRequirePhp(string $wurzel, string $php): void
{
    $datei = "{$wurzel}/version.json";
    $daten = json_decode((string) file_get_contents($datei), true);
    $daten['requires']['php'] = $php;
    file_put_contents($datei, json_encode($daten, JSON_PRETTY_PRINT) . "\n");
}

/** Paket aus HEAD im Wrapper-Ordner. $version null behaelt die Version aus HEAD. */
function e2ePaket(string $repo, string $basis, string $name, ?string $version, callable $eingriff): string
{
    $stage  = "{$basis}/{$name}-stage";
    $wrap   = 'mcmaier-EhrenSache-e2e0001';
    e2eArchiveTo($repo, $stage, $wrap . '/');
    $wurzel = "{$stage}/{$wrap}";

    if ($version !== null) {
        $daten = json_decode((string) file_get_contents("{$wurzel}/version.json"), true);
        $daten['version'] = $version;
        file_put_contents("{$wurzel}/version.json", json_encode($daten, JSON_PRETTY_PRINT) . "\n");
    }
    e2eWrite("{$wurzel}/private/helpers/neu_im_paket.php", '<?php // neu im Paket');

    $eingriff($wurzel);

    $zip = "{$basis}/{$name}.zip";
    e2eZipDir($stage, $zip);
    e2eRemoveTree($stage);
    return $zip;
}

/** Eine Phase in eigenem Prozess mit dem Code der Installation. */
function e2eUpdater(string $root, string $phase, array $ctx): array
{
    $runner   = dirname($root) . '/runner-' . uniqid() . '.php';
    $ctxDatei = $runner . '.json';
    file_put_contents($ctxDatei, json_encode($ctx));
    file_put_contents($runner, "<?php\nrequire " . var_export("{$root}/private/helpers/updater.php", true) . ";\n"
        . '$ctx = json_decode(file_get_contents(' . var_export($ctxDatei, true) . "), true);\n"
        . 'echo json_encode(' . ($phase === 'apply' ? 'updaterApply' : 'updaterVerify') . "(\$ctx));\n");

    $roh = e2eRun([PHP_BINARY, $runner]);
    unlink($runner);
    unlink($ctxDatei);

    $ergebnis = json_decode($roh, true);
    if (!is_array($ergebnis)) {
        throw new RuntimeException("Runner lieferte kein JSON:\n{$roh}");
    }
    return $ergebnis;
}

function e2eCtx(string $root, string $zip, string $dbVersion): array
{
    return [
        'install_root'     => $root,
        'zip'              => $zip,
        'db_version'       => $dbVersion,
        'tmp_root'         => "{$root}/private/.update-tmp",
        'backup_root'      => "{$root}/private/backup",
        'maintenance_file' => "{$root}/private/config/maintenance.lock",
    ];
}

function e2eVerifyCtx(string $root, string $backupDir): array
{
    return [
        'install_root'     => $root,
        'backup_dir'       => $backupDir,
        'maintenance_file' => "{$root}/private/config/maintenance.lock",
    ];
}

$aktuell  = (string) (json_decode(e2eRun(['git', '-C', $repo, 'show', 'HEAD:version.json']), true)['version'] ?? '');
$naechste = e2eNextPatch($aktuell);
$funktion = 'migrate_' . str_replace('.', '_', $aktuell);

$manifestEintrag = "    [\n        'from'     => '{$aktuell}',\n        'to'       => '{$naechste}',\n"
    . "        'file'     => '{$aktuell}.php',\n        'function' => '{$funktion}',\n    ],\n";

$mitMigration = static function (string $wurzel) use ($manifestEintrag, $aktuell, $funktion): void {
    e2eAppendManifest($wurzel, $manifestEintrag);
    e2eWrite("{$wurzel}/private/migrations/{$aktuell}.php",
        "<?php\nfunction {$funktion}(PDO \$pdo, string \$prefix, string \$configPath): array\n"
        . "{\n    return ['log' => [], 'warnings' => []];\n}\n");
};

$version = static fn(string $root): ?string =>
    json_decode((string) @file_get_contents("{$root}/version.json"), true)['version'] ?? null;

echo "Installiert: {$aktuell}, Paket: {$naechste}\n";

try {
    // ---- Fall A -------------------------------------------------------------
    echo "Fall A: gueltiges Paket\n";
    $root = e2eInstallation($repo, $basis, 'a');
    $zip  = e2ePaket($repo, $basis, 'paket-a', $naechste, $mitMigration);

    $apply = e2eUpdater($root, 'apply', e2eCtx($root, $zip, $aktuell));
    $melde($apply['ok'] === true, 'Tausch erfolgreich' . ($apply['ok'] ? '' : ': ' . implode(' | ', $apply['errors'])));
    $verify = $apply['ok'] ? e2eUpdater($root, 'verify', e2eVerifyCtx($root, (string) $apply['backup_dir']))
        : ['ok' => false, 'errors' => ['nicht ausgefuehrt']];
    $melde($verify['ok'] === true, 'Kettenpruefung mit neuem Code bestanden' . ($verify['ok'] ? '' : ': ' . implode(' | ', $verify['errors'])));
    $melde($version($root) === $naechste, "version.json steht auf {$naechste}");
    $melde(is_file("{$root}/private/helpers/neu_im_paket.php"), 'Neue Datei aus dem Paket liegt vor');
    $melde(!is_file("{$root}/private/handlers/veraltet.php"), 'Veraltete PHP-Datei ist entfernt');
    foreach (['CLAUDE.md', 'docs/notiz.md', 'private/migrations/_probe.php', 'public/google1234.html',
              'private/config/config.php', 'public/uploads/logo.png'] as $rel) {
        $melde(is_file("{$root}/{$rel}"), "Unberuehrt: {$rel}");
    }
    $melde(!is_file("{$root}/private/config/maintenance.lock"), 'Wartungsflag ist entfernt');
    $melde(is_file(($apply['backup_dir'] ?? '') . '/plan.json'), 'Sicherung mit plan.json liegt vor');
    $melde(is_file(($apply['backup_dir'] ?? '') . '/files/private/handlers/veraltet.php'), 'Geloeschte Datei ist gesichert');
    $melde(glob("{$root}/private/.update-tmp/paket-*") === [], 'Entpacktes Paket ist aufgeraeumt');

    // ---- Fall B -------------------------------------------------------------
    echo "Fall B: Manifest-Eintrag fehlt\n";
    $root = e2eInstallation($repo, $basis, 'b');
    $zip  = e2ePaket($repo, $basis, 'paket-b', $naechste, static function (string $w): void {});

    $apply = e2eUpdater($root, 'apply', e2eCtx($root, $zip, $aktuell));
    $melde($apply['ok'] === false, 'Paket abgewiesen');
    $melde(strpos(implode(' ', $apply['errors']), 'Migration') !== false, 'Meldung nennt die Migrationskette');
    $melde($version($root) === $aktuell, 'version.json unveraendert');
    $melde(!is_file("{$root}/private/config/maintenance.lock"), 'Kein Wartungsflag');
    $melde(!is_dir("{$root}/private/backup"), 'Keine Sicherung angelegt -- nichts wurde angefasst');

    // ---- Fall C -------------------------------------------------------------
    echo "Fall C: neue Kettenlogik ist strenger (Spec 8.2)\n";
    $root = e2eInstallation($repo, $basis, 'c');
    $zip  = e2ePaket($repo, $basis, 'paket-c', $naechste, static function (string $w) use ($manifestEintrag): void {
        // Manifest-Eintrag ja, Migrationsdatei nein. Die installierte Logik prueft
        // nur den Eintrag und laesst durch; die neue verlangt auch die Datei.
        e2eAppendManifest($w, $manifestEintrag);
        $datei = "{$w}/private/helpers/migrations.php";
        $text  = str_replace("\r\n", "\n", (string) file_get_contents($datei));
        $anker = "    return \$manifest;\n}";
        if (substr_count($text, $anker) !== 1) {
            throw new RuntimeException('Anker in migrations.php nicht eindeutig');
        }
        file_put_contents($datei, str_replace($anker,
            "    foreach (\$manifest as \$schritt) {\n"
            . "        if (!is_file(dirname(\$path) . '/' . \$schritt['file'])) {\n"
            . "            throw new RuntimeException('Migrationsdatei fehlt: ' . \$schritt['file']);\n"
            . "        }\n    }\n\n" . $anker, $text));
    });
    $vorher = (string) file_get_contents("{$root}/private/helpers/migrations.php");

    $apply = e2eUpdater($root, 'apply', e2eCtx($root, $zip, $aktuell));
    $melde($apply['ok'] === true, 'Installierte Kettenlogik laesst das Paket durch');
    $verify = e2eUpdater($root, 'verify', e2eVerifyCtx($root, (string) $apply['backup_dir']));
    $melde($verify['ok'] === false, 'Neue Kettenlogik weist ab');
    $melde(strpos(implode(' ', $verify['errors']), 'Migrationsdatei fehlt') !== false, 'Meldung stammt aus der neuen Logik');
    $melde($version($root) === $aktuell, "Rueckweg: version.json wieder {$aktuell}");
    $melde(!is_file("{$root}/private/helpers/neu_im_paket.php"), 'Rueckweg: neue Datei entfernt');
    $melde(is_file("{$root}/private/handlers/veraltet.php"), 'Rueckweg: geloeschte Datei zurueck');
    $melde(file_get_contents("{$root}/private/helpers/migrations.php") === $vorher, 'Rueckweg: alte migrations.php zurueck');
    $melde(!is_file("{$root}/private/config/maintenance.lock"), 'Wartungsflag ist entfernt');

    // ---- Fall D -------------------------------------------------------------
    echo "Fall D: Schreibhindernis\n";
    $root = e2eInstallation($repo, $basis, 'd');
    e2eWrite("{$root}/private/neuer_bereich", 'ich bin eine Datei');
    $zip  = e2ePaket($repo, $basis, 'paket-d', $naechste, static function (string $w) use ($mitMigration): void {
        $mitMigration($w);
        e2eWrite("{$w}/private/neuer_bereich/datei.php", '<?php');
    });

    $apply = e2eUpdater($root, 'apply', e2eCtx($root, $zip, $aktuell));
    $melde($apply['ok'] === false, 'Preflight weist ab');
    $melde(strpos(implode(' ', $apply['errors']), 'neuer_bereich') !== false, 'Meldung nennt das Hindernis');
    $melde($version($root) === $aktuell, 'version.json unveraendert');
    $melde(!is_file("{$root}/private/config/maintenance.lock"), 'Kein Wartungsflag');
    $melde(!is_dir("{$root}/private/backup"), 'Keine Sicherung angelegt');

    // ---- Fall E -------------------------------------------------------------
    echo "Fall E: Paket verlangt PHP 99\n";
    $root = e2eInstallation($repo, $basis, 'e');
    $zip  = e2ePaket($repo, $basis, 'paket-e', $naechste, static function (string $w) use ($mitMigration): void {
        $mitMigration($w);
        e2eRequirePhp($w, '99.0.0');
    });

    $apply = e2eUpdater($root, 'apply', e2eCtx($root, $zip, $aktuell));
    $melde($apply['ok'] === false, 'Vor dem Tausch abgewiesen');
    $melde(strpos(implode(' ', $apply['errors']), '99.0.0') !== false, 'Meldung nennt die PHP-Anforderung');
    $melde($version($root) === $aktuell, 'version.json unveraendert');
    $melde(!is_file("{$root}/private/config/maintenance.lock"), 'Kein Wartungsflag');
    $melde(!is_dir("{$root}/private/backup"), 'Keine Sicherung angelegt');

    // ---- Fall F -------------------------------------------------------------
    echo "Fall F: Installation aus v1.6.0, Paket verlangt PHP 99\n";
    $root = e2eInstallation($repo, $basis, 'f', 'v1.6.0');
    $zip  = e2ePaket($repo, $basis, 'paket-f', null, static function (string $w): void {
        e2eRequirePhp($w, '99.0.0');
    });

    $apply = e2eUpdater($root, 'apply', e2eCtx($root, $zip, '1.6.0'));
    $melde($apply['ok'] === true, 'Updater von 1.6.0 kennt requires nicht und tauscht'
        . ($apply['ok'] ? '' : ': ' . implode(' | ', $apply['errors'])));
    $verify = $apply['ok'] ? e2eUpdater($root, 'verify', e2eVerifyCtx($root, (string) $apply['backup_dir']))
        : ['ok' => true, 'errors' => []];
    $melde($verify['ok'] === false, 'Neuer Code im Folgeaufruf weist ab');
    $melde(strpos(implode(' ', $verify['errors']), '99.0.0') !== false, 'Meldung nennt die PHP-Anforderung');
    $melde($version($root) === '1.6.0', 'Rueckweg: version.json wieder 1.6.0');
    $melde(!is_file("{$root}/private/helpers/neu_im_paket.php"), 'Rueckweg: neue Datei entfernt');
    $melde(!is_file("{$root}/private/config/maintenance.lock"), 'Wartungsflag ist entfernt');
} finally {
    if ($keep) {
        echo "\nWegwerf-Installationen bleiben stehen (--keep): {$basis}\n";
    } else {
        e2eRemoveTree($basis);
    }
}

echo $fehler === 0 ? "\nAlles in Ordnung.\n" : "\n{$fehler} Pruefung(en) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
