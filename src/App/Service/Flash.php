<?php
namespace App\Service;

class Flash
{
    private const SUCCESS = 'success';
    private const ERROR   = 'error';

    public static function addSuccess(string $message): void
    {
        self::add(self::SUCCESS, $message);
    }

    public static function addError(string $message): void
    {
        self::add(self::ERROR, $message);
    }

    /**
     * Vrátí a zároveň smaže všechny flash zprávy z session.
     */
    public static function consume(): array
    {
        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);

        return [
            'success' => $flash[self::SUCCESS] ?? [],
            'error'   => $flash[self::ERROR] ?? [],
        ];
    }

    private static function add(string $type, string $message): void
    {
        $_SESSION['flash'][$type][] = $message;
    }
}
