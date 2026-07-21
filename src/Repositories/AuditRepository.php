<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Trilha de auditoria: registra quem fez o que, quando e de qual IP.
 * Consulta por SQL na tabela audit_log (sem tela dedicada por enquanto).
 */
final class AuditRepository
{
    /** @var callable|null */
    private $connect;

    public function __construct(private PDO $pdo, ?callable $connect = null)
    {
        $this->connect = $connect;
    }

    public function reconnect(): void
    {
        if ($this->connect !== null) {
            $this->pdo = ($this->connect)();
        }
    }

    /** @param array<string, mixed> $meta */
    public function record(string $username, string $action, ?string $entity, ?string $entityId, array $meta, string $ip): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (username, action, entity, entity_id, meta, ip)
             VALUES (:username, :action, :entity, :entity_id, :meta, :ip)'
        );

        $stmt->execute([
            'username' => $username !== '' ? $username : null,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'meta' => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            'ip' => $ip !== '' ? $ip : null,
        ]);
    }
}
