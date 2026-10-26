<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Config\Source;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\OptionSourceInterface;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Model\Client\BridgeRegistry;
use MageOS\AiBase\Model\Config\ConfigScopeResolver;
use MageOS\AiBase\Model\ServiceRegistry;
use MageOS\AiBase\Model\ServiceScope;

/**
 * Option source listing the AI services an administrator has configured, for reuse in the
 * system.xml of consumer modules:
 *
 *     <field id="ai_service" type="select" ...>
 *         <source_model>MageOS\AiBase\Model\Config\Source\ConfiguredService</source_model>
 *     </field>
 *
 * The stored value is the row id (AiServiceInterface::getId()), which resolves back through
 * AiServiceSelectorInterface::getById() or AiClientFactoryInterface::createById(). The service
 * code is deliberately not the value: the same backend can be registered more than once, with
 * different credentials or models, and those rows have to stay distinguishable.
 *
 * Every configured row is listed, including ones the bundled client cannot currently use. Modules
 * that call a provider with their own HTTP client do not need a bridge at all, so hiding those
 * rows would hide legitimate choices; instead the label says what is wrong with them.
 *
 * Scope: on the config edit page the rows listed are those of the scope being edited, read from the
 * page's `website` or `store` parameter, because a value saved for a website is resolved at runtime
 * against that website's services, and listing default's rows there offered ids the website may not
 * have. Without either parameter (default scope, or a page that is not a config edit page) the
 * selector's ambient scope answers, as it always did. A field saved on a website while a store view
 * below it overrides the services list is still offered the website's rows: one dropdown cannot show
 * every store view's list at once.
 *
 * Restricting the choice: a module whose feature works with one provider only (a Gemini-specific
 * API, say) declares a virtual type over this class with the `serviceCodes` argument set, and
 * points its field at that:
 *
 *     <virtualType name="Vendor\Module\Model\Config\Source\GeminiService"
 *                  type="MageOS\AiBase\Model\Config\Source\ConfiguredService">
 *         <arguments>
 *             <argument name="serviceCodes" xsi:type="array">
 *                 <item name="google" xsi:type="string">google</item>
 *             </argument>
 *         </arguments>
 *     </virtualType>
 *
 * Only rows of those providers are listed then. The filter is a constructor argument rather than
 * a subclass per provider because the set is open (a store's own OpenAI-compatible gateway counts
 * as OpenAI for one module and not for another) and because a module may accept several codes.
 */
class ConfiguredService implements OptionSourceInterface
{
    /**
     * Configuration key holding the selected model of a service row.
     */
    private const FIELD_MODEL = 'model';

    /**
     * @param AiServiceSelectorInterface $serviceSelector
     * @param ServiceRegistry $serviceRegistry
     * @param BridgeRegistry $bridgeRegistry
     * @param RequestInterface $request The config edit request, which names the scope being edited
     * @param ConfigScopeResolver $scopeResolver
     * @param ServiceScope $serviceScope
     * @param list<string> $serviceCodes Provider codes to list rows of; empty lists every provider
     */
    public function __construct(
        private readonly AiServiceSelectorInterface $serviceSelector,
        private readonly ServiceRegistry $serviceRegistry,
        private readonly BridgeRegistry $bridgeRegistry,
        private readonly RequestInterface $request,
        private readonly ConfigScopeResolver $scopeResolver,
        private readonly ServiceScope $serviceScope,
        private readonly array $serviceCodes = [],
    ) {
    }

    /**
     * @inheritdoc
     *
     * @return array<int,array{value:string,label:string}>
     */
    public function toOptionArray(): array
    {
        $options = $this->getConfiguredOptions();

        return $options === [] ? $this->getNothingConfiguredOptions() : $options;
    }

    /**
     * One option per configured row this source lists, in the admin's row order.
     *
     * @return array<int,array{value:string,label:string}>
     */
    protected function getConfiguredOptions(): array
    {
        return $this->numberDuplicateLabels(array_map(
            fn (AiServiceInterface $service): array => [
                'value' => $service->getId(),
                'label' => $this->getLabel($service),
            ],
            $this->getConfiguredServices(),
        ));
    }

    /**
     * What to offer when no row qualifies.
     *
     * Unrestricted, nothing: an empty dropdown is the state it always was, and the consuming
     * module decides what an unset field means. Restricted to specific providers, one empty-valued
     * option naming them: the module asked for a provider the administrator has not configured,
     * and an empty dropdown would leave them guessing which of ten providers to add. The empty
     * value is the same "nothing chosen" a consumer already handles.
     *
     * @return array<int,array{value:string,label:string}>
     */
    private function getNothingConfiguredOptions(): array
    {
        if ($this->serviceCodes === []) {
            return [];
        }

        return [[
            'value' => '',
            'label' => (string) __(
                'No %1 service configured yet. Add one under Stores > Configuration > Mage-OS > AI Configuration.',
                $this->listRestrictedProviders(),
            ),
        ]];
    }

    /**
     * The restricted providers' display names as one phrase: "OpenAI", "OpenAI or Anthropic",
     * "OpenAI, Anthropic or Azure OpenAI".
     *
     * @return string
     */
    private function listRestrictedProviders(): string
    {
        $names = array_map($this->getServiceName(...), $this->serviceCodes);
        $allButLast = implode(', ', array_slice($names, 0, -1));
        $last = implode('', array_slice($names, -1));

        return $allButLast === '' ? $last : sprintf('%s %s %s', $allButLast, __('or'), $last);
    }

    /**
     * The configured rows this source lists.
     *
     * Those of the scope being edited, or of the ambient scope when none is named, narrowed to the
     * providers this source is restricted to.
     *
     * @return list<AiServiceInterface>
     */
    private function getConfiguredServices(): array
    {
        return array_values(array_filter($this->getConfiguredServicesOfEveryProvider(), $this->isListed(...)));
    }

    /**
     * Whether a row belongs to a provider this source lists.
     *
     * @param AiServiceInterface $service
     * @return bool
     */
    private function isListed(AiServiceInterface $service): bool
    {
        return $this->serviceCodes === [] || in_array($service->getCode(), $this->serviceCodes, true);
    }

    /**
     * Every configured row of the scope being edited, or of the ambient scope when none is named.
     *
     * @return list<AiServiceInterface>
     */
    private function getConfiguredServicesOfEveryProvider(): array
    {
        $scope = $this->scopeResolver->fromRequest($this->request);
        if ($scope->isDefault()) {
            return $this->serviceSelector->getAll();
        }

        return $this->serviceScope->run($scope, fn (): array => $this->serviceSelector->getAll());
    }

    /**
     * Human-readable description of a configured row.
     *
     * Provider name, configured model, and any reason the bundled client could not use the row.
     *
     * @param AiServiceInterface $service
     * @return string
     */
    private function getLabel(AiServiceInterface $service): string
    {
        $name = $service->getLabel();
        $providerName = $this->getServiceName($service->getCode());

        // A row an administrator named is listed under that name, because the name is the purpose
        // they picked it for. The provider moves into the details, since a row called "Chat AI"
        // still has to say which backend it bills.
        $details = array_values(array_filter([
            $name === null ? null : $providerName,
            $this->getModel($service),
            $this->getUsabilityNote($service->getCode()),
        ]));

        return $details === []
            ? ($name ?? $providerName)
            : sprintf('%s (%s)', $name ?? $providerName, implode(', ', $details));
    }

    /**
     * Display name of a backend, falling back to its raw code.
     *
     * A row outlives the module that registered its provider, and an administrator who already
     * picked that row should still recognise it rather than see it vanish from the list.
     *
     * @param string $code
     * @return string
     */
    private function getServiceName(string $code): string
    {
        return $this->serviceRegistry->get($code)?->getName() ?? $code;
    }

    /**
     * Configured model of a row, or null when it carries none.
     *
     * @param AiServiceInterface $service
     * @return string|null
     */
    private function getModel(AiServiceInterface $service): ?string
    {
        $model = $service->getConfiguration()[self::FIELD_MODEL] ?? null;
        if (!is_scalar($model)) {
            return null;
        }

        return trim((string) $model) === '' ? null : trim((string) $model);
    }

    /**
     * Why the bundled client cannot use this backend, or null when it can.
     *
     * @param string $code
     * @return string|null
     */
    private function getUsabilityNote(string $code): ?string
    {
        if (!$this->bridgeRegistry->isSupported($code)) {
            return (string) __('no client bridge available');
        }

        return $this->bridgeRegistry->isAvailable($code) ? null : (string) __('bridge not installed');
    }

    /**
     * Suffix repeated labels with their position among their duplicates.
     *
     * Two rows of the same backend with the same model are a legitimate setup (two accounts, two
     * billing owners), and an administrator cannot pick between two identical option labels.
     *
     * @param array<int,array{value:string,label:string}> $options Option rows, each with a value and a label
     * @return array<int,array{value:string,label:string}>
     */
    private function numberDuplicateLabels(array $options): array
    {
        $occurrences = array_count_values(array_column($options, 'label'));
        $seen = [];

        return array_map(
            function (array $option) use ($occurrences, &$seen): array {
                $label = $option['label'];
                if ($occurrences[$label] < 2) {
                    return $option;
                }
                $seen[$label] = ($seen[$label] ?? 0) + 1;

                return $seen[$label] === 1
                    ? $option
                    : ['value' => $option['value'], 'label' => sprintf('%s #%d', $label, $seen[$label])];
            },
            $options,
        );
    }
}
