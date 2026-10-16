<?php
// app/services/PosterService.php
// Cartazes de eventos escolares: valida o formulário, monta o JSON do projeto
// (tipo "cartaz"), cria as páginas por formato e os textos esperados.
declare(strict_types=1);

namespace App\Services;

use App\Ai\Styles;
use App\Database;
use App\Models\AuditLog;
use App\Models\Page;
use InvalidArgumentException;

final class PosterService
{
    public const EVENT_TYPES = [
        'festa_junina'      => 'Festa junina',
        'feira_ciencias'    => 'Feira de ciências',
        'reuniao_pais'      => 'Reunião de pais e responsáveis',
        'mostra_cultural'   => 'Mostra cultural',
        'semana_leitura'    => 'Semana da leitura',
        'jogos_escolares'   => 'Jogos escolares',
        'formatura'         => 'Formatura',
        'dia_familia'       => 'Dia da família na escola',
        'volta_aulas'       => 'Volta às aulas',
        'campanha'          => 'Campanha (vacinação, doação, solidariedade)',
        'palestra'          => 'Palestra ou oficina',
        'outro'             => 'Outro evento',
    ];

    /** Formatos: chave → [rótulo, proporção, descrição para o prompt] */
    public const FORMATS = [
        'STORY' => ['Story (9:16)', '1080×1920', 'vertical no formato story de redes sociais (proporção 9:16)'],
        'FEED'  => ['Feed (1:1)', '1080×1080', 'quadrado para feed de redes sociais (proporção 1:1)'],
        'A4'    => ['Cartaz A4', 'A4 retrato', 'vertical A4 para impressão'],
    ];

    /** Valida o formulário e devolve o documento do cartaz. @throws InvalidArgumentException */
    public static function validate(array $in, ?array $school): array
    {
        $tipo = (string) ($in['tipo_evento'] ?? '');
        if (!isset(self::EVENT_TYPES[$tipo])) {
            throw new InvalidArgumentException('Escolha o tipo de evento.');
        }
        $titulo = trim((string) ($in['titulo'] ?? ''));
        if (mb_strlen($titulo) < 3 || mb_strlen($titulo) > 40) {
            throw new InvalidArgumentException('Informe o título do cartaz (3 a 40 caracteres).');
        }
        $data = trim((string) ($in['data'] ?? ''));
        if ($data === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || !checkdate((int) substr($data, 5, 2), (int) substr($data, 8, 2), (int) substr($data, 0, 4))) {
            throw new InvalidArgumentException('Informe a data do evento.');
        }
        $hora = trim((string) ($in['hora'] ?? ''));
        if ($hora !== '' && !preg_match('/^\d{2}:\d{2}$/', $hora)) {
            throw new InvalidArgumentException('Hora inválida (use HH:MM).');
        }
        $local = trim((string) ($in['local'] ?? ''));
        if (mb_strlen($local) < 3 || mb_strlen($local) > 60) {
            throw new InvalidArgumentException('Informe o local (3 a 60 caracteres).');
        }
        $chamada = trim((string) ($in['chamada'] ?? ''));
        if (mb_strlen($chamada) > 80) {
            throw new InvalidArgumentException('A chamada pode ter no máximo 80 caracteres.');
        }
        $infos = array_values(array_filter(array_map(fn($x) => trim((string) $x), preg_split("/\r?\n/", (string) ($in['informacoes'] ?? '')) ?: []), fn($x) => $x !== ''));
        if (count($infos) > 4) {
            throw new InvalidArgumentException('Use no máximo 4 linhas de informações.');
        }
        foreach ($infos as $i) {
            if (mb_strlen($i) > 60) {
                throw new InvalidArgumentException('Cada linha de informação pode ter no máximo 60 caracteres.');
            }
        }
        $estilo = (string) ($in['estilo'] ?? '');
        if (!Styles::exists($estilo)) {
            throw new InvalidArgumentException('Escolha um estilo visual.');
        }
        $formatos = array_values(array_intersect(array_keys(self::FORMATS), array_map('strtoupper', array_map('strval', (array) ($in['formatos'] ?? [])))));
        if ($formatos === []) {
            throw new InvalidArgumentException('Marque pelo menos um formato (Story, Feed ou A4).');
        }
        $dataFmt = date('d/m/Y', strtotime($data));
        return [
            'tipo'     => 'cartaz',
            'etapa'    => (string) ($in['etapa'] ?? 'EF_ANOS_INICIAIS'),
            'ano'      => (string) ($in['ano'] ?? ''),
            'componente' => 'Evento escolar',
            'habilidades' => [],
            'tema'     => $titulo,
            'finalidade' => 'evento',
            'estilo'   => $estilo,
            'evento'   => [
                'tipo'        => $tipo,
                'tipo_rotulo' => self::EVENT_TYPES[$tipo],
                'titulo'      => $titulo,
                'data'        => $data,
                'data_hora'   => $dataFmt . ($hora !== '' ? ' às ' . $hora : ''),
                'local'       => $local,
                'chamada'     => $chamada,
                'informacoes' => $infos,
                'escola'      => (string) ($school['short_name'] ?? $school['name'] ?? ''),
            ],
            'formatos' => $formatos,
            'capa'     => [],
            'paginas'  => [],
        ];
    }

    /** Cria o projeto do cartaz com uma página por formato. */
    public static function create(array $user, array $doc): int
    {
        $pdo = Database::get();
        $st = $pdo->prepare('INSERT INTO projects (user_id, school_id, type, title, stage, year, component, skill_codes, theme, purpose, style_slug, output_mode, status, content_json, reviewed_json, reviewed_at)
            VALUES (:user_id, :school_id, :type, :title, :stage, :year, :component, :codes, :theme, :purpose, :style, :mode, :status, :content, :content2, NOW())');
        $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $st->execute([
            'user_id' => (int) $user['id'], 'school_id' => $user['school_id'] !== null ? (int) $user['school_id'] : null,
            'type' => 'cartaz', 'title' => mb_substr($doc['evento']['titulo'], 0, 191), 'stage' => $doc['etapa'], 'year' => $doc['ano'] ?: '-',
            'component' => 'Evento escolar', 'codes' => '', 'theme' => mb_substr($doc['evento']['tipo_rotulo'], 0, 191), 'purpose' => 'evento',
            'style' => $doc['estilo'], 'mode' => 'imagem', 'status' => 'revisado', 'content' => $json, 'content2' => $json,
        ]);
        $id = (int) $pdo->lastInsertId();
        $n = 0;
        $up = $pdo->prepare('INSERT INTO pages (project_id, number, kind, format) VALUES (:p, :n, :k, :f)');
        foreach ($doc['formatos'] as $f) {
            $n++;
            $up->execute(['p' => $id, 'n' => $n, 'k' => 'cartaz', 'f' => $f]);
        }
        AuditLog::record('cartaz_criado', 'projects', $id, ['evento' => $doc['evento']['tipo'], 'formatos' => $doc['formatos']], (int) $user['id']);
        return $id;
    }

    /** Textos que devem aparecer no cartaz (conferência). @return string[] */
    public static function expectedTexts(array $doc): array
    {
        $e = $doc['evento'] ?? [];
        $out = [(string) ($e['titulo'] ?? ''), (string) ($e['data_hora'] ?? ''), (string) ($e['local'] ?? ''), (string) ($e['chamada'] ?? '')];
        foreach ($e['informacoes'] ?? [] as $i) {
            $out[] = (string) $i;
        }
        return array_values(array_filter(array_map('trim', $out), fn($t) => $t !== ''));
    }
}
