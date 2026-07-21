<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\AppException;
use PDO;
use PDOException;

final class ArticleRepository
{
    private const FALLBACK_IMAGE = 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=1200&q=80';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function published(): array
    {
        $stmt = $this->pdo->query(
            "SELECT a.*, s.name AS joined_source_name
             FROM articles a
             LEFT JOIN article_sources s ON s.id = a.source_id
             WHERE a.status = 'published'
             ORDER BY a.published_at DESC, a.created_at DESC"
        );

        return array_map(fn (array $row): array => $this->mapArticle($row), $stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function publishedBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.*, s.name AS joined_source_name
             FROM articles a
             LEFT JOIN article_sources s ON s.id = a.source_id
             WHERE a.status = 'published'
               AND a.slug = :slug
             LIMIT 1"
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->mapArticle($row) : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function publishedAndDrafts(): array
    {
        $stmt = $this->pdo->query(
            "SELECT a.*, s.name AS joined_source_name
             FROM articles a
             LEFT JOIN article_sources s ON s.id = a.source_id
             WHERE a.status IN ('draft', 'published')
             ORDER BY a.created_at DESC"
        );

        return array_map(fn (array $row): array => $this->mapArticle($row), $stmt->fetchAll());
    }

    /** @return array<int, array<string, mixed>> */
    public function pending(): array
    {
        $stmt = $this->pdo->query(
            "SELECT a.*, s.name AS joined_source_name
             FROM articles a
             LEFT JOIN article_sources s ON s.id = a.source_id
             WHERE a.status = 'pending_review'
             ORDER BY a.created_at DESC"
        );

        return array_map(fn (array $row): array => $this->mapArticle($row), $stmt->fetchAll());
    }

    /** @return array<int, array<string, mixed>> */
    public function sources(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM article_sources ORDER BY active DESC, created_at DESC'
        );

        return array_map(fn (array $row): array => $this->mapSource($row), $stmt->fetchAll());
    }

    /** @return array<int, array<string, mixed>> */
    public function activeSources(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM article_sources WHERE active = 1 ORDER BY created_at DESC'
        );

        return array_map(fn (array $row): array => $this->mapSource($row), $stmt->fetchAll());
    }

    /** @param array<int, string> $tags @return array<string, mixed> */
    public function createManual(string $title, string $summary, string $content, string $category, array $tags, ?string $imageUrl): array
    {
        $publicId = $this->publicId();
        $slug = $this->uniqueSlug($this->slugify($title));
        $stmt = $this->pdo->prepare(
            "INSERT INTO articles
               (public_id, title, slug, summary, content, category, tags, origin, status, image_url, published_at, reading_minutes, is_indexable)
             VALUES
               (:public_id, :title, :slug, :summary, :content, :category, :tags, 'manual', 'published', :image_url, UTC_TIMESTAMP(), :reading_minutes, 1)"
        );
        $stmt->execute([
            'public_id' => $publicId,
            'title' => $title,
            'slug' => $slug,
            'summary' => $summary,
            'content' => $content,
            'category' => $category,
            'tags' => $tags === [] ? null : json_encode($tags, JSON_UNESCAPED_UNICODE),
            'image_url' => $imageUrl,
            'reading_minutes' => $this->readingMinutes($content),
        ]);

        return $this->articleByPublicId($publicId);
    }

    /** @param array<int, string> $topics @return array<string, mixed> */
    public function createSource(string $name, string $url, string $type, array $topics, bool $active): array
    {
        $publicId = $this->publicId();

        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO article_sources (public_id, name, url, type, topics, active)
                 VALUES (:public_id, :name, :url, :type, :topics, :active)"
            );
            $stmt->execute([
                'public_id' => $publicId,
                'name' => $name,
                'url' => $url,
                'type' => $type,
                'topics' => $topics === [] ? null : json_encode($topics, JSON_UNESCAPED_UNICODE),
                'active' => $active ? 1 : 0,
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new AppException('Esta fonte ja esta cadastrada.', 409);
            }
            throw $exception;
        }

        return $this->sourceByPublicId($publicId);
    }

    /** @param array<int, string> $tags @return array<string, mixed>|null */
    public function insertExternalCandidate(
        string $sourcePublicId,
        string $title,
        string $summary,
        string $sourceUrl,
        ?string $imageUrl,
        string $category,
        array $tags,
        ?string $externalPublishedAt
    ): ?array {
        $sourceId = $this->internalSourceId($sourcePublicId);
        if ($sourceId === null) {
            return null;
        }

        $publicId = $this->publicId();
        $content = $summary . "\n\nLeia o conteúdo completo na fonte original.";

        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO articles
                   (public_id, title, slug, summary, content, category, tags, origin, status, source_id, source_name, source_url, image_url, external_published_at, reading_minutes, is_indexable)
                 SELECT
                   :public_id, :title, :slug, :summary, :content, :category, :tags, 'external_curated', 'pending_review', s.id, s.name, :source_url, :image_url, :external_published_at, 2, 0
                 FROM article_sources s
                 WHERE s.id = :source_id"
            );
            $stmt->execute([
                'public_id' => $publicId,
                'title' => $title,
                'slug' => $this->uniqueSlug($this->slugify($title)),
                'summary' => $summary,
                'content' => $content,
                'category' => $category,
                'tags' => $tags === [] ? null : json_encode($tags, JSON_UNESCAPED_UNICODE),
                'source_url' => $sourceUrl,
                'image_url' => $imageUrl,
                'external_published_at' => $externalPublishedAt,
                'source_id' => $sourceId,
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return null;
            }
            throw $exception;
        }

        return $this->articleByPublicId($publicId);
    }

    public function markSourceChecked(string $sourcePublicId): void
    {
        $stmt = $this->pdo->prepare('UPDATE article_sources SET last_checked_at = UTC_TIMESTAMP() WHERE public_id = :id');
        $stmt->execute(['id' => $sourcePublicId]);
    }

    /** @return array<string, mixed> */
    public function approve(string $publicId): array
    {
        $stmt = $this->pdo->prepare(
            "UPDATE articles
             SET status = 'published',
                 published_at = COALESCE(published_at, UTC_TIMESTAMP()),
                 updated_at = UTC_TIMESTAMP()
             WHERE public_id = :id
               AND status = 'pending_review'"
        );
        $stmt->execute(['id' => $publicId]);

        if ($stmt->rowCount() === 0) {
            throw new AppException('Curadoria nao encontrada ou ja revisada.', 404);
        }

        return $this->articleByPublicId($publicId);
    }

    public function reject(string $publicId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE articles
             SET status = 'rejected',
                 updated_at = UTC_TIMESTAMP()
             WHERE public_id = :id
               AND status = 'pending_review'"
        );
        $stmt->execute(['id' => $publicId]);

        if ($stmt->rowCount() === 0) {
            throw new AppException('Curadoria nao encontrada ou ja revisada.', 404);
        }
    }

    /** @return array<string, mixed> */
    private function articleByPublicId(string $publicId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.*, s.name AS joined_source_name
             FROM articles a
             LEFT JOIN article_sources s ON s.id = a.source_id
             WHERE a.public_id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $publicId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new AppException('Artigo nao encontrado.', 404);
        }

        return $this->mapArticle($row);
    }

    /** @return array<string, mixed> */
    private function sourceByPublicId(string $publicId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM article_sources WHERE public_id = :id LIMIT 1');
        $stmt->execute(['id' => $publicId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new AppException('Fonte nao encontrada.', 404);
        }

        return $this->mapSource($row);
    }

    private function internalSourceId(string $publicId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM article_sources WHERE public_id = :id LIMIT 1');
        $stmt->execute(['id' => $publicId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base !== '' ? $base : 'artigo';
        $candidate = $slug;
        $suffix = 2;
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM articles WHERE slug = :slug');

        while (true) {
            $stmt->execute(['slug' => $candidate]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $candidate;
            }
            $candidate = $slug . '-' . $suffix;
            $suffix++;
        }
    }

    private function slugify(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    private function readingMinutes(string $content): int
    {
        $words = preg_split('/\s+/', trim(strip_tags($content))) ?: [];
        return max(1, (int) ceil(count(array_filter($words)) / 180));
    }

    private function publicId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mapArticle(array $row): array
    {
        return [
            'id' => (string) $row['public_id'],
            'title' => (string) $row['title'],
            'slug' => (string) $row['slug'],
            'summary' => (string) $row['summary'],
            'content' => (string) $row['content'],
            'category' => (string) $row['category'],
            'tags' => $this->decodeList($row['tags'] ?? null),
            'origin' => (string) $row['origin'],
            'status' => (string) $row['status'],
            'sourceName' => $row['source_name'] ?: ($row['joined_source_name'] ?? null),
            'sourceUrl' => $row['source_url'] ?? null,
            'imageUrl' => $row['image_url'] ?: self::FALLBACK_IMAGE,
            'publishedAt' => $this->dateOnly($row['published_at'] ?? $row['created_at'] ?? null),
            'readingMinutes' => (int) $row['reading_minutes'],
            'isIndexable' => (bool) $row['is_indexable'],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mapSource(array $row): array
    {
        return [
            'id' => (string) $row['public_id'],
            'name' => (string) $row['name'],
            'url' => (string) $row['url'],
            'type' => (string) $row['type'],
            'active' => (bool) $row['active'],
            'topics' => $this->decodeList($row['topics'] ?? null),
            'lastCheckedAt' => $row['last_checked_at'] ? (string) $row['last_checked_at'] : 'Ainda nao verificado',
        ];
    }

    /** @return array<int, string> */
    private function decodeList(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    private function dateOnly(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return gmdate('Y-m-d');
        }

        return substr($value, 0, 10);
    }
}
