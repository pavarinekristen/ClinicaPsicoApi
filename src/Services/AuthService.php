<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppException;
use App\Core\Env;

/**
 * Autenticacao do painel administrativo.
 *
 * Credencial unica (dona do sistema) guardada no .env como ADMIN_USERNAME + ADMIN_PASSWORD_HASH
 * (hash bcrypt via password_hash). Apos o login, emite um token assinado (HMAC) e sem estado:
 * nao ha tabela de sessao. O token carrega o usuario e a expiracao, protegidos por APP_KEY.
 */
final class AuthService
{
    private const TOKEN_TTL_SECONDS = 43200; // 12 horas

    /**
     * Valida usuario/senha e devolve um token de sessao assinado.
     *
     * @return array{token: string, username: string, expires_at: string}
     */
    public function attempt(string $username, string $password): array
    {
        $expectedUser = (string) Env::get('ADMIN_USERNAME', '');
        $expectedHash = (string) Env::get('ADMIN_PASSWORD_HASH', '');

        if ($expectedUser === '' || $expectedHash === '') {
            throw new AppException('Login administrativo nao configurado no servidor.', 500);
        }

        // Sempre roda password_verify para nao vazar por timing se o usuario existe.
        $userOk = hash_equals($expectedUser, $username);
        $passOk = password_verify($password, $expectedHash);

        if (!$userOk || !$passOk) {
            throw new AppException('Usuario ou senha invalidos.', 401);
        }

        $expiresAt = time() + self::TOKEN_TTL_SECONDS;

        return [
            'token' => $this->issue($username, $expiresAt),
            'username' => $username,
            'expires_at' => gmdate('c', $expiresAt),
        ];
    }

    /** Verifica um token de sessao. Retorna o username, ou null se invalido/expirado. */
    public function verify(?string $token): ?string
    {
        if ($token === null || $token === '') {
            return null;
        }

        $secret = (string) Env::get('APP_KEY', '');
        if ($secret === '') {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$payloadB64, $signature] = $parts;
        $expected = hash_hmac('sha256', $payloadB64, $secret);

        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $payload = json_decode($this->base64UrlDecode($payloadB64), true);

        if (!is_array($payload) || !isset($payload['u'], $payload['exp'])) {
            return null;
        }

        if (time() > (int) $payload['exp']) {
            return null;
        }

        return (string) $payload['u'];
    }

    private function issue(string $username, int $expiresAt): string
    {
        $secret = (string) Env::get('APP_KEY', '');
        if ($secret === '') {
            throw new AppException('APP_KEY nao configurada no servidor.', 500);
        }

        $payloadB64 = $this->base64UrlEncode((string) json_encode(['u' => $username, 'exp' => $expiresAt]));
        $signature = hash_hmac('sha256', $payloadB64, $secret);

        return $payloadB64 . '.' . $signature;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
