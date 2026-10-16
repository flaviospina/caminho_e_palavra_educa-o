<?php
// app/Upload.php
// Recebimento seguro de arquivos: MIME real (finfo), tamanho máximo, nome
// aleatório e gravação fora de public/ (storage/).
declare(strict_types=1);

namespace App;

use InvalidArgumentException;

final class Upload
{
    public const IMAGE_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    /**
     * Valida e grava uma imagem enviada ($_FILES[$field]). Devolve o caminho
     * relativo a storage/ (ex.: schools/ab12…​.png) ou null se nada foi enviado.
     * @throws InvalidArgumentException com mensagem amigável
     */
    public static function image(string $field, string $subdir, int $maxBytes = 2_097_152): ?string
    {
        $f = $_FILES[$field] ?? null;
        if ($f === null || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($f['error'] ?? 0) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) ? 'O arquivo é maior que o permitido.' : 'Falha ao receber o arquivo. Tente de novo.');
        }
        if ((int) $f['size'] > $maxBytes) {
            throw new InvalidArgumentException('A imagem deve ter no máximo ' . round($maxBytes / 1048576, 1) . ' MB.');
        }
        $tmp = (string) $f['tmp_name'];
        if (!is_uploaded_file($tmp) && !is_file($tmp)) {
            throw new InvalidArgumentException('Arquivo inválido.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::IMAGE_TYPES[$mime])) {
            throw new InvalidArgumentException('Envie uma imagem PNG, JPG ou WebP.');
        }
        if (@getimagesize($tmp) === false) {
            throw new InvalidArgumentException('O arquivo não é uma imagem válida.');
        }
        $dir = STORAGE_PATH . '/' . trim($subdir, '/');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new InvalidArgumentException('Não foi possível preparar a pasta de destino.');
        }
        $name = bin2hex(random_bytes(12)) . '.' . self::IMAGE_TYPES[$mime];
        $dest = $dir . '/' . $name;
        if (!(is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dest) : copy($tmp, $dest))) {
            throw new InvalidArgumentException('Não foi possível gravar a imagem.');
        }
        @chmod($dest, 0644);
        return trim($subdir, '/') . '/' . $name;
    }

    /** Caminho absoluto de um arquivo de storage/, ou null. */
    public static function path(?string $relative): ?string
    {
        if ($relative === null || $relative === '' || str_contains($relative, '..')) {
            return null;
        }
        $file = STORAGE_PATH . '/' . ltrim($relative, '/');
        return is_file($file) ? $file : null;
    }

    public static function mime(string $file): string
    {
        return (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file);
    }
}
