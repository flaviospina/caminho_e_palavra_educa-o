<?php
// tests/cases/fase1_motores.php — motor simulado, adaptadores, erros e novas tentativas
declare(strict_types=1);

use App\Ai\AbstractImageEngine;
use App\Ai\AiException;
use App\Ai\EngineFactory;
use App\Ai\GeminiImage;
use App\Ai\HttpClient;
use App\Ai\ImageRequest;
use App\Ai\MockEngine;
use App\Ai\OpenAIImage;
use App\Ai\Pricing;
use App\Ai\PromptBuilder;
use App\Services\PocService;

/** Motor de teste: falha N vezes e depois devolve um PNG do MockEngine. */
final class MotorInstavel extends AbstractImageEngine
{
    public int $chamadas = 0;
    public function __construct(private int $falhas, private bool $retryable = true, int $retries = 2)
    {
        parent::__construct('chave', 'gpt-image-2.5-flare', 10, $retries, 0.0);
    }
    public function name(): string { return 'instavel'; }
    protected function label(): string { return 'Motor de teste'; }
    protected function priceTier(ImageRequest $r): string { return 'high'; }
    protected function callApi(ImageRequest $r): array
    {
        $this->chamadas++;
        if ($this->chamadas <= $this->falhas) {
            throw new AiException('O Motor de teste está instável no momento (erro 503).', 'HTTP 503', 'erro', $this->retryable);
        }
        $png = (new MockEngine())->generate($r)->png;
        return [$png, 100, 6240, []];
    }
}

function pedido_teste(int $pagina = 1): ImageRequest
{
    $doc = PocService::loadDoc(ROOT_PATH . '/data/exemplos/ef04_ciencias_misturas.json');
    return (new PromptBuilder())->build($doc, $pagina);
}

Teste::caso('mock: gera PNG válido 1024x1536 sem custo, com marca SIMULADO', function () {
    $r = (new MockEngine('gemini', 'gemini-3-pro-image'))->generate(pedido_teste());
    Teste::igual(1024, $r->width);
    Teste::igual(1536, $r->height);
    Teste::igual(0.0, $r->costUsd);
    Teste::igual('gemini', $r->engine);
    Teste::igual('simulado:gemini-3-pro-image', $r->model);
    $info = getimagesizefromstring($r->png);
    Teste::igual('image/png', $info['mime']);
    Teste::verdadeiro(strlen($r->png) > 10_000, 'PNG muito pequeno para ter conteúdo desenhado');
});

Teste::caso('mock: formatos STORY e FEED têm as proporções corretas', function () {
    $m = new MockEngine();
    $story = $m->generate(new ImageRequest('x', 'STORY'));
    Teste::igual([1080, 1920], [$story->width, $story->height]);
    $feed = $m->generate(new ImageRequest('x', 'FEED'));
    Teste::igual([1080, 1080], [$feed->width, $feed->height]);
    Teste::lanca(fn() => new ImageRequest('x', 'A3'), AiException::class);
});

Teste::caso('mock: motor de texto devolve JSON no esquema', function () {
    $json = (new MockEngine())->generateJson('sistema', 'pedido');
    Teste::igual('apostila', $json['tipo']);
    Teste::verdadeiro(isset($json['paginas'][0]['blocos']));
});

Teste::caso('fábrica: com AI_MOCK=1 gemini e openai viram motores simulados', function () {
    $f = new EngineFactory(['AI_MOCK' => '1', 'IMAGE_ENGINES' => 'gemini, openai']);
    $engines = $f->imageEngines();
    Teste::igual(['gemini', 'openai'], array_keys($engines));
    Teste::verdadeiro($engines['gemini'] instanceof MockEngine);
    Teste::igual('simulado:gemini-3-pro-image', $engines['gemini']->model());
    Teste::igual('simulado:gpt-image-2.5-flare', $engines['openai']->model());
    Teste::lanca(fn() => $f->imageEngine('dalle'), AiException::class);
});

Teste::caso('fábrica: com AI_MOCK=0 cria os adaptadores reais com modelo e tempo limite do .env', function () {
    $f = new EngineFactory(['AI_MOCK' => '0', 'GEMINI_IMAGE_MODEL' => 'gemini-3.1-flash-image', 'OPENAI_IMAGE_MODEL' => 'gpt-image-2.5-sunburst', 'AI_TIMEOUT' => '360']);
    $g = $f->imageEngine('gemini');
    $o = $f->imageEngine('openai');
    Teste::verdadeiro($g instanceof GeminiImage);
    Teste::verdadeiro($o instanceof OpenAIImage);
    Teste::igual('gemini-3.1-flash-image', $g->model());
    Teste::igual('gpt-image-2.5-sunburst', $o->model());
    Teste::igual('360', $f->setting('AI_TIMEOUT'), 'Tempo limite padrão 360 s');
    Teste::igual('2', (new EngineFactory())->setting('AI_RETRIES'), '2 novas tentativas por padrão');
});

Teste::caso('adaptadores: chave vazia é recusada antes de chamar a API', function () {
    $g = new GeminiImage('', 'gemini-3-pro-image');
    $e = Teste::lanca(fn() => $g->generate(pedido_teste()), AiException::class);
    Teste::contem('chave de API do Google Gemini não está configurada', $e->getMessage());
    Teste::igual('chave_invalida', $e->kind);
});

Teste::caso('adaptadores: corpo das requisições Gemini e OpenAI', function () {
    $req = pedido_teste();
    $gb = (new GeminiImage('k', 'gemini-3-pro-image'))->buildBody($req);
    Teste::igual(['IMAGE'], $gb['generationConfig']['responseModalities']);
    Teste::igual('2:3', $gb['generationConfig']['imageConfig']['aspectRatio']);
    Teste::igual('2K', $gb['generationConfig']['imageConfig']['imageSize']);
    Teste::igual($req->prompt, $gb['contents'][0]['parts'][0]['text']);
    $fb = (new GeminiImage('k', 'gemini-3.1-flash-image'))->buildBody($req);
    Teste::falso(isset($fb['generationConfig']['imageConfig']['imageSize']), 'Flash não recebe imageSize');
    $ob = (new OpenAIImage('k', 'gpt-image-2.5-flare'))->buildBody($req);
    Teste::igual(['model' => 'gpt-image-2.5-flare', 'n' => 1, 'size' => '1024x1536', 'quality' => 'high', 'output_format' => 'png'], array_diff_key($ob, ['prompt' => 1]));
});

Teste::caso('adaptadores: respostas das APIs são interpretadas (PNG, tokens) e erros viram mensagem', function () {
    $png = (new MockEngine())->generate(new ImageRequest('x'))->png;
    [$bytes, $in, $out] = GeminiImage::parseResponse(['candidates' => [['content' => ['parts' => [['text' => 'ok'], ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($png)]]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 300, 'candidatesTokenCount' => 1120]]);
    Teste::igual($png, $bytes);
    Teste::igual([300, 1120], [$in, $out]);
    $e = Teste::lanca(fn() => GeminiImage::parseResponse(['candidates' => [['finishReason' => 'SAFETY']]]), AiException::class);
    Teste::contem('não devolveu imagem (motivo: SAFETY)', $e->getMessage());

    [$bytes, $in, $out] = OpenAIImage::parseResponse(['data' => [['b64_json' => base64_encode($png)]], 'usage' => ['input_tokens' => 50, 'output_tokens' => 6240]]);
    Teste::igual($png, $bytes);
    Teste::igual([50, 6240], [$in, $out]);
    Teste::lanca(fn() => OpenAIImage::parseResponse(['data' => []]), AiException::class);
});

Teste::caso('erros HTTP: 401 chave inválida, 429 cota, timeout, 500 — mensagens em português', function () {
    $e = HttpClient::fromHttpStatus(401, ['error' => ['message' => 'invalid key']], '', 'OpenAI');
    Teste::igual('chave_invalida', $e->kind);
    Teste::contem('chave de API do OpenAI é inválida', $e->getMessage());
    Teste::falso($e->retryable);
    $e = HttpClient::fromHttpStatus(429, [], '', 'Google Gemini');
    Teste::igual('cota', $e->kind);
    Teste::contem('limite de uso ou cota esgotada', $e->getMessage());
    Teste::verdadeiro($e->retryable);
    $e = HttpClient::fromHttpStatus(503, [], '', 'OpenAI');
    Teste::contem('instável no momento', $e->getMessage());
    Teste::verdadeiro($e->retryable);
    $e = HttpClient::fromHttpStatus(400, ['error' => ['message' => 'model not found']], '', 'OpenAI');
    Teste::contem('rejeitou o pedido', $e->getMessage());
    Teste::contem('model not found', $e->getMessage());
    Teste::contem('HTTP 400', $e->detail);
    // Tempo limite real: host que não responde (porta fechada) com timeout curto
    $http = new HttpClient(2);
    $e = Teste::lanca(fn() => $http->postJson('http://127.0.0.1:1/x', [], [], 'Motor'), AiException::class);
    Teste::verdadeiro(in_array($e->kind, ['rede', 'tempo_limite'], true), 'Tipo: ' . $e->kind);
    Teste::contem('Motor', $e->getMessage());
});

Teste::caso('tentativas: falha 2x e sucesso na 3ª; falha definitiva após 2 novas tentativas', function () {
    $m = new MotorInstavel(2);
    $r = $m->generate(pedido_teste());
    Teste::igual(3, $m->chamadas);
    Teste::igual(3, $r->attempts);
    Teste::igual(round(6240 * 30 / 1e6 + 100 * 5 / 1e6, 5), $r->costUsd, 'Custo por tokens');

    $m = new MotorInstavel(10);
    $e = Teste::lanca(fn() => $m->generate(pedido_teste()), AiException::class);
    Teste::igual(3, $m->chamadas, '1 tentativa + 2 novas');
    Teste::contem('após 3 tentativas', $e->getMessage());

    $m = new MotorInstavel(10, false);
    Teste::lanca(fn() => $m->generate(pedido_teste()), AiException::class);
    Teste::igual(1, $m->chamadas, 'Erro não recuperável não repete');
});

Teste::caso('preços: estimativa por tokens e por imagem para os modelos confirmados', function () {
    foreach (['gemini-3-pro-image', 'gemini-3.1-flash-image', 'gpt-image-2.5-flare', 'gpt-image-2.5-sunburst'] as $m) {
        Teste::verdadeiro(Pricing::known($m), $m);
    }
    Teste::igual(0.134, Pricing::estimate('gemini-3-pro-image', null, null, '2K'));
    Teste::igual(round(1120 * 120 / 1e6 + 200 * 2 / 1e6, 5), Pricing::estimate('gemini-3-pro-image', 200, 1120, '2K'));
    Teste::igual(0.187, Pricing::estimate('gpt-image-2.5-flare', null, null, 'high'));
    Teste::igual(0.0, Pricing::estimate('modelo-desconhecido', 1, 1, 'x'));
});
