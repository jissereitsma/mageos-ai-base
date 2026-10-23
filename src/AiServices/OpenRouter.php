<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Api\JsonFetcherInterface;
use MageOS\AiBase\Api\ModelListProviderInterface;

class OpenRouter extends AbstractAiService implements ModelListProviderInterface
{
    use ModelListTrait;

    /**
     * OpenRouter model listing endpoint (public; auth optional).
     */
    private const MODELS_URL = 'https://openrouter.ai/api/v1/models';

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
        return 'openrouter';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'OpenRouter';
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
            $this->apiKeyField(),
            $this->freeTextModelField(),
        ];
    }

    /**
     * @inheritdoc
     */
    public function fetchModels(#[\SensitiveParameter] array $configuration): array
    {
        $headers = [];
        $apiKey = $this->resolveApiKey($configuration);
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }
        $response = $this->modelListFetcher->getJson(self::MODELS_URL, $headers);

        return $this->parseDataModelList($response, 'name');
    }
}
