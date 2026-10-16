<?php
// app/services/CoverBranding.php
// Identidade visual na capa: compõe, com GD, uma faixa na base da imagem
// gerada com o logotipo e o nome da escola e o logotipo/nome da rede
// (Secretaria). Assim a identidade não depende da IA desenhar logos.
declare(strict_types=1);

namespace App\Services;

use App\Upload;

final class CoverBranding
{
    public const NETWORK_LOGO_DIR = 'branding';

    /** Caminho do logotipo da rede (enviado pelo admin), se existir. */
    public static function networkLogo(): ?string
    {
        foreach (['png', 'jpg', 'webp'] as $ext) {
            $f = STORAGE_PATH . '/' . self::NETWORK_LOGO_DIR . '/rede.' . $ext;
            if (is_file($f)) {
                return $f;
            }
        }
        return null;
    }

    public static function networkName(): string
    {
        return (string) (env('NETWORK_NAME', 'Secretaria de Educação · São Caetano do Sul') ?: 'Secretaria de Educação · São Caetano do Sul');
    }

    /**
     * Aplica a faixa de identidade na base do PNG. $school pode ser null (só a rede).
     * Devolve os novos bytes PNG.
     */
    public static function apply(string $png, ?array $school): string
    {
        $img = @imagecreatefromstring($png);
        if ($img === false) {
            return $png;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $bandH = (int) round($h * 0.075);
        $y0 = $h - $bandH;
        $pad = (int) round($w * 0.03);

        $cor = self::hex($school['primary_color'] ?? null) ?? [30, 58, 138];
        $band = imagecolorallocatealpha($img, 255, 255, 255, 12);
        imagefilledrectangle($img, 0, $y0, $w, $h, $band);
        $linha = imagecolorallocate($img, $cor[0], $cor[1], $cor[2]);
        imagefilledrectangle($img, 0, $y0, $w, $y0 + max(3, (int) round($h * 0.005)), $linha);

        $font = ROOT_PATH . '/templates/fonts/DejaVuSans-Bold.ttf';
        $fontR = ROOT_PATH . '/templates/fonts/DejaVuSans.ttf';
        $texto = imagecolorallocate($img, 17, 24, 39);
        $cinza = imagecolorallocate($img, 71, 85, 105);
        $logoH = (int) round($bandH * 0.72);
        $yc = $y0 + (int) round($bandH / 2);

        // Bloco da rede (direita): logotipo + nome, no máximo 42% da largura; a fonte
        // diminui até caber e, se ainda assim não couber, o nome é abreviado com "…".
        $rede = self::networkName();
        $redeLogo = self::networkLogo();
        $redeLogoW = $redeLogo !== null ? self::logoSize($redeLogo, $logoH, 2.0)[0] : 0;   // logo da rede mais compacto, para sobrar espaço ao nome
        $gap = $redeLogoW > 0 ? $pad : 0;
        $rightMax = (int) round($w * 0.42);
        $sizeR = max(9, (int) round($bandH * 0.2));
        while ($sizeR > 9 && self::textWidth($fontR, $sizeR, $rede) + $redeLogoW + $gap > $rightMax) {
            $sizeR--;
        }
        $rede = self::fitText($fontR, $sizeR, $rede, $rightMax - $redeLogoW - $gap);
        $tw = self::textWidth($fontR, $sizeR, $rede);
        $xr = $w - $pad - $tw;
        imagettftext($img, $sizeR, 0, $xr, $yc + (int) round($sizeR * 0.4), $cinza, $fontR, $rede);
        if ($redeLogo !== null) {
            self::drawLogo($img, $redeLogo, $xr - $gap - $redeLogoW, $yc, $logoH, 2.0);
        }

        // Bloco da escola (esquerda): logotipo, nome e frase, limitados ao espaço que sobra
        $x = $pad;
        $leftMax = $xr - $gap - $redeLogoW - $pad;
        $logo = $school !== null ? Upload::path($school['logo_path'] ?? null) : null;
        if ($logo !== null) {
            $x = self::drawLogo($img, $logo, $x, $yc, $logoH) + $pad;
        }
        if ($school !== null) {
            $nome = (string) ($school['short_name'] ?: $school['name']);
            $size = max(10, (int) round($bandH * 0.26));
            $tag = trim((string) ($school['tagline'] ?? ''));
            $maxW = max(40, $leftMax - $x);
            $nome = self::fitText($font, $size, $nome, $maxW);
            $yNome = $tag !== '' ? $yc - (int) round($size * 0.2) : $yc + (int) round($size * 0.4);
            imagettftext($img, $size, 0, $x, $yNome, $texto, $font, $nome);
            if ($tag !== '') {
                $sizeT = max(9, (int) round($size * 0.75));
                imagettftext($img, $sizeT, 0, $x, $yNome + (int) round($size * 1.15), $cinza, $fontR, self::fitText($fontR, $sizeT, $tag, $maxW));
            }
        }

        ob_start();
        imagepng($img, null, 6);
        imagedestroy($img);
        return (string) ob_get_clean();
    }

    /** Tamanho final do logo (largura, altura) para a altura pedida, limitado a $maxRatio × a altura. @return array{0:int,1:int} */
    private static function logoSize(string $file, int $h, float $maxRatio = 3.0): array
    {
        $info = @getimagesize($file);
        if ($info === false || $info[1] < 1) {
            return [0, 0];
        }
        $w = (int) round($info[0] * $h / $info[1]);
        if ($w > $h * $maxRatio) {
            $w = (int) round($h * $maxRatio);
            $h = (int) round($info[1] * $w / max(1, $info[0]));
        }
        return [$w, $h];
    }

    /** Desenha o logo a partir de $x (borda esquerda), centrado em $yc; devolve o x final. */
    private static function drawLogo(\GdImage $img, string $file, int $x, int $yc, int $h, float $maxRatio = 3.0): int
    {
        $src = @imagecreatefromstring((string) file_get_contents($file));
        if ($src === false) {
            return $x;
        }
        [$w, $h] = self::logoSize($file, $h, $maxRatio);
        if ($w < 1) {
            imagedestroy($src);
            return $x;
        }
        imagealphablending($img, true);
        imagecopyresampled($img, $src, $x, $yc - (int) round($h / 2), 0, 0, $w, $h, imagesx($src), imagesy($src));
        imagedestroy($src);
        return $x + $w;
    }

    private static function textWidth(string $font, int $size, string $text): int
    {
        $box = imagettfbbox($size, 0, $font, $text);
        return $box === false ? 0 : (int) ($box[2] - $box[0]);
    }

    /** Abrevia o texto com "…" até caber na largura. */
    private static function fitText(string $font, int $size, string $text, int $maxW): string
    {
        if (self::textWidth($font, $size, $text) <= $maxW) {
            return $text;
        }
        $t = $text;
        while (mb_strlen($t) > 1 && self::textWidth($font, $size, $t . '…') > $maxW) {
            $t = mb_substr($t, 0, -1);
        }
        return rtrim($t) . '…';
    }

    /** @return array{0:int,1:int,2:int}|null */
    public static function hex(?string $hex): ?array
    {
        if ($hex === null || !preg_match('/^#?([0-9a-f]{6})$/i', $hex, $m)) {
            return null;
        }
        return [(int) hexdec(substr($m[1], 0, 2)), (int) hexdec(substr($m[1], 2, 2)), (int) hexdec(substr($m[1], 4, 2))];
    }
}
