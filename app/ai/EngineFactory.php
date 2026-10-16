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
        'IMAGE_ENGINE'       => 'gemini',
        'VERIFY_ENABLED'     => '1',
        'GEMINI_VISION_MODEL' => 'gemini-3.8-flash',
        'TEXT_ENGINE'        => 'gemini',
        'GEMINI_TEXT_MODEL'  => 'gemini-3.8-flash',
        'TEXT_TIMEOUT'       => '120',
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

    /** Motor de imagem usado na geração das páginas (IMAGE_ENGINE, padrão gemini). */
    public function defaultImageEngine(): ImageEngine
    {
        return $this->imageEngine($this->setting('IMAGE_ENGINE') ?: 'gemini');
    }

    /** Conferência automática: visão do Gemini, ou simulada com AI_MOCK=1. Null = desligada (VERIFY_ENABLED=0). */
    public function verifier(): ?Verifier
    {
        if (!in_array(strtolower($this->setting('VERIFY_ENABLED')), ['1', 'true', 'sim', 'on'], true)) {
            return null;
        }
        if ($this->mock()) {
            return new MockVerifier();
        }
        return new GeminiVerifier((string) env('GEMINI_API_KEY', ''), $this->setting('GEMINI_VISION_MODEL'), max(10, (int) $this->setting('TEXT_TIMEOUT')));
    }

    /** Motor de texto: TEXT_ENGINE=gemini (padrão) ou mock; com AI_MOCK=1 é sempre simulado. */
    public function textEngine(): TextEngine
    {
        $name = strtolower(trim($this->setting('TEXT_ENGINE')));
        if ($this->mock() || $name === 'mock') {
            return new MockEngine('mock', 'mock-text-v1');
        }
        if ($name === 'gemini') {
            return new GeminiText(
                (string) env('GEMINI_API_KEY', ''),
                $this->setting('GEMINI_TEXT_MODEL'),
                max(10, (int) $this->setting('TEXT_TIMEOUT')),
                max(0, (int) $this->setting('AI_RETRIES')),
                max(0.0, (float) $this->setting('AI_BACKOFF')) / 2
            );
        }
        throw new AiException("Motor de texto desconhecido: {$name}. Use gemini ou mock.");
    }
}
