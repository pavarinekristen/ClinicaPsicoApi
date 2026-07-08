<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<string, callable> */
    private array $routes = [];

    public function __construct(private readonly string $basePath = '')
    {
    }

    public function get(string $path, callable $handler): void
    {
        $this->routes["GET {$path}"] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes["POST {$path}"] = $handler;
    }

    public function dispatch(Request $request): never
    {
        $path = $this->normalizePath($request->path());
        $key = $request->method() . " {$path}";

        if (!isset($this->routes[$key])) {
            throw new AppException('Rota nao encontrada.', 404, ['path' => $path]);
        }

        ($this->routes[$key])($request);
        exit;
    }

    private function normalizePath(string $path): string
    {
        $basePath = rtrim($this->basePath, '/');

        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }

        return '/' . trim($path, '/');
    }
}