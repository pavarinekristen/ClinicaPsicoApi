<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\ArticleRepository;
use App\Repositories\AuditRepository;
use App\Services\ArticleImportService;
use App\Services\AuthService;

final class AdminArticleController
{
    private const MAX_AUTH_ATTEMPTS = 10;
    private const AUTH_WINDOW_SECONDS = 900;

    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly ArticleImportService $importer,
        private readonly AuthService $auth,
        private readonly AuditRepository $audit
    ) {
    }

    public function index(Request $request): never
    {
        $this->authorize($request);

        Response::ok([
            'articles' => $this->articles->publishedAndDrafts(),
            'pending' => $this->articles->pending(),
            'sources' => $this->articles->sources(),
        ]);
    }

    public function create(Request $request): never
    {
        $user = $this->authorize($request);

        $title = Validator::requiredString($request->input('title'), 'title', 220);
        $summary = Validator::requiredString($request->input('summary'), 'summary', 2000);
        $content = Validator::requiredString($request->input('content'), 'content', 60000);
        $category = Validator::requiredString($request->input('category'), 'category', 120);
        $tags = $this->stringList($request->input('tags'));
        $imageUrl = Validator::optionalString($request->input('image_url'), 'image_url', 500);

        $article = $this->articles->createManual($title, $summary, $content, $category, $tags, $imageUrl);
        $this->audit->record($user, 'artigo_autoral_criado', 'article', $article['id'], ['title' => $title], $request->ip());

        Response::ok(['article' => $article]);
    }

    public function approve(Request $request): never
    {
        $user = $this->authorize($request);
        $id = Validator::requiredString($request->input('article_id'), 'article_id', 36);

        $article = $this->articles->approve($id);
        $this->audit->record($user, 'curadoria_aprovada', 'article', $id, [], $request->ip());
        Response::ok(['article' => $article]);
    }

    public function reject(Request $request): never
    {
        $user = $this->authorize($request);
        $id = Validator::requiredString($request->input('article_id'), 'article_id', 36);

        $this->articles->reject($id);
        $this->audit->record($user, 'curadoria_rejeitada', 'article', $id, [], $request->ip());
        Response::ok(['rejected' => true]);
    }

    public function createSource(Request $request): never
    {
        $user = $this->authorize($request);

        $name = Validator::requiredString($request->input('name'), 'name', 160);
        $url = Validator::requiredString($request->input('url'), 'url', 500);
        $type = Validator::requiredString($request->input('type'), 'type', 20);
        $active = (bool) $request->input('active', true);
        $topics = $this->stringList($request->input('topics'));

        if (!in_array($type, ['rss', 'api', 'scraping'], true)) {
            throw new AppException('Tipo de fonte invalido.', 422);
        }

        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new AppException('URL da fonte invalida.', 422);
        }

        $source = $this->articles->createSource($name, $url, $type, $topics, $active);
        $this->audit->record($user, 'fonte_artigos_criada', 'article_source', $source['id'], ['url' => $url, 'type' => $type], $request->ip());

        Response::ok(['source' => $source]);
    }

    public function import(Request $request): never
    {
        $user = $this->authorize($request);
        $result = $this->importer->import();
        $this->audit->record($user, 'importacao_artigos_externos', 'article', null, $result, $request->ip());

        Response::ok($result);
    }

    private function authorize(Request $request): string
    {
        $limiter = new RateLimiter(dirname(__DIR__, 2) . '/storage/cache/ratelimit');
        $ip = $request->ip();
        $key = 'admin-auth:' . ($ip !== '' ? $ip : 'unknown');

        if ($limiter->tooManyAttempts($key, self::MAX_AUTH_ATTEMPTS, self::AUTH_WINDOW_SECONDS)) {
            throw new AppException('Muitas tentativas de acesso. Aguarde alguns minutos e tente de novo.', 429);
        }

        $username = $this->auth->verify($this->bearerToken($request));

        if ($username === null) {
            $limiter->hit($key, self::AUTH_WINDOW_SECONDS);
            usleep(350000);
            throw new AppException('Nao autorizado.', 401);
        }

        $limiter->clear($key);
        return $username;
    }

    private function bearerToken(Request $request): ?string
    {
        $header = $request->header('authorization');

        if ($header === null || !preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }

    /** @return array<int, string> */
    private function stringList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            return $this->cleanList(explode(',', $value));
        }

        if (!is_array($value)) {
            throw new AppException('Lista invalida.', 422);
        }

        return $this->cleanList($value);
    }

    /** @param array<int, mixed> $items @return array<int, string> */
    private function cleanList(array $items): array
    {
        $clean = [];
        foreach ($items as $item) {
            if (!is_string($item)) {
                throw new AppException('Lista invalida.', 422);
            }
            $item = trim($item);
            if ($item !== '') {
                $clean[] = mb_substr($item, 0, 80);
            }
        }

        return array_values(array_unique($clean));
    }
}
