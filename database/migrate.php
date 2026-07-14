<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Env;

/**
 * Runner de migrations (somente linha de comando).
 *
 *   php database/migrate.php status     lista aplicadas x pendentes
 *   php database/migrate.php migrate     aplica as pendentes, em ordem
 *   php database/migrate.php baseline    marca as atuais como aplicadas (NAO executa)
 *
 * Use `baseline` quando o banco JA foi montado a mao (phpMyAdmin) e voce quer
 * so registrar o ponto de partida; depois disso, use `migrate` para as novas.
 * Cada migration roda uma unica vez (controle na tabela schema_migrations).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente via linha de comando.\n");
}

require __DIR__ . '/../src/Core/autoload.php';

Env::load(__DIR__ . '/../.env');

$command = $argv[1] ?? 'status';
$migrationsDir = __DIR__ . '/migrations';

$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files);
$names = array_map('basename', $files);

$pdo = Database::connect();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(255) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (migration)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$appliedList = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($appliedList);
$pending = array_values(array_filter($names, static fn (string $n): bool => !isset($applied[$n])));
$record = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:m)');

switch ($command) {
    case 'status':
        echo "Aplicadas:\n";
        foreach ($names as $n) {
            if (isset($applied[$n])) {
                echo "  [x] {$n}\n";
            }
        }
        echo "Pendentes:\n";
        if ($pending === []) {
            echo "  (nenhuma)\n";
        }
        foreach ($pending as $n) {
            echo "  [ ] {$n}\n";
        }
        break;

    case 'baseline':
        foreach ($pending as $n) {
            $record->execute(['m' => $n]);
            echo "marcada: {$n}\n";
        }
        echo "Baseline concluido (nada foi executado).\n";
        break;

    case 'migrate':
        if ($pending === []) {
            echo "Nada pendente.\n";
            break;
        }
        foreach ($pending as $n) {
            echo "aplicando: {$n} ... ";
            if (migrationAlreadyReflectedInSchema($pdo, $n)) {
                $record->execute(['m' => $n]);
                echo "ja aplicada no schema; marcada\n";
                continue;
            }

            try {
                $pdo->exec((string) file_get_contents($migrationsDir . '/' . $n));
                $record->execute(['m' => $n]);
                echo "ok\n";
            } catch (\Throwable $e) {
                echo "FALHOU\n  {$e->getMessage()}\n";
                echo "Parei em {$n}. Corrija e rode de novo.\n";
                exit(1);
            }
        }
        echo "Migrations aplicadas.\n";
        break;

    default:
        echo "Comandos: status | migrate | baseline\n";
        exit(1);
}

function migrationAlreadyReflectedInSchema(PDO $pdo, string $migration): bool
{
    if ($migration !== '009_add_payment_acceptance.sql') {
        return false;
    }

    return columnsExist($pdo, 'reservas', [
        'payment_status',
        'pix_received_at',
        'aceite_termos',
        'aceite_privacidade',
        'versao_termos',
        'versao_privacidade',
        'data_hora_aceite',
        'origem_aceite',
        'texto_aceite',
        'aceite_user_agent',
        'aceite_ip',
    ]);
}

/** @param array<int, string> $columns */
function columnsExist(PDO $pdo, string $table, array $columns): bool
{
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $stmt = $pdo->prepare(
        "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME IN ({$placeholders})"
    );
    $stmt->execute([$table, ...$columns]);

    $found = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
    foreach ($columns as $column) {
        if (!isset($found[$column])) {
            return false;
        }
    }

    return true;
}
