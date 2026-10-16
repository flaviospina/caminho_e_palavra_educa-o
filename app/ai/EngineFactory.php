<?php
// app/ai/EngineFactory.php
// Cria os motores a partir do .env. Com AI_MOCK=1 todos viram MockEngine
// (rotulados com o nome do motor que substituem), sem gastar crédito.
declare(strict_types=1);

namespace App\Ai;

final class EngineFactory
{
    public const DEFAULTS = [
        'IMAGE_ENGINES'      => 'gemini,openai',
        'GEMINI_IMAGE_MODEL' => 'gemini-3-pro-image',
        'OPENAI_IMAGE_MODEL' => 'gpt-image-2.5-flare',
        'IMAGE_QUALITY'      => 'high',
        'AI_TIMEOUT'         => '360',
        'AI_RETRIES'         => '2',
        'AI_BACKOFF'         => '5',
    ];

    /** @param array<string,string> $overrides valores que substituem o .env (testes) */
    public function __construct(private array $overrides = [])
    {
    }

    public function setting(string $key): string
    {
        return (string) ($this->overrides[$key] ?? env($key, self::DEFAULTS[$key] ?? '') ?? '');
    }

    public function mock(): bool
    {
        if (array_key_exists('AI_MOCK', $this->overrides)) {
            return in_array(strtolower((string) $this->overrides['AI_MOCK']), ['1', 'true', 'sim', 'on'], true);
        }
        return AI_MOCK;
    }

    /** Nomes dos motores ativos (IMAGE_ENGINES), ex.: ['gemini', 'openai']. */
    public function enabledNames(): array
    {
        $names = array_filter(array_map('trim', explode(',', strtolower($this->setting('IMAGE_ENGINES')))));
        return array_values(array_unique($names));
    }

    /**
     * @param string[]|null $only limita aos nomes informados
     * @return array<string, ImageEngine> indexado pelo nome
     */
    public function imageEngines(?array $only = null): array
    {
        $names = $only === null ? $this->enabledNames() : array_map('strtolower', $only);
        $engines = [];
        foreach ($names as $name) {
            $engines[$name] = $this->imageEngine($name);
        }
        return $engines;
    }

    public function imageEngine(string $name): ImageEngine
    {
        $name = strtolower(trim($name));
        $timeout = max(10, (int) $this->setting('AI_TIMEOUT'));
        $retries = max(0, (int) $this->setting('AI_RETRIES'));
        $backoff = max(0.0, (float) $this->setting('AI_BACKOFF'));
        switch ($name) {
            case 'gemini':
                $model = $this->setting('GEMINI_IMAGE_MODEL');
                return $this->mock() ? new MockEngine('gemini', $model)
                    : new GeminiImage((string) env('GEMINI_API_KEY', ''), $model, $timeout, $retries, $backoff);
            case 'openai':
                $model = $this->setting('OPENAI_IMAGE_MODEL');
                return $this->mock() ? new MockEngine('openai', $model)
                    : new OpenAIImage((string) env('OPENAI_API_KEY', ''), $model, $timeout, $retries, $backoff);
            case 'mock':
                return new MockEngine('mock', 'mock-v1');
        }
        throw new AiException("Motor de imagem desconhecido: {$name}. Use gemini, openai ou mock.");
    }

    /** Motor de texto (Fase 1: sempre simulado; o adaptador real chega na Fase 3). */
    public function textEngine(): TextEngine
    {
        return new MockEngine('mock', 'mock-text-v1');
    }
}
