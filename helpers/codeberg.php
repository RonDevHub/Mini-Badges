<?php
require_once __DIR__ . '/../badge.php';
// Base parameters (can be overwritten by "pairs")
$owner  = q('owner', 'RonDevHub');
$repo  = q('repo', 'Mini-Badges');
$metric = q('metric', 'stars');
$ttl    = (int)$config['cacheTime'];
$cbtoken  = $config['codebergToken'] ?: null;
$namedColors = $config['colors'];

// Route-pairs (from .htaccess)
$iconPair    = q('iconpair', null);
$langPair    = q('lang', null);
$messagePair = q('messagepair', null);
$labelPair   = q('labelpair', null);

if (!empty($config['allowedCodebergOwners']) && is_array($config['allowedCodebergOwners']) && !in_array($owner, $config['allowedCodebergOwners'], true)) {
    http_response_code(400);
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="20"><text x="10" y="15" fill="red">⛔️ ' . ($L['invalid_owner'] ?? 'Invalid owner') . '</text></svg>';
    exit;
}

function gh_cache_path(string $key): string
{
    return dirname(__DIR__) . '/cache/' . preg_replace('~[^a-zA-Z0-9_.-]~', '_', $key) . '.json';
}

function cb_cached_get(string $url, int $ttl, ?string $cbtoken = null, bool $withHeaders = false): ?array
{
    $cache = gh_cache_path(sha1($url . ($withHeaders ? '_h' : '')));
    if (is_file($cache) && (time() - filemtime($cache) < $ttl)) {
        $data = @file_get_contents($cache);
        if ($data !== false) return json_decode($data, true);
    }

    $headers = [
        'User-Agent: MiniBadges/1.2.0',
        'Accept: application/json'
    ];
    if ($cbtoken) $headers[] = 'Authorization: Bearer ' . $cbtoken;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
        ]);

        if ($withHeaders) {
            curl_setopt($ch, CURLOPT_HEADER, true);
        }

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($withHeaders) {
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headerPart = substr($resp, 0, $headerSize);
            $bodyPart   = substr($resp, $headerSize);

            $headerLines = explode("\r\n", trim($headerPart));
            $parsedHeaders = [];
            foreach ($headerLines as $line) {
                if (strpos($line, ':') !== false) {
                    [$key, $val] = explode(':', $line, 2);
                    $parsedHeaders[trim($key)] = trim($val);
                }
            }

            curl_close($ch);
            if ($bodyPart !== false && $code >= 200 && $code < 300) {
                $result = [
                    'headers' => $parsedHeaders,
                    'body'    => json_decode($bodyPart, true),
                ];
                @file_put_contents($cache, json_encode($result));
                return $result;
            }
        } else {
            curl_close($ch);
            if ($resp !== false && $code >= 200 && $code < 300) {
                @file_put_contents($cache, $resp);
                return json_decode($resp, true);
            }
        }

        return null;
    }

    // ---- Fallback ohne cURL (nur Body) ----
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 15,
        ]
    ]);
    $resp = @file_get_contents($url, false, $context);
    if ($resp !== false) {
        @file_put_contents($cache, $resp);
        return json_decode($resp, true);
    }

    return null;
}

// ------------------- Auxiliary function short format -------------------
function formatNumberShort($num)
{
    if ($num >= 1000000) return number_format($num / 1000000, ($num % 1000000 === 0) ? 0 : 1) . 'm';
    if ($num >= 1000) return number_format($num / 1000, ($num % 1000 === 0) ? 0 : 1) . 'k';
    return (string)$num;
}
if (!function_exists('formatBytesShort')) {
    function formatBytesShort(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        } elseif ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        } elseif ($bytes < 1073741824) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1073741824, 1) . ' GB';
    }
}
function cb_repo_info(string $owner, string $repo, int $ttl, ?string $cbtoken): ?array
{
    $url = "https://codeberg.org/api/v1/repos/$owner/$repo";
    return cb_cached_get($url, $ttl, $cbtoken);
}
function cb_user_info(string $owner, int $ttl, ?string $cbtoken): ?array
{
    $url = "https://codeberg.org/api/v1/users/$owner";
    return cb_cached_get($url, $ttl, $cbtoken);
}
// ----------------- Release Infos --------------------
if (!function_exists('cb_releases_info')) {
    function cb_releases_info(string $owner, string $repo, int $ttl = 3600, ?string $cbtoken = null): ?array
    {
        $url = "https://codeberg.org/api/v1/repos/$owner/$repo/releases";
        return cb_cached_get($url, $ttl, $cbtoken);
    }
}

if (!function_exists('cb_latest_release')) {
    function cb_latest_release(string $owner, string $repo, int $ttl = 3600, ?string $cbtoken = null): ?array
    {
        $releases = cb_releases_info($owner, $repo, $ttl, $cbtoken);
        if (!$releases || !is_array($releases) || count($releases) === 0) {
            return null;
        }
        // Releases are sorted by date descending → first is the newest
        return $releases[0];
    }
}
// ----------------- Lizenz Infos --------------------
if (!function_exists('cb_licenses_info')) {
    function cb_licenses_info(int $ttl = 86400, ?string $cbtoken = null): ?array
    {
        // List of all licenses from Codeberg / Forgejo
        $url = "https://codeberg.org/api/v1/licenses";
        return cb_cached_get($url, $ttl, $cbtoken);
    }
}

if (!function_exists('cb_repo_license')) {
    function cb_repo_license(string $owner, string $repo, int $ttl = 3600, ?string $cbtoken = null): string
    {
        // Get LICENSE file
        $url = "https://codeberg.org/api/v1/repos/$owner/$repo/contents/LICENSE";
        $file = cb_cached_get($url, $ttl, $cbtoken);
        if (!$file || !isset($file['content'])) {
            return "No LICENSE file";
        }

        // get the first few lines of the LICENSE file
        $raw = base64_decode($file['content']);
        $lines = preg_split("/\r\n|\n|\r/", trim($raw));
        $firstLines = mb_strtolower(implode("\n", array_slice($lines, 0, 5)));

        // Get license list
        $licenses = cb_licenses_info($ttl, $cbtoken);
        if (!$licenses || !is_array($licenses)) {
            return "Unknown";
        }

        // rough comparison via description or key
        foreach ($licenses as $lic) {
            $id   = strtolower($lic['key'] ?? '');
            $name = strtolower($lic['name'] ?? '');
            if ($id && strpos($firstLines, $id) !== false) {
                return $lic['spdx_id'] ?? strtoupper($id);
            }
            if ($name && strpos($firstLines, $name) !== false) {
                return $lic['spdx_id'] ?? ucfirst($name);
            }
        }

        return "Unrecognized";
    }
}

// ----------------- Helpers --------------------
if (!function_exists('cb_is_issue')) {
    function cb_is_issue(array $item): bool
    {
        if (isset($item['pull_request'])) return false;
        if (!empty($item['pull_request_id'])) return false;
        if (!empty($item['is_pull_request'])) return false;
        if (!empty($item['is_pull'])) return false;
        // Fallback: treat everything else as an issue
        return true;
    }
}

// ----------------- Repo Issues Count (robust) --------------------
if (!function_exists('cb_repo_issues_count')) {
    /**
     * @param string $owner
     * @param string $repo
     * @param string $state 'open'|'closed'|'all'
     * @param int $ttl Cache TTL
     * @param string|null $cbtoken
     * @return int
     */
    function cb_repo_issues_count(string $owner, string $repo, string $state = 'all', int $ttl = 300, ?string $cbtoken = null): int
    {
        $url = "https://codeberg.org/api/v1/repos/$owner/$repo/issues?state=" . rawurlencode($state) . "&limit=1";
        $resp = cb_cached_get($url, $ttl, $cbtoken, true);

        if (is_array($resp) && isset($resp['headers']) && is_array($resp['headers'])) {
            // case-insensitive lookup
            $h = array_change_key_case($resp['headers'], CASE_LOWER);
            if (isset($h['x-total-count'])) {
                return (int)$h['x-total-count'];
            }
        }
        $perPage = 50; // pro Seite
        $page = 1;
        $total = 0;

        while (true) {
            $urlPage = "https://codeberg.org/api/v1/repos/$owner/$repo/issues?state=" . rawurlencode($state)
                . "&limit=" . $perPage
                . "&page=" . $page;
            $pageData = cb_cached_get($urlPage, $ttl, $cbtoken);
            if (!$pageData || !is_array($pageData) || count($pageData) === 0) {
                break;
            }
            foreach ($pageData as $item) {
                if (is_array($item) && cb_is_issue($item)) {
                    $total++;
                }
            }
            if (count($pageData) < $perPage) {
                break;
            }
            $page++;
            if ($page > 200) { // 200 * 50 = 10000 items max
                break;
            }
        }
        return $total;
    }
}

// ----------------- User / All-Repos helper --------------------
if (!function_exists('cb_user_public_repos')) {
    function cb_user_public_repos(string $owner, int $ttl = 300, ?string $cbtoken = null): array
    {
        $url = "https://codeberg.org/api/v1/users/$owner/repos?limit=100";
        $repos = cb_cached_get($url, $ttl, $cbtoken);
        return is_array($repos) ? $repos : [];
    }
}
if (!function_exists('cb_all_repos_issues_count')) {
    function cb_all_repos_issues_count(string $owner, string $state = 'all', int $ttl = 300, ?string $cbtoken = null): int
    {
        $repos = cb_user_public_repos($owner, $ttl, $cbtoken);
        $total = 0;
        foreach ($repos as $r) {
            if (!isset($r['name'])) continue;
            $total += cb_repo_issues_count($owner, $r['name'], $state, $ttl, $cbtoken);
        }
        return $total;
    }
}

// ----------------------------------------------------
// Count pull requests from a repo
// ----------------------------------------------------
if (!function_exists('cb_repo_prs_count')) {
    /**
     * Counts pull requests in a repo via the /pulls API.
     * Uses X-Total-Count header, fallback pagination.
     */
    function cb_repo_prs_count(string $owner, string $repo, string $state = 'all', int $ttl = 300, ?string $cbtoken = null): int
    {
        $url = "https://codeberg.org/api/v1/repos/$owner/$repo/pulls?state=" . rawurlencode($state) . "&limit=1";
        $resp = cb_cached_get($url, $ttl, $cbtoken, true);

        if (is_array($resp) && isset($resp['headers']) && is_array($resp['headers'])) {
            $h = array_change_key_case($resp['headers'], CASE_LOWER);
            if (isset($h['x-total-count'])) {
                return (int)$h['x-total-count'];
            }
        }

        // 2) Fallback: Pagination
        $perPage = 50;
        $page = 1;
        $total = 0;

        while (true) {
            $urlPage = "https://codeberg.org/api/v1/repos/$owner/$repo/pulls?state=" . rawurlencode($state)
                . "&limit=" . $perPage
                . "&page=" . $page;
            $pageData = cb_cached_get($urlPage, $ttl, $cbtoken);
            if (!$pageData || !is_array($pageData) || count($pageData) === 0) {
                break;
            }

            $total += count($pageData);

            if (count($pageData) < $perPage) {
                break;
            }

            $page++;
            if ($page > 200) break;
        }

        return $total;
    }
}

// ----------------------------------------------------
// Count pull requests across all repos
// ----------------------------------------------------
if (!function_exists('cb_all_repos_prs_count')) {
    function cb_all_repos_prs_count(string $owner, string $state = 'all', int $ttl = 300, ?string $cbtoken = null): int
    {
        $repos = cb_user_public_repos($owner, $ttl, $cbtoken);
        $total = 0;
        foreach ($repos as $r) {
            if (!isset($r['name'])) continue;
            $total += cb_repo_prs_count($owner, $r['name'], $state, $ttl, $cbtoken);
        }
        return $total;
    }
}
// ----------------------------------------------------
// Get all repos of a user (public)
// ----------------------------------------------------
function cb_get_all_repos(string $username, int $ttl, ?string $token = null): array
{
    $page = 1;
    $perPage = 100;
    $all = [];
    do {
        $url = "https://codeberg.org/api/v1/users/{$username}/repos?page={$page}&limit={$perPage}";
        $repos = cb_cached_get($url, $ttl, $token);
        if (!$repos || !is_array($repos)) break;
        $count = count($repos);
        $all = array_merge($all, $repos);
        $page++;
    } while ($count === $perPage);
    return $all;
}
// ----------------------------------------------------
// Get the last commit of all repos (date, time, lines)
// ----------------------------------------------------
function cb_get_last_commit_info(string $owner, int $ttl, ?string $cbtoken = null): ?array
{
    $repos = cb_get_all_repos($owner, $ttl, $cbtoken);
    $latestCommit = null;
    $latestTime = 0;

    foreach ($repos as $repo) {
        $repoName = $repo['name'];

        // 1. Alle Branches des Repos abrufen
        $branchesUrl = "https://codeberg.org/api/v1/repos/{$owner}/{$repoName}/branches";
        $branches = cb_cached_get($branchesUrl, $ttl, $cbtoken);

        if (!is_array($branches)) continue;

        foreach ($branches as $branch) {
            $branchName = $branch['name'];

            // 2. Letzten Commit für diesen Branch holen
            $commitUrl = "https://codeberg.org/api/v1/repos/{$owner}/{$repoName}/commits?sha=" . urlencode($branchName) . "&limit=1";
            $commits = cb_cached_get($commitUrl, $ttl, $cbtoken);

            if (is_array($commits) && !empty($commits[0])) {
                $commit = $commits[0];
                $ts = strtotime($commit['commit']['author']['date']);

                if ($ts > $latestTime) {
                    $latestTime = $ts;
                    $latestCommit = [
                        'repo'       => $repoName,
                        'branch'     => $branchName,
                        'date'       => date('d.m.Y', $ts),
                        'clock'      => date('H:i', $ts),
                        'additions'  => $commit['stats']['additions'] ?? 0,
                        'deletions'  => $commit['stats']['deletions'] ?? 0,
                        'message'    => $commit['commit']['message'] ?? ''
                    ];
                }
            }
        }
    }

    return $latestCommit;
}


function cb_get_repo_milestones(string $owner, string $repo, int $ttl, ?string $cbtoken = null): ?array
{
    $url = "https://codeberg.org/api/v1/repos/{$owner}/{$repo}/milestones?state=all";
    return cb_cached_get($url, $ttl, $cbtoken);
}
function cb_get_all_milestone_counts(string $owner, int $ttl, ?string $cbtoken = null): array
{
    $repos = cb_get_all_repos($owner, $ttl, $cbtoken);
    $open = 0;
    $closed = 0;
    $total = 0;

    foreach ($repos as $repo) {
        $milestones = cb_get_repo_milestones($owner, $repo['name'], $ttl, $cbtoken);
        if (is_array($milestones)) {
            foreach ($milestones as $m) {
                $total++;
                if (($m['state'] ?? '') === 'open') {
                    $open++;
                } else {
                    $closed++;
                }
            }
        }
    }

    return [
        'open'   => $open,
        'closed' => $closed,
        'total'  => $total,
    ];
}

// Get all repos of a user
function cb_get_user_repos(string $owner, int $ttl, ?string $cbtoken = null): ?array
{
    $url = "https://codeberg.org/api/v1/users/{$owner}/repos?per_page=100";
    return cb_cached_get($url, $ttl, $cbtoken);
}

// Get all releases of a repo
function cb_get_repo_releases(string $owner, string $repo, int $ttl, ?string $cbtoken = null): ?array
{
    $url = "https://codeberg.org/api/v1/repos/{$owner}/{$repo}/releases?per_page=100";
    return cb_cached_get($url, $ttl, $cbtoken);
}

// Sum downloads of a release
function cb_sum_release_downloads(array $release): int
{
    $sum = 0;
    if (isset($release['assets']) && is_array($release['assets'])) {
        foreach ($release['assets'] as $asset) {
            $sum += $asset['download_count'] ?? 0;
        }
    }
    return $sum;
}

// ------------------- Normalizers (reused) -------------------
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
        if (preg_match('/^[0-9a-f]{6}$/i', $c)) return '#' . $c;
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
        foreach ($parts as &$p) $p = str_replace($H, '-', $p);
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
        foreach ($parts as &$p) $p = str_replace($H, '-', $p);
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


// ------------------- Repo Info -------------------
$repoInfo = cb_repo_info($owner, $repo, $ttl, $cbtoken);

// ------------------- Switch Metric -------------------
switch ($metric) {
    case 'stars':
        $text1 = $L['stars'] ?? 'Stars';
        $text2 = isset($repoInfo['stars_count']) ? formatNumberShort($repoInfo['stars_count']) : 'N/A';
        break;
    case 'name':
        $text1 = $L['name'] ?? 'Name';
        $text2 = $repoInfo['name'] ?? 'N/A';
        break;
    case 'forks':
        $text1 = $L['forks'] ?? 'Forks';
        $text2 = $repoInfo['forks_count'] ?? 'N/A';
        break;
    case (preg_match('/^created-(at|since)$/', $metric, $m) ? true : false):
        $text1 = $L['created_at'] ?? 'Created';
        if (empty($repoInfo['created_at'])) {
            $text2 = $L['no_value'] ?? 'N/A';
            break;
        }
        $dt = new DateTime($repoInfo['created_at']);
        if ($m[1] === 'since') {
            $now = new DateTime();
            $diff = $now->diff($dt);
            if ($diff->y > 0) {
                $text2 = $diff->y . ' ' . ($diff->y > 1
                    ? ($L['time_year_plural'] ?? 'years')
                    : ($L['time_year_singular'] ?? 'year'));
            } elseif ($diff->m > 0) {
                $text2 = $diff->m . ' ' . ($diff->m > 1
                    ? ($L['time_month_plural'] ?? 'months')
                    : ($L['time_month_singular'] ?? 'month'));
            } elseif ($diff->d > 0) {
                $text2 = $diff->d . ' ' . ($diff->d > 1
                    ? ($L['time_day_plural'] ?? 'days')
                    : ($L['time_day_singular'] ?? 'day'));
            } else {
                $text2 = $L['today'] ?? 'today';
            }
        } else {
            $text2 = $dt->format('d.m.Y H:i');
        }
        break;

    case (preg_match('/^updated-(at|since)$/', $metric, $m) ? true : false):
        $text1 = $L['updated_at'] ?? 'Last Update';
        if (empty($repoInfo['updated_at'])) {
            $text2 = $L['no_value'] ?? 'N/A';
            break;
        }
        $dt = new DateTime($repoInfo['updated_at']);
        if ($m[1] === 'since') {
            $now = new DateTime();
            $diff = $now->diff($dt);
            if ($diff->y > 0) {
                $text2 = $diff->y . ' ' . ($diff->y > 1
                    ? ($L['time_year_plural'] ?? 'years')
                    : ($L['time_year_singular'] ?? 'year'));
            } elseif ($diff->m > 0) {
                $text2 = $diff->m . ' ' . ($diff->m > 1
                    ? ($L['time_month_plural'] ?? 'months')
                    : ($L['time_month_singular'] ?? 'month'));
            } elseif ($diff->d > 0) {
                $text2 = $diff->d . ' ' . ($diff->d > 1
                    ? ($L['time_day_plural'] ?? 'days')
                    : ($L['time_day_singular'] ?? 'day'));
            } else {
                $text2 = $L['today'] ?? 'today';
            }
        } else {
            $text2 = $dt->format('d.m.Y H:i');
        }
        break;

    case 'issues-open':
        $text1 = $L['issues_open'] ?? 'Isues Open';
        $text2 = $repoInfo['open_issues_count'] ?? 'N/A';
        break;

    case 'issues-closed':
        $text1 = $L['issues_closed'] ?? 'Closed Issues';
        $text2 = cb_repo_issues_count($owner, $repo, 'closed', $ttl, $cbtoken);
        break;

    case 'issues':
        $text1 = $L['issues_total'] ?? 'All Issues';
        $text2 = cb_repo_issues_count($owner, $repo, 'all', $ttl, $cbtoken);
        break;

    case 'issues-allopen':
        $text1 = $L['issues_allopen'] ?? 'Open Issues (All Repos)';
        $text2 = cb_all_repos_issues_count($owner, 'open', $ttl, $cbtoken);
        break;

    case 'issues-all':
        $text1 = $L['issues_all'] ?? 'All Issues (All Repos)';
        $text2 = cb_all_repos_issues_count($owner, 'all', $ttl, $cbtoken);
        break;

    case 'issues-allclosed':
        $text1 = $L['issues_allclosed'] ?? 'Closed Issues (All Repos)';
        $text2 = cb_all_repos_issues_count($owner, 'closed', $ttl, $cbtoken);
        break;

    case 'prs':
        $text1 = $L['prs_total'] ?? 'All PRs';
        $text2 = cb_repo_prs_count($owner, $repo, 'all', $ttl, $cbtoken);
        break;

    case 'prs-open':
        $text1 = $L['prs_open'] ?? 'Open PRs';
        $text2 = cb_repo_prs_count($owner, $repo, 'open', $ttl, $cbtoken);
        break;

    case 'prs-closed':
        $text1 = $L['prs_closed'] ?? 'Closed PRs';
        $text2 = cb_repo_prs_count($owner, $repo, 'closed', $ttl, $cbtoken);
        break;

    case 'prs-all':
        $text1 = $L['prs_all'] ?? 'All PRs (All Repos)';
        $text2 = cb_all_repos_prs_count($owner, 'all', $ttl, $cbtoken);
        break;

    case 'prs-allopen':
        $text1 = $L['prs_allopen'] ?? 'Open PRs (All Repos)';
        $text2 = cb_all_repos_prs_count($owner, 'open', $ttl, $cbtoken);
        break;

    case 'prs-allclosed':
        $text1 = $L['prs_allclosed'] ?? 'Closed PRs (All Repos)';
        $text2 = cb_all_repos_prs_count($owner, 'closed', $ttl, $cbtoken);
        break;

    case 'size':
        $text1 = $L['size'] ?? 'Size';
        if (isset($repoInfo['size'])) {
            // Codeberg liefert die Größe in KB → für formatBytesShort in Bytes umrechnen
            $text2 = formatBytesShort($repoInfo['size'] * 1024);
        } else {
            $text2 = 'N/A';
        }
        break;
    case 'watchers':
        $text1 = $L['watchers'] ?? 'Watchers';
        $text2 = $repoInfo['watchers_count'] ?? 'N/A';
        break;
    case 'language':
        $text1 = $L['language'] ?? 'Language';
        $text2 = $repoInfo['language'] ?? 'N/A';
        break;
    case 'release':
        $rel = cb_latest_release($owner, $repo, $ttl, $cbtoken);
        $text1 = $L['release_label'] ?? 'Last release';
        $text2 = $rel['name'] ?? 'Unknown';
        break;
    case 'releases':
        $rels = cb_releases_info($owner, $repo, $ttl, $cbtoken);
        $text1 = $L['releases_label'] ?? 'Total releases';
        $text2 = is_array($rels) ? count($rels) : 0;
        break;
    case 'release-tag':
        $rel = cb_latest_release($owner, $repo, $ttl, $cbtoken);
        $text1 = $L['release_tag_label'] ?? 'Latest tag';
        $text2 = $rel['tag_name'] ?? 'Unknown';
        break;

    case (preg_match('/^release@(.*)$/', $metric, $m) ? true : false):
        $rels = cb_releases_info($owner, $repo, $ttl, $cbtoken);
        $given = $m[1];
        if ($rels && count($rels) > 0) {
            $latest = $rels[0]['tag_name'];
            if ($latest === $given) {
                $text1 = $L['release_check_label'] ?? 'Release check';
                $text2 = "Up to date ($given)";
            } else {
                $text1 = $L['release_new_label'] ?? 'New release available';
                $text2 = "$latest (You: $given)";
            }
        } else {
            $text1 = $L['release_check_label'] ?? 'Release check';
            $text2 = "No releases found";
        }
        break;

    case 'license':
        $license = cb_repo_license($owner, $repo, $ttl, $cbtoken);
        $text1 = $L['license_label'] ?? 'License';
        $text2 = $license;
        break;

    case 'branch-default':
        $text1 = $L['branch_default'] ?? 'Default Branch';
        $text2 = $repoInfo['default_branch'] ?? 'N/A';
        break;

    case 'username':
        $user = cb_user_info($owner, $ttl, $cbtoken);
        $text1 = $L['name_label'] ?? 'Hello, my name is';
        $text2 = $user['username'] ?? 'Unknown';
        break;

    case 'location':
        $user = cb_user_info($owner, $ttl, $gcbtoken);
        $text1 = $L['location_label'] ?? "I'm from";
        $text2 = $user['location'] ?? ($L['no_value'] ?? 'Somewhere in the matrix 🌌');
        break;

    case 'register':
        $user = cb_user_info($owner, $ttl, $cbtoken);
        $text1 = $L['register_on'] ?? 'Registered on';
        if (!empty($user['created'])) {
            $dt = new DateTime($user['created']);
            $text2 = $dt->format('d.m.Y H:i');
        } else {
            $text2 = 'N/A';
        }
        break;

    case 'register-since':
        $user = cb_user_info($owner, $ttl, $cbtoken);
        $text1 = $L['register_since'] ?? 'Registered since';
        if (!empty($user['created'])) {
            $created = new DateTime($user['created']);
            $now = new DateTime();
            $diff = $now->diff($created);
            if ($diff->y > 0) {
                $text2 = $diff->y . ' ' . ($diff->y > 1 ? ($L['time_year_plural'] ?? 'years') : ($L['time_year_singular'] ?? 'year'));
            } elseif ($diff->m > 0) {
                $text2 = $diff->m . ' ' . ($diff->m > 1 ? ($L['time_month_plural'] ?? 'months') : ($L['time_month_singular'] ?? 'month'));
            } else {
                $text2 = $diff->d . ' ' . ($diff->d > 1 ? ($L['time_day_plural'] ?? 'days') : ($L['time_day_singular'] ?? 'day'));
            }
        } else {
            $text2 = 'N/A';
        }
        break;

    case 'followers':
        $user = cb_user_info($owner, $ttl, $cbtoken);
        $text1 = $L['followers'] ?? 'Followers';
        $text2 = isset($user['followers_count']) ? formatNumberShort($user['followers_count']) : 'N/A';
        break;
    case 'following':
        $user = cb_user_info($owner, $ttl, $cbtoken);
        $text1 = $L['following'] ?? 'Following';
        $text2 = isset($user['following_count']) ? formatNumberShort($user['following_count']) : 'N/A';
        break;
    case 'stars-give':
        $user = cb_user_info($owner, $ttl, $cbtoken);
        $text1 = $L['stars_give'] ?? 'Stars awarded';
        $text2 = isset($user['starred_repos_count']) ? formatNumberShort($user['starred_repos_count']) : 'N/A';
        break;

    case 'stars-all':
        $repos = cb_get_all_repos($owner, $ttl, $cbtoken);
        $total = 0;
        foreach ($repos as $repo) {
            $total += $repo['stars_count'] ?? 0;
        }
        $text1 = $L['stars_all'] ?? 'All Stars';
        $text2 = formatNumberShort($total);
        break;

    case 'watchers-all':
        $repos = cb_get_all_repos($owner, $ttl, $cbtoken);
        $total = 0;
        foreach ($repos as $repo) {
            $total += $repo['watchers_count'] ?? 0;
        }
        $text1 = $L['watchers_all'] ?? 'All Watchers';
        $text2 = formatNumberShort($total);
        break;

    case 'forks-all':
        $repos = cb_get_all_repos($owner, $ttl, $cbtoken);
        $total = 0;
        foreach ($repos as $repo) {
            $total += $repo['forks_count'] ?? 0;
        }
        $text1 = $L['forks_all'] ?? 'All Forks';
        $text2 = formatNumberShort($total);
        break;

    case 'size-all':
        $repos = cb_get_all_repos($owner, $ttl, $cbtoken);
        $totalKB = 0;
        foreach ($repos as $repo) {
            $totalKB += $repo['size'] ?? 0; // Codeberg returns size in KB
        }
        $text1 = $L['size_all'] ?? 'Total size';
        $text2 = formatBytesShort($totalKB * 1024);
        break;

    case 'releases-all':
        $repos = cb_get_all_repos($owner, $ttl, $cbtoken);
        $total = 0;
        foreach ($repos as $repo) {
            $relUrl = "https://codeberg.org/api/v1/repos/{$owner}/{$repo['name']}/releases";
            $rel = cb_cached_get($relUrl, $ttl, $cbtoken);
            if (is_array($rel)) {
                $total += count($rel);
            }
        }
        $text1 = $L['releases_all'] ?? 'All Releases';
        $text2 = formatNumberShort($total);
        break;

    case 'lastcommit':
        $info = cb_get_last_commit_info($owner, $ttl, $cbtoken);
        $text1 = $L['last_commit'] ?? 'Last commit';
        if ($info) {
            $text2 = "{$info['date']} {$info['clock']}";
        } else {
            $text2 = 'N/A';
        }
        break;

    case 'lastcommit-info':
        $info = cb_get_last_commit_info($owner, $ttl, $cbtoken);
        $text1 = $L['last_commit'] ?? 'Last commit';
        if ($info) {
            $adds = formatNumberShort($info['additions']);
            $dels = formatNumberShort($info['deletions']);
            $text2 = "{$info['date']} {$info['clock']} +{$adds} / -{$dels}";
        } else {
            $text2 = 'N/A';
        }
        break;

    case 'lastcommit-infos':
        $info = cb_get_last_commit_info($owner, $ttl, $cbtoken);
        $text1 = $L['last_commit'] ?? 'Last commit';
        if ($info) {
            $adds = formatNumberShort($info['additions']);
            $dels = formatNumberShort($info['deletions']);
            $text2 = "{$info['repo']}:{$info['branch']} • {$info['date']} {$info['clock']} +{$adds} / -{$dels}";
        } else {
            $text2 = 'N/A';
        }
        break;

    case 'milestones':
        $milestones = cb_get_repo_milestones($owner, $repo, $ttl, $cbtoken);
        $text1 = $L['milestones'] ?? 'Milestones';
        $text2 = is_array($milestones) ? formatNumberShort(count($milestones)) : 'N/A';
        break;

    case 'milestones-open':
        $milestones = cb_get_repo_milestones($owner, $repo, $ttl, $cbtoken);
        $openCount = 0;
        if (is_array($milestones)) {
            foreach ($milestones as $m) {
                if (($m['state'] ?? '') === 'open') $openCount++;
            }
        }
        $text1 = $L['milestones_open'] ?? 'Open milestones';
        $text2 = formatNumberShort($openCount);
        break;

    case 'milestones-closed':
        $milestones = cb_get_repo_milestones($owner, $repo, $ttl, $cbtoken);
        $closedCount = 0;
        if (is_array($milestones)) {
            foreach ($milestones as $m) {
                if (($m['state'] ?? '') === 'closed') $closedCount++;
            }
        }
        $text1 = $L['milestones_closed'] ?? 'Closed milestones';
        $text2 = formatNumberShort($closedCount);
        break;

    case 'milestones-all':
        $counts = cb_get_all_milestone_counts($owner, $ttl, $cbtoken);
        $text1 = $L['milestones_all'] ?? 'All milestones';
        $text2 = formatNumberShort($counts['total']);
        break;

    case 'milestones-allopen':
        $counts = cb_get_all_milestone_counts($owner, $ttl, $cbtoken);
        $text1 = $L['milestones_all_open'] ?? 'All open milestones';
        $text2 = formatNumberShort($counts['open']);
        break;

    case 'milestones-allclosed':
        $counts = cb_get_all_milestone_counts($owner, $ttl, $cbtoken);
        $text1 = $L['milestones_all_closed'] ?? 'All closed milestones';
        $text2 = formatNumberShort($counts['closed']);
        break;

    case 'milestonesinfo':
        $milestones = cb_get_repo_milestones($owner, $repo, $ttl, $cbtoken);
        if (is_array($milestones) && count($milestones) > 0) {
            $total = count($milestones);
            $open = 0;
            foreach ($milestones as $m) {
                if (($m['state'] ?? '') === 'open') $open++;
            }
            $percent = round(($open / $total) * 100);
            $text1 = $L['milestones_info'] ?? 'Milestones Info';
            $text2 = "{$open}/{$total} ({$percent}% open)";
        } else {
            $text1 = $L['milestones_info'] ?? 'Milestones Info';
            $text2 = '0/0 (0%)';
        }
        break;

    case 'milestonesinfo-open':
        $milestones = cb_get_repo_milestones($owner, $repo, $ttl, $cbtoken);
        if (is_array($milestones) && count($milestones) > 0) {
            $total = count($milestones);
            $open = 0;
            foreach ($milestones as $m) {
                if (($m['state'] ?? '') === 'open') $open++;
            }
            $percent = round(($open / $total) * 100);
            $text1 = $L['milestones_info_open'] ?? 'Open milestones (info)';
            $text2 = "{$open} ({$percent}% of {$total})";
        } else {
            $text1 = $L['milestones_info_open'] ?? 'Open milestones (info)';
            $text2 = '0 (0%)';
        }
        break;

    case 'milestonesinfo-closed':
        $milestones = cb_get_repo_milestones($owner, $repo, $ttl, $cbtoken);
        if (is_array($milestones) && count($milestones) > 0) {
            $total = count($milestones);
            $closed = 0;
            foreach ($milestones as $m) {
                if (($m['state'] ?? '') === 'closed') $closed++;
            }
            $percent = round(($closed / $total) * 100);
            $text1 = $L['milestones_info_closed'] ?? 'Closed milestones (info)';
            $text2 = "{$closed} ({$percent}% of {$total})";
        } else {
            $text1 = $L['milestones_info_closed'] ?? 'Closed milestones (info)';
            $text2 = '0 (0%)';
        }
        break;

    case 'repos':
        $repos = cb_get_user_repos($owner, $ttl, $cbtoken);
        $text1 = $L['repos'] ?? 'Public Repos';
        $text2 = is_array($repos) ? formatNumberShort(count($repos)) : 'N/A';
        break;

    case 'downloads':
        $releases = cb_get_repo_releases($owner, $repo, $ttl, $cbtoken);
        $downloads = 0;
        if (is_array($releases)) {
            foreach ($releases as $rel) {
                $downloads += cb_sum_release_downloads($rel);
            }
        }
        $text1 = $L['downloads'] ?? 'Downloads';
        $text2 = formatNumberShort($downloads);
        break;

    case 'downloads-latest':
        $releases = cb_get_repo_releases($owner, $repo, $ttl, $cbtoken);
        $downloads = 0;
        if (is_array($releases) && count($releases) > 0) {
            $latest = $releases[0]; // API usually returns sorted in descending order
            $downloads = cb_sum_release_downloads($latest);
        }
        $text1 = $L['downloads_latest'] ?? 'Latest Release Downloads';
        $text2 = formatNumberShort($downloads);
        break;

    case 'downloads-all':
        $repos = cb_get_user_repos($owner, $ttl, $cbtoken);
        $totalDownloads = 0;
        if (is_array($repos)) {
            foreach ($repos as $r) {
                $releases = cb_get_repo_releases($owner, $r['name'], $ttl, $cbtoken);
                if (is_array($releases)) {
                    foreach ($releases as $rel) {
                        $totalDownloads += cb_sum_release_downloads($rel);
                    }
                }
            }
        }
        $text1 = $L['downloads_all'] ?? 'All Downloads';
        $text2 = formatNumberShort($totalDownloads);
        break;

    default:
        $text1 = 'Metric';
        $text2 = 'N/A';
}
