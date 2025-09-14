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
    // Base parameters (can be overwritten by "pairs")
    $owner  = q('owner', 'RonDevHub');
    $repo   = q('repo', 'Mini-Badges');
    $metric = q('metric', 'stars');
    $ttl    = (int)$config['cacheTime'];
    $token  = $config['githubToken'] ?: null;
    $namedColors = $config['colors'];
    // Route-pairs (from .htaccess)
    $iconPair    = q('iconpair', null);
    $langPair    = q('lang', null);
    $messagePair = q('messagepair', null);
    $labelPair   = q('labelpair', null);

    if (!function_exists('mb_badge_normalize_color')) {
        function mb_badge_normalize_color(?string $raw, string $fallback): string
        {
            global $config; // <-- Wichtig: Zugriff auf globale Variable

            if ($raw === null || $raw === '' || $raw === '*') return $fallback;
            $raw = strtolower(trim($raw));

            // Verwende das Farbarray aus der globalen Konfiguration
            $map = $config['colors'] ?? []; // Verwende den ?? Operator als Fallback

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
        function mb_badge_decode_text(string $s): string
        {
            $s = urldecode($s);
            $H = "\x01";
            $U = "\x02";
            $s = str_replace(['--', '__'], [$H, $U], $s);
            $s = str_replace('_', ' ', $s);
            $s = str_replace([$H, $U], ['-', '_'], $s);
            return $s;
        }
    }

    if (!function_exists('mb_badge_parse_color_pair')) {
        function mb_badge_parse_color_pair(?string $seg): array
        {
            if ($seg === null || $seg === '') return [null, null];
            $seg = urldecode($seg);
            $H = "\x01";
            $seg = str_replace('--', $H, $seg);
            $parts = explode('-', $seg);
            foreach ($parts as &$p) {
                $p = str_replace($H, '-', $p);
            }
            return [$parts[0] ?? null, $parts[1] ?? null];
        }
    }
    if (!function_exists('mb_badge_parse_iconpair')) {
        function mb_badge_parse_iconpair(?string $seg): array
        {
            if ($seg === null || $seg === '') return [null, null];
            $seg = urldecode($seg);
            $H = "\x01";
            $seg = str_replace('--', $H, $seg);
            $parts = explode('-', $seg);
            foreach ($parts as &$p) {
                $p = str_replace($H, '-', $p);
            }
            if (count($parts) >= 2) {
                $iconColor = array_pop($parts);
                $iconName = implode('-', $parts);
            } else {
                $iconName = $parts[0];
                $iconColor = null;
            }
            $iconName = str_replace('__', '_', $iconName);
            $iconName = str_replace('_', ' ', $iconName);
            return [$iconName, $iconColor];
        }
    }

    // ------------------- Route pairs -------------------
    [$routeIconName, $routeIconColor] = mb_badge_parse_iconpair($iconPair);
    [$routeMsgColorRaw, $routeMsgTextColorRaw] = mb_badge_parse_color_pair($messagePair);
    [$routeLblColorRaw, $routeLblTextColorRaw] = mb_badge_parse_color_pair($labelPair);

    $iconRequested = $routeIconName ?: q('icon', null);
    $iconColorRaw  = ($routeIconColor !== null && $routeIconColor !== '') ? $routeIconColor : (q('iconColor', null) ?? '*');

    if ($routeLblColorRaw !== null && $routeLblColorRaw !== '') {
        $colorLabel = mb_badge_normalize_color($routeLblColorRaw, $colorLabel);
    }
    if ($routeLblTextColorRaw !== null && $routeLblTextColorRaw !== '') {
        $textColorLabel = mb_badge_normalize_color($routeLblTextColorRaw, $textColorLabel);
    }
    if ($routeMsgColorRaw !== null && $routeMsgColorRaw !== '') {
        $colorMessage = mb_badge_normalize_color($routeMsgColorRaw, $colorMessage);
    }
    if ($routeMsgTextColorRaw !== null && $routeMsgTextColorRaw !== '') {
        $textColorMessage = mb_badge_normalize_color($routeMsgTextColorRaw, $textColorMessage);
    }
    $iconColorNormalized = mb_badge_normalize_color($iconColorRaw, '#ffffff');

    // ------------------- Cache Helper -------------------
    if (!function_exists('gh_cache_get')) {
        function gh_cache_get(string $key, int $ttl = 300)
        {
            $file = sys_get_temp_dir() . '/ghcache_' . md5($key) . '.json';
            if (file_exists($file) && (filemtime($file) + $ttl) > time()) {
                $data = file_get_contents($file);
                return $data !== false ? json_decode($data, true) : null;
            }
            return null;
        }
    }
    if (!function_exists('gh_cache_set')) {
        function gh_cache_set(string $key, $data)
        {
            $file = sys_get_temp_dir() . '/ghcache_' . md5($key) . '.json';
            file_put_contents($file, json_encode($data));
        }
    }
    // ------------------- Repo Info -------------------
    $repoInfo = gh_repo_info($owner, $repo, $ttl, $token);

    // ------------------- Switch Metric -------------------
    switch ($metric) {
        // Case statement for 'lines'
        case (preg_match('/^lines(?:-(added|deleted|all))?$/', $metric, $m) ? true : false):
            $stats = gh_repo_lines($owner, $repo, $ttl, $token);
            $text1 = '';
            $text2 = '';
            $type = $m[1] ?? 'all';

            switch ($type) {
                case 'added':
                    $text1 = $L['lines_added'] ?? 'Lines added';
                    $text2 = formatNumberShort($stats['added']);
                    break;
                case 'deleted':
                    $text1 = $L['lines_deleted'] ?? 'Lines deleted';
                    $text2 = formatNumberShort($stats['deleted']);
                    break;
                case 'all':
                default:
                    $text1 = $L['lines_all'] ?? 'Lines';
                    $text2 = formatNumberShort($stats['total']);
                    break;
            }
            break;
        case (preg_match('/^milestones(?:-(open|closed|all|allopen|allclosed))?$/', $metric, $m) ? true : false):
            $type = $m[1] ?? 'default';
            $text1 = '';
            $text2 = '';

            // Metrics for a single repository
            if (in_array($type, ['default', 'open', 'closed'])) {
                switch ($type) {
                    case 'open':
                        $text1 = $L['milestones_open'] ?? 'Milestones (Open)';
                        $text2 = formatNumberShort(gh_repo_milestones_robust($owner, $repo, $ttl, $token, 'open'));
                        break;
                    case 'closed':
                        $text1 = $L['milestones_closed'] ?? 'Milestones (Closed)';
                        $text2 = formatNumberShort(gh_repo_milestones_robust($owner, $repo, $ttl, $token, 'closed'));
                        break;
                    case 'default':
                    default:
                        $text1 = $L['milestones'] ?? 'Milestones';
                        $text2 = formatNumberShort(gh_repo_milestones_robust($owner, $repo, $ttl, $token, 'all'));
                        break;
                }
            }
            // Metrics for all repositories of a user
            else {
                switch ($type) {
                    case 'all':
                        $text1 = $L['milestones_all'] ?? 'Milestones (All)';
                        $text2 = formatNumberShort(gh_user_milestones_all($owner, $ttl, $token, 'all'));
                        break;
                    case 'allopen':
                        $text1 = $L['milestones_allopen'] ?? 'Milestones (All Open)';
                        $text2 = formatNumberShort(gh_user_milestones_all($owner, $ttl, $token, 'open'));
                        break;
                    case 'allclosed':
                        $text1 = $L['milestones_allclosed'] ?? 'Milestones (All Closed)';
                        $text2 = formatNumberShort(gh_user_milestones_all($owner, $ttl, $token, 'closed'));
                        break;
                }
            }
            break;
        case 'stars':
            $text1 = $L['stars'] ?? 'Stars';
            $text2 = isset($repoInfo['stargazers_count']) ? formatNumberShort($repoInfo['stargazers_count']) : 'N/A';
            break;
        case 'stars-all':
            $text1 = $L['stars_all'] ?? 'Stars (All)';
            $text2 = formatNumberShort(gh_user_stars_all($owner, $ttl, $token));
            break;
        case 'forks':
            $text1 = $L['forks'] ?? 'Forks';
            $text2 = isset($repoInfo['forks_count']) ? formatNumberShort($repoInfo['forks_count']) : 'N/A';
            break;
        case 'forks-all':
            $text1 = $L['forks_all'] ?? 'Forks (All)';
            $text2 = formatNumberShort(gh_user_forks_all($owner, $ttl, $token));
            break;
        case 'issues':
            $text1 = $L['issues'] ?? 'Issues';
            $text2 = isset($repoInfo['open_issues_count']) ? formatNumberShort($repoInfo['open_issues_count']) : 'N/A';
            break;
        case 'issues-open':
            $text1 = 'Issues';
            $count = gh_repo_issues_open($owner, $repo, $ttl, $token);
            $text2 = formatNumberShort($count) . ' open';
            break;
        case 'issues-closed':
            $text1 = 'Issues';
            $count = gh_repo_issues_closed($owner, $repo, $ttl, $token);
            $text2 = formatNumberShort($count) . ' closed';
            break;    
        case 'issues_all':
            $text1 = $L['issues_all'] ?? 'Issues (All)';
            $text2 = formatNumberShort(gh_user_issues_all($owner, $ttl, $token));
            break;
        case 'watchers':
            $text1 = $L['watchers'] ?? 'Watchers';
            if (isset($repoInfo['subscribers_count'])) $text2 = formatNumberShort($repoInfo['subscribers_count']);
            elseif (isset($repoInfo['watchers_count'])) $text2 = formatNumberShort($repoInfo['watchers_count']);
            else $text2 = 'N/A';
            break;
        case 'watchers_all':
            $text1 = $L['watchers_all'] ?? 'Watchers (All)';
            $text2 = formatNumberShort(gh_user_watchers_all($owner, $ttl, $token));
            break;
        case 'downloads':
            $text1 = $L['downloads'] ?? 'Downloads';
            $text2 = formatNumberShort(gh_repo_downloads($owner, $repo, $ttl, $token));
            break;
        case 'downloads-latest':
            $text1 = $L['downloads_latest'] ?? 'Downloads Latest Release';
            $text2 = formatNumberShort(gh_repo_downloads_latest($owner, $repo, $ttl, $token));
            break;
        case 'downloads-all':
            $text1 = $L['downloads_all'] ?? 'Downloads (All)';
            $text2 = formatNumberShort(gh_user_downloads_all($owner, $ttl, $token));
            break;
        case 'branches':
            $text1 = $L['branches'] ?? 'Branches';
            $text2 = formatNumberShort(gh_repo_branches($owner, $repo, $ttl, $token));
            break;
        case 'branches-all':
            $text1 = $L['branches_all'] ?? 'Branches (All)';
            $text2 = formatNumberShort(gh_user_branches_all($owner, $ttl, $token));
            break;
        case 'release':
            $text1 = $L['release'] ?? 'Release';
            $rel = gh_repo_release($owner, $repo, $ttl, $token);
            $text2 = $rel['tag_name'] ?? ($repoInfo['default_branch'] ?? 'N/A');
            break;
        case 'license':
            $text1 = $L['license'] ?? 'License';
            $text2 = $repoInfo['license']['spdx_id'] ?? ($repoInfo['license']['key'] ?? 'N/A');
            break;
        case 'top_language':
            $text1 = $L['top_language'] ?? 'Top language';
            $text2 = gh_top_language($owner, $repo, $ttl, $token) ?? 'N/A';
            break;
        case 'size':
            $text1 = $L['size'] ?? 'Size';
            $text2 = isset($repoInfo['size']) ? formatNumberShort($repoInfo['size']) . ' KB' : 'N/A';
            break;
        case 'size_all':
            $text1 = $L['size_all'] ?? 'Size (All)';
            $text2 = formatNumberShort(gh_user_size_all($owner, $ttl, $token)) . ' KB';
            break;
        case 'created_at':
            $text1 = $L['created_at'] ?? 'Created';
            $text2 = isset($repoInfo['created_at']) ? date('Y-m-d', strtotime($repoInfo['created_at'])) : 'N/A';
            break;
        case 'repos_count':
            $text1 = $L['repos_count'] ?? 'Public Repos';
            $text2 = formatNumberShort(gh_user_repos_count($owner, $ttl, $token));
            break;
        case (preg_match('/^top_languages_all(?:-(\d+))?$/', $metric, $m) ? true : false):
            $limit = isset($m[1]) ? (int)$m[1] : 1;
            $langs = gh_user_top_languages_all($owner, $ttl, $token, $limit);
            if ($limit === 1) {
                $text1 = $langs[0][0];
                $text2 = $langs[0][1];
            } else {
                $pairs = [];
                foreach ($langs as [$lang, $perc]) $pairs[] = "$lang $perc";
                $text1 = $L['top_languages_all'] ?? 'Top languages (All)';
                $text2 = implode(' | ', $pairs);
            }
            // For the special case where we want no percentage
            if ($metric === 'top_languages_all' && isset($langs[0][0])) {
                $text1 = $L['top_languages_all'] ?? 'Top language (All)';
                $text2 = $langs[0][0];
            }
            break;
        case 'prs':
            $text1 = $L['pull_requests'] ?? 'Pull requests';
            $text2 = formatNumberShort(gh_repo_pull_requests($owner, $repo, $ttl, $token));
            break;
        case 'prs-merged':
            $text1 = $L['prs_merged'] ?? 'Merged PRs';
            $text2 = formatNumberShort(gh_repo_merged_pull_requests($owner, $repo, $ttl, $token));
            break;
        case 'prs-mergedall':
            $text1 = $L['prs_merged_all'] ?? 'Merged PRs (All)';
            $text2 = formatNumberShort(gh_user_merged_pull_requests_all($owner, $ttl, $token));
            break;
        case 'prs-all':
            $text1 = $L['pull_requests_all'] ?? 'PRs (All)';
            $text2 = formatNumberShort(gh_user_pull_requests_all($owner, $ttl, $token));
            break;
        case 'subscribers':
            $text1 = $L['subscribers_count'] ?? 'Subscribers';
            $text2 = isset($repoInfo['subscribers_count']) ? formatNumberShort($repoInfo['subscribers_count']) : 'N/A';
            break;
        case 'subscribers-all':
            $text1 = $L['subscribers_count_all'] ?? 'Subscribers (All)';
            $text2 = formatNumberShort(gh_user_subscribers_all($owner, $ttl, $token));
            break;
        case 'successrate':
            $text1 = $L['success_rate'] ?? 'Success Rate';
            $text2 = gh_repo_success_rate($owner, $repo, $ttl, $token);
            break;
        case 'successrate-all':
            $text1 = $L['success_rate_all'] ?? 'Success Rate (All)';
            $text2 = gh_user_success_rate_all($owner, $ttl, $token);
            break;
        case 'files':
            $text1 = $L['files'] ?? 'Files';
            $text2 = formatNumberShort(gh_repo_files($owner, $repo, $ttl, $token));
            break;
        case 'files-all':
            $text1 = $L['files_all'] ?? 'Files (All)';
            $text2 = formatNumberShort(gh_user_files_all($owner, $ttl, $token));
            break;
        case 'tags':
            $text1 = $L['tags'] ?? 'Tags';
            $text2 = formatNumberShort(gh_repo_tags($owner, $repo, $ttl, $token));
            break;
        case 'tags-all':
            $text1 = $L['tags_all'] ?? 'Tags (All)';
            $text2 = formatNumberShort(gh_user_tags_all($owner, $ttl, $token));
            break;
        case 'follower':
            $text1 = $L['follower'] ?? 'Followers';
            $text2 = formatNumberShort(gh_user_followers($owner, $ttl, $token));
            break;
        case 'following':
            $text1 = $L['following'] ?? 'Following';
            $text2 = formatNumberShort(gh_user_following($owner, $ttl, $token));
            break;
        case 'projects':
            $text1 = $L['projects'] ?? 'Projects';
            $text2 = formatNumberShort(gh_repo_projects($owner, $repo, $ttl, $token));
            break;
        case 'projects-all':
            $text1 = $L['projects_all'] ?? 'Projects (All)';
            $text2 = formatNumberShort(gh_user_projects_all($owner, $ttl, $token));
            break;
        case 'releases':
            $text1 = $L['releases'] ?? 'Releases';
            $text2 = formatNumberShort(gh_repo_releases($owner, $repo, $ttl, $token));
            break;
        case 'releases_all':
            $text1 = $L['releases_all'] ?? 'Releases (All)';
            $text2 = formatNumberShort(gh_user_releases_all($owner, $ttl, $token));
            break;
        case (preg_match('/^gists(?:-(list|size|date|forks|listall|list(\d+))|-(size|forks)@(.+))?$/', $metric, $m) ? true : false):
            $gists_info = gh_user_gists_info($owner, $ttl, $token);
            $text1 = '';
            $text2 = '';
            // Check for specific formats (e.g. 'size@' or 'forks@')
            if (isset($m[3]) && isset($m[4])) {
                $type = $m[3];
                $gist_name = $m[4];
                $found_gist = null;
                foreach ($gists_info['gists'] as $gist) {
                    foreach ($gist['files'] as $file) {
                        if (strtolower($file['name']) === strtolower($gist_name)) {
                            $found_gist = $gist;
                            break 2;
                        }
                    }
                }
                if (!$found_gist) {
                    $text1 = $L['gist_not_found'] ?? 'Gist Not Found';
                    $text2 = $gist_name;
                } else {
                    if ($type === 'size') {
                        $text1 = $L['gist_size'] ?? "Gist: {$gist_name}";
                        $text2 = formatSizeShort($found_gist['size']);
                    } else { // type === 'forks'
                        $forks_count = gh_gist_forks_count($found_gist['id'], $ttl, $token);
                        $text1 = $L['gist_forks'] ?? "Gist: {$gist_name}";
                        $text2 = formatNumberShort($forks_count);
                    }
                }
            }
            // Standard formats (e.g. 'gists' or 'gists-size')
            else {
                $type = $m[1] ?? 'default';
                switch ($type) {
                    case 'default':
                        $text1 = $L['gists'] ?? 'Gists';
                        $text2 = formatNumberShort($gists_info['count']);
                        break;
                    case (preg_match('/^list(\d+)$/', $type, $n) ? $type : false):
                        $limit = (int)$n[1];
                        $list_names = array_column(array_slice($gists_info['gists'], 0, $limit), 'description');
                        $text1 = $L['gists_list_short'] ?? "Last {$limit} Gists";
                        $text2 = implode(' | ', array_filter($list_names));
                        if (empty($text2)) {
                            $text2 = 'N/A';
                        }
                        break;
                    case 'listall':
                        $text1 = $L['gists_listall'] ?? 'All Gists';
                        $list_names = array_column($gists_info['gists'], 'description');
                        $text2 = implode(' | ', array_filter($list_names));
                        if (empty($text2)) {
                            $text2 = 'N/A';
                        }
                        break;
                    case 'size':
                        $text1 = $L['gists_size'] ?? 'Total Size';
                        $text2 = formatSizeShort($gists_info['total_size']);
                        break;
                    case 'date':
                        $text1 = $L['gists_date'] ?? 'Last Gist';
                        if ($gists_info['latest_gist_date']) {
                            $text2 = date('Y-m-d', strtotime($gists_info['latest_gist_date']));
                        } else {
                            $text2 = 'N/A';
                        }
                        break;
                    case 'forks':
                        $total_forks = 0;
                        foreach ($gists_info['gists'] as $gist) {
                            $total_forks += gh_gist_forks_count($gist['id'], $ttl, $token);
                        }
                        $text1 = $L['gists_forks'] ?? 'Gist Forks';
                        $text2 = formatNumberShort($total_forks);
                        break;
                }
            }
            break;
            break;
        case (preg_match('/^checkversion@(.+)$/', $metric, $m) ? true : false):
            $current_version = $m[1];
            $text1 = '';
            $text2 = '';
            // Handle fallback if no version is provided
            if (empty($current_version)) {
                $text1 = $L['version_check'] ?? 'Version Check';
                $text2 = $L['no_version_found'] ?? 'No version provided';
            } else {
                $comparison = gh_repo_compare_version($owner, $repo, $current_version, $ttl, $token);
                switch ($comparison['status']) {
                    case 'ok':
                        $text1 = $L['check_release'] ?? 'Check Release';
                        $text2 = $L['check_ok'] ?? 'OK';
                        break;
                    case 'new_release':
                        $text1 = $L['new_release_available'] ?? 'New release available:';
                        $text2 = $comparison['latest_version'];
                        break;
                    case 'error':
                    default:
                        $text1 = $L['version_check'] ?? 'Version Check';
                        $text2 = $L['error'] ?? $comparison['message'];
                        break;
                }
            }
            break;
        case (preg_match('/^top_language_count(?:-(\d+))?$/', $metric, $m) ? true : false):
            $limit = isset($m[1]) ? (int)$m[1] : 1;
            $langs = gh_top_language_count($owner, $repo, $ttl, $token, $limit);
            if ($limit === 1) {
                $text1 = $langs[0][0];
                $text2 = $langs[0][1];
            } else {
                $pairs = [];
                foreach ($langs as [$lang, $perc]) $pairs[] = "$lang $perc";
                $text1 = $L['top_languages'] ?? 'Top languages';
                $text2 = implode(' | ', $pairs);
            }
            break;
        case (preg_match('/^push(?:-(time|datetime|info|lines))?$/', $metric, $m) ? true : false):
            $subMetric = $m[1] ?? 'default';
            $text1 = '';
            $text2 = '';
            $pushInfo = gh_push_info($owner, $repo, $ttl, $token);
            switch ($subMetric) {
                case 'time':
                    $text1 = $L['push_time'] ?? 'Last Push';
                    $text2 = ($pushInfo['latest']['datetime'] !== 'N/A') ? date('H:i:s', strtotime($pushInfo['latest']['datetime'])) : 'N/A';
                    break;
                case 'datetime':
                    $text1 = $L['push_datetime'] ?? 'Last Push';
                    $text2 = $pushInfo['latest']['datetime'] ?? 'N/A';
                    break;
                case 'lines':
                    $text1 = $L['push_lines'] ?? 'Lines';
                    $text2 = "+{$pushInfo['latest']['lines_added']} | -{$pushInfo['latest']['lines_deleted']}";
                    break;
                case 'info':
                    $text1 = $L['push_info'] ?? 'Last Push Info';
                    $text2 = "{$pushInfo['latest']['date']} | +{$pushInfo['latest']['lines_added']} -{$pushInfo['latest']['lines_deleted']}";
                    break;
                default:
                    $text1 = $L['push_date'] ?? 'Last Push';
                    $text2 = $pushInfo['latest']['date'] ?? 'N/A';
                    break;
            }
            break;
        case (preg_match('/^commit(?:-(time|datetime|info|lines))?$/', $metric, $m) ? true : false):
            $subMetric = $m[1] ?? 'default';
            $text1 = '';
            $text2 = '';
            $commitInfo = gh_commit_info($owner, $repo, $ttl, $token);
            switch ($subMetric) {
                case 'time':
                    $text1 = $L['commit_time'] ?? 'Last Commit';
                    $text2 = ($commitInfo['latest']['datetime'] !== 'N/A') ? date('H:i:s', strtotime($commitInfo['latest']['datetime'])) : 'N/A';
                    break;
                case 'datetime':
                    $text1 = $L['commit_datetime'] ?? 'Last Commit';
                    $text2 = $commitInfo['latest']['datetime'] ?? 'N/A';
                    break;
                case 'lines':
                    $text1 = $L['commit_lines'] ?? 'Lates Commit';
                    $text2 = "+{$commitInfo['latest']['lines_added']} | -{$commitInfo['latest']['lines_deleted']}";
                    break;
                case 'info':
                    $text1 = $L['commit_info'] ?? 'Last Commit Info';
                    $text2 = "{$commitInfo['latest']['date']} | +{$commitInfo['latest']['lines_added']} -{$commitInfo['latest']['lines_deleted']}";
                    break;
                default:
                    $text1 = $L['commit_date'] ?? 'Last Commit';
                    $text2 = $commitInfo['latest']['date'] ?? 'N/A';
                    break;
            }
            break;
        default:
            $text1 = 'Metric';
            $text2 = 'N/A';
    }
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
