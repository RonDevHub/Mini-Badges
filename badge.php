<?php
// Mini-Badges – Shields.io Style (überarbeitet: Rundungen fix + Plastic-Glanz + Lang)
mb_internal_encoding('UTF-8');
$config = include __DIR__ . '/config.php';

// Sprache laden
$lang = q('lang', $config['defaultLang'] ?? 'en');
$langFile = __DIR__ . '/lang/' . basename($lang) . '.php';
$L = is_file($langFile) ? include $langFile : include __DIR__ . '/lang/en.php';

// Output headers
header('Content-Type: image/svg+xml; charset=UTF-8');
header('Cache-Control: public, max-age=' . (int)$config['svgMaxAge']);

// Helpers
function q(string $key, ?string $default = null): ?string {
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function approxWidth(string $text, int $char = 7): int {
    $len = mb_strlen($text);
    $widen = 0;
    for ($i=0; $i<$len; $i++) {
        $ch = mb_substr($text, $i, 1);
        if (!preg_match('/^[\x20-\x7E]$/u', $ch)) $widen++;
    }
    return (int)(($len + $widen) * $char);
}
function normalizeColor(?string $c, string $fallback): string {
    if (!$c) return $fallback;
    $c = ltrim($c, '#');
    return preg_match('/^[0-9a-fA-F]{6}$/', $c) ? "#".$c : $fallback;
}

// Path-Helfer: Nur links runden / nur rechts runden (keine Rundung in der Mitte)
function path_left_rounded(int $w, int $h, int $r): string {
    $r = max(0, min($r, intdiv($h,2)));
    return "M {$r},0 H {$w} V {$h} H {$r} A {$r} {$r} 0 0 1 0 ".($h-$r)." V {$r} A {$r} {$r} 0 0 1 {$r} 0 Z";
}
function path_right_rounded(int $w, int $h, int $r): string {
    $r = max(0, min($r, intdiv($h,2)));
    $wr = $w - $r;
    return "M 0,0 H {$wr} A {$r} {$r} 0 0 1 {$w} {$r} V ".($h-$r)." A {$r} {$r} 0 0 1 {$wr} {$h} H 0 Z";
}

// Style presets
$style = q('style','flat');
$styles = [
    'flat' =>         [ 'height' => 20, 'radius' => 3,  'padX' => 5,  'gap' => 6,  'font' => 11, 'caps' => false, 'gradient' => false ],
    'round' =>        [ 'height' => 20, 'radius' => 10, 'padX' => 5,  'gap' => 6,  'font' => 11, 'caps' => false, 'gradient' => false ],
    'flat-square' =>  [ 'height' => 20, 'radius' => 0,  'padX' => 5,  'gap' => 6,  'font' => 11, 'caps' => false, 'gradient' => false ],
    'plastic' =>      [ 'height' => 20, 'radius' => 3,  'padX' => 5,  'gap' => 6,  'font' => 11, 'caps' => false, 'gradient' => true  ],
    'for-the-badge' =>[ 'height' => 28, 'radius' => 5,  'padX' => 10, 'gap' => 10, 'font' => 12, 'caps' => true,  'gradient' => false ],
];
$p = $styles[$style] ?? $styles['flat'];
$h = (int)$p['height'];
$pad = (int)$p['padX'];
$font = (int)$p['font'];
$radius = (int)$p['radius'];

// Colors
$colorLabel = normalizeColor(q('colorLabel'), $config['defaultLabelColor']);
$colorMessage = normalizeColor(q('colorMessage'), $config['defaultMessageColor']);
$textColorLabel = normalizeColor(q('textColorLabel'), $config['defaultTextColor']);
$textColorMessage = normalizeColor(q('textColorMessage'), $config['defaultTextColor']);
$fontFamily = $config['fontFamily'];

// Plastic-Glanz / Lichteffekt
$defs = '';
if (!empty($p['gradient'])) {
    $defs .= '<linearGradient id="shine" x2="0" y2="100%"><stop offset="0" stop-color="#fff" stop-opacity=".18"/><stop offset="1" stop-color="#000" stop-opacity=".18"/></linearGradient>';
    $defs .= '<linearGradient id="gloss" x2="0" y2="100%"><stop offset="0" stop-color="#fff" stop-opacity=".65"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></linearGradient>';
}

// Type
$type = q('type','static');

// ------------------- TYPE: STATIC -------------------
if ($type === 'static') {

    // -------- Helpers nur für diesen Block (kollidieren nicht, da via function_exists abgesichert) --------
    if (!function_exists('mb_badge_named_colors')) {
        function mb_badge_named_colors(): array {
            // Kompakter, erweiterbarer Satz gängiger Farbnamen
            return [
                'black'=>'#000000','white'=>'#ffffff','gray'=>'#808080','grey'=>'#808080',
                'red'=>'#ff0000','orange'=>'#ffa500','yellow'=>'#ffff00','lime'=>'#00ff00','green'=>'#4c1',
                'teal'=>'#008080','cyan'=>'#00ffff','aqua'=>'#00ffff','blue'=>'#007ec6','navy'=>'#000080',
                'purple'=>'#800080','magenta'=>'#ff00ff','pink'=>'#ffc0cb','brown'=>'#a52a2a',
                // Shields-typische Defaults
                'brightgreen'=>'#4c1','green'=>'#4c1','yellowgreen'=>'#a4a61d','yellow'=>'#dfb317',
                'orange'=>'#fe7d37','red'=>'#e05d44','blue'=>'#007ec6','lightgrey'=>'#9f9f9f','success'=>'#4c1'
            ];
        }
    }

    if (!function_exists('mb_badge_normalize_color')) {
        /**
         * Akzeptiert: '*' (Fallback), Farbnamen, 3/6-stellige HEX (mit/ohne '#').
         * Gibt '#rrggbb' zurück, sonst $fallback.
         */
        function mb_badge_normalize_color(?string $raw, string $fallback): string {
            if ($raw === null || $raw === '' || $raw === '*') return $fallback;
            $raw = strtolower(trim($raw));
            $map = mb_badge_named_colors();
            if (isset($map[$raw])) return $map[$raw];

            // HEX normalisieren
            $c = ltrim($raw, '#');
            if (preg_match('/^[0-9a-f]{3}$/i', $c)) {
                // z.B. f0a -> ff00aa
                $c = $c[0].$c[0].$c[1].$c[1].$c[2].$c[2];
                return '#'.$c;
            }
            if (preg_match('/^[0-9a-f]{6}$/i', $c)) {
                return '#'.$c;
            }
            return $fallback;
        }
    }

    if (!function_exists('mb_badge_decode_text')) {
        /**
         * Dekodiert die Text-Escapes:
         *  --  => -
         *  __  => _
         *   _  => Leerzeichen
         * Außerdem URL-decode.
         */
        function mb_badge_decode_text(string $s): string {
            $s = urldecode($s);
            // Platzhalter, damit wir saubere Reihenfolge hinbekommen
            $H = "\x01"; // hyphen placeholder
            $U = "\x02"; // underscore placeholder
            $s = str_replace(['--','__'], [$H,$U], $s);
            $s = str_replace('_', ' ', $s); // simples '_' wird Space
            $s = str_replace([$H,$U], ['-','_'], $s); // Platzhalter zurück
            return $s;
        }
    }

    if (!function_exists('mb_badge_parse_side')) {
        /**
         * Zerlegt ein Segment wie:
         *   textLeft-colorLabel-textColorLabel
         *   textLeft-colorLabel
         *   textLeft
         * und gibt [text, color, textColor] (raw, noch nicht normalisiert) zurück.
         * Doppelte Bindestriche in text (--) werden vor dem Split geschützt.
         */
        function mb_badge_parse_side(?string $segment): array {
            if ($segment === null) return ['', null, null];
            $segment = urldecode($segment);

            $H = "\x01";               // Placeholder für '--'
            $segment = str_replace('--', $H, $segment);
            $parts = explode('-', $segment);
            foreach ($parts as &$p) { $p = str_replace($H, '-', $p); }

            $n = count($parts);
            if ($n >= 3) {
                $text = implode('-', array_slice($parts, 0, $n - 2));
                $color = $parts[$n - 2];
                $textColor = $parts[$n - 1];
            } elseif ($n === 2) {
                $text = $parts[0];
                $color = $parts[1];
                $textColor = null;
            } else {
                $text = $parts[0] ?? '';
                $color = null;
                $textColor = null;
            }
            // Text erst NACH dem Split decodieren (Unterstriche/Spaces/Hyphens)
            $text = mb_badge_decode_text($text);
            return [$text, $color, $textColor];
        }
    }

    // -------- Route-Unterstützung: /static/<left>/<right>/<style> --------
    // .htaccess sollte auf left/right/style mappen (siehe unten).
    $leftSeg  = $_GET['left']  ?? null;
    $rightSeg = $_GET['right'] ?? null;

    $textLeft  = q('textLeft',  'Status');
    $textRight = q('textRight', 'OK');

    // Diese Farben wurden oben schon aus GET initiiert:
    // $colorLabel, $colorMessage, $textColorLabel, $textColorMessage

    if ($leftSeg !== null || $rightSeg !== null) {
        // Links
        [$lText, $lColor, $lTextColor] = mb_badge_parse_side($leftSeg ?? '');
        if ($lText !== '')            $textLeft = $lText;
        if ($lColor !== null && $lColor !== '')
            $colorLabel = mb_badge_normalize_color($lColor, $colorLabel);
        if ($lTextColor !== null && $lTextColor !== '')
            $textColorLabel = mb_badge_normalize_color($lTextColor, $textColorLabel);

        // Rechts
        [$rText, $rColor, $rTextColor] = mb_badge_parse_side($rightSeg ?? '');
        if ($rText !== '')            $textRight = $rText;
        if ($rColor !== null && $rColor !== '')
            $colorMessage = mb_badge_normalize_color($rColor, $colorMessage);
        if ($rTextColor !== null && $rTextColor !== '')
            $textColorMessage = mb_badge_normalize_color($rTextColor, $textColorMessage);
    }

    // Großschreibung je nach Style
    if (!empty($p['caps'])) {
        $textLeft  = mb_strtoupper($textLeft);
        $textRight = mb_strtoupper($textRight);
    }

    // Breiten berechnen
    $wLeft  = $pad + approxWidth($textLeft)  + $pad;
    $wRight = $pad + approxWidth($textRight) + $pad;
    $W      = $wLeft + $wRight;
    $yText  = $h / 2;

    // SVG rendern
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="'.$W.'" height="'.$h.'">';
    if ($defs) echo '<defs>'.$defs.'</defs>';

    // Linkes Feld (nur links rund)
    echo '<path d="'.path_left_rounded($wLeft, $h, $radius).'" fill="'.$colorLabel.'"/>';

    // Rechtes Feld (nur rechts rund)
    echo '<g transform="translate('.$wLeft.',0)"><path d="'.path_right_rounded($wRight, $h, $radius).'" fill="'.$colorMessage.'"/></g>';

    // Texte
    echo '<text x="'.($wLeft/2).'" y="'.$yText.'" fill="'.$textColorLabel.'" font-family="'.esc($fontFamily).'" font-size="'.$font.'" text-anchor="middle" dominant-baseline="middle">'.esc($textLeft).'</text>';
    echo '<text x="'.($wLeft + $wRight/2).'" y="'.$yText.'" fill="'.$textColorMessage.'" font-family="'.esc($fontFamily).'" font-size="'.$font.'" text-anchor="middle" dominant-baseline="middle">'.esc($textRight).'</text>';

    // Plastic-Glanz
    if (!empty($p['gradient'])) {
        echo '<rect x="0" y="0" width="'.$W.'" height="'.$h.'" fill="url(#shine)"/>';
        echo '<rect x="0" y="0" width="'.$W.'" height="'.($h/2).'" fill="url(#gloss)"/>';
    }
    echo '</svg>';
    exit;
}


// ------------------- TYPE: ICON -------------------
if ($type === 'icon') {
    $icon       = q('icon');
    $iconColor  = normalizeColor(q('iconColor'), '#fff');
    $textLeft   = q('textIconLeft','');          // optionaler Text im linken Feld (rechts vom Icon)
    $textRight  = q('textIconRight','OK');       // Text im rechten Feld
    $colorLeft  = normalizeColor(q('colorLabel'), '#555');
    $colorRight = normalizeColor(q('colorMessage'), '#7db701');

    // --- Icon laden & robust normalisieren (immer 14x14) ---
    $iconW  = 14;
    $gap    = 1; // Abstand zwischen Icon und linkem Text
    $iconSvgNormalized = '';
    if ($icon && is_file($path = __DIR__ . '/icons/' . basename($icon).'.svg')) {
        $raw = file_get_contents($path);
        // Farbe auf iconColor bringen, falls 'currentColor' genutzt wird
        $raw = str_replace('currentColor', $iconColor, $raw);
        // XML/Doctype entfernen
        $raw = preg_replace('/<\?xml[^>]*\?>/i', '', $raw);
        $raw = preg_replace('/<!DOCTYPE[^>]*>/i', '', $raw);

        $viewBox = '0 0 24 24'; // brauchbarer Fallback
        $inner   = $raw;

        // Falls ein umschließendes <svg> vorhanden ist -> Inneres extrahieren + viewBox übernehmen
        if (preg_match('/<svg\b([^>]*)>(.*?)<\/svg>/is', $raw, $m)) {
            $svgAttrs = $m[1];
            $inner    = $m[2];
            if (preg_match('/viewBox="([^"]+)"/i', $svgAttrs, $vb)) {
                $viewBox = $vb[1];
            }
        } else {
            // Kein <svg>-Wrapper im Icon: versuchen, eine viewBox aus Pfadangaben NICHT zu erraten -> Fallback
            $viewBox = '0 0 24 24';
        }

        // Normalisierte, eigenständige SVG-Instanz (14x14), die wir frei positionieren können
        $iconSvgNormalized =
            '<svg width="'.$iconW.'" height="'.$iconW.'" viewBox="'.esc($viewBox).'" xmlns="http://www.w3.org/2000/svg">'.$inner.'</svg>';
    }

    // --- Breitenberechnung für das linke Feld ---
    // Aufbau: [pad] Icon [gap] (optional Text) [pad]
    if ($iconSvgNormalized !== '' && $textLeft !== '') {
        $leftInner = $iconW + $gap + approxWidth($textLeft);
    } elseif ($iconSvgNormalized !== '' && $textLeft === '') {
        $leftInner = $iconW; // nur Icon
    } elseif ($iconSvgNormalized === '' && $textLeft !== '') {
        $leftInner = approxWidth($textLeft); // nur Text (falls Icon fehlt)
    } else {
        $leftInner = 0; // weder Icon noch Text -> minimaler Rumpf
    }

    $wLeft  = $pad + $leftInner + $pad;
    // Minimalbreiten, damit Form nicht kollabiert
    if ($iconSvgNormalized !== '' && $textLeft === '') {
        $wLeft = max($wLeft, $pad + $iconW + $pad);
    }
    if ($iconSvgNormalized === '' && $textLeft === '') {
        $wLeft = max($wLeft, $pad * 2);
    }

    // Rechtes Feld
    $wRight = $pad + approxWidth($textRight) + $pad;
    $W = $wLeft + $wRight;

    // Vertikale Zentrierung
    $yText = $h / 2;

    echo '<svg xmlns="http://www.w3.org/2000/svg" width="'.$W.'" height="'.$h.'">';
    if ($defs) echo '<defs>'.$defs.'</defs>';

    // ---- Linkes Feld: nur links runde Ecken ----
    echo '<path d="'.path_left_rounded($wLeft, $h, $radius).'" fill="'.$colorLeft.'"/>';

    // Icon-Position: mit Text links am Padding; ohne Text zentriert
    if ($iconSvgNormalized !== '') {
        if ($textLeft !== '') {
            $iconX = $pad;
        } else {
            $iconX = ($wLeft - $iconW) / 2; // zentriert
        }
        $iconY = ($h - $iconW) / 2;
        // Wir geben das normalisierte 14x14-SVG mit expliziter x/y-Position aus
        // (positionieren per Attribut, ohne die internen Maße des Icons zu beeinflussen)
        $iconOut = preg_replace('/<svg\b/i', '<svg x="'.$iconX.'" y="'.$iconY.'"', $iconSvgNormalized, 1);
        echo $iconOut;
    }

    // Linker Text (falls vorhanden): mittig im Bereich rechts vom Icon (oder im ganzen linken Feld, wenn kein Icon)
    if ($textLeft !== '') {
        $textStartX = $pad + ($iconSvgNormalized !== '' ? ($iconW + $gap) : 0);
        $leftTextInnerWidth = $wLeft - $textStartX - $pad;
        $leftTextCenterX = $textStartX + ($leftTextInnerWidth / 2);
        echo '<text x="'.$leftTextCenterX.'" y="'.$yText.'" fill="'.$textColorLabel.'" font-family="'.esc($fontFamily).'" font-size="'.$font.'" text-anchor="middle" dominant-baseline="middle">'.esc($textLeft).'</text>';
    }

    // ---- Rechtes Feld: nur rechts runde Ecken ----
    echo '<g transform="translate('.$wLeft.',0)"><path d="'.path_right_rounded($wRight, $h, $radius).'" fill="'.$colorRight.'"/></g>';
    echo '<text x="'.($wLeft + $wRight/2).'" y="'.$yText.'" fill="'.$textColorMessage.'" font-family="'.esc($fontFamily).'" font-size="'.$font.'" text-anchor="middle" dominant-baseline="middle">'.esc($textRight).'</text>';

    // Glanz + Licht (Plastic)
    if (!empty($p['gradient'])) {
        echo '<rect x="0" y="0" width="'.$W.'" height="'.$h.'" fill="url(#shine)"/>';
        echo '<rect x="0" y="0" width="'.$W.'" height="'.($h/2).'" fill="url(#gloss)"/>';
    }

    echo '</svg>';
    exit;
}



// ------------------- TYPE: GITHUB -------------------
if ($type === 'github') {
    require_once __DIR__ . '/github.php';
    $owner  = q('owner','badges');
    $repo   = q('repo','shields');
    $metric = q('metric','stars');
    $ttl    = (int)$config['cacheTime'];
    $token  = $config['githubToken'] ?: null;
    $icon   = q('icon');
    $iconColor = normalizeColor(q('iconColor'), '#fff');

    if (!empty($config['allowedOwners']) && is_array($config['allowedOwners']) && !in_array($owner, $config['allowedOwners'], true)) {
        http_response_code(400);
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="20"><text x="10" y="15" fill="red">Invalid owner</text></svg>';
        exit;
    }

    $repoInfo = gh_repo_info($owner, $repo, $ttl, $token);
    switch ($metric) {
        case 'stars':        $text1=$L['stars'];        $text2=$repoInfo['stargazers_count']??'N/A'; break;
        case 'forks':        $text1=$L['forks'];        $text2=$repoInfo['forks_count']??'N/A'; break;
        case 'issues':       $text1=$L['issues'];       $text2=$repoInfo['open_issues_count']??'N/A'; break;
        case 'watchers':     $text1=$L['watchers'];     $text2=$repoInfo['subscribers_count']??($repoInfo['watchers_count']??'N/A'); break;
        case 'release':      $text1=$L['release'];      $rel=gh_repo_release($owner,$repo,$ttl,$token); $text2=$rel['tag_name']??($repoInfo['default_branch']??'N/A'); break;
        case 'license':      $text1=$L['license'];      $text2=$repoInfo['license']['spdx_id']??($repoInfo['license']['key']??'N/A'); break;
        case 'top_language': $text1=$L['top_language']; $text2=gh_top_language($owner,$repo,$ttl,$token)??'N/A'; break;
        default:             $text1='Metric';           $text2='N/A';
    }

    // --- Icon laden & normalisieren ---
    $iconW  = 14;
    $gap    = 2; // Abstand Icon ↔ Text
    $iconSvgNormalized = '';
    if ($icon && is_file($path = __DIR__ . '/icons/' . basename($icon).'.svg')) {
        $raw = file_get_contents($path);
        $raw = str_replace('currentColor', $iconColor, $raw);
        $raw = preg_replace('/<\?xml[^>]*\?>/i', '', $raw);
        $raw = preg_replace('/<!DOCTYPE[^>]*>/i', '', $raw);

        $viewBox = '0 0 24 24';
        $inner   = $raw;
        if (preg_match('/<svg\b([^>]*)>(.*?)<\/svg>/is', $raw, $m)) {
            $svgAttrs = $m[1];
            $inner    = $m[2];
            if (preg_match('/viewBox="([^"]+)"/i', $svgAttrs, $vb)) {
                $viewBox = $vb[1];
            }
        }
        $iconSvgNormalized =
            '<svg width="'.$iconW.'" height="'.$iconW.'" viewBox="'.esc($viewBox).'" xmlns="http://www.w3.org/2000/svg">'.$inner.'</svg>';
    }

    // --- Breitenberechnung für linkes Feld ---
    if ($iconSvgNormalized !== '' && $text1 !== '') {
        $leftInner = $iconW + $gap + approxWidth($text1);
    } elseif ($iconSvgNormalized !== '' && $text1 === '') {
        $leftInner = $iconW; // nur Icon
    } else {
        $leftInner = approxWidth($text1); // nur Text
    }

    $wLeft  = $pad + $leftInner + $pad;
    if ($iconSvgNormalized !== '' && $text1 === '') {
        $wLeft = max($wLeft, $pad + $iconW + $pad);
    }

    $wRight = $pad + approxWidth($text2) + $pad;
    $W = $wLeft + $wRight;

    $yText = $h / 2;

    echo '<svg xmlns="http://www.w3.org/2000/svg" width="'.$W.'" height="'.$h.'">';
    if ($defs) echo '<defs>'.$defs.'</defs>';

    // ---- Linkes Feld ----
    echo '<path d="'.path_left_rounded($wLeft, $h, $radius).'" fill="'.$colorLabel.'"/>';

    // Icon-Position
    if ($iconSvgNormalized !== '') {
        if ($text1 !== '') {
            $iconX = $pad;
        } else {
            $iconX = ($wLeft - $iconW) / 2; // zentriert
        }
        $iconY = ($h - $iconW) / 2;
        $iconOut = preg_replace('/<svg\b/i', '<svg x="'.$iconX.'" y="'.$iconY.'"', $iconSvgNormalized, 1);
        echo $iconOut;
    }

    // Text links (falls vorhanden)
    if ($text1 !== '') {
        $textStartX = $pad + ($iconSvgNormalized !== '' ? ($iconW + $gap) : 0);
        $leftTextInnerWidth = $wLeft - $textStartX - $pad;
        $leftTextCenterX = $textStartX + ($leftTextInnerWidth / 2);
        echo '<text x="'.$leftTextCenterX.'" y="'.$yText.'" fill="'.$textColorLabel.'" font-family="'.esc($fontFamily).'" font-size="'.$font.'" text-anchor="middle" dominant-baseline="middle">'.esc($text1).'</text>';
    }

    // ---- Rechtes Feld ----
    echo '<g transform="translate('.$wLeft.',0)"><path d="'.path_right_rounded($wRight, $h, $radius).'" fill="'.$colorMessage.'"/></g>';
    echo '<text x="'.($wLeft + $wRight/2).'" y="'.$yText.'" fill="'.$textColorMessage.'" font-family="'.esc($fontFamily).'" font-size="'.$font.'" text-anchor="middle" dominant-baseline="middle">'.esc($text2).'</text>';

    // Glanz/Licht
    if (!empty($p['gradient'])) {
        echo '<rect x="0" y="0" width="'.$W.'" height="'.$h.'" fill="url(#shine)"/>';
        echo '<rect x="0" y="0" width="'.$W.'" height="'.($h/2).'" fill="url(#gloss)"/>';
    }

    echo '</svg>';
    exit;
}

