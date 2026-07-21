<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ArticleRepository;
use DateTimeImmutable;
use Throwable;

final class ArticleImportService
{
    private const DEFAULT_TERMS = [
        'psicologia',
        'saude mental',
        'terapia',
        'ansiedade',
        'depressao',
        'bem-estar',
        'bem estar',
        'bons habitos',
        'habitos saudaveis',
        'habitos alimentares',
        'alimentacao',
        'nutricao',
        'sono',
        'descanso',
        'autocuidado',
        'qualidade de vida',
        'rotina saudavel',
        'saude emocional',
        'neurodesenvolvimento',
    ];

    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly string $logPath
    ) {
    }

    /** @return array{run_id: string, sources_checked: int, items_found: int, imported: int, skipped: int, featured: int} */
    public function import(): array
    {
        $runId = $this->articles->startImportRun();
        $sources = $this->articles->activeSources();
        $sourcesChecked = 0;
        $itemsFound = 0;
        $imported = 0;
        $skipped = 0;
        $featured = 0;

        try {
            foreach ($sources as $source) {
                $sourcesChecked++;
                try {
                    $items = $this->itemsForSource($source);
                    $this->articles->reconnect();
                    $itemsFound += count($items);
                    $topics = $this->topics($source);

                    foreach ($items as $item) {
                        if (!$this->isRelevant($item, $topics)) {
                            $skipped++;
                            continue;
                        }

                        $created = $this->articles->insertExternalCandidate(
                            (string) $source['id'],
                            $item['title'],
                            $this->limit($item['summary'], 1000),
                            $item['url'],
                            $item['imageUrl'],
                            $this->category($item, $topics),
                            $this->matchedTags($item, $topics),
                            $item['publishedAt'],
                            'published'
                        );

                        if ($created === null) {
                            $skipped++;
                        } else {
                            $imported++;
                        }
                    }
                } catch (Throwable $exception) {
                    $skipped++;
                    $this->log(sprintf('source=%s error=%s', (string) ($source['url'] ?? ''), $exception->getMessage()));
                } finally {
                    $this->articles->reconnect();
                    $this->articles->markSourceChecked((string) $source['id']);
                }
            }

            $this->articles->reconnect();
            $featured = $this->articles->refreshDailyFeatured(6);
            $this->articles->reconnect();
            $this->articles->finishImportRun($runId, 'completed', $sourcesChecked, $itemsFound, $imported, $skipped, $featured);

            return [
                'run_id' => $runId,
                'sources_checked' => $sourcesChecked,
                'items_found' => $itemsFound,
                'imported' => $imported,
                'skipped' => $skipped,
                'featured' => $featured,
            ];
        } catch (Throwable $exception) {
            $this->articles->reconnect();
            $this->articles->finishImportRun(
                $runId,
                'failed',
                $sourcesChecked,
                $itemsFound,
                $imported,
                $skipped,
                $featured,
                mb_substr($exception->getMessage(), 0, 500)
            );

            throw $exception;
        }
    }

    /** @param array<string, mixed> $source @return array<int, array{title: string, summary: string, url: string, imageUrl: ?string, publishedAt: ?string}> */
    private function itemsForSource(array $source): array
    {
        $url = (string) $source['url'];
        if (!$this->allowedUrl($url)) {
            throw new \RuntimeException('URL bloqueada por seguranca.');
        }

        $body = $this->fetch($url);
        $type = (string) $source['type'];

        if ($type === 'rss') {
            return $this->parseRss($body);
        }

        if ($type === 'api') {
            return $this->parseJsonApi($body);
        }

        return $this->parseHtmlArticle($body, $url);
    }

    /** @return array<int, array{title: string, summary: string, url: string, imageUrl: ?string, publishedAt: ?string}> */
    private function parseRss(string $body): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$xml) {
            return [];
        }

        $items = [];
        $rssItems = $xml->channel->item ?? [];
        foreach ($rssItems as $item) {
            $title = trim((string) $item->title);
            $url = trim((string) $item->link);
            $summary = trim(strip_tags((string) ($item->description ?? $item->children('content', true)->encoded ?? '')));

            if ($title === '' || $url === '') {
                continue;
            }

            $items[] = [
                'title' => $this->decode($title),
                'summary' => $summary !== '' ? $this->decode($summary) : $this->decode($title),
                'url' => $url,
                'imageUrl' => $this->rssImage($item),
                'publishedAt' => $this->dateOrNull((string) ($item->pubDate ?? '')),
            ];
        }

        foreach ($xml->entry ?? [] as $entry) {
            $title = trim((string) $entry->title);
            $url = '';
            foreach ($entry->link as $link) {
                $attrs = $link->attributes();
                $href = trim((string) ($attrs['href'] ?? ''));
                if ($href !== '') {
                    $url = $href;
                    break;
                }
            }

            $summary = trim(strip_tags((string) ($entry->summary ?? $entry->content ?? '')));
            if ($title === '' || $url === '') {
                continue;
            }

            $items[] = [
                'title' => $this->decode($title),
                'summary' => $summary !== '' ? $this->decode($summary) : $this->decode($title),
                'url' => $url,
                'imageUrl' => null,
                'publishedAt' => $this->dateOrNull((string) ($entry->updated ?? $entry->published ?? '')),
            ];
        }

        return array_slice($items, 0, 20);
    }

    /** @return array<int, array{title: string, summary: string, url: string, imageUrl: ?string, publishedAt: ?string}> */
    private function parseJsonApi(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [];
        }

        $rows = $decoded['articles'] ?? $decoded['items'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            $url = trim((string) ($row['url'] ?? $row['link'] ?? ''));
            $summary = trim(strip_tags((string) ($row['description'] ?? $row['summary'] ?? $row['excerpt'] ?? $title)));
            if ($title === '' || $url === '') {
                continue;
            }

            $items[] = [
                'title' => $this->decode($title),
                'summary' => $this->decode($summary),
                'url' => $url,
                'imageUrl' => is_string($row['urlToImage'] ?? $row['image'] ?? null) ? (string) ($row['urlToImage'] ?? $row['image']) : null,
                'publishedAt' => $this->dateOrNull((string) ($row['publishedAt'] ?? $row['published_at'] ?? '')),
            ];
        }

        return array_slice($items, 0, 20);
    }

    /** @return array<int, array{title: string, summary: string, url: string, imageUrl: ?string, publishedAt: ?string}> */
    private function parseHtmlArticle(string $body, string $url): array
    {
        $title = $this->matchMeta($body, 'og:title') ?: $this->matchTitle($body);
        $summary = $this->matchMeta($body, 'description') ?: $this->matchMeta($body, 'og:description') ?: $title;
        $image = $this->matchMeta($body, 'og:image');
        $canonical = $this->matchCanonical($body) ?: $url;

        if ($title === '') {
            return [];
        }

        return [[
            'title' => $this->decode($title),
            'summary' => $this->decode($summary),
            'url' => $canonical,
            'imageUrl' => $image !== '' ? $image : null,
            'publishedAt' => null,
        ]];
    }

    private function fetch(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_USERAGENT => 'InstitutoIdeiaArticleCurator/1.0',
                CURLOPT_MAXREDIRS => 3,
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if (!is_string($body) || $body === '' || $status >= 400) {
                throw new \RuntimeException('Fonte indisponivel.');
            }

            return $body;
        }

        $context = stream_context_create([
            'http' => ['timeout' => 12, 'user_agent' => 'InstitutoIdeiaArticleCurator/1.0'],
        ]);
        $body = file_get_contents($url, false, $context);
        if (!is_string($body) || $body === '') {
            throw new \RuntimeException('Fonte indisponivel.');
        }

        return $body;
    }

    private function allowedUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($host) || !in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        if (in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }

        $ips = gethostbynamel($host) ?: [];
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $source @return array<int, string> */
    private function topics(array $source): array
    {
        $topics = $source['topics'] ?? [];
        return array_values(array_unique(array_merge(is_array($topics) ? $topics : [], self::DEFAULT_TERMS)));
    }

    /** @param array<string, mixed> $item @param array<int, string> $topics */
    private function isRelevant(array $item, array $topics): bool
    {
        $haystack = $this->normalize(($item['title'] ?? '') . ' ' . ($item['summary'] ?? ''));
        foreach ($topics as $topic) {
            if (str_contains($haystack, $this->normalize($topic))) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $item @param array<int, string> $topics @return array<int, string> */
    private function matchedTags(array $item, array $topics): array
    {
        $haystack = $this->normalize(($item['title'] ?? '') . ' ' . ($item['summary'] ?? ''));
        $tags = [];
        foreach ($topics as $topic) {
            if (str_contains($haystack, $this->normalize($topic))) {
                $tags[] = $topic;
            }
        }

        return array_slice(array_values(array_unique($tags)), 0, 6);
    }

    /** @param array<string, mixed> $item @param array<int, string> $topics */
    private function category(array $item, array $topics): string
    {
        $tags = $this->matchedTags($item, $topics);
        return $tags[0] ?? 'Psicologia e Saude';
    }

    private function normalize(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        return mb_strtolower($value);
    }

    private function limit(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 3) . '...' : $value;
    }

    private function decode(string $value): string
    {
        return html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function dateOrNull(string $value): ?string
    {
        if (trim($value) === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function rssImage(\SimpleXMLElement $item): ?string
    {
        $media = $item->children('media', true);
        if (isset($media->content)) {
            $attrs = $media->content->attributes();
            $url = (string) ($attrs['url'] ?? '');
            return $url !== '' ? $url : null;
        }
        if (isset($item->enclosure)) {
            $attrs = $item->enclosure->attributes();
            $type = (string) ($attrs['type'] ?? '');
            $url = (string) ($attrs['url'] ?? '');
            if ($url !== '' && str_starts_with($type, 'image/')) {
                return $url;
            }
        }
        return null;
    }

    private function matchMeta(string $body, string $name): string
    {
        $quoted = preg_quote($name, '/');
        if (preg_match('/<meta[^>]+(?:property|name)=["\']' . $quoted . '["\'][^>]+content=["\']([^"\']+)["\']/i', $body, $matches)) {
            return (string) $matches[1];
        }
        if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']' . $quoted . '["\']/i', $body, $matches)) {
            return (string) $matches[1];
        }
        return '';
    }

    private function matchTitle(string $body): string
    {
        return preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $matches) ? strip_tags((string) $matches[1]) : '';
    }

    private function matchCanonical(string $body): string
    {
        return preg_match('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i', $body, $matches) ? (string) $matches[1] : '';
    }

    private function log(string $line): void
    {
        @error_log('[' . gmdate('c') . '] ' . $line . "\n", 3, $this->logPath);
    }
}
