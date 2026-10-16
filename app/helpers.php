<?php
// app/helpers.php
// Funções globais pequenas usadas em views e controladores.
declare(strict_types=1);

/** Escapa qualquer valor para saída em HTML (sempre use nas views). */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Monta uma URL do sistema respeitando BASE_URL ou a subpasta (BASE_PATH).
 * url('/health') → https://cecapescs.com.br/materiais/health
 */
function url(string $path = ''): string
{
    $base = BASE_URL !== '' ? BASE_URL : BASE_PATH;
    return $base . '/' . ltrim($path, '/');
}
