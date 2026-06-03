<?php

declare(strict_types=1);

namespace Esse;

class GitHubApi
{
    private static function token(): string
    {
        if (!class_exists('Esse\DB') || !defined('ESSE_DB_NAME')) return '';
        $ts  = DB::table('settings');
        $enc = DB::value("SELECT `value` FROM `{$ts}` WHERE `key` = 'github_token'") ?? '';
        return $enc ? Crypto::decrypt($enc) : '';
    }

    private static function request(string $url): ?array
    {
        $token = self::token();

        $ch = curl_init($url);
        $headers = [
            'User-Agent: ESSE-CMS/' . (defined('ESSE_VERSION') ? \ESSE_VERSION : '0'),
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        if ($token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT        => 10,
            \CURLOPT_HTTPHEADER     => $headers,
            \CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $json = curl_exec($ch);
        $code = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);

        if (!$json || $code < 200 || $code >= 300) return null;
        return json_decode($json, true);
    }

    // Search repos by topic and optionally by owner
    public static function searchPlugins(string $owner, bool $trustedOnly = false): array
    {
        $query = urlencode("topic:esse-plugin user:{$owner}");
        $data  = self::request("https://api.github.com/search/repositories?q={$query}&per_page=50&sort=updated");

        if (!$data || empty($data['items'])) return [];

        return array_map(function($item) use ($trustedOnly) {
            return [
                'name'        => $item['name'],
                'full_name'   => $item['full_name'],
                'description' => $item['description'] ?? '',
                'stars'       => $item['stargazers_count'] ?? 0,
                'updated_at'  => $item['pushed_at'] ?? '',
                'html_url'    => $item['html_url'],
                'trusted'     => $trustedOnly,
            ];
        }, $data['items']);
    }

    // Get latest release info for a repo
    public static function latestRelease(string $fullName): ?array
    {
        $releases = self::request("https://api.github.com/repos/{$fullName}/releases");
        if (!$releases || empty($releases[0])) return null;

        $r = $releases[0];
        return [
            'version'      => ltrim($r['tag_name'], 'v'),
            'tag'          => $r['tag_name'],
            'download_url' => $r['zipball_url'] ?? '',
            'published_at' => $r['published_at'] ?? '',
            'prerelease'   => $r['prerelease'] ?? false,
        ];
    }

    // Get rate limit info
    public static function rateLimit(): array
    {
        $data = self::request('https://api.github.com/rate_limit');
        if (!$data) return ['limit' => 0, 'remaining' => 0, 'reset' => 0];

        $core = $data['resources']['core'] ?? [];
        $search = $data['resources']['search'] ?? [];
        return [
            'core_limit'       => $core['limit'] ?? 0,
            'core_remaining'   => $core['remaining'] ?? 0,
            'search_limit'     => $search['limit'] ?? 0,
            'search_remaining' => $search['remaining'] ?? 0,
        ];
    }
}
