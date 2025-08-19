<?php
// Mini‑Badges – Badge Renderer (PHP only)
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
    // simplified width estimate (emoji count ~ double width)
    $len = mb_strlen($text);
    // crude emoji heuristic: count characters outside basic latin
    $widen = 0;
    for ($i=0; $i<$len; $i++) {
        $ch = mb_substr($text, $i, 1);
        if (!preg_match('/^[\x20-\x7E]$/u', $ch)) $widen++;
    }
    $effective = $len + $widen; // widen non-ASCII a bit
    return (int)($effective * $char);
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
$color1     = q('color1', $config['defaultLabelColor']);
$color2     = q('color2', $config['defaultMessageColor']);
$textColor1 = q('textColor1', $config['defaultTextColor']);
$textColor2 = q('textColor2', $config['defaultTextColor']);
$icon       = q('icon');               // icons/<name>.svg (without .svg)
$iconColor  = q('iconColor', '#fff');
$iconPos    = (int)q('iconPos', '1');  // 1 = in left, 2 = in right
$iconText   = q('iconText');           // optional text after icon (in same field)

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

    $repoInfo = gh_repo_info($owner, $repo, $ttl, $token);

    switch ($metric) {
        case 'stars':
            $text1 = tr($T,'stars','Stars');
            $text2 = isset($repoInfo['stargazers_count']) ? (string)$repoInfo['stargazers_count'] : 'N/A';
            $icon  = $icon ?: 'star';
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
        default:
            // leave as provided (allows custom combos)
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
    if ($iconText) $iconText = mb_strtoupper($iconText);
}

// Compute widths; include iconText in proper field
$iconWidth = $iconSvg ? 14 : 0; // raw icon width
$iconGap   = $iconSvg ? 4 : 0;
$iconTextWidth = $iconText ? approxWidth(' ' . $iconText) : 0;

$w1 = $pad + approxWidth($text1) + $pad;
$w2 = $pad + approxWidth($text2) + $pad;

if ($iconSvg && $iconPos === 1) $w1 += $iconWidth + $iconGap + $iconTextWidth;
if ($iconSvg && $iconPos === 2) $w2 += $iconWidth + $iconGap + $iconTextWidth;

$W  = $w1 + $w2;

// Optional gradient (plastic style)
$grad = '';
if (!empty($p['gradient'])) {
    $grad = '<linearGradient id="g" x2="0" y2="100%"><stop offset="0" stop-color="#fff" stop-opacity=".7"/><stop offset="1" stop-opacity=".7"/></linearGradient>';
}

// Start SVG
echo '<svg xmlns="http://www.w3.org/2000/svg" width="'.(int)$W.'" height="'.(int)$h.'" role="img">';
echo '<title>' . esc($text1 . ' ' . $text2) . '</title>';
if ($grad) echo $grad;

// Backgrounds
echo '<rect rx="'.$radius.'" width="'.$w1.'" height="'.$h.'" fill="'.esc($color1).'"/>';
echo '<rect rx="'.$radius.'" x="'.$w1.'" width="'.$w2.'" height="'.$h.'" fill="'.esc($color2).'"/>';

// Overlay for plastic
if ($grad) {
    echo '<rect rx="'.$radius.'" width="'.$W.'" height="'.$h.'" fill="url(#g)"/>';
}

// Text positions
$yText = ($h / 2) + ($font / 2) - 2;

// Left text + optional icon
$leftContentX = $pad;
if ($iconSvg && $iconPos === 1) {
    // icon
    $iconY = ($h - 14) / 2;
    echo '<g transform="translate('.(int)$leftContentX.','.(int)$iconY.')">'.$iconSvg.'</g>';
    $leftContentX += $iconWidth + $iconGap;
    if ($iconText) {
        echo '<text x="'.(int)$leftContentX.'" y="'.(int)$yText.'" fill="'.esc($textColor1).'" font-family="'.esc($fontFamily).'" font-size="'.$font.'" dominant-baseline="middle">'.esc(' '.$iconText).'</text>';
        $leftContentX += $iconTextWidth;
    }
}
$leftTextX = $w1 / 2;
echo '<text x="'.(int)$leftTextX.'" y="'.(int)$yText.'" fill="'.esc($textColor1).'" font-family="'.esc($fontFamily).'" font-size="'.$font.'" text-anchor="middle">'.esc($text1).'</text>';

// Right text + optional icon
$rightContentX = $w1 + $pad;
if ($iconSvg && $iconPos === 2) {
    $iconY = ($h - 14) / 2;
    echo '<g transform="translate('.(int)$rightContentX.','.(int)$iconY.')">'.$iconSvg.'</g>';
    $rightContentX += $iconWidth + $iconGap;
    if ($iconText) {
        echo '<text x="'.(int)$rightContentX.'" y="'.(int)$yText.'" fill="'.esc($textColor2).'" font-family="'.esc($fontFamily).'" font-size="'.$font.'" dominant-baseline="middle">'.esc(' '.$iconText).'</text>';
        $rightContentX += $iconTextWidth;
    }
}
$rightTextX = $w1 + ($w2 / 2);
echo '<text x="'.(int)$rightTextX.'" y="'.(int)$yText.'" fill="'.esc($textColor2).'" font-family="'.esc($fontFamily).'" font-size="'.$font.'" text-anchor="middle">'.esc($text2).'</text>';

echo '</svg>';
