<?php
// app/ai/Styles.php
// Catálogo de estilos visuais: metadados em templates/styles/estilos.json e um
// prompt-modelo por estilo em templates/styles/<chave>.txt.
declare(strict_types=1);

namespace App\Ai;

final class Styles
{
    private static ?array $cache = null;

    public static function dir(): string
    {
        return ROOT_PATH . '/templates/styles';
    }

    /** @return array<string, array> todos os estilos, indexados pela chave */
    public static function all(): array
    {
        if (self::$cache === null) {
            $json = @file_get_contents(self::dir() . '/estilos.json');
            $data = $json === false ? null : json_decode($json, true);
            if (!is_array($data)) {
                throw new AiException('Catálogo de estilos (templates/styles/estilos.json) ausente ou inválido.', json_last_error_msg());
            }
            $out = [];
            foreach ($data as $item) {
                $out[(string) $item['chave']] = $item;
            }
            self::$cache = $out;
        }
        return self::$cache;
    }

    public static function exists(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    public static function get(string $key): array
    {
        $all = self::all();
        if (!isset($all[$key])) {
            throw new AiException("Estilo desconhecido: {$key}. Escolha um dos estilos cadastrados.", implode(', ', array_keys($all)));
        }
        return $all[$key];
    }

    /** Prompt-modelo do estilo (texto com marcadores {titulo}, {blocos}…). */
    public static function template(string $key): string
    {
        self::get($key);
        $file = self::dir() . '/' . $key . '.txt';
        $txt = @file_get_contents($file);
        if ($txt === false || trim($txt) === '') {
            throw new AiException("Prompt-modelo do estilo {$key} não encontrado.", $file);
        }
        return $txt;
    }
}
