<?php

declare(strict_types=1);

// Serve the EasyCatasto favicon from this app's origin because some browsers
// do not reliably show the direct cross-origin <link rel="icon"> reference.

function analyticspro_favicon_fetch(string $url): ?array
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 4,
            'follow_location' => 1,
            'ignore_errors' => true,
            'user_agent' => 'AnalyticsPRO favicon proxy',
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false || $body === '') {
        return null;
    }

    $headers = $http_response_header ?? [];
    $statusLine = is_array($headers) && isset($headers[0]) ? (string) $headers[0] : '';
    if ($statusLine !== '' && preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
        $statusCode = (int) $matches[1];
        if ($statusCode >= 400) {
            return null;
        }
    }

    $contentType = 'image/x-icon';
    foreach ($headers as $header) {
        if (stripos((string) $header, 'Content-Type:') === 0) {
            $contentType = trim((string) substr((string) $header, 13));
            break;
        }
    }

    $contentType = analyticspro_favicon_safe_content_type($contentType);
    if ($contentType === null) {
        return null;
    }

    return [
        'body' => $body,
        'content_type' => $contentType,
    ];
}

function analyticspro_favicon_safe_content_type(string $contentType): ?string
{
    $mime = strtolower(trim(strtok($contentType, ';') ?: ''));
    $allowed = [
        'image/x-icon',
        'image/vnd.microsoft.icon',
        'image/ico',
        'image/icon',
        'image/png',
        'image/svg+xml',
        'image/jpeg',
        'image/gif',
        'image/webp',
    ];
    return in_array($mime, $allowed, true) ? $mime : null;
}

function analyticspro_favicon_cache_dir(): string
{
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'analyticspro-favicon-cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function analyticspro_favicon_cache_paths(): array
{
    $dir = analyticspro_favicon_cache_dir();
    return [
        'cache' => $dir . DIRECTORY_SEPARATOR . 'easycatasto-favicon.json',
    ];
}

function analyticspro_favicon_read_cache(?int $maxAge = null): ?array
{
    $paths = analyticspro_favicon_cache_paths();
    if (!is_file($paths['cache'])) {
        return null;
    }

    $meta = json_decode((string) @file_get_contents($paths['cache']), true);
    if (!is_array($meta) || empty($meta['cached_at']) || empty($meta['content_type']) || empty($meta['body_base64'])) {
        return null;
    }
    if ($maxAge !== null && ((int) $meta['cached_at'] + $maxAge) < time()) {
        return null;
    }

    $body = base64_decode((string) $meta['body_base64'], true);
    if ($body === false || $body === '') {
        return null;
    }

    $contentType = analyticspro_favicon_safe_content_type((string) $meta['content_type']);
    if ($contentType === null) {
        return null;
    }

    return [
        'body' => $body,
        'content_type' => $contentType,
        'cached_at' => (int) $meta['cached_at'],
    ];
}

function analyticspro_favicon_write_cache(array $icon): void
{
    if (($icon['body'] ?? '') === '' || ($icon['content_type'] ?? '') === '') {
        return;
    }

    $paths = analyticspro_favicon_cache_paths();
    $payload = json_encode([
        'content_type' => (string) $icon['content_type'],
        'cached_at' => time(),
        'body_base64' => base64_encode((string) $icon['body']),
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload) || $payload === '') {
        return;
    }

    $tmpFile = tempnam(analyticspro_favicon_cache_dir(), 'fav');
    if ($tmpFile === false) {
        return;
    }
    if (@file_put_contents($tmpFile, $payload, LOCK_EX) === false) {
        @unlink($tmpFile);
        return;
    }
    @chmod($tmpFile, 0664);
    @rename($tmpFile, $paths['cache']);
}

function analyticspro_favicon_output(array $icon, int $maxAge): never
{
    $contentType = analyticspro_favicon_safe_content_type((string) ($icon['content_type'] ?? '')) ?: 'image/x-icon';
    header('Content-Type: ' . $contentType);
    header('Cache-Control: public, max-age=' . $maxAge);
    header('Vary: Accept');
    echo $icon['body'];
    exit;
}

function analyticspro_favicon_absolute_url(string $baseUrl, string $href): string
{
    $href = trim($href);
    if ($href === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $href)) {
        return $href;
    }
    if (strpos($href, '//') === 0) {
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        return $scheme . ':' . $href;
    }

    $parts = parse_url($baseUrl);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return $href;
    }
    $origin = $parts['scheme'] . '://' . $parts['host'] . (!empty($parts['port']) ? ':' . $parts['port'] : '');
    if (strpos($href, '/') === 0) {
        return $origin . $href;
    }

    $path = $parts['path'] ?? '/';
    $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
    return $origin . ($dir !== '' ? $dir : '') . '/' . ltrim($href, '/');
}

function analyticspro_favicon_from_homepage(string $homepageUrl): ?string
{
    $homepage = analyticspro_favicon_fetch($homepageUrl);
    if ($homepage === null) {
        return null;
    }

    if (!preg_match_all('/<link\b[^>]*>/i', $homepage['body'], $matches)) {
        return null;
    }

    foreach ($matches[0] as $tag) {
        if (!preg_match('/\brel=["\']?([^"\'>]+)["\']?/i', $tag, $relMatch) || !preg_match('/\bhref=["\']?([^"\'>]+)["\']?/i', $tag, $hrefMatch)) {
            continue;
        }
        $rel = strtolower(trim((string) ($relMatch[1] ?? '')));
        if (strpos($rel, 'icon') === false) {
            continue;
        }
        $candidate = analyticspro_favicon_absolute_url($homepageUrl, (string) ($hrefMatch[1] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return null;
}

$homepageUrl = 'https://app.easycatasto.it/';
$cacheMaxAge = 86400;

if ($cachedIcon = analyticspro_favicon_read_cache($cacheMaxAge)) {
    analyticspro_favicon_output($cachedIcon, $cacheMaxAge);
}

$candidates = array_values(array_unique(array_filter([
    analyticspro_favicon_from_homepage($homepageUrl),
    'https://app.easycatasto.it/favicon.ico',
])));

foreach ($candidates as $candidateUrl) {
    $icon = analyticspro_favicon_fetch($candidateUrl);
    if ($icon === null) {
        continue;
    }
    analyticspro_favicon_write_cache($icon);
    analyticspro_favicon_output($icon, $cacheMaxAge);
}

if ($staleIcon = analyticspro_favicon_read_cache()) {
    analyticspro_favicon_output($staleIcon, 3600);
}

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('Vary: Accept');
echo <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" role="img" aria-label="EasyCatasto">
  <rect width="64" height="64" rx="12" fill="#2A519F"/>
  <path d="M18 45V26l14-9 14 9v19h-8V31H26v14z" fill="#ffffff"/>
  <path d="M32 17l18 11v5h-4v10h-6V29H24v14h-6V33h-4v-5z" fill="#f28e0e"/>
</svg>
SVG;
