<?php
// Mini-Badges – Badge Renderer (PHP only)
mb_internal_encoding('UTF-8');
$config = include __DIR__ . '/config.php';

// Output headers
header('Content-Type: image/svg+xml; charset=UTF-8');
header('Cache-Control: public, max-age=' . (int)$config['svgMaxAge']);

// Helpers
function q(string $key, ?string $default = null): ?string {
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function tr(array $t, string $key, string $fallback): string { return $t[$key] ?? $fallback; }
function approxWidth(string $text, int $char = 7): int {
    $len = mb_strlen($text);
    $widen = 0;
    for ($i=0; $i<$len; $i++) {
        $ch = mb_substr($text, $i, 1);
        if (!preg_match('/^[\x20-\x7E]$/u', $ch)) $widen++;
    }
    $effective = $len + $widen;
    return (int)($effective * $char);
}
function normalizeColor(?string $c, string $fallback): string {
    if (!$c) return $fallback;
    $c = ltrim($c, '#');
    if (preg_match('/^[0-9a-fA-F]{6}$/', $c)) return "#".$c;
    return $fallback;
}

// Load translations
$lang = preg_match('~^[a-z]{2}$~i', q('lang','en')) ? q('lang','en') : 'en';
$langFile = __DIR__ . "/lang/{$lang}.php";
$T = is_file($langFile) ? include $langFile : include __DIR__ . '/lang/en.php';

// Style presets
$style = q('style','flat');
$styles = [
    'flat' =>         [ 'height' => 20, 'radius' => 3, 'padX' => 10, 'gap' => 6, 'font' => 11, 'caps' => false, 'gradient' => false ],
    'flat-square' =>  [ 'height' => 20, 'radius' => 0, 'padX' => 10, 'gap' => 6, 'font' => 11, 'caps' => false, 'gradient' => false ],
    'plastic' =>      [ 'height' => 20, 'radius' => 3, 'padX' => 10, 'gap' => 6, 'font' => 11, 'caps' => false, 'gradient' => true  ],
    'for-the-badge' =>[ 'height' => 28, 'radius' => 5, 'padX' => 14, 'gap' => 10,'font' => 12, 'caps' => true,  'gradient' => false ],
];
$p = $styles[$style] ?? $styles['flat'];

// Colors & text
$color1     = normalizeColor(q('color1'), $config['defaultLabelColor']);
$color2     = normalizeColor(q('color2'), $config['defaultMessageColor']);
$textColor1 = normalizeColor(q('textColor1'), $config['defaultTextColor']);
$textColor2 = normalizeColor(q('textColor2'), $config['defaultTextColor']);
$icon       = q('icon');                // icon file name (without .svg)
$iconColor  = normalizeColor(q('iconColor'), '#fff');
$iconPos    = (int)q('iconPos', '1');   // 1 = in left, 2 = in right
$iconText   = null; // IconText nicht mehr nutzen im linken Teil

$type  = q('type','static');
$text1 = q('text1','Status');
$text2 = q('text2','OK');

// Dynamic: GitHub
if ($type === 'github') {
    require_once __DIR__ . '/github.php';
    $owner  = q('owner','badges');
    $repo   = q('repo','shields');
    $metric = q('metric','stars');
    $ttl    = (int)$config['cacheTime'];
    $token  = $config['githubToken'] ?: null;

    // Owner check
    if (!empty($config['allowedOwners']) && is_array($config['allowedOwners'])) {
        if (!in_array($owner, $config['allowedOwners'], true)) {
            http_response_code(400);
            echo '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="20"><text x="10" y="15" fill="red">Invalid owner</text></svg>';
            exit;
        }
    }

    $repoInfo = gh_repo_info($owner, $repo, $ttl, $token);

    switch ($metric) {
        case 'stars':
            $text1 = tr($T,'stars','Stars');
            $text2 = isset($repoInfo['stargazers_count']) ? (string)$repoInfo['stargazers_count'] : 'N/A';
            break;
        case 'forks':
            $text1 = tr($T,'forks','Forks');
            $text2 = isset($repoInfo['forks_count']) ? (string)$repoInfo['forks_count'] : 'N/A';
            break;
        case 'issues':
            $text1 = tr($T,'issues','Issues');
            $text2 = isset($repoInfo['open_issues_count']) ? (string)$repoInfo['open_issues_count'] : 'N/A';
            break;
        case 'watchers':
            $text1 = tr($T,'watchers','Watchers');
            $text2 = isset($repoInfo['subscribers_count']) ? (string)$repoInfo['subscribers_count'] :
                     (isset($repoInfo['watchers_count']) ? (string)$repoInfo['watchers_count'] : 'N/A');
            break;
        case 'release':
            $rel   = gh_repo_release($owner,$repo,$ttl,$token);
            $text1 = tr($T,'release','Release');
            $text2 = $rel['tag_name'] ?? ($repoInfo['default_branch'] ?? 'N/A');
            break;
        case 'license':
            $text1 = tr($T,'license','License');
            $text2 = $repoInfo['license']['spdx_id'] ?? ($repoInfo['license']['key'] ?? 'N/A');
            break;
        case 'top_language':
            $text1 = tr($T,'top_language','Top language');
            $top   = gh_top_language($owner,$repo,$ttl,$token);
            $text2 = $top ?: 'N/A';
            break;
    }
}

// Load icon SVG if present
$iconSvg = '';
if ($icon) {
    $path = __DIR__ . '/icons/' . basename($icon) . '.svg';
    if (is_file($path)) {
        $raw = file_get_contents($path);
        $iconSvg = str_replace('currentColor', $iconColor, $raw);
    }
}

// Geometry
$h = (int)$p['height'];
$pad = (int)$p['padX'];
$font = (int)$p['font'];
$radius = (int)$p['radius'];
$fontFamily = $config['fontFamily'];

if (!empty($p['caps'])) {
    $text1 = mb_strtoupper($text1);
    $text2 = mb_strtoupper($text2);
}

$iconWidth = $iconSvg ? 14 : 0; 
$iconGap   = $iconSvg ? 4 : 0;

$w1 = $pad + approxWidth($text1) + $pad;
$w2 = $pad + approxWidth($text2) + $pad;
if ($iconSvg && $iconPos === 1) $w1 += $iconWidth + $iconGap;
if ($iconSvg && $iconPos === 2) $w2 += $iconWidth + $iconGap;

$W  = $w1 + $w2;

// Optional gradient
$grad = '';
if (!empty($p['gradient'])) {
    $grad = '<linearGradient id="g" x2="0" y2="100%"><stop offset="0" stop-color="#fff" stop-opacity=".7"/><stop offset="1" stop-opacity=".7"/></linearGradient>';
}

// --- SVG Output ---
echo '<svg xmlns="http://www.w3.org/2000/svg" width="'.(int)$W.'" height="'.(int)$h.'" role="img">';
echo '<title>' . esc($text1 . ' ' . $text2) . '</title>';
if ($grad) echo $grad;

// backgrounds: nur außen Rundungen
echo '<rect x="0" y="0" width="'.$W.'" height="'.$h.'" rx="'.$radius.'" fill="'.$color2.'"/>';
echo '<rect x="0" y="```php
0" width="'.$w1.'" height="'.$h.'" rx="'.$radius.'" fill="'.$color1.'"/>';

if ($grad) {
    echo '<rect x="0" y="0" width="'.$W.'" height="'.$h.'" rx="'.$radius.'" fill="url(#g)"/>';
}

// Textposition
$yText = ($h / 2) + ($font / 2) - 2;

// Left field
$leftContentX = $pad;

// Icon only if icon is set and iconPos is 1 (before text1)
if ($iconSvg) {
    $iconY = ($h - 14) / 2; // Vertically center the icon
    echo '<g transform="translate('.(int)$leftContentX.','.(int)$iconY.')">'.$iconSvg.'</g>';
    $leftContentX += $iconWidth + $iconGap; // Adjust left content X to make room for the icon

    // Display text1 if set, otherwise use the default value or leave empty
    if ($text1) {
        $leftTextX = $w1 / 2;
        echo '<text x="'.(int)$leftTextX.'" y="'.(int)$yText.'" fill="'.$textColor1.'" font-family="'.esc($fontFamily).'" font-size="'.$font.'" text-anchor="middle">'.esc($text1).'</text>';
    }
} else {
    // If no icon is set, display only text1 in the left field
    $leftTextX = $w1 / 2;
    echo '<text x="'.(int)$leftTextX.'" y="'.(int)$yText.'" fill="'.$textColor1.'" font-family="'.esc($fontFamily).'" font-size="'.$font.'" text-anchor="middle">'.esc($text1).'</text>';
}

// Right field
$rightContentX = $w1 + $pad;

// Display text2
$rightTextX = $w1 + ($w2 / 2);
echo '<text x="'.(int)$rightTextX.'" y="'.(int)$yText.'" fill="'.$textColor2.'" font-family="'.esc($fontFamily).'" font-size="'.$font.'" text-anchor="middle">'.esc($text2).'</text>';

echo '</svg>';
