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
        Response::ok(['articles' => $this->articles->published()]);
    }

    public function show(Request $request): never
    {
        $slug = Validator::requiredString($request->query('slug'), 'slug', 240);

        Response::ok([
            'article' => $this->articles->publishedBySlug($slug),
        ]);
    }
}
