<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class RoomRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function activeRooms(): array
    {
        $stmt = $this->pdo->query(
            'SELECT public_id AS id, numero, nome, categoria, ativa FROM salas WHERE ativa = 1 ORDER BY numero'
        );

        return $stmt->fetchAll();
    }
}