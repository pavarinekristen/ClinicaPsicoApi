<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Rate limiter simples baseado em arquivo (sem dependencias externas).
 * Cada chave (ex.: IP) tem um contador de falhas dentro de uma janela deslizante.
 * Degrada com seguranca: se o storage falhar, nao bloqueia (mantem disponibilidade).
 */
final class RateLimiter
{
    public function __construct(private readonly string $directory)
    {
    }

    /** Retorna true se a chave ja excedeu o limite dentro da janela atual. */
    public function tooManyAttempts(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return false;
        }

        $state = json_decode((string) @file_get_contents($path), true);

        if (!is_array($state) || time() > ($state['reset'] ?? 0)) {
            return false;
        }

        return (int) ($state['attempts'] ?? 0) >= $maxAttempts;
    }

    /** Registra uma tentativa falha e retorna o total acumulado na janela. */
    public function hit(string $key, int $windowSeconds): int
    {
        $this->ensureDir();
        $handle = @fopen($this->path($key), 'c+');

        if ($handle === false) {
            return 0; // storage indisponivel: nao bloqueia
        }

        try {
            @flock($handle, LOCK_EX);
            $raw = stream_get_contents($handle);
            $state = json_decode((string) $raw, true);
            $now = time();

            if (!is_array($state) || $now > ($state['reset'] ?? 0)) {
                $state = ['attempts' => 0, 'reset' => $now + $windowSeconds];
            }

            $state['attempts'] = (int) $state['attempts'] + 1;

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($state));
            fflush($handle);

            return (int) $state['attempts'];
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /** Limpa o contador (ex.: apos autenticacao bem-sucedida). */
    public function clear(string $key): void
    {
        $path = $this->path($key);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function path(string $key): string
    {
        return rtrim($this->directory, '/\\') . '/' . sha1($key) . '.json';
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0700, true);
        }
    }
}
