<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Api\JsonFetcherInterface;
use MageOS\AiBase\Api\ModelListProviderInterface;

class OpenAi extends AbstractAiService implements ModelListProviderInterface
{
    use ModelListTrait;

    /**
     * OpenAI model listing endpoint.
     */
    private const MODELS_URL = 'https://api.openai.com/v1/models';

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
        return 'openai';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'OpenAI';
    }

    /**
     * Curated fallback for a row that has not refreshed its list from the API yet.
     *
     * Kept short on purpose: Refresh Models lists everything the account can use.
     *
     * @return array<string, string>
     */
    public function getSupportedModels(): array
    {
        return [
            'gpt-5.5'      => 'GPT-5.5',
            'gpt-5.4'      => 'GPT-5.4',
            'gpt-5.4-mini' => 'GPT-5.4 mini',
            'gpt-5.4-nano' => 'GPT-5.4 nano',
            'gpt-4.1'      => 'GPT-4.1',
        ];
    }

    /**
     * @inheritdoc
     */
    public function getConfigurationFields(): array
    {
        return [
            $this->apiKeyField(),
            $this->modelField($this->getSupportedModels()),
        ];
    }

    /**
     * @inheritdoc
     */
    public function fetchModels(#[\SensitiveParameter] array $configuration): array
    {
        $response = $this->modelListFetcher->getJson(self::MODELS_URL, [
            'Authorization' => 'Bearer ' . $this->resolveApiKey($configuration),
        ]);

        return $this->parseDataModelList($response);
    }
}
