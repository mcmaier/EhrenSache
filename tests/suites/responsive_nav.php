<?php
declare(strict_types=1);

/**
 * Die Navigation muss auch im flachen Querformat bedienbar bleiben (OI-53).
 *
 * Gemessen am 11.09.2026 in der laufenden Instanz, Viewport 667x375 mit
 * aufgeklappter Leiste: Kopfbereich 213 px + Reiter 33 px + Fussbereich 145 px
 * ergeben 391 px in einem 375 px hohen Fenster. Die Navigationsliste traegt
 * `flex: 1` und bekommt damit den Rest — also NULL. Sieben Eintraege zu je
 * 56 px waren schlicht nicht vorhanden, nicht etwa nur klein.
 *
 * Dazu kommt der Haltepunkt: Ein Telefon ist quer 844 bis 926 px breit, also
 * oberhalb von 768 px. Der Menueknopf verschwindet dort, die 250-px-Leiste
 * steht fest im Layout — und dieselbe Liste kollabiert auf 17 px.
 *
 * Diese Suite haelt beides fest und sichert zusaetzlich die Falle ab, die ein
 * halber Fix aufreisst: Wer nur die Mobilregel um eine Hoehenbedingung
 * erweitert, laesst den spaeteren Block `min-width: 1200px` unveraendert. Bei
 * einem flach gezogenen Fenster gewinnt der und versteckt den Menueknopf,
 * waehrend die Leiste bereits ausgefahren ist: keine Navigation mehr, dafuer
 * ein Inhalt mit 250 px Rand ins Leere.
 */

$repoRoot = dirname(__DIR__, 2);

/**
 * Zerlegt eine CSS-Datei in ihre @media-Bloecke.
 *
 * Liefert je Block die Bedingung und den Rumpf. Eine Verschachtelungsebene
 * genuegt — tiefer geht das Stylesheet des Dashboards nicht.
 *
 * @return list<array{bedingung: string, rumpf: string}>
 */
function mediaBloecke(string $css): array
{
    preg_match_all('/@media([^{]+)\{((?:[^{}]|\{[^{}]*\})*)\}/', $css, $m, PREG_SET_ORDER);

    $bloecke = [];
    foreach ($m as $treffer) {
        $bloecke[] = [
            'bedingung' => trim(preg_replace('/\s+/', ' ', $treffer[1]) ?? ''),
            'rumpf'     => $treffer[2],
        ];
    }

    return $bloecke;
}

/**
 * Sucht im Rumpf eines @media-Blocks eine Deklaration innerhalb eines Selektors.
 * Der Selektor muss exakt so dastehen, die Deklaration wird lose gesucht.
 */
function regelSetzt(string $rumpf, string $selektor, string $deklaration): bool
{
    if (!preg_match('/(?:^|[},])\s*' . preg_quote($selektor, '/') . '\s*\{([^}]*)\}/m', $rumpf, $m)) {
        return false;
    }

    $inhalt = preg_replace('/\s+/', '', $m[1]) ?? '';

    return strpos($inhalt, preg_replace('/\s+/', '', $deklaration) ?? '') !== false;
}

test('Der Menueknopf erscheint auch im flachen Querformat', function () use ($repoRoot) {
    // Ein Telefon ist quer breiter als 768 px. Haengt die mobile Bedienung
    // allein an der Breite, faellt sie genau in der Lage aus, in der die
    // Leiste am wenigsten Platz hat.
    $css = (string) file_get_contents($repoRoot . '/public/css/responsive.css');

    $treffer = array_filter(mediaBloecke($css), static fn (array $b): bool =>
        strpos($b['bedingung'], 'max-height') !== false
        && regelSetzt($b['rumpf'], '.mobile-menu-btn', 'display:flex'));

    assertTrue(
        $treffer !== [],
        'Kein @media-Block mit max-height blendet den Menueknopf ein — im Querformat bleibt die Navigation unerreichbar'
    );
});

test('Die Leiste faehrt im flachen Querformat aus dem Layout', function () use ($repoRoot) {
    // Der Knopf allein genuegt nicht: Solange die Leiste fest im Layout steht,
    // frisst sie 250 der 844 px Breite und der Knopf haette nichts zu oeffnen.
    $css = (string) file_get_contents($repoRoot . '/public/css/responsive.css');

    $treffer = array_filter(mediaBloecke($css), static fn (array $b): bool =>
        strpos($b['bedingung'], 'max-height') !== false
        && regelSetzt($b['rumpf'], '.sidebar', 'transform:translateX(-100%)'));

    assertTrue($treffer !== [], 'Die Seitenleiste bleibt im flachen Querformat fest im Layout stehen');
});

test('Kein Breitenblock versteckt den Menueknopf im flachen Querformat', function () use ($repoRoot) {
    // Die eigentliche Falle. `min-width: 1200px` steht in der Datei NACH der
    // Mobilregel und gewinnt bei gleicher Spezifitaet. Ohne Hoehenbedingung
    // nimmt er dem flach gezogenen Fenster den Knopf wieder weg.
    $css = (string) file_get_contents($repoRoot . '/public/css/responsive.css');

    foreach (mediaBloecke($css) as $block) {
        if (!regelSetzt($block['rumpf'], '.mobile-menu-btn', 'display:none')) {
            continue;
        }

        assertTrue(
            strpos($block['bedingung'], 'min-height') !== false,
            "@media {$block['bedingung']} versteckt den Menueknopf ohne Hoehenbedingung — "
            . 'bei flachem Fenster ist die Leiste dann ausgefahren UND unerreichbar'
        );
    }
});

test('Kein Breitenblock raeumt dem Inhalt Platz fuer eine ausgefahrene Leiste ein', function () use ($repoRoot) {
    // Gegenstueck zur vorigen Pruefung: Ein margin-left von der Breite der
    // Leiste ist falsch, sobald die Leiste ausgefahren ist.
    $css = (string) file_get_contents($repoRoot . '/public/css/responsive.css');

    foreach (mediaBloecke($css) as $block) {
        if (!regelSetzt($block['rumpf'], '.main-content', 'margin-left:250px')) {
            continue;
        }

        assertTrue(
            strpos($block['bedingung'], 'min-height') !== false,
            "@media {$block['bedingung']} haelt 250 px fuer die Leiste frei, ohne die Hoehe zu pruefen"
        );
    }
});

test('Die Navigationsliste wird im flachen Querformat nicht mehr zerdrueckt', function () use ($repoRoot) {
    // Der Kern des Befunds: `.nav-menu { flex: 1 }` bekommt den Rest, und der
    // ist bei 375 px Fensterhoehe null. Im flachen Querformat muss die Liste
    // ihre Eintragshoehe behalten und stattdessen die ganze Leiste scrollen.
    $css = (string) file_get_contents($repoRoot . '/public/css/sections/sidebar.css')
         . (string) file_get_contents($repoRoot . '/public/css/responsive.css');

    $flach = array_filter(mediaBloecke($css), static fn (array $b): bool =>
        strpos($b['bedingung'], 'max-height') !== false);

    $liste = array_filter($flach, static fn (array $b): bool =>
        regelSetzt($b['rumpf'], '.nav-menu', 'flex:none'));

    assertTrue(
        $liste !== [],
        'Die Navigationsliste traegt im flachen Querformat weiterhin flex: 1 und schrumpft auf null'
    );

    $scrollt = array_filter($flach, static fn (array $b): bool =>
        regelSetzt($b['rumpf'], '.sidebar', 'overflow-y:auto'));

    assertTrue(
        $scrollt !== [],
        'Die Leiste selbst scrollt nicht — was nicht mehr hineinpasst, ist dann unerreichbar'
    );
});

test('Die Seitenleiste rechnet mit der tatsaechlich sichtbaren Hoehe', function () use ($repoRoot) {
    // 100vh ist auf dem Telefon die Hoehe OHNE Adressleiste. Steht sie da,
    // ragt die Leiste unten aus dem Bild — im Querformat um ein Vielfaches
    // ihres verbliebenen Platzes. 100dvh misst, was wirklich zu sehen ist;
    // aeltere Browser ueberlesen die Deklaration und behalten 100vh.
    $css = (string) file_get_contents($repoRoot . '/public/css/sections/sidebar.css');

    assertTrue(
        preg_match('/\.sidebar\s*\{[^}]*height:\s*100vh;[^}]*height:\s*100dvh;/s', $css) === 1,
        '.sidebar setzt 100dvh nicht als Nachzug zu 100vh — die Reihenfolge ist der Fallback'
    );
});

test('Der Menueknopf richtet sich nach derselben Bedingung wie das Stylesheet', function () use ($repoRoot) {
    // Die Sichtbarkeit des Knopfes entscheidet ein Inline-Style aus ui.js —
    // der schlaegt jede CSS-Regel. Die Schwelle steht damit zwangslaeufig
    // zweimal da: einmal als Medienabfrage im Stylesheet, einmal als
    // Zeichenkette im JavaScript. Laufen die beiden auseinander, blendet das
    // eine ein, was das andere ausgeblendet laesst — hier tritt genau das
    // Querformat wieder durch. Diese Pruefung haelt sie deckungsgleich.
    $js  = (string) file_get_contents($repoRoot . '/public/js/modules/ui.js');
    $css = (string) file_get_contents($repoRoot . '/public/css/responsive.css');

    assertTrue(
        preg_match('/innerWidth\s*<=?\s*\d+/', $js) !== 1,
        'ui.js prueft die Fensterbreite selbst — die Hoehe bleibt dabei unberuecksichtigt'
    );

    assertTrue(
        preg_match("/matchMedia\(\s*([A-Z_]+|'[^']*'|\"[^\"]*\")\s*\)/", $js) === 1,
        'ui.js entscheidet die Sichtbarkeit nicht ueber eine Medienabfrage'
    );

    // Die Bedingung selbst — als Konstante oder direkt im Aufruf.
    assertTrue(
        preg_match("/'((?:\([^']*\)\s*,?\s*)+)'/", $js, $m) === 1,
        'In ui.js steht keine Medienabfrage-Zeichenkette'
    );

    $ausJs = array_map('trim', explode(',', $m[1]));
    sort($ausJs);

    // Alle Bedingungen, unter denen das Stylesheet den Knopf einblendet.
    $ausCss = [];
    foreach (mediaBloecke($css) as $block) {
        if (regelSetzt($block['rumpf'], '.mobile-menu-btn', 'display:flex')) {
            $ausCss[] = $block['bedingung'];
        }
    }
    sort($ausCss);

    assertSame(
        $ausJs,
        $ausCss,
        'ui.js und responsive.css blenden den Menueknopf unter verschiedenen Bedingungen ein: '
        . '[' . implode(' | ', $ausJs) . '] gegen [' . implode(' | ', $ausCss) . ']'
    );
});
