<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\AuditRepository;
use App\Services\AuthService;

final class AuthController
{
    private const MAX_LOGIN_ATTEMPTS = 10;
    private const LOGIN_WINDOW_SECONDS = 900; // 15 minutos

    public function __construct(private readonly AuthService $auth, private readonly AuditRepository $audit)
    {
    }

    public function login(Request $request): never
    {
        $limiter = new RateLimiter(dirname(__DIR__, 2) . '/storage/cache/ratelimit');
        $ip = $request->ip();
        $key = 'admin-login:' . ($ip !== '' ? $ip : 'unknown');

        if ($limiter->tooManyAttempts($key, self::MAX_LOGIN_ATTEMPTS, self::LOGIN_WINDOW_SECONDS)) {
            throw new AppException('Muitas tentativas de login. Aguarde alguns minutos e tente de novo.', 429);
        }

        $username = Validator::requiredString($request->input('username'), 'username', 120);
        $password = Validator::requiredString($request->input('password'), 'password', 200);

        try {
            $result = $this->auth->attempt($username, $password);
        } catch (AppException $exception) {
            // Penaliza e registra apenas credencial invalida (401); erro de config (500) nao conta.
            if ($exception->statusCode() === 401) {
                $limiter->hit($key, self::LOGIN_WINDOW_SECONDS);
                usleep(350000);
                $this->audit->record($username, 'login_failed', 'auth', null, [], $ip);
            }

            throw $exception;
        }

        $limiter->clear($key);
        $this->audit->record($username, 'login_success', 'auth', null, [], $ip);

        Response::ok($result);
    }
}
