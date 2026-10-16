<?php
// app/controllers/StylesController.php
// Biblioteca de estilos por faixa etária, com amostra de referência e a página
// real mais recente gerada em cada estilo (dos projetos que o usuário pode ver).
declare(strict_types=1);

namespace App\Controllers;

use App\Ai\Styles;
use App\Auth;
use App\Database;
use App\Response;
use App\View;

final class StylesController
{
    public const FAIXAS = [
        'EI'               => 'Educação Infantil',
        'EF_ANOS_INICIAIS' => 'Fundamental – Anos Iniciais',
        'EF_ANOS_FINAIS'   => 'Fundamental – Anos Finais',
        'EM'               => 'Ensino Médio',
    ];

    public function index(): Response
    {
        $user = Auth::user();
        $previas = $this->realPreviews($user);
        $porFaixa = [];
        foreach (self::FAIXAS as $k => $rotulo) {
            $porFaixa[$k] = ['rotulo' => $rotulo, 'estilos' => []];
        }
        foreach (Styles::all() as $e) {
            foreach ($e['faixas'] ?? array_keys(self::FAIXAS) as $f) {
                if (isset($porFaixa[$f])) {
                    $porFaixa[$f]['estilos'][] = $e + ['previa_real' => $previas[$e['chave']] ?? null];
                }
            }
        }
        return Response::html(View::render('estilos', ['titulo' => 'Biblioteca de estilos', 'por_faixa' => $porFaixa]));
    }

    /** Página pronta mais recente por estilo, entre os projetos visíveis ao usuário. @return array<string,array{projeto:int,numero:int,url:string}> */
    private function realPreviews(array $user): array
    {
        $role = (string) $user['role_slug'];
        $sql = "SELECT p.id AS project_id, p.style_slug, pg.number, pg.attempts FROM projects p JOIN pages pg ON pg.project_id = p.id
                WHERE p.deleted_at IS NULL AND pg.status = 'pronto' AND pg.image_path IS NOT NULL AND pg.number > 0";
        $params = [];
        if ($role === 'professor') {
            $sql .= ' AND p.user_id = :uid';
            $params['uid'] = (int) $user['id'];
        } elseif ($role === 'coordenador') {
            $sql .= ' AND (p.user_id = :uid OR p.school_id = :sid)';
            $params['uid'] = (int) $user['id'];
            $params['sid'] = (int) ($user['school_id'] ?? 0);
        }
        $st = Database::get()->prepare($sql . ' ORDER BY pg.updated_at DESC, pg.id DESC LIMIT 200');
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            if (!isset($out[$r['style_slug']])) {
                $out[$r['style_slug']] = ['projeto' => (int) $r['project_id'], 'numero' => (int) $r['number'], 'url' => url('/projetos/' . $r['project_id'] . '/pagina/' . $r['number'] . '.png?v=' . (int) $r['attempts'])];
            }
        }
        return $out;
    }
}
