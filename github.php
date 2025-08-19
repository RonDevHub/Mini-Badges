<?php
function gh_cache_path(string $key): string {
    return __DIR__ . '/cache/' . preg_replace('~[^a-zA-Z0-9_.-]~', '_', $key) . '.json';
}

function gh_cached_get(string $url, int $ttl, ?string $token = null): ?array {
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

function gh_repo_info(string $owner, string $repo, int $ttl, ?string $token): ?array {
    $url = "https://api.github.com/repos/$owner/$repo";
    return gh_cached_get($url, $ttl, $token);
}

function gh_repo_release(string $owner, string $repo, int $ttl, ?string $token): ?array {
    $url = "https://api.github.com/repos/$owner/$repo/releases/latest";
    return gh_cached_get($url, $ttl, $token);
}

function gh_repo_languages(string $owner, string $repo, int $ttl, ?string $token): ?array {
    $url = "https://api.github.com/repos/$owner/$repo/languages";
    return gh_cached_get($url, $ttl, $token);
}

function gh_top_language(string $owner, string $repo, int $ttl, ?string $token): ?string {
    $langs = gh_repo_languages($owner, $repo, $ttl, $token);
    if (!$langs || !is_array($langs) || empty($langs)) return null;
    arsort($langs);
    foreach ($langs as $name => $bytes) {
        return $name;
    }
    return null;
}
