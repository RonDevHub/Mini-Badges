<?php
require_once __DIR__ . '/../badge.php';
// Base parameters (can be overwritten by "pairs")
    $owner  = q('owner', 'RonDevHub');
    $repo   = q('repo', 'Mini-Badges');
    $metric = q('metric', 'stars');
    $ttl    = (int)$config['cacheTime'];
    $ghtoken  = $config['githubToken'] ?: null;
    $namedColors = $config['colors'];
    // Route-pairs (from .htaccess)
    $iconPair    = q('iconpair', null);
    $langPair    = q('lang', null);
    $messagePair = q('messagepair', null);
    $labelPair   = q('labelpair', null);

    if (!empty($config['allowedOwners']) && is_array($config['allowedOwners']) && !in_array($owner, $config['allowedOwners'], true)) {
        http_response_code(400);
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="20"><text x="10" y="15" fill="red">⛔️ Invalid owner</text></svg>';
        exit;
    }

function gh_cache_path(string $key): string
{
    return dirname(__DIR__) . '/cache/' . preg_replace('~[^a-zA-Z0-9_.-]~', '_', $key) . '.json';
}

function gh_cached_get(string $url, int $ttl, ?string $ghtoken = null): ?array
{
    $cache = gh_cache_path(sha1($url));
    if (is_file($cache) && (time() - filemtime($cache) < $ttl)) {
        $data = @file_get_contents($cache);
        if ($data !== false) return json_decode($data, true);
    }
    $headers = [
        'User-Agent: MiniBadges/1.2.0',
        'Accept: application/vnd.github+json'
    ];
    if ($ghtoken) $headers[] = 'Authorization: Bearer ' . $ghtoken;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp !== false && $code >= 200 && $code < 300) {
            @file_put_contents($cache, $resp);
            return json_decode($resp, true);
        }
        return null;
    }
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
function gh_repo_info(string $owner, string $repo, int $ttl, ?string $ghtoken): ?array
{
    $url = "https://api.github.com/repos/$owner/$repo";
    return gh_cached_get($url, $ttl, $ghtoken);
}
function gh_repo_release(string $owner, string $repo, int $ttl, ?string $ghtoken): ?array
{
    $url = "https://api.github.com/repos/$owner/$repo/releases/latest";
    return gh_cached_get($url, $ttl, $ghtoken);
}
function gh_repo_languages(string $owner, string $repo, int $ttl, ?string $ghtoken): ?array
{
    $url = "https://api.github.com/repos/$owner/$repo/languages";
    return gh_cached_get($url, $ttl, $ghtoken);
}
function gh_top_language(string $owner, string $repo, int $ttl, ?string $ghtoken): ?string
{
    $langs = gh_repo_languages($owner, $repo, $ttl, $ghtoken);
    if (!$langs || !is_array($langs) || empty($langs)) return null;
    arsort($langs);
    foreach ($langs as $name => $bytes) {
        return $name;
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
    function formatBytesShort(int $bytes): string {
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

// ------------------- Functions of the metrics -------------------
// ------------------- Top Language Count Function -------------------
if (!function_exists('gh_top_language_count')) {
    function gh_top_language_count(string $owner, string $repo, int $ttl = 300, ?string $ghtoken = null, int $limit = 1): array
    {
        $url = "https://api.github.com/repos/$owner/$repo/languages";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
        if ($ghtoken) $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
        $context = stream_context_create($opts);
        $json = @file_get_contents($url, false, $context);
        if ($json === false) return [['-', '0%']];
        $data = json_decode($json, true);
        if (!$data || count($data) === 0) return [['-', '0%']];
        $total = array_sum($data);
        arsort($data);
        $out = [];
        $i = 0;
        foreach ($data as $lang => $bytes) {
            $percent = $total > 0 ? round(($bytes / $total) * 100) : 0;
            $out[] = [$lang, $percent . '%'];
            if (++$i >= $limit) break;
        }
        return $out;
    }
}
// ------------------- Stars Function (All) -------------------
if (!function_exists('gh_user_stars_all')) {
    function gh_user_stars_all(string $user, int $ttl = 3600, ?string $ghtoken = null): int
    {
        $cacheKey = "stars_all_{$user}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $sum = 0;
        $page = 1;
        do {
            $url = "https://api.github.com/users/{$user}/repos?per_page=100&page={$page}";
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
            if ($ghtoken) {
                $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
            }
            $context = stream_context_create($opts);
            $json = @file_get_contents($url, false, $context);
            if (!$json) {
                break;
            }
            $repos = json_decode($json, true);
            if (!is_array($repos) || count($repos) === 0) {
                break;
            }
            foreach ($repos as $r) {
                $sum += (int)($r['stargazers_count'] ?? 0);
            }
            $page++;
        } while (count($repos) === 100);
        gh_cache_set($cacheKey, $sum);
        return $sum;
    }
}
// ------------------- Downloads function (incl. limit) -------------------
if (!function_exists('gh_repo_downloads')) {
    function gh_repo_downloads(string $owner, string $repo, int $ttl = 300, ?string $ghtoken = null, int $limit = 0): int
    {
        $cacheKey = "downloads_{$owner}_{$repo}_limit{$limit}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) return (int)$cached;

        $url = "https://api.github.com/repos/$owner/$repo/releases";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
        if ($ghtoken) $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
        $context = stream_context_create($opts);
        $json = @file_get_contents($url, false, $context);
        if (!$json) return 0;
        $releases = json_decode($json, true);
        if (!is_array($releases)) return 0;
        $sum = 0;
        $count = 0;
        foreach ($releases as $rel) {
            if ($limit > 0 && $count >= $limit) break;
            if (!empty($rel['assets'])) {
                foreach ($rel['assets'] as $asset) {
                    $name = $asset['name'] ?? '';
                    if (stripos($name, 'source') !== false || preg_match('/\.zip$/i', $name) && stripos($name, 'source') !== false) continue;
                    $sum += (int)($asset['download_count'] ?? 0);
                }
            }
            $count++;
        }
        gh_cache_set($cacheKey, $sum);
        return $sum;
    }
}
if (!function_exists('gh_repo_downloads_latest')) {
    function gh_repo_downloads_latest(string $owner, string $repo, int $ttl = 300, ?string $ghtoken = null): int
    {
        return gh_repo_downloads($owner, $repo, $ttl, $ghtoken, 1);
    }
}
if (!function_exists('gh_user_downloads_all')) {
    function gh_user_downloads_all(string $user, int $ttl = 3600, ?string $ghtoken = null, int $releasesLimit = 0): int
    {
        $cacheKey = "downloads_all_{$user}_limit{$releasesLimit}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) return (int)$cached;
        $sum = 0;
        $page = 1;
        do {
            $url = "https://api.github.com/users/{$user}/repos?per_page=100&page={$page}";
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
            if ($ghtoken) $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
            $context = stream_context_create($opts);
            $json = @file_get_contents($url, false, $context);
            if (!$json) break;
            $repos = json_decode($json, true);
            if (!is_array($repos) || count($repos) === 0) break;

            foreach ($repos as $r) {
                $ownerR = $r['owner']['login'];
                $repoR  = $r['name'];
                $sum += gh_repo_downloads($ownerR, $repoR, $ttl, $ghtoken, $releasesLimit);
            }
            $page++;
        } while (count($repos) === 100);
        gh_cache_set($cacheKey, $sum);
        return $sum;
    }
}
// ------------------- Branches function -------------------
if (!function_exists('gh_repo_branches')) {
    function gh_repo_branches(string $owner, string $repo, int $ttl = 300, ?string $ghtoken = null): int
    {
        $cacheKey = "branches_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) return (int)$cached;
        $url = "https://api.github.com/repos/$owner/$repo/branches?per_page=100";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
        if ($ghtoken) $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
        $context = stream_context_create($opts);
        $json = @file_get_contents($url, false, $context);
        if (!$json) return 0;
        $branches = json_decode($json, true);
        if (!is_array($branches)) return 0;
        $count = count($branches);
        gh_cache_set($cacheKey, $count);
        return $count;
    }
}
if (!function_exists('gh_user_branches_all')) {
    function gh_user_branches_all(string $user, int $ttl = 3600, ?string $ghtoken = null): int
    {
        $cacheKey = "branches_all_{$user}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) return (int)$cached;
        $sum = 0;
        $page = 1;
        do {
            $url = "https://api.github.com/users/{$user}/repos?per_page=100&page={$page}";
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
            if ($ghtoken) $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
            $context = stream_context_create($opts);
            $json = @file_get_contents($url, false, $context);
            if (!$json) break;
            $repos = json_decode($json, true);
            if (!is_array($repos) || count($repos) === 0) break;
            foreach ($repos as $r) {
                $ownerR = $r['owner']['login'];
                $repoR  = $r['name'];
                $sum += gh_repo_branches($ownerR, $repoR, $ttl, $ghtoken);
            }
            $page++;
        } while (count($repos) === 100);
        gh_cache_set($cacheKey, $sum);
        return $sum;
    }
}
// ------------------- Size function (All) -------------------
if (!function_exists('gh_user_size_all')) {
    function gh_user_size_all(string $user, int $ttl = 3600, ?string $ghtoken = null): int
    {
        $cacheKey = "size_all_{$user}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $sum = 0;
        $page = 1;
        do {
            $url = "https://api.github.com/users/{$user}/repos?per_page=100&page={$page}";
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
            if ($ghtoken) {
                $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
            }
            $context = stream_context_create($opts);
            $json = @file_get_contents($url, false, $context);
            if (!$json) {
                break;
            }
            $repos = json_decode($json, true);
            if (!is_array($repos) || count($repos) === 0) {
                break;
            }
            foreach ($repos as $r) {
                $sum += (int)($r['size'] ?? 0);
            }
            $page++;
        } while (count($repos) === 100);
        gh_cache_set($cacheKey, $sum);
        return $sum;
    }
}
// ------------------- Forks function (All) -------------------
if (!function_exists('gh_user_forks_all')) {
    function gh_user_forks_all(string $user, int $ttl = 3600, ?string $ghtoken = null): int
    {
        $cacheKey = "forks_all_{$user}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $sum = 0;
        $page = 1;
        do {
            $url = "https://api.github.com/users/{$user}/repos?per_page=100&page={$page}";
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
            if ($ghtoken) {
                $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
            }
            $context = stream_context_create($opts);
            $json = @file_get_contents($url, false, $context);
            if (!$json) {
                break;
            }
            $repos = json_decode($json, true);
            if (!is_array($repos) || count($repos) === 0) {
                break;
            }
            foreach ($repos as $r) {
                $sum += (int)($r['forks_count'] ?? 0);
            }
            $page++;
        } while (count($repos) === 100);
        gh_cache_set($cacheKey, $sum);
        return $sum;
    }
}
// ------------------- Issues function (All) -------------------
if (!function_exists('gh_user_issues_all')) {
    function gh_user_issues_all(string $user, int $ttl = 3600, ?string $ghtoken = null): int
    {
        $cacheKey = "issues_all_{$user}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $sum = 0;
        $page = 1;
        do {
            $url = "https://api.github.com/users/{$user}/repos?per_page=100&page={$page}";
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
            if ($ghtoken) {
                $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
            }
            $context = stream_context_create($opts);
            $json = @file_get_contents($url, false, $context);
            if (!$json) {
                break;
            }
            $repos = json_decode($json, true);
            if (!is_array($repos) || count($repos) === 0) {
                break;
            }
            foreach ($repos as $r) {
                $sum += (int)($r['open_issues_count'] ?? 0);
            }
            $page++;
        } while (count($repos) === 100);
        gh_cache_set($cacheKey, $sum);
        return $sum;
    }
}
// ------------------- Watchers fuction (All) -------------------
if (!function_exists('gh_user_watchers_all')) {
    function gh_user_watchers_all(string $user, int $ttl = 3600, ?string $ghtoken = null): int
    {
        $cacheKey = "watchers_all_{$user}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $sum = 0;
        $page = 1;
        do {
            $url = "https://api.github.com/users/{$user}/repos?per_page=100&page={$page}";
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
            if ($ghtoken) {
                $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
            }
            $context = stream_context_create($opts);
            $json = @file_get_contents($url, false, $context);

            if (!$json) {
                break;
            }
            $repos = json_decode($json, true);
            if (!is_array($repos) || count($repos) === 0) {
                break;
            }
            foreach ($repos as $r) {
                // Watchers and Subscribers are the same thing
                $sum += (int)($r['subscribers_count'] ?? $r['watchers_count'] ?? 0);
            }
            $page++;
        } while (count($repos) === 100);
        gh_cache_set($cacheKey, $sum);
        return $sum;
    }
}
// ------------------- Repositories Counter Function -------------------
if (!function_exists('gh_user_repos_count')) {
    function gh_user_repos_count(string $user, int $ttl = 3600, ?string $ghtoken = null): int
    {
        $cacheKey = "repos_count_{$user}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $count = 0;
        $page = 1;
        do {
            $url = "https://api.github.com/users/{$user}/repos?per_page=100&page={$page}";
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
            if ($ghtoken) {
                $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
            }
            $context = stream_context_create($opts);
            $json = @file_get_contents($url, false, $context);
            if (!$json) {
                break;
            }
            $repos = json_decode($json, true);
            if (!is_array($repos) || count($repos) === 0) {
                break;
            }
            $count += count($repos);
            $page++;
        } while (count($repos) === 100);
        gh_cache_set($cacheKey, $count);
        return $count;
    }
}
// ------------------- Top Languages function (All) -------------------
if (!function_exists('gh_user_top_languages_all')) {
    function gh_user_top_languages_all(string $user, int $ttl = 3600, ?string $ghtoken = null, int $limit = 1): array
    {
        $cacheKey = "top_languages_all_{$user}_limit{$limit}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return $cached;
        }
        $languageCounts = [];
        $page = 1;
        do {
            $url = "https://api.github.com/users/{$user}/repos?per_page=100&page={$page}";
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
            if ($ghtoken) {
                $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
            }
            $context = stream_context_create($opts);
            $json = @file_get_contents($url, false, $context);
            if (!$json) {
                break;
            }
            $repos = json_decode($json, true);
            if (!is_array($repos) || count($repos) === 0) {
                break;
            }
            foreach ($repos as $repo) {
                $repoOwner = $repo['owner']['login'];
                $repoName = $repo['name'];
                $repoLanguages = gh_top_language_count($repoOwner, $repoName, $ttl, $ghtoken, 10);
                foreach ($repoLanguages as [$lang, $bytes]) {
                    if ($lang === '-') continue;
                    $languageCounts[$lang] = ($languageCounts[$lang] ?? 0) + (int)str_replace('%', '', $bytes);
                }
            }
            $page++;
        } while (count($repos) === 100);
        if (empty($languageCounts)) return [['-', '0%']];
        arsort($languageCounts);
        $total = array_sum($languageCounts);
        $out = [];
        $i = 0;
        foreach ($languageCounts as $lang => $bytes) {
            $percent = $total > 0 ? round(($bytes / $total) * 100) : 0;
            $out[] = [$lang, $percent . '%'];
            if (++$i >= $limit) {
                break;
            }
        }
        gh_cache_set($cacheKey, $out);
        return $out;
    }
}
// ------------------- Pull Requests Function -------------------
if (!function_exists('gh_repo_pull_requests')) {
    function gh_repo_pull_requests(string $owner, string $repo, int $ttl = 300, ?string $ghtoken = null): int
    {
        $cacheKey = "prs_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $url = "https://api.github.com/repos/{$owner}/{$repo}/pulls?state=all";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
        if ($ghtoken) {
            $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
        }
        $context = stream_context_create($opts);
        $json = @file_get_contents($url, false, $context);
        if (!$json) {
            return 0;
        }
        $prs = json_decode($json, true);
        $count = is_array($prs) ? count($prs) : 0;
        gh_cache_set($cacheKey, $count);
        return $count;
    }
}
// ------------------- Merged Pull Requests Function -------------------
if (!function_exists('gh_repo_merged_pull_requests')) {
    function gh_repo_merged_pull_requests(string $owner, string $repo, int $ttl = 300, ?string $ghtoken = null): int
    {
        $cacheKey = "prs_merged_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $count = 0;
        $page = 1;
        do {
            $url = "https://api.github.com/repos/{$owner}/{$repo}/pulls?state=closed&per_page=100&page={$page}";
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
            if ($ghtoken) {
                $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
            }
            $context = stream_context_create($opts);
            $json = @file_get_contents($url, false, $context);
            if (!$json) {
                break;
            }
            $pulls = json_decode($json, true);
            if (!is_array($pulls) || count($pulls) === 0) {
                break;
            }
            foreach ($pulls as $pull) {
                if (isset($pull['merged_at']) && $pull['merged_at'] !== null) {
                    $count++;
                }
            }
            $page++;
        } while (count($pulls) === 100);
        gh_cache_set($cacheKey, $count);
        return $count;
    }
}
// ------------------- Merged Pull Requests Function (All) -------------------
if (!function_exists('gh_user_merged_pull_requests_all')) {
    function gh_user_merged_pull_requests_all(string $user, int $ttl = 3600, ?string $ghtoken = null): int
    {
        $cacheKey = "prs_merged_all_{$user}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $sum = 0;
        $page = 1;
        do {
            $url = "https://api.github.com/users/{$user}/repos?per_page=100&page={$page}";
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
            if ($ghtoken) {
                $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
            }
            $context = stream_context_create($opts);
            $json = @file_get_contents($url, false, $context);
            if (!$json) {
                break;
            }
            $repos = json_decode($json, true);
            if (!is_array($repos) || count($repos) === 0) {
                break;
            }
            foreach ($repos as $r) {
                $sum += gh_repo_merged_pull_requests($r['owner']['login'], $r['name'], $ttl, $ghtoken);
            }
            $page++;
        } while (count($repos) === 100);
        gh_cache_set($cacheKey, $sum);
        return $sum;
    }
}
// ------------------- Subscribers Function (All) -------------------
if (!function_exists('gh_user_subscribers_all')) {
    function gh_user_subscribers_all(string $user, int $ttl = 3600, ?string $ghtoken = null): int
    {
        $cacheKey = "subscribers_all_{$user}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $sum = 0;
        $page = 1;
        do {
            $url = "https://api.github.com/users/{$user}/repos?per_page=100&page={$page}";
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
            if ($ghtoken) {
                $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
            }
            $context = stream_context_create($opts);
            $json = @file_get_contents($url, false, $context);
            if (!$json) {
                break;
            }
            $repos = json_decode($json, true);
            if (!is_array($repos) || count($repos) === 0) {
                break;
            }
            foreach ($repos as $r) {
                // Zusätzlicher API-Aufruf, um die vollständigen Repo-Daten zu erhalten
                $repoUrl = "https://api.github.com/repos/{$r['owner']['login']}/{$r['name']}";
                $repoJson = @file_get_contents($repoUrl, false, $context);
                if ($repoJson) {
                    $repoInfo = json_decode($repoJson, true);
                    $sum += (int)($repoInfo['subscribers_count'] ?? 0);
                }
            }
            $page++;
        } while (count($repos) === 100);
        gh_cache_set($cacheKey, $sum);
        return $sum;
    }
}
// ------------------- Success Rate Function -------------------
if (!function_exists('gh_repo_success_rate')) {
    function gh_repo_success_rate(string $owner, string $repo, int $ttl = 300, ?string $ghtoken = null): string
    {
        $cacheKey = "success_rate_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return $cached;
        }
        $merged_prs = gh_repo_merged_pull_requests($owner, $repo, $ttl, $ghtoken);
        $total_prs = gh_repo_pull_requests($owner, $repo, $ttl, $ghtoken);

        if ($total_prs === 0) {
            $rate = 'N/A';
        } else {
            $rate = round(($merged_prs / $total_prs) * 100) . '%';
        }
        gh_cache_set($cacheKey, $rate);
        return $rate;
    }
}
// ------------------- Success Rate Function (All) -------------------
if (!function_exists('gh_user_success_rate_all')) {
    function gh_user_success_rate_all(string $user, int $ttl = 3600, ?string $ghtoken = null): string
    {
        $cacheKey = "success_rate_all_{$user}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return $cached;
        }
        $merged_prs_all = gh_user_merged_pull_requests_all($user, $ttl, $ghtoken);
        $total_prs_all = gh_user_pull_requests_all($user, $ttl, $ghtoken);

        if ($total_prs_all === 0) {
            $rate = 'N/A';
        } else {
            $rate = round(($merged_prs_all / $total_prs_all) * 100) . '%';
        }
        gh_cache_set($cacheKey, $rate);
        return $rate;
    }
}
// ------------------- Files Function -------------------
if (!function_exists('gh_repo_files')) {
    function gh_repo_files(string $owner, string $repo, int $ttl = 300, ?string $ghtoken = null): int
    {
        $cacheKey = "files_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $repoInfo = gh_repo_info($owner, $repo, $ttl, $ghtoken);
        $default_branch = $repoInfo['default_branch'] ?? 'main';

        $url = "https://api.github.com/repos/{$owner}/{$repo}/git/trees/{$default_branch}?recursive=1";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
        if ($ghtoken) {
            $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
        }
        $context = stream_context_create($opts);
        $json = @file_get_contents($url, false, $context);
        if (!$json) {
            return 0;
        }
        $tree = json_decode($json, true);
        if (!is_array($tree) || !isset($tree['tree'])) {
            return 0;
        }
        $count = count(array_filter($tree['tree'], fn($item) => $item['type'] === 'blob'));
        gh_cache_set($cacheKey, $count);
        return $count;
    }
}
// ------------------- Files Function (All) -------------------
if (!function_exists('gh_user_files_all')) {
    function gh_user_files_all(string $user, int $ttl = 3600, ?string $ghtoken = null): int
    {
        $cacheKey = "files_all_{$user}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $sum = 0;
        $page = 1;
        do {
            $url = "https://api.github.com/users/{$user}/repos?per_page=100&page={$page}";
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
            if ($ghtoken) {
                $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
            }
            $context = stream_context_create($opts);
            $json = @file_get_contents($url, false, $context);
            if (!$json) {
                break;
            }
            $repos = json_decode($json, true);
            if (!is_array($repos) || count($repos) === 0) {
                break;
            }
            foreach ($repos as $r) {
                $sum += gh_repo_files($r['owner']['login'], $r['name'], $ttl, $ghtoken);
            }
            $page++;
        } while (count($repos) === 100);
        gh_cache_set($cacheKey, $sum);
        return $sum;
    }
}
// ------------------- Tags Function -------------------
if (!function_exists('gh_repo_tags')) {
    function gh_repo_tags(string $owner, string $repo, int $ttl = 300, ?string $ghtoken = null): int
    {
        $cacheKey = "tags_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $url = "https://api.github.com/repos/{$owner}/{$repo}/tags?per_page=100";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
        if ($ghtoken) {
            $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
        }
        $context = stream_context_create($opts);
        $json = @file_get_contents($url, false, $context);
        if (!$json) {
            return 0;
        }
        $tags = json_decode($json, true);
        $count = is_array($tags) ? count($tags) : 0;
        gh_cache_set($cacheKey, $count);
        return $count;
    }
}
// ------------------- Tags Function (All) -------------------
if (!function_exists('gh_user_tags_all')) {
    function gh_user_tags_all(string $user, int $ttl = 3600, ?string $ghtoken = null): int
    {
        $cacheKey = "tags_all_{$user}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $sum = 0;
        $page = 1;
        do {
            $url = "https://api.github.com/users/{$user}/repos?per_page=100&page={$page}";
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
            if ($ghtoken) {
                $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
            }
            $context = stream_context_create($opts);
            $json = @file_get_contents($url, false, $context);
            if (!$json) {
                break;
            }
            $repos = json_decode($json, true);
            if (!is_array($repos) || count($repos) === 0) {
                break;
            }
            foreach ($repos as $r) {
                $sum += gh_repo_tags($r['owner']['login'], $r['name'], $ttl, $ghtoken);
            }
            $page++;
        } while (count($repos) === 100);
        gh_cache_set($cacheKey, $sum);
        return $sum;
    }
}
// ------------------- Follower Count Function -------------------
if (!function_exists('gh_user_followers')) {
    function gh_user_followers(string $user, int $ttl = 3600, ?string $ghtoken = null): int
    {
        $cacheKey = "followers_{$user}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }

        $url = "https://api.github.com/users/{$user}";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
        if ($ghtoken) {
            $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
        }
        $context = stream_context_create($opts);

        $json = @file_get_contents($url, false, $context);
        if (!$json) {
            return 0;
        }

        $userInfo = json_decode($json, true);
        $count = $userInfo['followers'] ?? 0;
        gh_cache_set($cacheKey, $count);
        return $count;
    }
}
// ------------------- Following Count Function -------------------
if (!function_exists('gh_user_following')) {
    function gh_user_following(string $user, int $ttl = 3600, ?string $ghtoken = null): int
    {
        $cacheKey = "following_{$user}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }

        $url = "https://api.github.com/users/{$user}";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
        if ($ghtoken) {
            $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
        }
        $context = stream_context_create($opts);

        $json = @file_get_contents($url, false, $context);
        if (!$json) {
            return 0;
        }

        $userInfo = json_decode($json, true);
        $count = $userInfo['following'] ?? 0;
        gh_cache_set($cacheKey, $count);
        return $count;
    }
}
// ------------------- Projects Function -------------------
if (!function_exists('gh_repo_projects')) {
    function gh_repo_projects(string $owner, string $repo, int $ttl = 300, ?string $ghtoken = null): int
    {
        $cacheKey = "projects_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $url = "https://api.github.com/repos/{$owner}/{$repo}/projects?per_page=100";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n", "Accept" => "application/vnd.github.v3+json"]];
        if ($ghtoken) {
            $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
        }
        $context = stream_context_create($opts);
        $json = @file_get_contents($url, false, $context);
        if (!$json) {
            return 0;
        }
        $projects = json_decode($json, true);
        $count = is_array($projects) ? count($projects) : 0;
        gh_cache_set($cacheKey, $count);
        return $count;
    }
}
// ------------------- Projekte Function (All) -------------------
if (!function_exists('gh_user_projects_all')) {
    function gh_user_projects_all(string $user, int $ttl = 3600, ?string $ghtoken = null): int
    {
        $cacheKey = "projects_all_{$user}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $sum = 0;
        $page = 1;
        do {
            $url = "https://api.github.com/users/{$user}/repos?per_page=100&page={$page}";
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
            if ($ghtoken) {
                $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
            }
            $context = stream_context_create($opts);
            $json = @file_get_contents($url, false, $context);
            if (!$json) {
                break;
            }
            $repos = json_decode($json, true);
            if (!is_array($repos) || count($repos) === 0) {
                break;
            }
            foreach ($repos as $r) {
                $sum += gh_repo_projects($r['owner']['login'], $r['name'], $ttl, $ghtoken);
            }
            $page++;
        } while (count($repos) === 100);
        gh_cache_set($cacheKey, $sum);
        return $sum;
    }
}
// ------------------- Releases Function -------------------
if (!function_exists('gh_repo_releases')) {
    function gh_repo_releases(string $owner, string $repo, int $ttl = 300, ?string $ghtoken = null): int
    {
        $cacheKey = "releases_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $url = "https://api.github.com/repos/{$owner}/{$repo}/releases?per_page=100";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
        if ($ghtoken) {
            $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
        }
        $context = stream_context_create($opts);
        $json = @file_get_contents($url, false, $context);
        if (!$json) {
            return 0;
        }
        $releases = json_decode($json, true);
        $count = is_array($releases) ? count($releases) : 0;
        gh_cache_set($cacheKey, $count);
        return $count;
    }
}
// ------------------- Releases Function (All) -------------------
if (!function_exists('gh_user_releases_all')) {
    function gh_user_releases_all(string $user, int $ttl = 3600, ?string $ghtoken = null): int
    {
        $cacheKey = "releases_all_{$user}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $sum = 0;
        $page = 1;
        do {
            $url = "https://api.github.com/users/{$user}/repos?per_page=100&page={$page}";
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
            if ($ghtoken) {
                $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
            }
            $context = stream_context_create($opts);
            $json = @file_get_contents($url, false, $context);
            if (!$json) {
                break;
            }
            $repos = json_decode($json, true);
            if (!is_array($repos) || count($repos) === 0) {
                break;
            }
            foreach ($repos as $r) {
                $sum += gh_repo_releases($r['owner']['login'], $r['name'], $ttl, $ghtoken);
            }
            $page++;
        } while (count($repos) === 100);
        gh_cache_set($cacheKey, $sum);
        return $sum;
    }
}
// ------------------- Gists Info Function (Core) -------------------
if (!function_exists('gh_user_gists_info')) {
    function gh_user_gists_info(string $user, int $ttl = 3600, ?string $ghtoken = null): array
    {
        $cacheKey = "gists_info_{$user}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return $cached;
        }
        $gists = [];
        $total_size = 0;
        $latest_gist_date = null;
        $page = 1;

        do {
            $url = "https://api.github.com/users/{$user}/gists?per_page=100&page={$page}";
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
            if ($ghtoken) {
                $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
            }
            $context = stream_context_create($opts);
            $json = @file_get_contents($url, false, $context);

            if (!$json) {
                break;
            }
            $userGists = json_decode($json, true);
            if (!is_array($userGists) || count($userGists) === 0) {
                break;
            }
            foreach ($userGists as $gist) {
                $gist_created_at = strtotime($gist['created_at']);
                $gistData = [
                    'id' => $gist['id'],
                    'description' => $gist['description'],
                    'created_at' => $gist['created_at'],
                    'updated_at' => $gist['updated_at'],
                    'html_url' => $gist['html_url'],
                    'size' => 0,
                    'files' => [],
                ];
                foreach ($gist['files'] as $filename => $file) {
                    $gistData['files'][] = [
                        'name' => $filename,
                        'size' => $file['size'] ?? 0,
                    ];
                    $gistData['size'] += $file['size'] ?? 0;
                }
                $total_size += $gistData['size'];
                if ($latest_gist_date === null || $gist_created_at > strtotime($latest_gist_date)) {
                    $latest_gist_date = $gist['created_at'];
                }
                $gists[] = $gistData;
            }
            $page++;
        } while (count($userGists) === 100);
        usort($gists, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
        $result = [
            'count' => count($gists),
            'total_size' => $total_size,
            'latest_gist_date' => $latest_gist_date,
            'gists' => $gists,
        ];
        gh_cache_set($cacheKey, $result);
        return $result;
    }
}
// ------------------- Gist Forks Counter Function (Robust) -------------------
if (!function_exists('gh_gist_forks_count')) {
    function gh_gist_forks_count(string $gistId, int $ttl = 300, ?string $ghtoken = null): int
    {
        $cacheKey = "gist_forks_{$gistId}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $url = "https://api.github.com/gists/{$gistId}/forks";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
        if ($ghtoken) {
            $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
        }
        $context = stream_context_create($opts);
        $json = @file_get_contents($url, false, $context);
        if (!$json) {
            return 0;
        }
        $forks = json_decode($json, true);
        $count = is_array($forks) ? count($forks) : 0;
        gh_cache_set($cacheKey, $count);
        return $count;
    }
}
// ------------------- Lines Statistics Function -------------------
if (!function_exists('gh_repo_lines')) {
    function gh_repo_lines(string $owner, string $repo, int $ttl = 300, ?string $ghtoken = null): array
    {
        $cacheKey = "lines_stat_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return $cached;
        }
        $total_added = 0;
        $total_deleted = 0;
        $page = 1;
        do {
            $url = "https://api.github.com/repos/{$owner}/{$repo}/commits?per_page=100&page={$page}";
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
            if ($ghtoken) {
                $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
            }
            $context = stream_context_create($opts);
            $json = @file_get_contents($url, false, $context);
            if (!$json) {
                break;
            }
            $commits = json_decode($json, true);
            if (!is_array($commits) || count($commits) === 0) {
                break;
            }
            foreach ($commits as $commit) {
                $commitDetailsUrl = $commit['url'];
                $commitDetailsJson = @file_get_contents($commitDetailsUrl, false, $context);
                if ($commitDetailsJson) {
                    $details = json_decode($commitDetailsJson, true);
                    $total_added += (int)($details['stats']['additions'] ?? 0);
                    $total_deleted += (int)($details['stats']['deletions'] ?? 0);
                }
            }
            $page++;
        } while (count($commits) === 100);
        $stats = [
            'added'   => $total_added,
            'deleted' => $total_deleted,
            'total'   => $total_added + $total_deleted
        ];
        gh_cache_set($cacheKey, $stats);
        return $stats;
    }
}
// ------------------- Milestones Function -------------------
if (!function_exists('gh_repo_milestones')) {
    function gh_repo_milestones(string $owner, string $repo, int $ttl = 300, ?string $ghtoken = null): array
    {
        $cacheKey = "milestones_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return $cached;
        }
        $open_count = 0;
        $closed_count = 0;
        $url_open = "https://api.github.com/repos/{$owner}/{$repo}/milestones?state=open&per_page=100";
        $url_closed = "https://api.github.com/repos/{$owner}/{$repo}/milestones?state=closed&per_page=100";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
        if ($ghtoken) {
            $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
        }
        $context = stream_context_create($opts);
        $json_open = @file_get_contents($url_open, false, $context);
        if ($json_open) {
            $open_milestones = json_decode($json_open, true);
            $open_count = is_array($open_milestones) ? count($open_milestones) : 0;
        }
        $json_closed = @file_get_contents($url_closed, false, $context);
        if ($json_closed) {
            $closed_milestones = json_decode($json_closed, true);
            $closed_count = is_array($closed_milestones) ? count($closed_milestones) : 0;
        }
        $stats = [
            'open'   => $open_count,
            'closed' => $closed_count,
            'total'  => $open_count + $closed_count
        ];
        gh_cache_set($cacheKey, $stats);
        return $stats;
    }
}
// ------------------- Milestones Function (Single Repo) -------------------
if (!function_exists('gh_repo_milestones_robust')) {
    function gh_repo_milestones_robust(string $owner, string $repo, int $ttl = 300, ?string $ghtoken = null, string $state = 'all'): int
    {
        $cacheKey = "milestones_{$owner}_{$repo}_{$state}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $url = "https://api.github.com/repos/{$owner}/{$repo}/milestones?state={$state}&per_page=100";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
        if ($ghtoken) {
            $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
        }
        $context = stream_context_create($opts);
        $count = 0;
        $json = @file_get_contents($url, false, $context);
        if ($json) {
            $milestones = json_decode($json, true);
            $count = is_array($milestones) ? count($milestones) : 0;
        }
        gh_cache_set($cacheKey, $count);
        return $count;
    }
}
// ------------------- Milestones Function (All Repos) -------------------
if (!function_exists('gh_user_milestones_all')) {
    function gh_user_milestones_all(string $user, int $ttl = 3600, ?string $ghtoken = null, string $state = 'all'): int
    {
        $cacheKey = "milestones_all_{$user}_{$state}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $sum = 0;
        $page = 1;
        do {
            $url = "https://api.github.com/users/{$user}/repos?per_page=100&page={$page}";
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
            if ($ghtoken) {
                $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
            }
            $context = stream_context_create($opts);
            $json = @file_get_contents($url, false, $context);
            if (!$json) {
                break;
            }
            $repos = json_decode($json, true);
            if (!is_array($repos) || count($repos) === 0) {
                break;
            }
            foreach ($repos as $r) {
                $sum += gh_repo_milestones_robust($r['owner']['login'], $r['name'], $ttl, $ghtoken, $state);
            }
            $page++;
        } while (count($repos) === 100);
        gh_cache_set($cacheKey, $sum);
        return $sum;
    }
}
// ------------------- Version comparison function -------------------
if (!function_exists('gh_repo_compare_version')) {
    function gh_repo_compare_version(string $owner, string $repo, string $current_version, int $ttl = 300, ?string $ghtoken = null): array
    {
        $cacheKey = "version_check_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return $cached;
        }
        $url = "https://api.github.com/repos/{$owner}/{$repo}/releases/latest";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
        if ($ghtoken) {
            $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
        }
        $context = stream_context_create($opts);
        $json = @file_get_contents($url, false, $context);
        if (!$json) {
            return ['status' => 'error', 'message' => 'API Error or Release not found'];
        }
        $latest_release = json_decode($json, true);
        if (!is_array($latest_release) || !isset($latest_release['tag_name'])) {
            return ['status' => 'error', 'message' => 'Invalid API Response'];
        }
        $latest_version = $latest_release['tag_name'];
        // Normalize version strings to handle 'v' prefixes
        $latest_version_clean = ltrim($latest_version, 'vV');
        $current_version_clean = ltrim($current_version, 'vV');
        // Compare versions
        if (version_compare($current_version_clean, $latest_version_clean, '>=')) {
            $result = ['status' => 'ok', 'message' => 'OK'];
        } else {
            $result = ['status' => 'new_release', 'latest_version' => $latest_version];
        }
        gh_cache_set($cacheKey, $result);
        return $result;
    }
}

// ------------------- Neuer Ansatz der Pushes und Commits ----------------------

if (!function_exists('gh_push_info')) {
    function gh_push_info(string $owner, ?string $repo = null, int $ttl = 300, ?string $ghtoken = null): array
    {
        $cacheKey = "push_info_" . ($repo ? "repo_{$owner}_{$repo}" : "user_{$owner}");
        $cached = gh_cache_get($cacheKey, $ttl);

        $info = [
            'latest' => [
                'date' => 'N/A',
                'datetime' => 'N/A',
                'lines_added' => 0,
                'lines_deleted' => 0,
            ],
        ];

        $opts = ["http" => ["header" => "User-Agent: MiniBadges\r\n"]];
        if ($ghtoken) {
            $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
        }
        $context = stream_context_create($opts);

        if ($repo) {
            $repoUrl = "https://api.github.com/repos/{$owner}/{$repo}";
            $repoJson = @file_get_contents($repoUrl, false, $context);
            $repoData = $repoJson ? json_decode($repoJson, true) : null;
            $latestPushTimestamp = isset($repoData['pushed_at']) ? strtotime($repoData['pushed_at']) : 0;

            $cachedPushTimestamp = ($cached !== null && isset($cached['latest']['datetime'])) ? strtotime($cached['latest']['datetime']) : 0;

            if ($latestPushTimestamp > $cachedPushTimestamp || $cached === null) {
                // Finde den neuesten Commit über alle Branches hinweg
                $branchesUrl = "https://api.github.com/repos/{$owner}/{$repo}/branches";
                $branchesJson = @file_get_contents($branchesUrl, false, $context);
                $branches = $branchesJson ? json_decode($branchesJson, true) : [];

                $latestCommit = null;
                $latestCommitTime = 0;

                foreach ($branches as $branch) {
                    $commitUrl = "https://api.github.com/repos/{$owner}/{$repo}/commits/{$branch['name']}";
                    $commitJson = @file_get_contents($commitUrl, false, $context);
                    if ($commitJson) {
                        $commitData = json_decode($commitJson, true);
                        $commitTime = strtotime($commitData['commit']['committer']['date']);
                        if ($commitTime > $latestCommitTime) {
                            $latestCommitTime = $commitTime;
                            $latestCommit = $commitData;
                        }
                    }
                }

                if ($latestCommit) {
                    $info['latest']['date'] = date('Y-m-d', $latestCommitTime);
                    $info['latest']['datetime'] = date('Y-m-d H:i:s', $latestCommitTime);

                    $commitUrl = $latestCommit['url'];
                    $commitJson = @file_get_contents($commitUrl, false, $context);
                    if ($commitJson) {
                        $commitDetails = json_decode($commitJson, true);
                        $info['latest']['lines_added'] = $commitDetails['stats']['additions'] ?? 0;
                        $info['latest']['lines_deleted'] = $commitDetails['stats']['deletions'] ?? 0;
                    }
                }

                gh_cache_set($cacheKey, $info);
                return $info;
            } else {
                return $cached;
            }
        } else {
            // ... (Logik für alle Benutzer-Repos, unverändert) ...
            $repos = gh_user_public_repos($owner, $ttl, $ghtoken);
            $latestPush = null;
            $latestPushTime = 0;
            foreach ($repos as $repoItem) {
                if (isset($repoItem['pushed_at'])) {
                    $pushTime = strtotime($repoItem['pushed_at']);
                    if ($pushTime > $latestPushTime) {
                        $latestPushTime = $pushTime;
                        $latestPush = $repoItem;
                    }
                }
            }
            if ($latestPush) {
                $info['latest']['date'] = date('Y-m-d', $latestPushTime);
                $info['latest']['datetime'] = date('Y-m-d H:i:s', $latestPushTime);
            }
            gh_cache_set($cacheKey, $info);
            return $info;
        }
    }
}
if (!function_exists('gh_commit_info')) {
    function gh_commit_info(string $owner, ?string $repo = null, int $ttl = 300, ?string $ghtoken = null): array
    {
        $cacheKey = "commit_info_" . ($repo ? "repo_{$owner}_{$repo}" : "user_{$owner}");
        $cached = gh_cache_get($cacheKey, $ttl);

        $info = [
            'latest' => [
                'date' => 'N/A',
                'datetime' => 'N/A',
                'lines_added' => 0,
                'lines_deleted' => 0,
            ],
        ];

        $opts = ["http" => ["header" => "User-Agent: MiniBadges\r\n"]];
        if ($ghtoken) {
            $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
        }
        $context = stream_context_create($opts);

        if ($repo) {
            // Finde den neuesten Commit über alle Branches hinweg
            $branchesUrl = "https://api.github.com/repos/{$owner}/{$repo}/branches";
            $branchesJson = @file_get_contents($branchesUrl, false, $context);
            $branches = $branchesJson ? json_decode($branchesJson, true) : [];

            $latestCommit = null;
            $latestCommitTime = 0;

            foreach ($branches as $branch) {
                $commitUrl = "https://api.github.com/repos/{$owner}/{$repo}/commits/{$branch['name']}";
                $commitJson = @file_get_contents($commitUrl, false, $context);
                if ($commitJson) {
                    $commitData = json_decode($commitJson, true);
                    $commitTime = strtotime($commitData['commit']['committer']['date']);
                    if ($commitTime > $latestCommitTime) {
                        $latestCommitTime = $commitTime;
                        $latestCommit = $commitData;
                    }
                }
            }

            $cachedCommitTimestamp = ($cached !== null && isset($cached['latest']['datetime'])) ? strtotime($cached['latest']['datetime']) : 0;

            if ($latestCommitTime > $cachedCommitTimestamp || $cached === null) {
                if ($latestCommit) {
                    $info['latest']['date'] = date('Y-m-d', $latestCommitTime);
                    $info['latest']['datetime'] = date('Y-m-d H:i:s', $latestCommitTime);

                    $commitUrl = $latestCommit['url'];
                    $commitJson = @file_get_contents($commitUrl, false, $context);
                    if ($commitJson) {
                        $commitDetails = json_decode($commitJson, true);
                        $info['latest']['lines_added'] = $commitDetails['stats']['additions'] ?? 0;
                        $info['latest']['lines_deleted'] = $commitDetails['stats']['deletions'] ?? 0;
                    }
                }
                gh_cache_set($cacheKey, $info);
                return $info;
            } else {
                return $cached;
            }
        } else {
            // ... (Logik für alle Benutzer-Repos, unverändert) ...
            $repos = gh_user_public_repos($owner, $ttl, $ghtoken);
            $latestCommit = null;
            $latestCommitTime = 0;
            foreach ($repos as $repoItem) {
                $url = "https://api.github.com/repos/{$owner}/{$repoItem['name']}/commits?per_page=1";
                $json = @file_get_contents($url, false, $context);
                if ($json) {
                    $commits = json_decode($json, true);
                    if (!empty($commits) && isset($commits[0]['commit']['committer']['date'])) {
                        $commitTime = strtotime($commits[0]['commit']['committer']['date']);
                        if ($commitTime > $latestCommitTime) {
                            $latestCommitTime = $commitTime;
                            $latestCommit = $commits[0];
                        }
                    }
                }
            }
            if ($latestCommit) {
                $info['latest']['date'] = date('Y-m-d', $latestCommitTime);
                $info['latest']['datetime'] = date('Y-m-d H:i:s', $latestCommitTime);
                $commitUrl = $latestCommit['url'];
                $commitJson = @file_get_contents($commitUrl, false, $context);
                if ($commitJson) {
                    $commitDetails = json_decode($commitJson, true);
                    $info['latest']['lines_added'] = $commitDetails['stats']['additions'] ?? 0;
                    $info['latest']['lines_deleted'] = $commitDetails['stats']['deletions'] ?? 0;
                }
            }
            gh_cache_set($cacheKey, $info);
            return $info;
        }
    }
}
if (!function_exists('gh_all_commit_info')) {
    function gh_all_commit_info(string $owner, int $ttl = 300, ?string $ghtoken = null): array
    {
        $cacheKey = "all_commit_info_{$owner}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return $cached;
        }

        $info = [
            'date' => 'N/A',
            'datetime' => 'N/A',
            'lines_added' => 0,
            'lines_deleted' => 0,
        ];

        $repos = gh_user_public_repos($owner, $ttl, $ghtoken);
        $latestCommit = null;
        $latestCommitTime = 0;
        $opts = ["http" => ["header" => "User-Agent: MiniBadges\r\n"]];
        if ($ghtoken) {
            $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
        }
        $context = stream_context_create($opts);

        foreach ($repos as $repoItem) {
            $url = "https://api.github.com/repos/{$owner}/{$repoItem['name']}/commits?per_page=1";
            $json = @file_get_contents($url, false, $context);
            if ($json) {
                $commits = json_decode($json, true);
                if (!empty($commits)) {
                    $commit = $commits[0];
                    $commitTime = strtotime($commit['commit']['committer']['date']);
                    if ($commitTime > $latestCommitTime) {
                        $latestCommitTime = $commitTime;
                        $latestCommit = $commit;
                    }
                }
            }
        }

        if ($latestCommit) {
            $info['date'] = date('Y-m-d', $latestCommitTime);
            $info['datetime'] = date('Y-m-d H:i:s', $latestCommitTime);
            $commitUrl = $latestCommit['url'];
            $commitJson = @file_get_contents($commitUrl, false, $context);
            if ($commitJson) {
                $commitDetails = json_decode($commitJson, true);
                $info['lines_added'] = $commitDetails['stats']['additions'] ?? 0;
                $info['lines_deleted'] = $commitDetails['stats']['deletions'] ?? 0;
            }
        }

        gh_cache_set($cacheKey, $info);
        return $info;
    }
}
// ------------------- Open Issues Funktion -------------------
if (!function_exists('gh_repo_issues_open')) {
    function gh_repo_issues_open(string $owner, string $repo, int $ttl = 3600, ?string $ghtoken = null): int
    {
        $cacheKey = "new_robust_issues_open_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }

        $url = "https://api.github.com/repos/{$owner}/{$repo}";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
        if ($ghtoken) {
            $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
        }
        $context = stream_context_create($opts);

        $json = @file_get_contents($url, false, $context);
        if (!$json) {
            return 0;
        }

        $repoData = json_decode($json, true);
        $count = $repoData['open_issues_count'] ?? 0;

        gh_cache_set($cacheKey, $count);
        return $count;
    }
}
// ------------------- Closed Issues Funktion -------------------
if (!function_exists('gh_repo_issues_closed')) {
    function gh_repo_issues_closed(string $owner, string $repo, int $ttl = 3600, ?string $ghtoken = null): int
    {
        $cacheKey = "new_robust_issues_closed_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }

        // Retrieve all closed items, including pull requests
        $url = "https://api.github.com/repos/{$owner}/{$repo}/issues?state=closed&per_page=100";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.2.0\r\n"]];
        if ($ghtoken) {
            $opts['http']['header'] .= "Authorization: token $ghtoken\r\n";
        }
        $context = stream_context_create($opts);

        $json = @file_get_contents($url, false, $context);
        if (!$json) {
            return 0;
        }

        $items = json_decode($json, true);
        if (!is_array($items)) {
            return 0;
        }

        $closedIssueCount = 0;
        foreach ($items as $item) {
            // Only count issues that are not pull requests
            if (!isset($item['pull_request'])) {
                $closedIssueCount++;
            }
        }

        gh_cache_set($cacheKey, $closedIssueCount);
        return $closedIssueCount;
    }
}
// ---------------- Sponsor Count -----------------------
if (!function_exists('gh_user_sponsors_count')) {
    function gh_user_sponsors_count(string $username, int $ttl = 3600, ?string $ghtoken = null): int
    {
        $cacheKey = "gh_sponsors_count_{$username}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }

        if (!$ghtoken) {
            return 0; // Token erforderlich
        }

        $query = <<<GQL
query {
  user(login: "{$username}") {
    sponsorshipsAsMaintainer {
      totalCount
    }
  }
}
GQL;

        $ch = curl_init('https://api.github.com/graphql');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: bearer ' . $ghtoken,
            'User-Agent: MiniBadges/1.2.0'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['query' => $query]));

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $count = $data['data']['user']['sponsorshipsAsMaintainer']['totalCount'] ?? 0;

        gh_cache_set($cacheKey, $count);
        return (int)$count;
    }
}
// ----------------- Discussions ----------------------
if (!function_exists('gh_repo_discussions')) {
    /**
     * Liefert wichtige Informationen zu Discussions eines Repositories
     * @param string $owner GitHub Owner
     * @param string $repo GitHub Repository
     * @param int $ttl Cache-Zeit in Sekunden
     * @param string|null $ghtoken GitHub Token
     * @return array
     * [
     *   'count' => int,
     *   'last' => [
     *       'title' => string,
     *       'author' => string,
     *       'createdAt' => string,
     *       'updatedAt' => string,
     *       'commentsCount' => int
     *   ]
     * ]
     */
    function gh_repo_discussions(string $owner, string $repo, int $ttl = 3600, ?string $ghtoken = null): array
    {
        $cacheKey = "gh_discussions_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return $cached;
        }

        if (!$ghtoken) {
            return ['count' => 0, 'last' => null];
        }

        $query = <<<GQL
query {
  repository(owner: "{$owner}", name: "{$repo}") {
    discussions(first: 1, orderBy: {field: CREATED_AT, direction: DESC}) {
      totalCount
      nodes {
        title
        createdAt
        updatedAt
        author {
          login
        }
        comments(first: 0) {
          totalCount
        }
      }
    }
  }
}
GQL;

        $ch = curl_init('https://api.github.com/graphql');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: bearer ' . $ghtoken,
            'User-Agent: MiniBadges/1.2.0'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['query' => $query]));

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $discussionData = $data['data']['repository']['discussions'] ?? null;

        $lastDiscussion = null;
        if (isset($discussionData['nodes'][0])) {
            $node = $discussionData['nodes'][0];

            // kleine Hilfsfunktion für Datumsformatierung
            $formatDate = function ($dateStr) {
                if (!$dateStr) return '';
                $dt = new DateTime($dateStr);
                return $dt->format('Y-m-d H:i:s');
            };

            $lastDiscussion = [
                'title'        => $node['title'] ?? '',
                'author'       => $node['author']['login'] ?? '',
                'createdAt'    => isset($node['createdAt']) ? $formatDate($node['createdAt']) : '',
                'updatedAt'    => isset($node['updatedAt']) ? $formatDate($node['updatedAt']) : '',
                'commentsCount' => $node['comments']['totalCount'] ?? 0
            ];
        }


        $result = [
            'count' => $discussionData['totalCount'] ?? 0,
            'last' => $lastDiscussion
        ];

        gh_cache_set($cacheKey, $result);
        return $result;
    }
}
if (!function_exists('gh_user_commits_complete')) {
    function gh_user_commits_complete(string $owner, int $ttl = 3600, ?string $ghtoken = null): array
    {
        $cacheKey = "gh_commits_complete_{$owner}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) return $cached;

        if (!$ghtoken) return ['total' => 0, 'last' => null, 'repos' => []];

        $totalCommits = 0;
        $lastCommit = null;
        $reposCommits = [];

        $page = 1;
        do {
            $reposUrl = "https://api.github.com/users/{$owner}/repos?per_page=100&type=public&page={$page}";
            $opts = [
                "http" => [
                    "header" => "User-Agent: MiniBadges/1.2.0\r\nAuthorization: token {$ghtoken}\r\n"
                ]
            ];
            $context = stream_context_create($opts);
            $reposJson = @file_get_contents($reposUrl, false, $context);
            $repos = $reposJson ? json_decode($reposJson, true) : [];
            if (!is_array($repos) || empty($repos)) break;

            foreach ($repos as $repo) {
                $repoName = $repo['name'];
                $repoCommits = 0;
                $repoLastCommit = null;

                // Alle Branches des Repos
                $branchesJson = @file_get_contents("https://api.github.com/repos/{$owner}/{$repoName}/branches?per_page=100", false, $context);
                $branches = $branchesJson ? json_decode($branchesJson, true) : [];
                foreach ($branches as $branch) {
                    $branchName = $branch['name'];

                    // Holen der Commits mit stats
                    $commitsJson = @file_get_contents("https://api.github.com/repos/{$owner}/{$repoName}/commits?sha={$branchName}&per_page=100", false, $context);
                    $commits = $commitsJson ? json_decode($commitsJson, true) : [];
                    $repoCommits += count($commits);

                    foreach ($commits as $commit) {
                        $commitDateRaw = $commit['commit']['committer']['date'] ?? null;
                        if (!$commitDateRaw) continue;
                        $commitTime = strtotime($commitDateRaw);

                        // Letzten Commit per Repo finden
                        if (!$repoLastCommit || $commitTime > strtotime($repoLastCommit['date'])) {
                            // Hole Commit stats
                            $commitUrl = $commit['url'] ?? null;
                            $linesAdded = 0;
                            $linesDeleted = 0;
                            if ($commitUrl) {
                                $commitDetailsJson = @file_get_contents($commitUrl, false, $context);
                                $commitDetails = $commitDetailsJson ? json_decode($commitDetailsJson, true) : [];
                                $linesAdded = $commitDetails['stats']['additions'] ?? 0;
                                $linesDeleted = $commitDetails['stats']['deletions'] ?? 0;
                            }
                            $repoLastCommit = [
                                'date' => date('Y-m-d H:i:s', $commitTime),
                                'lines_added' => $linesAdded,
                                'lines_deleted' => $linesDeleted
                            ];
                        }

                        // Letzten Commit global finden
                        if (!$lastCommit || $commitTime > strtotime($lastCommit['date'])) {
                            $lastCommit = [
                                'date' => date('Y-m-d H:i:s', $commitTime),
                                'lines_added' => $commitDetails['stats']['additions'] ?? 0,
                                'lines_deleted' => $commitDetails['stats']['deletions'] ?? 0
                            ];
                        }
                    }
                }

                $reposCommits[$repoName] = $repoCommits;
                $totalCommits += $repoCommits;
            }

            $page++;
        } while (count($repos) === 100);

        $result = [
            'total' => $totalCommits,
            'last' => $lastCommit,
            'repos' => $reposCommits
        ];
        gh_cache_set($cacheKey, $result);
        return $result;
    }
}
if (!function_exists('gh_repo_commits_complete')) {
    function gh_repo_commits_complete(string $owner, string $repo, int $ttl = 3600, ?string $ghtoken = null, ?string $branchFilter = null): int
    {
        $cacheKey = "gh_repo_commits_complete_{$owner}_{$repo}_" . ($branchFilter ?: 'all');
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) return (int)$cached;

        if (!$ghtoken) return 0;

        $commitCount = 0;
        $opts = [
            "http" => [
                "header" => "User-Agent: MiniBadges/1.2.0\r\nAuthorization: token {$ghtoken}\r\n"
            ]
        ];
        $context = stream_context_create($opts);

        // Branch-Liste holen
        $branches = [];
        if ($branchFilter) {
            // Prüfen, ob der Branch existiert
            $branchUrl = "https://api.github.com/repos/{$owner}/{$repo}/branches/{$branchFilter}";
            $branchJson = @file_get_contents($branchUrl, false, $context);
            if (!$branchJson) {
                gh_cache_set($cacheKey, -1); // Fehler: Branch nicht gefunden
                return -1;
            }
            $branches[] = ['name' => $branchFilter];
        } else {
            $branchesJson = @file_get_contents("https://api.github.com/repos/{$owner}/{$repo}/branches?per_page=100", false, $context);
            $branches = $branchesJson ? json_decode($branchesJson, true) : [];
        }

        foreach ($branches as $branch) {
            $branchName = $branch['name'];
            $page = 1;
            do {
                $commitsUrl = "https://api.github.com/repos/{$owner}/{$repo}/commits?sha={$branchName}&per_page=100&page={$page}";
                $commitsJson = @file_get_contents($commitsUrl, false, $context);
                $commits = $commitsJson ? json_decode($commitsJson, true) : [];
                $commitCount += count($commits);
                $page++;
            } while (!empty($commits) && count($commits) === 100);
        }

        gh_cache_set($cacheKey, $commitCount);
        return $commitCount;
    }
}
if (!function_exists('gh_repo_codesize')) {
    function gh_repo_codesize(string $owner, string $repo, int $ttl = 3600, ?string $ghtoken = null): int
    {
        $cacheKey = "gh_repo_codesize_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) return (int)$cached;

        if (!$ghtoken) return 0;

        $opts = [
            "http" => [
                "header" => "User-Agent: MiniBadges/1.2.0\r\nAuthorization: token {$ghtoken}\r\n"
            ]
        ];
        $context = stream_context_create($opts);

        // GitHub gibt Sprachen mit Codezeilen in Bytes zurück
        $url = "https://api.github.com/repos/{$owner}/{$repo}/languages";
        $json = @file_get_contents($url, false, $context);
        $langs = $json ? json_decode($json, true) : [];

        $total = 0;
        foreach ($langs as $lang => $bytes) {
            $total += (int)$bytes;
        }

        gh_cache_set($cacheKey, $total);
        return $total;
    }
}
if (!function_exists('gh_user_codesize_all')) {
    function gh_user_codesize_all(string $owner, int $ttl = 3600, ?string $ghtoken = null): int
    {
        $cacheKey = "gh_user_codesize_all_{$owner}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) return (int)$cached;

        if (!$ghtoken) return 0;

        $opts = [
            "http" => [
                "header" => "User-Agent: MiniBadges/1.2.0\r\nAuthorization: token {$ghtoken}\r\n"
            ]
        ];
        $context = stream_context_create($opts);

        $page = 1;
        $total = 0;
        do {
            $reposJson = @file_get_contents("https://api.github.com/users/{$owner}/repos?per_page=100&page={$page}", false, $context);
            $repos = $reposJson ? json_decode($reposJson, true) : [];
            foreach ($repos as $repo) {
                $langsJson = @file_get_contents("https://api.github.com/repos/{$owner}/{$repo['name']}/languages", false, $context);
                $langs = $langsJson ? json_decode($langsJson, true) : [];
                foreach ($langs as $bytes) {
                    $total += (int)$bytes;
                }
            }
            $page++;
        } while (!empty($repos) && count($repos) === 100);

        gh_cache_set($cacheKey, $total);
        return $total;
    }
}
// ----------------- Profil Infos --------------------
if (!function_exists('gh_user_fullinfo')) {
    function gh_user_fullinfo(string $username, int $ttl = 3600, ?string $ghtoken = null): array {
        $cacheKey = "gh_user_fullinfo_{$username}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) return $cached;

        if (!$ghtoken) return [];

        $opts = [
            "http" => [
                "header" => "User-Agent: MiniBadges/1.2.0\r\nAuthorization: bearer {$ghtoken}\r\n",
                "method" => "POST",
            ]
        ];

        $query = json_encode([
            "query" => <<<GQL
query {
  user(login: "{$username}") {
    login
    name
    company
    location
    createdAt
    updatedAt
    status {
      emoji
      message
      updatedAt
    }
  }
}
GQL
        ]);

        $opts['http']['header'] .= "Content-Type: application/json\r\n";
        $opts['http']['content'] = $query;
        $context = stream_context_create($opts);

        $url = "https://api.github.com/graphql";
        $result = @file_get_contents($url, false, $context);
        $data = $result ? json_decode($result, true) : [];

        if (!isset($data['data']['user'])) {
            return [];
        }

        $user = $data['data']['user'];
        gh_cache_set($cacheKey, $user);
        return $user;
    }
}


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
    $repoInfo = gh_repo_info($owner, $repo, $ttl, $ghtoken);

    // ------------------- Switch Metric -------------------
    switch ($metric) {
        // Case statement for 'lines'
        case (preg_match('/^lines(?:-(added|deleted|all))?$/', $metric, $m) ? true : false):
            $stats = gh_repo_lines($owner, $repo, $ttl, $ghtoken);
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
                        $text2 = formatNumberShort(gh_repo_milestones_robust($owner, $repo, $ttl, $ghtoken, 'open'));
                        break;
                    case 'closed':
                        $text1 = $L['milestones_closed'] ?? 'Milestones (Closed)';
                        $text2 = formatNumberShort(gh_repo_milestones_robust($owner, $repo, $ttl, $ghtoken, 'closed'));
                        break;
                    case 'default':
                    default:
                        $text1 = $L['milestones'] ?? 'Milestones';
                        $text2 = formatNumberShort(gh_repo_milestones_robust($owner, $repo, $ttl, $ghtoken, 'all'));
                        break;
                }
            }
            // Metrics for all repositories of a user
            else {
                switch ($type) {
                    case 'all':
                        $text1 = $L['milestones_all'] ?? 'Milestones (All)';
                        $text2 = formatNumberShort(gh_user_milestones_all($owner, $ttl, $ghtoken, 'all'));
                        break;
                    case 'allopen':
                        $text1 = $L['milestones_allopen'] ?? 'Milestones (All Open)';
                        $text2 = formatNumberShort(gh_user_milestones_all($owner, $ttl, $ghtoken, 'open'));
                        break;
                    case 'allclosed':
                        $text1 = $L['milestones_allclosed'] ?? 'Milestones (All Closed)';
                        $text2 = formatNumberShort(gh_user_milestones_all($owner, $ttl, $ghtoken, 'closed'));
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
            $text2 = formatNumberShort(gh_user_stars_all($owner, $ttl, $ghtoken));
            break;
        case 'forks':
            $text1 = $L['forks'] ?? 'Forks';
            $text2 = isset($repoInfo['forks_count']) ? formatNumberShort($repoInfo['forks_count']) : 'N/A';
            break;
        case 'forks-all':
            $text1 = $L['forks_all'] ?? 'Forks (All)';
            $text2 = formatNumberShort(gh_user_forks_all($owner, $ttl, $ghtoken));
            break;
        case 'issues':
            $text1 = $L['issues'] ?? 'Issues';
            $text2 = isset($repoInfo['open_issues_count']) ? formatNumberShort($repoInfo['open_issues_count']) : 'N/A';
            break;
        case 'issues-open':
            $text1 = $L['issues'] ?? 'Issues';
            $count = gh_repo_issues_open($owner, $repo, $ttl, $ghtoken);
            $text2 = formatNumberShort($count) . ' open';
            break;
        case 'issues-closed':
            $text1 = $L['issues'] ?? 'Issues';
            $count = gh_repo_issues_closed($owner, $repo, $ttl, $ghtoken);
            $text2 = formatNumberShort($count) . ' closed';
            break;
        case 'issues_all':
            $text1 = $L['issues_all'] ?? 'Issues (All)';
            $text2 = formatNumberShort(gh_user_issues_all($owner, $ttl, $ghtoken));
            break;
        case 'sponsors':
            $text1 = 'Sponsors';
            $count = gh_user_sponsors_count($owner, $ttl, $ghtoken);
            $text2 = formatNumberShort($count);
            break;
        case 'watchers':
            $text1 = $L['watchers'] ?? 'Watchers';
            if (isset($repoInfo['subscribers_count'])) $text2 = formatNumberShort($repoInfo['subscribers_count']);
            elseif (isset($repoInfo['watchers_count'])) $text2 = formatNumberShort($repoInfo['watchers_count']);
            else $text2 = 'N/A';
            break;
        case 'watchers_all':
            $text1 = $L['watchers_all'] ?? 'Watchers (All)';
            $text2 = formatNumberShort(gh_user_watchers_all($owner, $ttl, $ghtoken));
            break;
        case 'downloads':
            $text1 = $L['downloads'] ?? 'Downloads';
            $text2 = formatNumberShort(gh_repo_downloads($owner, $repo, $ttl, $ghtoken));
            break;
        case 'downloads-latest':
            $text1 = $L['downloads_latest'] ?? 'Downloads Latest Release';
            $text2 = formatNumberShort(gh_repo_downloads_latest($owner, $repo, $ttl, $ghtoken));
            break;
        case 'downloads-all':
            $text1 = $L['downloads_all'] ?? 'Downloads (All)';
            $text2 = formatNumberShort(gh_user_downloads_all($owner, $ttl, $ghtoken));
            break;
        case 'branches':
            $text1 = $L['branches'] ?? 'Branches';
            $text2 = formatNumberShort(gh_repo_branches($owner, $repo, $ttl, $ghtoken));
            break;
        case 'branches-all':
            $text1 = $L['branches_all'] ?? 'Branches (All)';
            $text2 = formatNumberShort(gh_user_branches_all($owner, $ttl, $ghtoken));
            break;
        case 'release':
            $text1 = $L['release'] ?? 'Release';
            $rel = gh_repo_release($owner, $repo, $ttl, $ghtoken);
            $text2 = $rel['tag_name'] ?? ($repoInfo['default_branch'] ?? 'N/A');
            break;
        case 'license':
            $text1 = $L['license'] ?? 'License';
            $text2 = $repoInfo['license']['spdx_id'] ?? ($repoInfo['license']['key'] ?? 'N/A');
            break;
        case 'top_language':
            $text1 = $L['top_language'] ?? 'Top language';
            $text2 = gh_top_language($owner, $repo, $ttl, $ghtoken) ?? 'N/A';
            break;
        case 'size':
            $text1 = $L['size'] ?? 'Size';
            $text2 = isset($repoInfo['size']) ? formatNumberShort($repoInfo['size']) . ' KB' : 'N/A';
            break;
        case 'size_all':
            $text1 = $L['size_all'] ?? 'Size (All)';
            $text2 = formatNumberShort(gh_user_size_all($owner, $ttl, $ghtoken)) . ' KB';
            break;
        case 'created_at':
            $text1 = $L['created_at'] ?? 'Created';
            $text2 = isset($repoInfo['created_at']) ? date('Y-m-d', strtotime($repoInfo['created_at'])) : 'N/A';
            break;
        case 'repos_count':
            $text1 = $L['repos_count'] ?? 'Public Repos';
            $text2 = formatNumberShort(gh_user_repos_count($owner, $ttl, $ghtoken));
            break;
        case (preg_match('/^top_languages_all(?:-(\d+))?$/', $metric, $m) ? true : false):
            $limit = isset($m[1]) ? (int)$m[1] : 1;
            $langs = gh_user_top_languages_all($owner, $ttl, $ghtoken, $limit);
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
            $text2 = formatNumberShort(gh_repo_pull_requests($owner, $repo, $ttl, $ghtoken));
            break;
        case 'prs-merged':
            $text1 = $L['prs_merged'] ?? 'Merged PRs';
            $text2 = formatNumberShort(gh_repo_merged_pull_requests($owner, $repo, $ttl, $ghtoken));
            break;
        case 'prs-mergedall':
            $text1 = $L['prs_merged_all'] ?? 'Merged PRs (All)';
            $text2 = formatNumberShort(gh_user_merged_pull_requests_all($owner, $ttl, $ghtoken));
            break;
        case 'prs-all':
            $text1 = $L['pull_requests_all'] ?? 'PRs (All)';
            $text2 = formatNumberShort(gh_user_pull_requests_all($owner, $ttl, $ghtoken));
            break;
        case 'subscribers':
            $text1 = $L['subscribers_count'] ?? 'Subscribers';
            $text2 = isset($repoInfo['subscribers_count']) ? formatNumberShort($repoInfo['subscribers_count']) : 'N/A';
            break;
        case 'subscribers-all':
            $text1 = $L['subscribers_count_all'] ?? 'Subscribers (All)';
            $text2 = formatNumberShort(gh_user_subscribers_all($owner, $ttl, $ghtoken));
            break;
        case 'successrate':
            $text1 = $L['success_rate'] ?? 'Success Rate';
            $text2 = gh_repo_success_rate($owner, $repo, $ttl, $ghtoken);
            break;
        case 'successrate-all':
            $text1 = $L['success_rate_all'] ?? 'Success Rate (All)';
            $text2 = gh_user_success_rate_all($owner, $ttl, $ghtoken);
            break;
        case 'files':
            $text1 = $L['files'] ?? 'Files';
            $text2 = formatNumberShort(gh_repo_files($owner, $repo, $ttl, $ghtoken));
            break;
        case 'files-all':
            $text1 = $L['files_all'] ?? 'Files (All)';
            $text2 = formatNumberShort(gh_user_files_all($owner, $ttl, $ghtoken));
            break;
        case 'tags':
            $text1 = $L['tags'] ?? 'Tags';
            $text2 = formatNumberShort(gh_repo_tags($owner, $repo, $ttl, $ghtoken));
            break;
        case 'tags-all':
            $text1 = $L['tags_all'] ?? 'Tags (All)';
            $text2 = formatNumberShort(gh_user_tags_all($owner, $ttl, $ghtoken));
            break;
        case 'follower':
            $text1 = $L['follower'] ?? 'Followers';
            $text2 = formatNumberShort(gh_user_followers($owner, $ttl, $ghtoken));
            break;
        case 'following':
            $text1 = $L['following'] ?? 'Following';
            $text2 = formatNumberShort(gh_user_following($owner, $ttl, $ghtoken));
            break;
        case 'projects':
            $text1 = $L['projects'] ?? 'Projects';
            $text2 = formatNumberShort(gh_repo_projects($owner, $repo, $ttl, $ghtoken));
            break;
        case 'projects-all':
            $text1 = $L['projects_all'] ?? 'Projects (All)';
            $text2 = formatNumberShort(gh_user_projects_all($owner, $ttl, $ghtoken));
            break;
        case 'releases':
            $text1 = $L['releases'] ?? 'Releases';
            $text2 = formatNumberShort(gh_repo_releases($owner, $repo, $ttl, $ghtoken));
            break;
        case 'releases_all':
            $text1 = $L['releases_all'] ?? 'Releases (All)';
            $text2 = formatNumberShort(gh_user_releases_all($owner, $ttl, $ghtoken));
            break;
        case (preg_match('/^gists(?:-(list|size|date|forks|listall|list(\d+))|-(size|forks)@(.+))?$/', $metric, $m) ? true : false):
            $gists_info = gh_user_gists_info($owner, $ttl, $ghtoken);
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
                        $forks_count = gh_gist_forks_count($found_gist['id'], $ttl, $ghtoken);
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
                            $total_forks += gh_gist_forks_count($gist['id'], $ttl, $ghtoken);
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
                $comparison = gh_repo_compare_version($owner, $repo, $current_version, $ttl, $ghtoken);
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
            $langs = gh_top_language_count($owner, $repo, $ttl, $ghtoken, $limit);
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
            $pushInfo = gh_push_info($owner, $repo, $ttl, $ghtoken);
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
        // Case statement for 'discussions'
        case (preg_match('/^discussions(?:-(lastdate|lastupdate|lasttitle|lastauthor|count))?$/', $metric, $m) ? true : false):
            $stats = gh_repo_discussions($owner, $repo, $ttl, $ghtoken);
            $text1 = '';
            $text2 = '';
            $type = $m[1] ?? 'count'; // Default auf count

            switch ($type) {
                case 'lastdate':
                    $text1 = $L['discussions_lastdate'] ?? 'Last Discussion Date';
                    $text2 = $stats['last']['createdAt'] ?? 'N/A';
                    break;
                case 'lastupdate':
                    $text1 = $L['discussions_lastupdate'] ?? 'Last Discussion Update';
                    $text2 = $stats['last']['updatedAt'] ?? 'N/A';
                    break;
                case 'lasttitle':
                    $text1 = $L['discussions_lasttitle'] ?? 'Last Discussion Title';
                    $text2 = $stats['last']['title'] ?? 'N/A';
                    break;
                case 'lastauthor':
                    $text1 = $L['discussions_lastauthor'] ?? 'Last Discussion Author';
                    $text2 = $stats['last']['author'] ?? 'N/A';
                    break;
                case 'count':
                default:
                    $text1 = $L['discussions_count'] ?? 'Discussions';
                    $text2 = formatNumberShort($stats['count']);
                    break;
            }
            break;
        case (preg_match('/^commit(?:-(time|datetime|info|lines))?$/', $metric, $m) ? true : false):
            $subMetric = $m[1] ?? 'default';
            $text1 = '';
            $text2 = '';
            $commitInfo = gh_commit_info($owner, $repo, $ttl, $ghtoken);
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
        case (preg_match('/^commits(?:-(all|last|last-info))?(?:@([\w\-\_]+))?$/', $metric, $m) ? true : false):
            $text1 = '';
            $text2 = '';
            $type = $m[1] ?? 'repo';
            $branchFilter = $m[2] ?? null;
            switch ($type) {
                case 'all':
                    $stats = gh_user_commits_complete($owner, $ttl, $ghtoken);
                    $text1 = $L['commits_all'] ?? 'Commits';
                    $text2 = formatNumberShort($stats['total']);
                    break;
                case 'last':
                    $stats = gh_user_commits_complete($owner, $ttl, $ghtoken);
                    $text1 = $L['commits_last'] ?? 'Last Commit';
                    $text2 = $stats['last']['date'] ?? 'N/A';
                    break;
                case 'last-info':
                    $stats = gh_user_commits_complete($owner, $ttl, $ghtoken);
                    $text1 = $L['commits_last_info'] ?? 'Last Commit Info';
                    if ($stats['last']) {
                        $text2 = $stats['last']['date'] .
                            " | +{$stats['last']['lines_added']} -{$stats['last']['lines_deleted']}";
                    } else {
                        $text2 = 'N/A';
                    }
                    break;
                case 'repo':
                default:
                    $count = gh_repo_commits_complete($owner, $repo, $ttl, $ghtoken, $branchFilter);
                    $text1 = $L['commits_repo'] ?? 'Commits';
                    if ($branchFilter) {
                        $text1 .= " ($branchFilter)";
                    }
                    if ($count === -1) {
                        $text2 = "Branch not found";
                    } else {
                        $text2 = formatNumberShort($count);
                    }
                    break;
            }
            break;
        case (preg_match('/^codesize(?:-all)?$/', $metric) ? true : false):
            if (str_starts_with($metric, 'codesize-all')) {
                $bytes = gh_user_codesize_all($owner, $ttl, $ghtoken);
                $text1 = $L['codesize_all'] ?? 'Code Size (All Repos)';
                $text2 = formatBytesShort($bytes);
            } else {
                $bytes = gh_repo_codesize($owner, $repo, $ttl, $ghtoken);
                $text1 = $L['codesize_repo'] ?? 'Code Size';
                $text2 = formatBytesShort($bytes);
            }
            break;
        case 'name':
            $user = gh_user_fullinfo($owner, $ttl, $ghtoken);
            $text1 = $L['name_label'] ?? 'Hello, my name is';
            $text2 = $user['name'] ?? $user['login'] ?? 'Unknown';
            break;
        case 'company':
            $user = gh_user_fullinfo($owner, $ttl, $ghtoken);
            $text1 = $L['company_label'] ?? 'Company';
            $text2 = $user['company'] ?? ($L['no_company'] ?? 'No value set');
            break;
        case 'location':
            $user = gh_user_fullinfo($owner, $ttl, $ghtoken);
            $text1 = $L['location_label'] ?? "I'm from";
            $text2 = $user['location'] ?? ($L['no_location'] ?? 'Somewhere in the matrix 🌌');
            break;
        case 'status':
            $user = gh_user_fullinfo($owner, $ttl, $ghtoken);
            $text1 = $L['status_label'] ?? 'Status';
            if (!empty($user['status'])) {
                $text2 = ($user['status']['emoji'] ?? '') . ' ' . ($user['status']['message'] ?? '');
            } else {
                $text2 = $L['no_status'] ?? 'No status set';
            }
            break;
        case (preg_match('/^(created|updated)At(?:-since)?$/', $metric, $m) ? true : false):
            $user = gh_user_fullinfo($owner, $ttl, $ghtoken);
            $field = $m[1] . 'At';
            $dateStr = $user[$field] ?? null;

            if (!$dateStr) {
                $text1 = $m[1] === 'created' ? ($L['account_created'] ?? 'Account created') : ($L['last_updated'] ?? 'Last updated');
                $text2 = $L['no_value'] ?? 'N/A';
                break;
            }
            $date = new DateTime($dateStr);
            if (str_ends_with($metric, '-since')) {
                $now = new DateTime();
                $diff = $now->diff($date);
                if ($diff->y > 0) {
                    $text2 = $diff->y . ' ' . ($diff->y > 1 ? ($L['time_year_plural'] ?? 'years') : ($L['time_year_singular'] ?? 'year'));
                } elseif ($diff->m > 0) {
                    $text2 = $diff->m . ' ' . ($diff->m > 1 ? ($L['time_month_plural'] ?? 'months') : ($L['time_month_singular'] ?? 'month'));
                } else {
                    $text2 = $diff->d . ' ' . ($diff->d > 1 ? ($L['time_day_plural'] ?? 'days') : ($L['time_day_singular'] ?? 'day'));
                }
            } else {
                $text2 = $date->format('Y-m-d');
            }
            $text1 = $m[1] === 'created' ? ($L['account_created'] ?? 'Account created') : ($L['last_updated'] ?? 'Last updated');
            break;
        default:
            $text1 = 'Metric';
            $text2 = 'N/A';
    }