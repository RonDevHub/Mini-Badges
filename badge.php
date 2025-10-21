<?php
// Mini-Badges - Shields.io Style
mb_internal_encoding('UTF-8');
$config = include __DIR__ . '/helpers/config.php';

// Load language
$lang = q('lang', $config['defaultLang'] ?? 'en');
$langFile = __DIR__ . '/lang/' . basename($lang) . '.php';
$L = is_file($langFile) ? include $langFile : include __DIR__ . '/lang/en.php';

// Output headers
header('Content-Type: image/svg+xml; charset=UTF-8');
header('Cache-Control: public, max-age=' . (int)$config['svgMaxAge']);

// Helpers
function q(string $key, ?string $default = null): ?string
{
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}
function esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function approxWidth(string $text, int $char = 7): int
{
    $len = mb_strlen($text);
    $widen = 0;
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($text, $i, 1);
        if (!preg_match('/^[\x20-\x7E]$/u', $ch)) $widen++;
    }
    return (int)(($len + $widen) * $char);
}
function normalizeColor(?string $c, string $fallback): string
{
    if (!$c) return $fallback;
    $c = ltrim($c, '#');
    return preg_match('/^[0-9a-fA-F]{6}$/', $c) ? "#" . $c : $fallback;
}

// Path helper: Round only left / round only right (no rounding in the middle)
function path_left_rounded(int $w, int $h, int $r): string
{
    $r = max(0, min($r, intdiv($h, 2)));
    return "M {$r},0 H {$w} V {$h} H {$r} A {$r} {$r} 0 0 1 0 " . ($h - $r) . " V {$r} A {$r} {$r} 0 0 1 {$r} 0 Z";
}
function path_right_rounded(int $w, int $h, int $r): string
{
    $r = max(0, min($r, intdiv($h, 2)));
    $wr = $w - $r;
    return "M 0,0 H {$wr} A {$r} {$r} 0 0 1 {$w} {$r} V " . ($h - $r) . " A {$r} {$r} 0 0 1 {$wr} {$h} H 0 Z";
}

// Style presets
$style = q('style', 'flat');
$styles = [
    'flat' =>         ['height' => 20, 'radius' => 3,  'padX' => 5,  'gap' => 6,  'font' => 11, 'caps' => false, 'gradient' => false],
    'round' =>        ['height' => 20, 'radius' => 10, 'padX' => 5,  'gap' => 6,  'font' => 11, 'caps' => false, 'gradient' => false],
    'flat-square' =>  ['height' => 20, 'radius' => 0,  'padX' => 5,  'gap' => 6,  'font' => 11, 'caps' => false, 'gradient' => false],
    'plastic' =>      ['height' => 20, 'radius' => 3,  'padX' => 5,  'gap' => 6,  'font' => 11, 'caps' => false, 'gradient' => true],
    'for-the-badge' => ['height' => 28, 'radius' => 5,  'padX' => 10, 'gap' => 10, 'font' => 12, 'caps' => true,  'gradient' => false],
    // Newly added styles
    'social' =>        ['height' => 24, 'radius' => 12, 'padX' => 8,  'gap' => 8,  'font' => 12, 'caps' => false, 'gradient' => false],
    'classic' =>       ['height' => 22, 'radius' => 3,  'padX' => 6,  'gap' => 7,  'font' => 12, 'caps' => false, 'gradient' => true],
    'minimalist' =>    ['height' => 18, 'radius' => 0,  'padX' => 4,  'gap' => 5,  'font' => 10, 'caps' => true,  'gradient' => false],
    // Unusual styles
    'pill' =>          ['height' => 18, 'radius' => 9,  'padX' => 8,  'gap' => 6,  'font' => 10, 'caps' => false, 'gradient' => false],
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

// Plastic gloss / light effect
$defs = '';
if (!empty($p['gradient'])) {
    $defs .= '<linearGradient id="shine" x2="0" y2="100%"><stop offset="0" stop-color="#fff" stop-opacity=".18"/><stop offset="1" stop-color="#000" stop-opacity=".18"/></linearGradient>';
    $defs .= '<linearGradient id="gloss" x2="0" y2="100%"><stop offset="0" stop-color="#fff" stop-opacity=".65"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></linearGradient>';
}

// Type
$type = q('type', 'static');

// ------------------- TYPE: STATIC -------------------
if ($type === 'static') {
    $namedColors = $config['colors'];

    if (!function_exists('mb_badge_normalize_color')) {
        function mb_badge_normalize_color(?string $raw, string $fallback): string
        {
            global $config;
            if ($raw === null || $raw === '' || $raw === '*') return $fallback;
            $raw = strtolower(trim($raw));
            $map = $config['colors'] ?? [];
            if (isset($map[$raw])) return $map[$raw];
            $c = ltrim($raw, '#');
            if (preg_match('/^[0-9a-f]{3}$/i', $c)) {
                $c = $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
                return '#' . $c;
            }
            if (preg_match('/^[0-9a-f]{6}$/i', $c)) {
                return '#' . $c;
            }
            return $fallback;
        }
    }

    if (!function_exists('mb_badge_decode_text')) {
        /**
         * Decodes the text escapes:
         *  --  => -
         *  __  => _
         *   _  => space
         * Also URL-decode.
         */
        function mb_badge_decode_text(string $s): string
        {
            $s = urldecode($s);
            // Placeholder so we get a clean order
            $H = "\x01"; // hyphen placeholder
            $U = "\x02"; // underscore placeholder
            $s = str_replace(['--', '__'], [$H, $U], $s);
            $s = str_replace('_', ' ', $s); // simple '_' becomes Space
            $s = str_replace([$H, $U], ['-', '_'], $s); // Placeholder back
            return $s;
        }
    }

    if (!function_exists('mb_badge_parse_side')) {
        /**
         * Decomposes a segment like:
         *   textLeft-colorLabel-textColorLabel
         *   textLeft-colorLabel
         *   textLeft
         * and returns [text, color, textColor] (raw, not yet normalized).
         * Double hyphens in text (--) are protected before the split.
         */
        function mb_badge_parse_side(?string $segment): array
        {
            if ($segment === null) return ['', null, null];
            $segment = urldecode($segment);

            $H = "\x01";               // Placeholder for '--'
            $segment = str_replace('--', $H, $segment);
            $parts = explode('-', $segment);
            foreach ($parts as &$p) {
                $p = str_replace($H, '-', $p);
            }

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
            // Decode text only AFTER the split (underscores/spaces/hyphens)
            $text = mb_badge_decode_text($text);
            return [$text, $color, $textColor];
        }
    }

    // -------- Route support: /static/<left>/<right>/<style> --------
    // .htaccess should map to left/right/style (see below).
    $leftSeg  = $_GET['left']  ?? null;
    $rightSeg = $_GET['right'] ?? null;

    $textLeft  = q('textLeft',  'Status');
    $textRight = q('textRight', 'OK');

    // These colors were already initiated from GET above:
    // $colorLabel, $colorMessage, $textColorLabel, $textColorMessage

    if ($leftSeg !== null || $rightSeg !== null) {
        // Left
        [$lText, $lColor, $lTextColor] = mb_badge_parse_side($leftSeg ?? '');
        if ($lText !== '')            $textLeft = $lText;
        if ($lColor !== null && $lColor !== '')
            $colorLabel = mb_badge_normalize_color($lColor, $colorLabel);
        if ($lTextColor !== null && $lTextColor !== '')
            $textColorLabel = mb_badge_normalize_color($lTextColor, $textColorLabel);

        // Right
        [$rText, $rColor, $rTextColor] = mb_badge_parse_side($rightSeg ?? '');
        if ($rText !== '')            $textRight = $rText;
        if ($rColor !== null && $rColor !== '')
            $colorMessage = mb_badge_normalize_color($rColor, $colorMessage);
        if ($rTextColor !== null && $rTextColor !== '')
            $textColorMessage = mb_badge_normalize_color($rTextColor, $textColorMessage);
    }

    // Capitalization depending on style
    if (!empty($p['caps'])) {
        $textLeft  = mb_strtoupper($textLeft);
        $textRight = mb_strtoupper($textRight);
    }

    // Calculate widths
    $wLeft  = $pad + approxWidth($textLeft)  + $pad;
    $wRight = $pad + approxWidth($textRight) + $pad;
    $W      = $wLeft + $wRight;
    $yText  = $h / 2;

    // Render SVG
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="' . $W . '" height="' . $h . '">';
    if ($defs) echo '<defs>' . $defs . '</defs>';

    // Left field (only left corners rounded)
    echo '<path d="' . path_left_rounded($wLeft, $h, $radius) . '" fill="' . $colorLabel . '"/>';

    // Right field (only right corners rounded)
    echo '<g transform="translate(' . $wLeft . ',0)"><path d="' . path_right_rounded($wRight, $h, $radius) . '" fill="' . $colorMessage . '"/></g>';

    // Texts
    echo '<text x="' . ($wLeft / 2) . '" y="' . $yText . '" fill="' . $textColorLabel . '" font-family="' . esc($fontFamily) . '" font-size="' . $font . '" text-anchor="middle" dominant-baseline="middle">' . esc($textLeft) . '</text>';
    echo '<text x="' . ($wLeft + $wRight / 2) . '" y="' . $yText . '" fill="' . $textColorMessage . '" font-family="' . esc($fontFamily) . '" font-size="' . $font . '" text-anchor="middle" dominant-baseline="middle">' . esc($textRight) . '</text>';

    // Plastic gloss
    if (!empty($p['gradient'])) {
        echo '<rect x="0" y="0" width="' . $W . '" height="' . $h . '" fill="url(#shine)"/>';
        echo '<rect x="0" y="0" width="' . $W . '" height="' . ($h / 2) . '" fill="url(#gloss)"/>';
    }
    echo '</svg>';
    exit;
}

// ------------------- TYPE: ICON -------------------
if ($type === 'icon') {
    $namedColors = $config['colors'];

    if (!function_exists('mb_badge_normalize_color')) {
        function mb_badge_normalize_color(?string $raw, string $fallback): string
        {
            global $config;
            if ($raw === null || $raw === '' || $raw === '*') return $fallback;
            $raw = strtolower(trim($raw));
            $map = $config['colors'] ?? [];

            if (isset($map[$raw])) return $map[$raw];
            $c = ltrim($raw, '#');
            if (preg_match('/^[0-9a-f]{3}$/i', $c)) {
                $c = $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
                return '#' . $c;
            }
            if (preg_match('/^[0-9a-f]{6}$/i', $c)) {
                return '#' . $c;
            }
            return $fallback;
        }
    }

    if (!function_exists('mb_badge_decode_text')) {
        /**
         * Decode text escapes:
         *  --  => -
         *  __  => _
         *   _  => space
         * plus urldecode.
         */
        function mb_badge_decode_text(string $s): string
        {
            $s = urldecode($s);
            $H = "\x01"; // hyphen placeholder
            $U = "\x02"; // underscore placeholder
            $s = str_replace(['--', '__'], [$H, $U], $s);
            $s = str_replace('_', ' ', $s);
            $s = str_replace([$H, $U], ['-', '_'], $s);
            return $s;
        }
    }

    if (!function_exists('mb_badge_parse_side')) {
        /**
         * Parse segment like:
         *   text-color-textColor
         *   text-color
         *   text
         * Returns array [text, color, textColor] (raw).
         */
        function mb_badge_parse_side(?string $segment): array
        {
            if ($segment === null) return ['', null, null];
            $segment = urldecode($segment);

            $H = "\x01";               // placeholder for '--'
            $segment = str_replace('--', $H, $segment);
            $parts = explode('-', $segment);
            foreach ($parts as &$p) {
                $p = str_replace($H, '-', $p);
            }

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
            $text = mb_badge_decode_text($text);
            return [$text, $color, $textColor];
        }
    }

    if (!function_exists('mb_badge_parse_iconpair')) {
        /**
         * Parse icon pair: iconName-iconColor
         * iconName may contain '-' (preserve via '--' placeholder).
         * Returns [iconName, color] where iconName is not decoded to spaces.
         */
        function mb_badge_parse_iconpair(?string $segment): array
        {
            if ($segment === null) return ['', null];
            $segment = urldecode($segment);

            $H = "\x01"; // placeholder for '--' to protect hyphens in icon name
            $segment = str_replace('--', $H, $segment);
            $parts = explode('-', $segment);
            foreach ($parts as &$p) {
                $p = str_replace($H, '-', $p);
            }

            $n = count($parts);
            if ($n >= 2) {
                $color = array_pop($parts);
                $iconName = implode('-', $parts);
            } else {
                $iconName = $parts[0] ?? '';
                $color = null;
            }
            // Do not convert single '_' to space for icon names; allow '__' -> '_'
            $iconName = str_replace('__', '_', $iconName);
            return [$iconName, $color];
        }
    }

    // -------- Route segments (from rewrite rule) --------
    $iconPair = q('iconpair', null);   // e.g. "github-ff5555"
    $rightSeg = q('rightseg', null);   // e.g. "Stars-4c1-fff"
    $leftSeg  = q('leftseg', null);    // e.g. "PHP-7db701-fff"

    // Legacy / direct GET support (still allowed)
    $fallbackIcon = q('icon', null);
    $fallbackIconColor = q('iconColor', null);
    $textLeft = q('textIconLeft', '');
    $textRight = q('textIconRight', 'OK');

    // Defaults from surrounding scope (colorLabel/colorMessage etc.) are used as fallback:
    // $colorLabel, $colorMessage, $textColorLabel, $textColorMessage are expected to exist.

    // --- parse iconpair (if present) ---
    $iconNameParsed = null;
    $iconColorParsed = null;
    if ($iconPair !== null && $iconPair !== '') {
        [$iconNameParsed, $iconColorParsed] = mb_badge_parse_iconpair($iconPair);
    }

    // choose icon name and icon color (priority: iconpair > GET icon / iconColor)
    $iconName = $iconNameParsed ?: $fallbackIcon;
    $iconColorRaw = $iconColorParsed ?: $fallbackIconColor;
    // normalize icon color (allow names)
    $iconColor = mb_badge_normalize_color($iconColorRaw, '#fff');

    // --- parse left/right segments (triplets) ---
    [$rText, $rColorRaw, $rTextColorRaw] = mb_badge_parse_side($rightSeg ?? '');
    [$lText, $lColorRaw, $lTextColorRaw] = mb_badge_parse_side($leftSeg ?? '');

    // Apply parsed values if present, otherwise use GET/defaults
    if ($rText !== '') $textRight = $rText;
    if ($lText !== '') $textLeft = $lText;

    // Normalize colors; if segment contains '*' keep existing defaults.
    // colorLabel/colorMessage/textColorLabel/textColorMessage come from earlier in the script.
    if ($lColorRaw !== null && $lColorRaw !== '') {
        $colorLabel = mb_badge_normalize_color($lColorRaw, $colorLabel);
    }
    if ($lTextColorRaw !== null && $lTextColorRaw !== '') {
        $textColorLabel = mb_badge_normalize_color($lTextColorRaw, $textColorLabel);
    }
    if ($rColorRaw !== null && $rColorRaw !== '') {
        $colorMessage = mb_badge_normalize_color($rColorRaw, $colorMessage);
    }
    if ($rTextColorRaw !== null && $rTextColorRaw !== '') {
        $textColorMessage = mb_badge_normalize_color($rTextColorRaw, $textColorMessage);
    }

    // Support legacy single GET params if iconPair didn't specify icon name
    if (!$iconName) {
        $iconName = $fallbackIcon; // might be null
    }

    // --- Icon load / normalize (14x14) ---
    $iconW = 14;
    $gap = 2; // Abstand Icon ↔ Linker Text (smaller number = closer)
    $iconSvgNormalized = '';
    if ($iconName && is_file($path = __DIR__ . '/icons/' . basename($iconName) . '.svg')) {
        $raw = @file_get_contents($path);
        if ($raw !== false) {
            // Replace 'currentColor' with our chosen icon color
            $raw = str_replace('currentColor', $iconColor, $raw);
            // strip xml/doctypes
            $raw = preg_replace('/<\?xml[^>]*\?>/i', '', $raw);
            $raw = preg_replace('/<!DOCTYPE[^>]*>/i', '', $raw);

            $viewBox = '0 0 24 24';
            $inner = $raw;

            if (preg_match('/<svg\b([^>]*)>(.*?)<\/svg>/is', $raw, $m)) {
                $svgAttrs = $m[1];
                $inner = $m[2];
                if (preg_match('/viewBox="([^"]+)"/i', $svgAttrs, $vb)) {
                    $viewBox = $vb[1];
                }
            }

            // Build a normalized small svg we can position with x/y
            $iconSvgNormalized = '<svg width="' . $iconW . '" height="' . $iconW . '" viewBox="' . esc($viewBox) . '" xmlns="http://www.w3.org/2000/svg">' . $inner . '</svg>';
        }
    }

    // --- Width calculation for the left field ---
    // leftInner = icon + gap + textLeft (depending on presence)
    if ($iconSvgNormalized !== '' && $textLeft !== '') {
        $leftInner = $iconW + $gap + approxWidth($textLeft);
    } elseif ($iconSvgNormalized !== '' && $textLeft === '') {
        $leftInner = $iconW; // only icon
    } elseif ($iconSvgNormalized === '' && $textLeft !== '') {
        $leftInner = approxWidth($textLeft); // only text (if icon missing)
    } else {
        $leftInner = 0; // neither icon nor text -> minimal
    }

    $wLeft = $pad + $leftInner + $pad;
    // ensure minimal widths
    if ($iconSvgNormalized !== '' && $textLeft === '') {
        $wLeft = max($wLeft, $pad + $iconW + $pad);
    }
    if ($iconSvgNormalized === '' && $textLeft === '') {
        $wLeft = max($wLeft, $pad * 2);
    }

    // Right field width
    $wRight = $pad + approxWidth($textRight) + $pad;
    $W = $wLeft + $wRight;

    // Vertical text center
    $yText = $h / 2;

    // --- Render SVG ---
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int)$W . '" height="' . (int)$h . '">';
    if ($defs) echo '<defs>' . $defs . '</defs>';

    // Left field (only left corners rounded)
    echo '<path d="' . path_left_rounded((int)$wLeft, (int)$h, (int)$radius) . '" fill="' . esc($colorLabel) . '"/>';

    // Icon placement:
    if ($iconSvgNormalized !== '') {
        if ($textLeft !== '') {
            $iconX = $pad;
        } else {
            // center icon inside left field
            $iconX = max(0, ($wLeft - $iconW) / 2);
        }
        $iconY = max(0, ($h - $iconW) / 2);
        // inject x/y into the small svg element (safe: replaces first <svg)
        $iconOut = preg_replace('/<svg\b/i', '<svg x="' . (int)$iconX . '" y="' . (int)$iconY . '"', $iconSvgNormalized, 1);
        echo $iconOut;
    }

    // Left text (if present) — center in remaining area to the right of icon (or entire left box if no icon)
    if ($textLeft !== '') {
        $textStartX = $pad + ($iconSvgNormalized !== '' ? ($iconW + $gap) : 0);
        $leftTextInnerWidth = $wLeft - $textStartX - $pad;
        $leftTextCenterX = $textStartX + ($leftTextInnerWidth / 2);
        echo '<text x="' . (int)$leftTextCenterX . '" y="' . (int)$yText . '" fill="' . esc($textColorLabel) . '" font-family="' . esc($fontFamily) . '" font-size="' . $font . '" text-anchor="middle" dominant-baseline="middle">' . esc($textLeft) . '</text>';
    }

    // Right field (only right corners rounded)
    echo '<g transform="translate(' . (int)$wLeft . ',0)"><path d="' . path_right_rounded((int)$wRight, (int)$h, (int)$radius) . '" fill="' . esc($colorMessage) . '"/></g>';
    echo '<text x="' . ($wLeft + $wRight / 2) . '" y="' . (int)$yText . '" fill="' . esc($textColorMessage) . '" font-family="' . esc($fontFamily) . '" font-size="' . $font . '" text-anchor="middle" dominant-baseline="middle">' . esc($textRight) . '</text>';

    // Plastic shine/gloss (if enabled)
    if (!empty($p['gradient'])) {
        echo '<rect x="0" y="0" width="' . (int)$W . '" height="' . (int)$h . '" fill="url(#shine)"/>';
        echo '<rect x="0" y="0" width="' . (int)$W . '" height="' . ($h / 2) . '" fill="url(#gloss)"/>';
    }

    echo '</svg>';
    exit;
}

// ------------------- TYPE: GITHUB -------------------
if ($type === 'github') {
    require_once __DIR__ . '/helpers/github.php';
    
    // ------------------- Load icon -------------------
    $iconSvgNormalized = '';
    $iconW = 0;
    if ($iconRequested && is_file($path = __DIR__ . '/icons/' . basename($iconRequested) . '.svg')) {
        $raw = file_get_contents($path);
        $raw = str_replace('currentColor', $iconColorNormalized, $raw);
        $raw = preg_replace('/<\?xml[^>]*\?>/i', '', $raw);
        $raw = preg_replace('/<!DOCTYPE[^>]*>/i', '', $raw);
        $viewBox = '0 0 24 24';
        $inner = $raw;
        if (preg_match('/<svg\b([^>]*)>(.*?)<\/svg>/is', $raw, $m)) {
            $svgAttrs = $m[1];
            $inner = $m[2];
            if (preg_match('/viewBox="([^"]+)"/i', $svgAttrs, $vb)) {
                $viewBox = $vb[1];
            }
        }
        $iconW = 14;
        $iconSvgNormalized = '<svg width="' . $iconW . '" height="' . $iconW . '" viewBox="' . esc($viewBox) . '" xmlns="http://www.w3.org/2000/svg">' . $inner . '</svg>';
    }
    // ------------------- Widths -------------------
    $gapIconText = (int)($p['gap'] ?? 4);
    if ($iconSvgNormalized !== '' && $text1 !== '') {
        $leftInner = $iconW + $gapIconText + approxWidth($text1);
    } elseif ($iconSvgNormalized !== '' && $text1 === '') {
        $leftInner = $iconW;
    } else {
        $leftInner = approxWidth($text1);
    }
    $wLeft = $pad + $leftInner + $pad;
    if ($iconSvgNormalized !== '' && $text1 === '') {
        $wLeft = max($wLeft, $pad + $iconW + $pad);
    }
    $wRight = $pad + approxWidth($text2) + $pad;
    $W = $wLeft + $wRight;
    $yText = $h / 2;

    // ------------------- Output -------------------
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int)$W . '" height="' . (int)$h . '">';
    if ($defs) echo '<defs>' . $defs . '</defs>';
    echo '<path d="' . path_left_rounded($wLeft, $h, $radius) . '" fill="' . esc($colorLabel) . '"/>';
    if ($iconSvgNormalized !== '') {
        $iconX = ($text1 !== '') ? $pad : intval(($wLeft - $iconW) / 2);
        $iconY = intval(($h - $iconW) / 2);
        $iconOut = preg_replace('/<svg\b/i', '<svg x="' . $iconX . '" y="' . $iconY . '"', $iconSvgNormalized, 1);
        echo $iconOut;
    }
    if ($text1 !== '') {
        $textStartX = $pad + ($iconSvgNormalized !== '' ? ($iconW + $gapIconText) : 0);
        $leftTextInnerWidth = $wLeft - $textStartX - $pad;
        $leftTextCenterX = $textStartX + ($leftTextInnerWidth / 2);
        echo '<text x="' . $leftTextCenterX . '" y="' . $yText . '" fill="' . esc($textColorLabel) . '" font-family="' . esc($fontFamily) . '" font-size="' . $font . '" text-anchor="middle" dominant-baseline="middle">' . esc($text1) . '</text>';
    }
    echo '<g transform="translate(' . $wLeft . ',0)"><path d="' . path_right_rounded($wRight, $h, $radius) . '" fill="' . esc($colorMessage) . '"/></g>';
    echo '<text x="' . ($wLeft + $wRight / 2) . '" y="' . $yText . '" fill="' . esc($textColorMessage) . '" font-family="' . esc($fontFamily) . '" font-size="' . $font . '" text-anchor="middle" dominant-baseline="middle">' . esc($text2) . '</text>';
    if (!empty($p['gradient'])) {
        echo '<rect x="0" y="0" width="' . $W . '" height="' . $h . '" fill="url(#shine)"/>';
        echo '<rect x="0" y="0" width="' . $W . '" height="' . ($h / 2) . '" fill="url(#gloss)"/>';
    }
    echo '</svg>';
    exit;
}

// ------------------- TYPE: CODEBERG -------------------
if ($type === 'codeberg') {
    require_once __DIR__ . '/helpers/codeberg.php';
    
    // ------------------- Load icon -------------------
    $iconSvgNormalized = '';
    $iconW = 0;
    if ($iconRequested && is_file($path = __DIR__ . '/icons/' . basename($iconRequested) . '.svg')) {
        $raw = file_get_contents($path);
        $raw = str_replace('currentColor', $iconColorNormalized, $raw);
        $raw = preg_replace('/<\?xml[^>]*\?>/i', '', $raw);
        $raw = preg_replace('/<!DOCTYPE[^>]*>/i', '', $raw);
        $viewBox = '0 0 24 24';
        $inner = $raw;
        if (preg_match('/<svg\b([^>]*)>(.*?)<\/svg>/is', $raw, $m)) {
            $svgAttrs = $m[1];
            $inner = $m[2];
            if (preg_match('/viewBox="([^"]+)"/i', $svgAttrs, $vb)) {
                $viewBox = $vb[1];
            }
        }
        $iconW = 14;
        $iconSvgNormalized = '<svg width="' . $iconW . '" height="' . $iconW . '" viewBox="' . esc($viewBox) . '" xmlns="http://www.w3.org/2000/svg">' . $inner . '</svg>';
    }
    // ------------------- Widths -------------------
    $gapIconText = (int)($p['gap'] ?? 4);
    if ($iconSvgNormalized !== '' && $text1 !== '') {
        $leftInner = $iconW + $gapIconText + approxWidth($text1);
    } elseif ($iconSvgNormalized !== '' && $text1 === '') {
        $leftInner = $iconW;
    } else {
        $leftInner = approxWidth($text1);
    }
    $wLeft = $pad + $leftInner + $pad;
    if ($iconSvgNormalized !== '' && $text1 === '') {
        $wLeft = max($wLeft, $pad + $iconW + $pad);
    }
    $wRight = $pad + approxWidth($text2) + $pad;
    $W = $wLeft + $wRight;
    $yText = $h / 2;

    // ------------------- Output -------------------
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int)$W . '" height="' . (int)$h . '">';
    if ($defs) echo '<defs>' . $defs . '</defs>';
    echo '<path d="' . path_left_rounded($wLeft, $h, $radius) . '" fill="' . esc($colorLabel) . '"/>';
    if ($iconSvgNormalized !== '') {
        $iconX = ($text1 !== '') ? $pad : intval(($wLeft - $iconW) / 2);
        $iconY = intval(($h - $iconW) / 2);
        $iconOut = preg_replace('/<svg\b/i', '<svg x="' . $iconX . '" y="' . $iconY . '"', $iconSvgNormalized, 1);
        echo $iconOut;
    }
    if ($text1 !== '') {
        $textStartX = $pad + ($iconSvgNormalized !== '' ? ($iconW + $gapIconText) : 0);
        $leftTextInnerWidth = $wLeft - $textStartX - $pad;
        $leftTextCenterX = $textStartX + ($leftTextInnerWidth / 2);
        echo '<text x="' . $leftTextCenterX . '" y="' . $yText . '" fill="' . esc($textColorLabel) . '" font-family="' . esc($fontFamily) . '" font-size="' . $font . '" text-anchor="middle" dominant-baseline="middle">' . esc($text1) . '</text>';
    }
    echo '<g transform="translate(' . $wLeft . ',0)"><path d="' . path_right_rounded($wRight, $h, $radius) . '" fill="' . esc($colorMessage) . '"/></g>';
    echo '<text x="' . ($wLeft + $wRight / 2) . '" y="' . $yText . '" fill="' . esc($textColorMessage) . '" font-family="' . esc($fontFamily) . '" font-size="' . $font . '" text-anchor="middle" dominant-baseline="middle">' . esc($text2) . '</text>';
    if (!empty($p['gradient'])) {
        echo '<rect x="0" y="0" width="' . $W . '" height="' . $h . '" fill="url(#shine)"/>';
        echo '<rect x="0" y="0" width="' . $W . '" height="' . ($h / 2) . '" fill="url(#gloss)"/>';
    }
    echo '</svg>';
    exit;
}