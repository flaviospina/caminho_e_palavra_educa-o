<?php
// tests/cases/fase1_prompt.php — estilos, PromptBuilder e kit de exemplos
declare(strict_types=1);

use App\Ai\AiException;
use App\Ai\PromptBuilder;
use App\Ai\Styles;
use App\Services\PocService;

Teste::caso('estilos: catálogo com 6 estilos e um prompt-modelo por estilo', function () {
    $all = Styles::all();
    Teste::igual(6, count($all));
    foreach (['cartoon_infantil', 'doodle_escolar', 'colagem_adesivos', 'infografico_limpo', 'jovem_cinematografico', 'epico_realista'] as $k) {
        Teste::verdadeiro(Styles::exists($k), "Estilo {$k}");
        $e = Styles::get($k);
        foreach (['nome', 'publico', 'descricao', 'mascote', 'cores', 'limites'] as $campo) {
            Teste::verdadeiro(isset($e[$campo]), "{$k}.{$campo}");
        }
        $tpl = Styles::template($k);
        foreach (['{titulo}', '{subtitulo}', '{blocos}', '{rodape}', '{numero}', '{total}', '{descricao_do_estilo}', '{mascote}'] as $ph) {
            Teste::contem($ph, $tpl, "{$k}.txt sem {$ph}");
        }
        Teste::contem('UMA ÚNICA página vertical A4', $tpl);
        Teste::verdadeiro(!empty($e['amostra']) && is_file(ROOT_PATH . '/public' . $e['amostra']), "{$k}: imagem de amostra");
        $info = getimagesize(ROOT_PATH . '/public' . $e['amostra']);
        Teste::verdadeiro($info !== false && $info[0] >= 300 && $info[1] > $info[0], "{$k}: amostra em retrato");
    }
    Teste::lanca(fn() => Styles::get('inexistente'), AiException::class);
});

Teste::caso('kit: 6 exemplos válidos (3 componentes × 2 anos), capa + 3 páginas, estilo existente', function () {
    $ex = PocService::examples();
    Teste::igual(6, count($ex));
    $combos = [];
    foreach ($ex as $nome => $file) {
        $doc = PocService::loadDoc($file);
        Teste::igual(3, count($doc['paginas']), "{$nome}: páginas");
        Teste::verdadeiro(Styles::exists($doc['estilo']), "{$nome}: estilo");
        Teste::verdadeiro(isset($doc['capa']['titulo']), "{$nome}: capa");
        foreach ($doc['habilidades'] as $h) {
            Teste::verdadeiro((bool) preg_match('/^E[FMI]\d{2}[A-Z]{2}\d{2}$/', $h['codigo']), "{$nome}: código {$h['codigo']}");
        }
        Teste::igual('abertura', $doc['paginas'][0]['tipo']);
        Teste::igual(6, count($doc['paginas'][0]['blocos']), "{$nome}: 6 blocos na abertura");
        $combos[$doc['componente']][] = $doc['ano'];
    }
    Teste::igual(3, count($combos), 'Três componentes');
    foreach ($combos as $comp => $anos) {
        Teste::igual(2, count(array_unique($anos)), "{$comp}: dois anos");
    }
});

Teste::caso('prompt: placeholders preenchidos, blocos exatos e "Página N de T"', function () {
    $doc = PocService::loadDoc(ROOT_PATH . '/data/exemplos/ef04_ciencias_misturas.json');
    $req = (new PromptBuilder())->build($doc, 2, 'high');
    $p = $req->prompt;
    Teste::falso((bool) preg_match('/\{[a-z_]+\}/', $p), 'Sobrou marcador no prompt');
    Teste::contem('Título grande: "Tipos de mistura"', $p);
    Teste::contem('Página 2 de 3', $p);
    Teste::contem('4º ano — Ensino Fundamental – Anos Iniciais', $p);
    Teste::contem('Componente curricular: Ciências', $p);
    Teste::contem('Bloco 01 — Título: "Mistura homogênea"', $p);
    Teste::contem('Texto: "Parece uma coisa só. Exemplo: água com açúcar."', $p);
    Teste::contem('• "Peneirar"', $p);
    Teste::contem('Rodapé: "Olhe bem: dá para ver as partes ou não?"', $p);
    Teste::contem('doodles', $p, 'Descrição do estilo');
    Teste::igual('A4', $req->format);
    Teste::igual('2:3', $req->gemini_aspect());
    Teste::igual('1024x1536', $req->openai_size());
    Teste::igual('Tipos de mistura', $req->context['titulo']);
    Teste::igual(6, count($req->context['blocos']));
});

Teste::caso('prompt: página inexistente ou sem título é rejeitada com mensagem clara', function () {
    $doc = PocService::loadDoc(ROOT_PATH . '/data/exemplos/ef04_ciencias_misturas.json');
    $e = Teste::lanca(fn() => (new PromptBuilder())->build($doc, 9), AiException::class);
    Teste::contem('Página 9 não existe', $e->getMessage());
    $doc['paginas'][0]['titulo'] = '';
    $e = Teste::lanca(fn() => (new PromptBuilder())->build($doc, 1), AiException::class);
    Teste::contem('sem título', $e->getMessage());
    $doc['estilo'] = 'nao_existe';
    $e = Teste::lanca(fn() => (new PromptBuilder())->build($doc, 2), AiException::class);
    Teste::contem('Estilo desconhecido', $e->getMessage());
});

Teste::caso('prompt: rótulos de ano e etapa', function () {
    Teste::igual('4º ano', PromptBuilder::anoLabel('4'));
    Teste::igual('Pré II', PromptBuilder::anoLabel('Pré II'));
    Teste::igual('Ensino Médio', PromptBuilder::etapaLabel('EM'));
    Teste::igual('XYZ', PromptBuilder::etapaLabel('XYZ'));
});
