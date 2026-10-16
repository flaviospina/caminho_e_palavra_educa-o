<?php
// app/controllers/TermController.php
// Termo de uso: exibição e registro do aceite (data, hora, IP).
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Models\Consent;
use App\Request;
use App\Response;
use App\View;

final class TermController
{
    /** Converte o Markdown simples de docs/TERMO-DE-USO.md em HTML seguro. */
    public static function termHtml(): string
    {
        $md = (string) @file_get_contents(ROOT_PATH . '/docs/TERMO-DE-USO.md');
        $html = '';
        $inList = false;
        foreach (explode("\n", $md) as $line) {
            $line = rtrim($line);
            if (preg_match('/^- (.*)$/', $line, $m)) {
                if (!$inList) {
                    $html .= '<ul>';
                    $inList = true;
                }
                $html .= '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }
            if ($inList) {
                $html .= '</ul>';
                $inList = false;
            }
            if (preg_match('/^(#{1,3}) (.*)$/', $line, $m)) {
                $n = strlen($m[1]) + 1;
                $html .= "<h{$n}>" . self::inline($m[2]) . "</h{$n}>";
            } elseif ($line !== '') {
                $html .= '<p>' . self::inline($line) . '</p>';
            }
        }
        return $html . ($inList ? '</ul>' : '');
    }

    private static function inline(string $t): string
    {
        return preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', e($t)) ?? e($t);
    }

    /** GET /termo */
    public function show(): Response
    {
        $user = Auth::user();
        return Response::html(View::render('termo', [
            'titulo'   => 'Termo de uso',
            'html'     => self::termHtml(),
            'aceito'   => Consent::hasCurrent((int) $user['id']),
            'versao'   => TERM_VERSION,
        ]));
    }

    /** POST /termo */
    public function accept(): Response
    {
        $user = Auth::user();
        if (!Csrf::check(Request::post(Csrf::FIELD))) {
            Request::flash('erro', 'Sessão expirada. Tente de novo.');
            return Response::redirect(url('/termo'));
        }
        if (empty($_POST['aceito'])) {
            Request::flash('erro', 'Para continuar, marque "Li e aceito o termo de uso".');
            return Response::redirect(url('/termo'));
        }
        Consent::record((int) $user['id'], Request::ip(), Request::userAgent());
        Request::flash('ok', 'Termo aceito. Bem-vindo(a)!');
        return Response::redirect(url('/painel'));
    }
}
