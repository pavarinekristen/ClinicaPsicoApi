<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\ArticleRepository;

final class ArticleController
{
    public function __construct(private readonly ArticleRepository $articles)
    {
    }

    public function index(Request $request): never
    {
        $featuredOnly = $request->query('featured') === '1';
        $limit = $this->positiveInt($request->query('limit'), 60, 1, 60);

        Response::ok(['articles' => $this->articles->published($featuredOnly, $limit)]);
    }

    public function show(Request $request): never
    {
        $slug = Validator::requiredString($request->query('slug'), 'slug', 240);

        Response::ok([
            'article' => $this->articles->publishedBySlug($slug),
        ]);
    }

    private function positiveInt(mixed $value, int $default, int $min, int $max): int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}
