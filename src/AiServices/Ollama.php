<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Api\JsonFetcherInterface;
use MageOS\AiBase\Api\ModelListProviderInterface;

class Ollama extends AbstractAiService implements ModelListProviderInterface
{
    use ModelListTrait;

    /**
     * Default base URL of a local Ollama instance.
     */
    public const DEFAULT_BASE_URL = 'http://localhost:11434';

    /**
     * @param FieldDescriptorInterfaceFactory $fieldFactory
     * @param JsonFetcherInterface $modelListFetcher
     */
    public function __construct(
        FieldDescriptorInterfaceFactory $fieldFactory,
        private readonly JsonFetcherInterface $modelListFetcher,
    ) {
        parent::__construct($fieldFactory);
    }

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return 'ollama';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Ollama';
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
            $this->baseUrlField(self::DEFAULT_BASE_URL),
            $this->freeTextModelField(),
        ];
    }

    /**
     * @inheritdoc
     */
    public function fetchModels(#[\SensitiveParameter] array $configuration): array
    {
        $baseUrl = $this->resolveBaseUrl($configuration, self::DEFAULT_BASE_URL);
        $response = $this->modelListFetcher->getJson($baseUrl . '/api/tags');

        if (!isset($response['models']) || !is_array($response['models'])) {
            throw new LocalizedException(
                __('Unexpected model list response from %1: missing "models" list.', $this->getName())
            );
        }

        $models = [];
        foreach ($response['models'] as $entry) {
            if (!is_array($entry) || !isset($entry['name']) || !is_string($entry['name']) || $entry['name'] === '') {
                continue;
            }
            $models[$entry['name']] = $entry['name'];
        }

        return $models;
    }

    /**
     * The base URL alone: a local runtime's bridge factory takes its endpoint first and no API key.
     *
     * @param array<string,mixed> $configuration
     * @return list<mixed>
     */
    public function getPlatformArguments(#[\SensitiveParameter] array $configuration): array
    {
        return [$this->resolveBaseUrl($configuration, self::DEFAULT_BASE_URL)];
    }
}
