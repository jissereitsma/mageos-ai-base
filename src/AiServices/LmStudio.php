<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Api\JsonFetcherInterface;
use MageOS\AiBase\Api\ModelListProviderInterface;

class LmStudio extends AbstractAiService implements ModelListProviderInterface
{
    use ModelListTrait;

    /**
     * Default base URL of a local LM Studio instance.
     */
    public const DEFAULT_BASE_URL = 'http://localhost:1234';

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
        return 'lmstudio';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'LM Studio';
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
        $response = $this->modelListFetcher->getJson($baseUrl . '/v1/models');

        return $this->parseDataModelList($response);
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
