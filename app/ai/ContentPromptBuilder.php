<?php
// app/ai/ContentPromptBuilder.php
// Monta os prompts (sistema + pedido) para a IA de texto gerar o JSON da
// seção 5, com as habilidades BNCC oficiais e os limites do estilo.
declare(strict_types=1);

namespace App\Ai;

use App\Models\BnccSkill;

final class ContentPromptBuilder
{
    public const FINALIDADES = [
        'introducao'    => 'introduzir o tema pela primeira vez',
        'aprofundamento' => 'aprofundar um tema já apresentado',
        'revisao'       => 'revisar o tema antes de uma avaliação',
        'atividade'     => 'propor atividades práticas sobre o tema',
    ];

    /**
     * @param array{etapa:string,ano:string,componente:string,habilidades:array<int,array>,tema:string,finalidade:string,paginas:int,estilo:string,observacoes?:string} $req
     * @return array{system:string,user:string,request:array}
     */
    public function build(array $req): array
    {
        $style = Styles::get($req['estilo']);
        $lim = ContentValidator::limitsFor($req['estilo']);
        $etapa = BnccSkill::STAGES[$req['etapa']] ?? $req['etapa'];
        $ano = BnccSkill::yearLabel($req['etapa'], (string) $req['ano']);
        $n = max(1, min(ContentValidator::MAX_PAGINAS, (int) $req['paginas']));

        $habsTxt = '';
        $habsJson = [];
        foreach ($req['habilidades'] as $h) {
            $habsTxt .= "- {$h['code']}: {$h['description']}\n";
            $habsJson[] = ['codigo' => $h['code'], 'resumo' => '<resumo curto e fiel, até 200 caracteres>'];
        }

        $tiposPaginas = ['abertura'];
        for ($i = 2; $i <= $n; $i++) {
            $tiposPaginas[] = $i === $n && $n > 1 ? 'pratica' : 'explicacao';
        }

        $system = <<<TXT
Você é um especialista em elaboração de material didático para a rede pública municipal de São Caetano do Sul (Brasil), alinhado à BNCC.
Responda SOMENTE com um objeto JSON válido (sem comentários, sem markdown, sem texto antes ou depois), em português do Brasil, com acentuação correta.

Esquema obrigatório:
{
  "tipo": "apostila",
  "etapa": "<copiar exatamente>",
  "ano": "<copiar exatamente>",
  "componente": "<copiar exatamente>",
  "habilidades": [{"codigo": "<código oficial>", "resumo": "<resumo fiel, até {$lim['resumo']} caracteres>"}],
  "tema": "<copiar exatamente>",
  "finalidade": "<copiar exatamente>",
  "estilo": "<copiar exatamente>",
  "capa": {"texto_superior": "<componente · ano>", "titulo": "<até {$lim['titulo']} caracteres>", "subtitulo": "<até {$lim['subtitulo']}>", "frase_inferior": "<até 90>"},
  "paginas": [
    {"numero": 1, "tipo": "abertura", "titulo": "...", "subtitulo": "...", "blocos": [
      {"n": "01", "titulo": "Objetivo", "texto": "..."},
      {"n": "02", "titulo": "O que você vai aprender", "itens": ["...", "..."]},
      {"n": "03", "titulo": "Habilidades BNCC", "itens": ["<código> – <resumo curto>"]},
      {"n": "04", "titulo": "Por que é importante", "itens": ["..."]},
      {"n": "05", "titulo": "Como vamos estudar", "itens": ["..."]},
      {"n": "06", "titulo": "Nas próximas páginas", "itens": ["Página 2 – ..."]}
    ], "rodape": "<frase curta>"},
    {"numero": 2, "tipo": "explicacao", "titulo": "...", "subtitulo": "...", "blocos": [...], "rodape": "..."}
  ]
}

Regras:
1. Use SOMENTE os códigos de habilidade fornecidos no pedido; nunca invente códigos.
2. Limites por estilo "{$style['nome']}": título de página até {$lim['titulo']} caracteres; subtítulo até {$lim['subtitulo']}; título de bloco até {$lim['bloco_titulo']}; texto de bloco até {$lim['bloco_texto']}; cada item até {$lim['item']}; no máximo {$lim['itens_max']} itens por bloco e {$lim['blocos_max']} blocos por página; rodapé até 90.
3. Cada bloco tem "texto" (parágrafo curto) OU "itens" (lista); numere os blocos "01", "02"…
4. A página 1 é sempre "abertura" com os 6 blocos do esquema. As demais páginas são "explicacao" (conceitos com exemplos concretos) e a última, se houver mais de uma, é "pratica" (atividades, desafio e questão para conversar).
5. Linguagem adequada à faixa etária, frases curtas, exemplos do cotidiano brasileiro, tom acolhedor.
6. Nunca inclua nomes, fotos ou dados de alunos, nem personagens de marcas, filmes ou desenhos.
7. Não use emojis nem caracteres decorativos; o texto será desenhado em uma página A4.
TXT;

        $obs = trim((string) ($req['observacoes'] ?? ''));
        $user = "Crie o conteúdo de uma apostila com {$n} página(s), com os tipos nesta ordem: " . implode(', ', $tiposPaginas) . ".\n"
            . "Etapa: {$req['etapa']} ({$etapa})\nAno: {$req['ano']} ({$ano})\nComponente: {$req['componente']}\n"
            . "Tema: {$req['tema']}\nFinalidade: {$req['finalidade']} (" . (self::FINALIDADES[$req['finalidade']] ?? $req['finalidade']) . ")\n"
            . "Estilo: {$req['estilo']} (público: {$style['publico']})\n"
            . "Habilidades BNCC oficiais (use exatamente estes códigos):\n{$habsTxt}"
            . ($obs !== '' ? "Observações do professor: {$obs}\n" : '')
            . "\nDados do pedido (JSON): " . json_encode([
                'etapa' => $req['etapa'], 'ano' => (string) $req['ano'], 'componente' => $req['componente'],
                'habilidades' => $habsJson, 'tema' => $req['tema'], 'finalidade' => $req['finalidade'],
                'estilo' => $req['estilo'], 'paginas' => $n, 'tipos_paginas' => $tiposPaginas,
            ], JSON_UNESCAPED_UNICODE);

        return ['system' => $system, 'user' => $user, 'request' => $req];
    }

    /** Prompt de correção: devolve os erros ao modelo para uma nova tentativa. */
    public function fixPrompt(array $doc, array $errors): string
    {
        return "O JSON anterior foi rejeitado pelos motivos abaixo. Corrija APENAS o necessário e devolva o JSON completo novamente, respeitando o esquema e os limites:\n- "
            . implode("\n- ", $errors) . "\n\nJSON anterior:\n" . json_encode($doc, JSON_UNESCAPED_UNICODE);
    }
}
