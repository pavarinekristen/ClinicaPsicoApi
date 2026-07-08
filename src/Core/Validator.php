<?php

declare(strict_types=1);

namespace App\Core;

final class Validator
{
    public static function requiredString(mixed $value, string $field, int $max = 255): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new AppException("Campo obrigatorio: {$field}.", 422);
        }

        $value = trim($value);
        if (mb_strlen($value) > $max) {
            throw new AppException("Campo muito longo: {$field}.", 422);
        }

        return $value;
    }

    public static function optionalString(mixed $value, string $field, int $max = 255): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new AppException("Campo invalido: {$field}.", 422);
        }

        $value = trim($value);
        if (mb_strlen($value) > $max) {
            throw new AppException("Campo muito longo: {$field}.", 422);
        }

        return $value;
    }

    public static function dateYmd(mixed $value, string $field): string
    {
        $value = self::requiredString($value, $field, 10);
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new AppException("Data invalida: {$field}. Use YYYY-MM-DD.", 422);
        }

        return $value;
    }
}