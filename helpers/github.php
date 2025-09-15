<?php
function gh_cache_path(string $key): string
{
    return dirname(__DIR__) . '/cache/' . preg_replace('~[^a-zA-Z0-9_.-]~', '_', $key) . '.json';
}

function gh_cached_get(string $url, int $ttl, ?string $token = null): ?array
{
    $cache = gh_cache_path(sha1($url));
    if (is_file($cache) && (time() - filemtime($cache) < $ttl)) {
        $data = @file_get_contents($cache);
        if ($data !== false) return json_decode($data, true);
    }
    $headers = [
        'User-Agent: MiniBadges/1.0',
        'Accept: application/vnd.github+json'
    ];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
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
function gh_repo_info(string $owner, string $repo, int $ttl, ?string $token): ?array
{
    $url = "https://api.github.com/repos/$owner/$repo";
    return gh_cached_get($url, $ttl, $token);
}
function gh_repo_release(string $owner, string $repo, int $ttl, ?string $token): ?array
{
    $url = "https://api.github.com/repos/$owner/$repo/releases/latest";
    return gh_cached_get($url, $ttl, $token);
}
function gh_repo_languages(string $owner, string $repo, int $ttl, ?string $token): ?array
{
    $url = "https://api.github.com/repos/$owner/$repo/languages";
    return gh_cached_get($url, $ttl, $token);
}
function gh_top_language(string $owner, string $repo, int $ttl, ?string $token): ?string
{
    $langs = gh_repo_languages($owner, $repo, $ttl, $token);
    if (!$langs || !is_array($langs) || empty($langs)) return null;
    arsort($langs);
    foreach ($langs as $name => $bytes) {
        return $name;
    }
    return null;
}
// ------------------- Load language -------------------
if ($langPair) {
    $langFileLocal = __DIR__ . '/lang/' . basename($langPair) . '.php';
    $L = is_file($langFileLocal) ? include $langFileLocal : (is_file(__DIR__ . '/lang/en.php') ? include __DIR__ . '/lang/en.php' : []);
} else {
    if (!isset($L) || !is_array($L)) {
        $langTop = q('lang', $config['defaultLang'] ?? 'en');
        $langFileTop = __DIR__ . '/lang/' . basename($langTop) . '.php';
        $L = is_file($langFileTop) ? include $langFileTop : include __DIR__ . '/lang/en.php';
    }
}
// ------------------- Auxiliary function short format -------------------
function formatNumberShort($num)
{
    if ($num >= 1000000) return number_format($num / 1000000, ($num % 1000000 === 0) ? 0 : 1) . 'm';
    if ($num >= 1000) return number_format($num / 1000, ($num % 1000 === 0) ? 0 : 1) . 'k';
    return (string)$num;
}
// ------------------- Functions of the metrics -------------------
// ------------------- Top Language Count Function -------------------
if (!function_exists('gh_top_language_count')) {
    function gh_top_language_count(string $owner, string $repo, int $ttl = 300, ?string $token = null, int $limit = 1): array
    {
        $url = "https://api.github.com/repos/$owner/$repo/languages";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
        if ($token) $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_user_stars_all(string $user, int $ttl = 3600, ?string $token = null): int
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
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
            if ($token) {
                $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_repo_downloads(string $owner, string $repo, int $ttl = 300, ?string $token = null, int $limit = 0): int
    {
        $cacheKey = "downloads_{$owner}_{$repo}_limit{$limit}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) return (int)$cached;

        $url = "https://api.github.com/repos/$owner/$repo/releases";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
        if ($token) $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_repo_downloads_latest(string $owner, string $repo, int $ttl = 300, ?string $token = null): int
    {
        return gh_repo_downloads($owner, $repo, $ttl, $token, 1);
    }
}
if (!function_exists('gh_user_downloads_all')) {
    function gh_user_downloads_all(string $user, int $ttl = 3600, ?string $token = null, int $releasesLimit = 0): int
    {
        $cacheKey = "downloads_all_{$user}_limit{$releasesLimit}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) return (int)$cached;
        $sum = 0;
        $page = 1;
        do {
            $url = "https://api.github.com/users/{$user}/repos?per_page=100&page={$page}";
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
            if ($token) $opts['http']['header'] .= "Authorization: token $token\r\n";
            $context = stream_context_create($opts);
            $json = @file_get_contents($url, false, $context);
            if (!$json) break;
            $repos = json_decode($json, true);
            if (!is_array($repos) || count($repos) === 0) break;

            foreach ($repos as $r) {
                $ownerR = $r['owner']['login'];
                $repoR  = $r['name'];
                $sum += gh_repo_downloads($ownerR, $repoR, $ttl, $token, $releasesLimit);
            }
            $page++;
        } while (count($repos) === 100);
        gh_cache_set($cacheKey, $sum);
        return $sum;
    }
}
// ------------------- Branches function -------------------
if (!function_exists('gh_repo_branches')) {
    function gh_repo_branches(string $owner, string $repo, int $ttl = 300, ?string $token = null): int
    {
        $cacheKey = "branches_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) return (int)$cached;
        $url = "https://api.github.com/repos/$owner/$repo/branches?per_page=100";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
        if ($token) $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_user_branches_all(string $user, int $ttl = 3600, ?string $token = null): int
    {
        $cacheKey = "branches_all_{$user}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) return (int)$cached;
        $sum = 0;
        $page = 1;
        do {
            $url = "https://api.github.com/users/{$user}/repos?per_page=100&page={$page}";
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
            if ($token) $opts['http']['header'] .= "Authorization: token $token\r\n";
            $context = stream_context_create($opts);
            $json = @file_get_contents($url, false, $context);
            if (!$json) break;
            $repos = json_decode($json, true);
            if (!is_array($repos) || count($repos) === 0) break;
            foreach ($repos as $r) {
                $ownerR = $r['owner']['login'];
                $repoR  = $r['name'];
                $sum += gh_repo_branches($ownerR, $repoR, $ttl, $token);
            }
            $page++;
        } while (count($repos) === 100);
        gh_cache_set($cacheKey, $sum);
        return $sum;
    }
}
// ------------------- Size function (All) -------------------
if (!function_exists('gh_user_size_all')) {
    function gh_user_size_all(string $user, int $ttl = 3600, ?string $token = null): int
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
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
            if ($token) {
                $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_user_forks_all(string $user, int $ttl = 3600, ?string $token = null): int
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
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
            if ($token) {
                $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_user_issues_all(string $user, int $ttl = 3600, ?string $token = null): int
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
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
            if ($token) {
                $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_user_watchers_all(string $user, int $ttl = 3600, ?string $token = null): int
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
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
            if ($token) {
                $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_user_repos_count(string $user, int $ttl = 3600, ?string $token = null): int
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
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
            if ($token) {
                $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_user_top_languages_all(string $user, int $ttl = 3600, ?string $token = null, int $limit = 1): array
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
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
            if ($token) {
                $opts['http']['header'] .= "Authorization: token $token\r\n";
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
                $repoLanguages = gh_top_language_count($repoOwner, $repoName, $ttl, $token, 10);
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
    function gh_repo_pull_requests(string $owner, string $repo, int $ttl = 300, ?string $token = null): int
    {
        $cacheKey = "prs_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $url = "https://api.github.com/repos/{$owner}/{$repo}/pulls?state=all";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
        if ($token) {
            $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_repo_merged_pull_requests(string $owner, string $repo, int $ttl = 300, ?string $token = null): int
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
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
            if ($token) {
                $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_user_merged_pull_requests_all(string $user, int $ttl = 3600, ?string $token = null): int
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
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
            if ($token) {
                $opts['http']['header'] .= "Authorization: token $token\r\n";
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
                $sum += gh_repo_merged_pull_requests($r['owner']['login'], $r['name'], $ttl, $token);
            }
            $page++;
        } while (count($repos) === 100);
        gh_cache_set($cacheKey, $sum);
        return $sum;
    }
}
// ------------------- Subscribers Function (All) -------------------
if (!function_exists('gh_user_subscribers_all')) {
    function gh_user_subscribers_all(string $user, int $ttl = 3600, ?string $token = null): int
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
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
            if ($token) {
                $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_repo_success_rate(string $owner, string $repo, int $ttl = 300, ?string $token = null): string
    {
        $cacheKey = "success_rate_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return $cached;
        }
        $merged_prs = gh_repo_merged_pull_requests($owner, $repo, $ttl, $token);
        $total_prs = gh_repo_pull_requests($owner, $repo, $ttl, $token);

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
    function gh_user_success_rate_all(string $user, int $ttl = 3600, ?string $token = null): string
    {
        $cacheKey = "success_rate_all_{$user}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return $cached;
        }
        $merged_prs_all = gh_user_merged_pull_requests_all($user, $ttl, $token);
        $total_prs_all = gh_user_pull_requests_all($user, $ttl, $token);

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
    function gh_repo_files(string $owner, string $repo, int $ttl = 300, ?string $token = null): int
    {
        $cacheKey = "files_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $repoInfo = gh_repo_info($owner, $repo, $ttl, $token);
        $default_branch = $repoInfo['default_branch'] ?? 'main';

        $url = "https://api.github.com/repos/{$owner}/{$repo}/git/trees/{$default_branch}?recursive=1";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
        if ($token) {
            $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_user_files_all(string $user, int $ttl = 3600, ?string $token = null): int
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
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
            if ($token) {
                $opts['http']['header'] .= "Authorization: token $token\r\n";
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
                $sum += gh_repo_files($r['owner']['login'], $r['name'], $ttl, $token);
            }
            $page++;
        } while (count($repos) === 100);
        gh_cache_set($cacheKey, $sum);
        return $sum;
    }
}
// ------------------- Tags Function -------------------
if (!function_exists('gh_repo_tags')) {
    function gh_repo_tags(string $owner, string $repo, int $ttl = 300, ?string $token = null): int
    {
        $cacheKey = "tags_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $url = "https://api.github.com/repos/{$owner}/{$repo}/tags?per_page=100";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
        if ($token) {
            $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_user_tags_all(string $user, int $ttl = 3600, ?string $token = null): int
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
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
            if ($token) {
                $opts['http']['header'] .= "Authorization: token $token\r\n";
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
                $sum += gh_repo_tags($r['owner']['login'], $r['name'], $ttl, $token);
            }
            $page++;
        } while (count($repos) === 100);
        gh_cache_set($cacheKey, $sum);
        return $sum;
    }
}
// ------------------- Follower Count Function -------------------
if (!function_exists('gh_user_followers')) {
    function gh_user_followers(string $user, int $ttl = 3600, ?string $token = null): int
    {
        $cacheKey = "followers_{$user}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }

        $url = "https://api.github.com/users/{$user}";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
        if ($token) {
            $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_user_following(string $user, int $ttl = 3600, ?string $token = null): int
    {
        $cacheKey = "following_{$user}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }

        $url = "https://api.github.com/users/{$user}";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
        if ($token) {
            $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_repo_projects(string $owner, string $repo, int $ttl = 300, ?string $token = null): int
    {
        $cacheKey = "projects_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $url = "https://api.github.com/repos/{$owner}/{$repo}/projects?per_page=100";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n", "Accept" => "application/vnd.github.v3+json"]];
        if ($token) {
            $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_user_projects_all(string $user, int $ttl = 3600, ?string $token = null): int
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
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
            if ($token) {
                $opts['http']['header'] .= "Authorization: token $token\r\n";
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
                $sum += gh_repo_projects($r['owner']['login'], $r['name'], $ttl, $token);
            }
            $page++;
        } while (count($repos) === 100);
        gh_cache_set($cacheKey, $sum);
        return $sum;
    }
}
// ------------------- Releases Function -------------------
if (!function_exists('gh_repo_releases')) {
    function gh_repo_releases(string $owner, string $repo, int $ttl = 300, ?string $token = null): int
    {
        $cacheKey = "releases_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $url = "https://api.github.com/repos/{$owner}/{$repo}/releases?per_page=100";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
        if ($token) {
            $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_user_releases_all(string $user, int $ttl = 3600, ?string $token = null): int
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
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
            if ($token) {
                $opts['http']['header'] .= "Authorization: token $token\r\n";
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
                $sum += gh_repo_releases($r['owner']['login'], $r['name'], $ttl, $token);
            }
            $page++;
        } while (count($repos) === 100);
        gh_cache_set($cacheKey, $sum);
        return $sum;
    }
}
// ------------------- Gists Info Function (Core) -------------------
if (!function_exists('gh_user_gists_info')) {
    function gh_user_gists_info(string $user, int $ttl = 3600, ?string $token = null): array
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
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
            if ($token) {
                $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_gist_forks_count(string $gistId, int $ttl = 300, ?string $token = null): int
    {
        $cacheKey = "gist_forks_{$gistId}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $url = "https://api.github.com/gists/{$gistId}/forks";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
        if ($token) {
            $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_repo_lines(string $owner, string $repo, int $ttl = 300, ?string $token = null): array
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
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
            if ($token) {
                $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_repo_milestones(string $owner, string $repo, int $ttl = 300, ?string $token = null): array
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
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
        if ($token) {
            $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_repo_milestones_robust(string $owner, string $repo, int $ttl = 300, ?string $token = null, string $state = 'all'): int
    {
        $cacheKey = "milestones_{$owner}_{$repo}_{$state}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }
        $url = "https://api.github.com/repos/{$owner}/{$repo}/milestones?state={$state}&per_page=100";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
        if ($token) {
            $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_user_milestones_all(string $user, int $ttl = 3600, ?string $token = null, string $state = 'all'): int
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
            $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
            if ($token) {
                $opts['http']['header'] .= "Authorization: token $token\r\n";
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
                $sum += gh_repo_milestones_robust($r['owner']['login'], $r['name'], $ttl, $token, $state);
            }
            $page++;
        } while (count($repos) === 100);
        gh_cache_set($cacheKey, $sum);
        return $sum;
    }
}
// ------------------- Version comparison function -------------------
if (!function_exists('gh_repo_compare_version')) {
    function gh_repo_compare_version(string $owner, string $repo, string $current_version, int $ttl = 300, ?string $token = null): array
    {
        $cacheKey = "version_check_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return $cached;
        }
        $url = "https://api.github.com/repos/{$owner}/{$repo}/releases/latest";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
        if ($token) {
            $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_push_info(string $owner, ?string $repo = null, int $ttl = 300, ?string $token = null): array
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
        if ($token) {
            $opts['http']['header'] .= "Authorization: token $token\r\n";
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
            $repos = gh_user_public_repos($owner, $ttl, $token);
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
    function gh_commit_info(string $owner, ?string $repo = null, int $ttl = 300, ?string $token = null): array
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
        if ($token) {
            $opts['http']['header'] .= "Authorization: token $token\r\n";
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
            $repos = gh_user_public_repos($owner, $ttl, $token);
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
    function gh_all_commit_info(string $owner, int $ttl = 300, ?string $token = null): array
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

        $repos = gh_user_public_repos($owner, $ttl, $token);
        $latestCommit = null;
        $latestCommitTime = 0;
        $opts = ["http" => ["header" => "User-Agent: MiniBadges\r\n"]];
        if ($token) {
            $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_repo_issues_open(string $owner, string $repo, int $ttl = 3600, ?string $token = null): int
    {
        $cacheKey = "new_robust_issues_open_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }

        $url = "https://api.github.com/repos/{$owner}/{$repo}";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
        if ($token) {
            $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_repo_issues_closed(string $owner, string $repo, int $ttl = 3600, ?string $token = null): int
    {
        $cacheKey = "new_robust_issues_closed_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }

        // Retrieve all closed items, including pull requests
        $url = "https://api.github.com/repos/{$owner}/{$repo}/issues?state=closed&per_page=100";
        $opts = ["http" => ["header" => "User-Agent: MiniBadges/1.0\r\n"]];
        if ($token) {
            $opts['http']['header'] .= "Authorization: token $token\r\n";
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
    function gh_user_sponsors_count(string $username, int $ttl = 3600, ?string $token = null): int
    {
        $cacheKey = "gh_sponsors_count_{$username}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return (int)$cached;
        }

        if (!$token) {
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
            'Authorization: bearer ' . $token,
            'User-Agent: MiniBadges/1.0'
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
     * @param string|null $token GitHub Token
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
    function gh_repo_discussions(string $owner, string $repo, int $ttl = 3600, ?string $token = null): array
    {
        $cacheKey = "gh_discussions_{$owner}_{$repo}";
        $cached = gh_cache_get($cacheKey, $ttl);
        if ($cached !== null) {
            return $cached;
        }

        if (!$token) {
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
            'Authorization: bearer ' . $token,
            'User-Agent: MiniBadges/1.0'
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
