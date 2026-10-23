<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Api\PlatformArgumentsProviderInterface;

/**
 * Base class for a provider: the one supported way to implement AiServiceConfigurationInterface.
 *
 * A method added to that interface in a minor release lands here with a default at the same time,
 * so a provider extending this class keeps working without a change. The field builders give every
 * provider the same field names, which is what the admin form, the credential encryption and the
 * client factory read back.
 *
 * The constructor takes only the field factory on purpose: a subclass with dependencies of its own
 * calls `parent::__construct($fieldFactory)`, and that call must never have to change.
 *
 * Covered by this module's compatibility promise like every `@api` type, though Magento's coding
 * standard keeps that tag off abstract classes; see docs/CONSUMING.md, "What's stable".
 */
abstract class AbstractAiService implements AiServiceConfigurationInterface, PlatformArgumentsProviderInterface
{
    /**
     * Stored field holding the provider's API key.
     */
    protected const FIELD_API_KEY = 'api_key';

    /**
     * Stored field holding the selected model.
     */
    protected const FIELD_MODEL = 'model';

    /**
     * Stored field holding a self-hosted provider's base URL.
     */
    protected const FIELD_BASE_URL = 'base_url';

    /**
     * @param FieldDescriptorInterfaceFactory $fieldFactory
     */
    public function __construct(
        protected readonly FieldDescriptorInterfaceFactory $fieldFactory,
    ) {
    }

    /**
     * No curated list by default; the model field then takes free text.
     *
     * @return array<string,string> Map of model value => label
     */
    public function getSupportedModels(): array
    {
        return [];
    }

    /**
     * An API key and a model, which is all most hosted providers need.
     *
     * @return FieldDescriptorInterface[]
     */
    public function getConfigurationFields(): array
    {
        $supportedModels = $this->getSupportedModels();

        return [
            $this->apiKeyField(),
            $supportedModels === [] ? $this->freeTextModelField() : $this->modelField($supportedModels),
        ];
    }

    /**
     * The API key alone, which is what most hosted providers' bridge factories take first.
     *
     * @param array<string,mixed> $configuration
     * @return list<mixed>
     */
    public function getPlatformArguments(#[\SensitiveParameter] array $configuration): array
    {
        return [$this->stringValue($configuration, self::FIELD_API_KEY)];
    }

    /**
     * Build the standard API key password field, encrypted at rest and masked in the form.
     *
     * @return FieldDescriptorInterface
     */
    protected function apiKeyField(): FieldDescriptorInterface
    {
        return $this->fieldFactory->create([
            'name'      => self::FIELD_API_KEY,
            'label'     => 'API Key',
            'type'      => FieldDescriptorInterface::TYPE_PASSWORD,
            'encrypted' => true,
        ]);
    }

    /**
     * Build the standard model select field from a supported-models map.
     *
     * @param array<string,string> $supportedModels Map of model value => label
     * @return FieldDescriptorInterface
     */
    protected function modelField(array $supportedModels): FieldDescriptorInterface
    {
        $options = [];
        foreach ($supportedModels as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $this->fieldFactory->create([
            'name'    => self::FIELD_MODEL,
            'label'   => 'Model',
            'type'    => FieldDescriptorInterface::TYPE_SELECT,
            'options' => $options,
        ]);
    }

    /**
     * Build the standard base URL text field, flagged as an endpoint.
     *
     * The endpoint flag is what stops a masked credential from being carried over to a host typed
     * into this field in the same save; see FieldDescriptorInterface::isEndpoint().
     *
     * @param string $default
     * @param string|null $label Overrides the default "Base URL" label, e.g. to warn about a
     *        provider-specific pitfall in the field an administrator actually reads.
     * @return FieldDescriptorInterface
     */
    protected function baseUrlField(string $default, ?string $label = null): FieldDescriptorInterface
    {
        return $this->fieldFactory->create([
            'name'     => self::FIELD_BASE_URL,
            'label'    => $label ?? 'Base URL',
            'type'     => FieldDescriptorInterface::TYPE_TEXT,
            'default'  => $default,
            'endpoint' => true,
        ]);
    }

    /**
     * Build a free-text model field for services without a curated model list.
     *
     * @return FieldDescriptorInterface
     */
    protected function freeTextModelField(): FieldDescriptorInterface
    {
        return $this->fieldFactory->create([
            'name'  => self::FIELD_MODEL,
            'label' => 'Model',
            'type'  => FieldDescriptorInterface::TYPE_TEXT,
        ]);
    }

    /**
     * Read a string off a stored row, treating anything that is not one as absent.
     *
     * The row is whatever json_decode made of the stored value, so a hand-edited or half-saved
     * config can hold an array or an int where a credential belongs. Casting one would hand the
     * bridge "Array" and get back an authentication failure that names neither row nor field.
     *
     * @param array<string,mixed> $configuration
     * @param string $field
     * @param string $default
     * @return string
     */
    protected function stringValue(
        #[\SensitiveParameter] array $configuration,
        string $field,
        string $default = '',
    ): string {
        $value = $configuration[$field] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * The configured base URL, falling back to a default, without surrounding space or trailing slash.
     *
     * @param array<string,mixed> $configuration
     * @param string $default
     * @return string
     */
    protected function resolveBaseUrl(#[\SensitiveParameter] array $configuration, string $default): string
    {
        $baseUrl = trim($this->stringValue($configuration, self::FIELD_BASE_URL));

        return rtrim($baseUrl !== '' ? $baseUrl : $default, '/');
    }
}
