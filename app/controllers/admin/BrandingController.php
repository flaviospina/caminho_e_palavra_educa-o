<?php
// app/controllers/admin/BrandingController.php
// Admin: identidade visual da rede (logotipo da Secretaria) e estado do banco.
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Csrf;
use App\Models\AuditLog;
use App\Request;
use App\Response;
use App\Services\CoverBranding;
use App\Services\SchemaUpdater;
use App\Upload;
use App\View;
use InvalidArgumentException;
use Throwable;

final class BrandingController
{
    /** GET /admin/identidade */
    public function index(): Response
    {
        return Response::html(View::render('admin/identidade', [
            'titulo'    => 'Identidade visual da rede',
            'rede'      => CoverBranding::networkName(),
            'tem_logo'  => CoverBranding::networkLogo() !== null,
            'pendentes' => (new SchemaUpdater())->pending(),
        ]));
    }

    /** POST /admin/identidade — envia/remove o logotipo da rede. */
    public function save(): Response
    {
        if (!Csrf::check(Request::post(Csrf::FIELD))) {
            Request::flash('erro', 'Sessão expirada. Tente de novo.');
            return Response::redirect(url('/admin/identidade'));
        }
        try {
            if (!empty($_POST['remover_logo'])) {
                $atual = CoverBranding::networkLogo();
                if ($atual !== null) {
                    @unlink($atual);
                }
                AuditLog::record('identidade_logo_removido', 'branding');
                Request::flash('ok', 'Logotipo da rede removido.');
            } else {
                $rel = Upload::image('logo', CoverBranding::NETWORK_LOGO_DIR);
                if ($rel === null) {
                    throw new InvalidArgumentException('Escolha um arquivo de imagem.');
                }
                $novo = STORAGE_PATH . '/' . $rel;
                $ext = pathinfo($novo, PATHINFO_EXTENSION);
                foreach (['png', 'jpg', 'webp'] as $e) {
                    @unlink(STORAGE_PATH . '/' . CoverBranding::NETWORK_LOGO_DIR . '/rede.' . $e);
                }
                rename($novo, STORAGE_PATH . '/' . CoverBranding::NETWORK_LOGO_DIR . '/rede.' . $ext);
                AuditLog::record('identidade_logo_enviado', 'branding');
                Request::flash('ok', 'Logotipo da rede atualizado. Ele entra na faixa inferior das próximas capas.');
            }
        } catch (InvalidArgumentException $e) {
            Request::flash('erro', $e->getMessage());
        }
        return Response::redirect(url('/admin/identidade'));
    }

    /** GET /identidade/logo — logotipo da rede (usuários logados). */
    public function logo(): Response
    {
        $file = CoverBranding::networkLogo();
        if ($file === null) {
            return new Response('Sem logotipo.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        return new Response((string) file_get_contents($file), 200, ['Content-Type' => Upload::mime($file), 'Cache-Control' => 'private, max-age=3600']);
    }

    /** POST /admin/banco/atualizar — aplica colunas pendentes (MySQL 5.7 não tem IF NOT EXISTS). */
    public function updateSchema(): Response
    {
        if (!Csrf::check(Request::post(Csrf::FIELD))) {
            Request::flash('erro', 'Sessão expirada. Tente de novo.');
            return Response::redirect(url('/admin/identidade'));
        }
        try {
            $done = (new SchemaUpdater())->apply();
            AuditLog::record('banco_atualizado', 'schema', null, ['colunas' => $done]);
            Request::flash('ok', $done === [] ? 'A estrutura do banco já estava atualizada.' : 'Estrutura atualizada: ' . implode(', ', $done) . '.');
        } catch (Throwable $e) {
            \App\Log::error('Atualização de estrutura falhou', ['erro' => $e->getMessage()]);
            Request::flash('erro', 'Não foi possível atualizar a estrutura: ' . $e->getMessage());
        }
        return Response::redirect(url('/admin/identidade'));
    }
}
