<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

/**
 * Any server that speaks the de facto OpenAI Chat Completions wire format on a host of the
 * administrator's own choosing — a self-hosted gateway or aggregator (LiteLLM, an
 * OpenRouter-style proxy, Eden AI), not a specific vendor. Unlike Ollama and LM Studio this has
 * no sensible default host, so the field ships without one and an administrator must supply both
 * the endpoint and, where the server requires it, an API key.
 */
class OpenAiCompatible extends AbstractAiService
{
    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return 'openai_compatible';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'OpenAI-Compatible';
    }

    /**
     * @inheritdoc
     */
    public function getSupportedModels(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getConfigurationFields(): array
    {
        return [
            $this->baseUrlField('', 'Base URL (will strip /v1)'),
            $this->apiKeyField(),
            $this->freeTextModelField(),
        ];
    }

    /**
     * The base URL and, where the server wants one, the API key.
     *
     * Unlike Ollama and LM Studio this fronts no specific runtime, so it cannot assume the server is
     * unauthenticated; an empty key is passed as null, which the bridge reads as "send none".
     *
     * @param array<string,mixed> $configuration
     * @return list<mixed>
     */
    public function getPlatformArguments(#[\SensitiveParameter] array $configuration): array
    {
        $apiKey = $this->stringValue($configuration, self::FIELD_API_KEY);

        return [$this->resolveBaseUrlWithoutVersion($configuration), $apiKey !== '' ? $apiKey : null];
    }

    /**
     * The base URL with a trailing API version segment stripped.
     *
     * {@see \Symfony\AI\Platform\Bridge\Generic\Factory::createPlatform()}'s bridge always appends
     * `/v1/chat/completions` itself, so an administrator pasting the `/v1`-suffixed URL their gateway
     * shows them (as LiteLLM and most OpenAI-compatible gateways do) would otherwise double it into a
     * path the gateway 404s on, with nothing in the response pointing at why.
     *
     * @param array<string,mixed> $configuration
     * @return string
     */
    private function resolveBaseUrlWithoutVersion(#[\SensitiveParameter] array $configuration): string
    {
        $baseUrl = $this->resolveBaseUrl($configuration, '');

        return preg_replace('#/v1$#', '', $baseUrl) ?? $baseUrl;
    }
}
