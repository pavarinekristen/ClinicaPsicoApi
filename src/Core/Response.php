<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function applyCors(Request $request): void
    {
        // Fail-closed: sem CORS_ALLOWED_ORIGINS configurado, nenhuma origem cross-site e liberada.
        // Nunca emite "*" — apenas reflete origens que estao explicitamente na allowlist.
        $configured = Env::get('CORS_ALLOWED_ORIGINS', '') ?? '';
        $allowed = array_filter(array_map('trim', explode(',', $configured)));
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if ($origin !== '' && in_array($origin, $allowed, true)) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 86400');
    }

    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(array $data = []): never
    {
        self::json(['ok' => true, 'data' => $data]);
    }

    public static function error(string $message, int $status = 400, array $details = []): never
    {
        self::json(['ok' => false, 'error' => ['message' => $message, 'details' => $details]], $status);
    }

    public static function noContent(): never
    {
        http_response_code(204);
        exit;
    }
}