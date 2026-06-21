<?php

declare(strict_types=1);

namespace Esse;

// Flash-Nachrichten ueber Redirects hinweg (PRG-Pattern) — ersetzt das frueher
// direkt verstreute $_SESSION['flash']-Handling in den Admin-Seiten.
class Flash
{
    public static function set(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }

    // Liest die aktuelle Flash-Nachricht und loescht sie gleich wieder — einmal lesbar.
    public static function consume(): ?array
    {
        if (empty($_SESSION['flash'])) return null;

        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
}
