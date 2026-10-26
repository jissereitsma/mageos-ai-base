<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Config\Source;

require_once __DIR__ . '/../../../Stubs/FixedConfigScopeResolver.php';

use Magento\Framework\App\RequestInterface;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Model\AiService;
use MageOS\AiBase\Model\Client\BridgeRegistry;
use MageOS\AiBase\Model\Config\ConfigScope;
use MageOS\AiBase\Model\Config\ConfigScopeResolver;
use MageOS\AiBase\Model\Config\Source\ConfiguredService;
use MageOS\AiBase\Model\Config\Source\ConfiguredServiceWithAutomatic;
use MageOS\AiBase\Model\ServiceRegistry;
use MageOS\AiBase\Model\ServiceScope;
use MageOS\AiBase\Test\Unit\Stubs\FixedConfigScopeResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ConfiguredServiceTest extends TestCase
{
    private AiServiceSelectorInterface&MockObject $serviceSelector;
    private ServiceScope $serviceScope;
    private ConfigScopeResolver $scopeResolver;

    protected function setUp(): void
    {
        $this->serviceSelector = $this->createMock(AiServiceSelectorInterface::class);
        $this->serviceScope = new ServiceScope();
        $this->scopeResolver = FixedConfigScopeResolver::atDefault();
    }

    /**
     * On a website's config page the dropdown lists that website's rows, because a value saved there
     * is resolved at runtime against that website's services.
     */
    public function test_lists_the_rows_of_the_scope_being_edited(): void
    {
        $this->scopeResolver = new FixedConfigScopeResolver(new ConfigScope('websites', 2, 'second'));
        $this->serviceSelector->method('getAll')->willReturnCallback(
            fn (): array => $this->serviceScope->getCurrent()?->getCode() === 'second'
                ? [new AiService('_website_row', 'openai', ['model' => 'gpt-4o'])]
                : [new AiService('_default_row', 'openai', ['model' => 'gpt-4o'])]
        );

        self::assertSame(['_website_row'], array_column($this->subject()->toOptionArray(), 'value'));
        self::assertNull($this->serviceScope->getCurrent());
    }

    /**
     * Without a website or store on the page nothing changes: the selector answers in its ambient
     * scope, exactly as it did before the option source knew about scopes.
     */
    public function test_leaves_the_ambient_scope_alone_when_no_scope_is_being_edited(): void
    {
        $scopes = [];
        $this->serviceSelector->method('getAll')->willReturnCallback(
            function () use (&$scopes): array {
                $scopes[] = $this->serviceScope->getCurrent();

                return [];
            }
        );

        $this->subject()->toOptionArray();

        self::assertSame([null], $scopes);
    }

    public function test_offers_no_options_when_nothing_is_configured(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([]);

        self::assertSame([], $this->subject()->toOptionArray());
    }

    /**
     * The stored value is the row id rather than the service code, because the code cannot tell
     * two rows of the same provider apart.
     */
    public function test_uses_the_row_id_as_the_stored_value(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'openai', ['model' => 'gpt-4o']),
            new AiService('_row_b', 'anthropic', ['model' => 'claude-sonnet-4-6']),
        ]);

        self::assertSame(
            ['_row_a', '_row_b'],
            array_column($this->subject()->toOptionArray(), 'value')
        );
    }

    /**
     * A row an administrator named is listed under that name: the name is the purpose they picked
     * it for, which is what they recognise in another module's configuration. The provider moves
     * into the details rather than disappearing, since the row still bills a specific backend.
     */
    public function test_labels_a_named_row_by_its_name_and_keeps_the_provider_visible(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'openai', ['_label' => 'Chat AI', 'api_key' => 'k', 'model' => 'gpt-4o']),
        ]);

        self::assertSame('Chat AI (OpenAI, gpt-4o)', (string) $this->subject()->toOptionArray()[0]['label']);
    }

    public function test_a_blank_name_falls_back_to_the_provider_name(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'openai', ['_label' => '   ', 'api_key' => 'k', 'model' => 'gpt-4o']),
        ]);

        self::assertSame('OpenAI (gpt-4o)', (string) $this->subject()->toOptionArray()[0]['label']);
    }

    /**
     * Two rows of one provider named for their purpose are exactly the setup naming exists for, so
     * they must not collapse into the same label and get numbered instead.
     */
    public function test_two_named_rows_of_one_provider_stay_distinguishable(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'openai', ['_label' => 'Chat AI', 'model' => 'gpt-4o']),
            new AiService('_row_b', 'openai', ['_label' => 'Summaries', 'model' => 'gpt-4o']),
        ]);

        $labels = array_map(
            static fn (array $option): string => (string) $option['label'],
            $this->subject()->toOptionArray()
        );

        self::assertSame(['Chat AI (OpenAI, gpt-4o)', 'Summaries (OpenAI, gpt-4o)'], $labels);
    }

    public function test_labels_a_row_with_the_provider_name_and_its_configured_model(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'openai', ['api_key' => 'k', 'model' => 'gpt-4o']),
        ]);

        self::assertSame('OpenAI (gpt-4o)', (string) $this->subject()->toOptionArray()[0]['label']);
    }

    public function test_labels_a_row_without_a_model_by_provider_name_alone(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'openai', ['api_key' => 'k']),
            new AiService('_row_b', 'openai', ['api_key' => 'k', 'model' => '   ']),
        ]);

        $labels = array_map('strval', array_column($this->subject()->toOptionArray(), 'label'));

        self::assertSame(['OpenAI', 'OpenAI #2'], $labels);
    }

    /**
     * A row can outlive the module that registered its provider. Dropping it would silently
     * change what an administrator already picked, so it stays selectable under its raw code.
     */
    public function test_labels_a_row_of_an_unregistered_provider_with_its_raw_code(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'somevendor', ['model' => 'some-model']),
        ]);

        self::assertSame(
            'somevendor (some-model, no client bridge available)',
            (string) $this->subject()->toOptionArray()[0]['label']
        );
    }

    /**
     * Picking a provider whose bridge is missing fails only later, when something tries to call
     * it. Saying so in the option label moves that discovery to the moment of choosing.
     */
    public function test_marks_a_row_whose_bridge_package_is_not_installed(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'ollama', ['model' => 'llama3']),
        ]);

        self::assertSame(
            'Ollama (llama3, bridge not installed)',
            (string) $this->subject()->toOptionArray()[0]['label']
        );
    }

    public function test_leaves_a_row_unmarked_when_its_bridge_is_installed(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'openai', ['model' => 'gpt-4o']),
        ]);

        self::assertSame('OpenAI (gpt-4o)', (string) $this->subject()->toOptionArray()[0]['label']);
    }

    /**
     * Registering the same backend twice with the same model is legitimate (two accounts, two
     * billing owners), and produces two rows an administrator has to be able to tell apart.
     */
    public function test_numbers_rows_that_would_otherwise_share_a_label(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'openai', ['api_key' => 'k1', 'model' => 'gpt-4o']),
            new AiService('_row_b', 'openai', ['api_key' => 'k2', 'model' => 'gpt-4o']),
            new AiService('_row_c', 'openai', ['api_key' => 'k3', 'model' => 'o1-mini']),
        ]);

        $labels = array_map('strval', array_column($this->subject()->toOptionArray(), 'label'));

        self::assertSame(['OpenAI (gpt-4o)', 'OpenAI (gpt-4o) #2', 'OpenAI (o1-mini)'], $labels);
    }

    public function test_ignores_a_non_scalar_model_value(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'openai', ['model' => ['gpt-4o']]),
        ]);

        self::assertSame('OpenAI', (string) $this->subject()->toOptionArray()[0]['label']);
    }

    public function test_automatic_variant_prepends_an_empty_valued_option(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'openai', ['model' => 'gpt-4o']),
        ]);

        $options = $this->automaticSubject()->toOptionArray();

        self::assertCount(2, $options);
        self::assertSame('', $options[0]['value']);
        self::assertSame('Automatic (first usable service)', (string) $options[0]['label']);
        self::assertSame('_row_a', $options[1]['value']);
    }

    public function test_automatic_variant_still_offers_the_automatic_option_with_nothing_configured(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([]);

        $options = $this->automaticSubject()->toOptionArray();

        self::assertCount(1, $options);
        self::assertSame('', $options[0]['value']);
    }

    /**
     * A module whose feature only works with one provider restricts the dropdown to that provider's
     * rows, so an administrator cannot point a Gemini-only feature at an OpenAI key.
     */
    public function test_lists_only_the_rows_of_the_providers_it_is_restricted_to(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'openai', ['model' => 'gpt-4o']),
            new AiService('_row_b', 'anthropic', ['model' => 'claude-sonnet-4-6']),
            new AiService('_row_c', 'openai', ['model' => 'o1-mini']),
        ]);

        self::assertSame(
            ['_row_a', '_row_c'],
            array_column($this->subject(['openai'])->toOptionArray(), 'value')
        );
    }

    public function test_a_restriction_may_name_several_providers(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'openai', ['model' => 'gpt-4o']),
            new AiService('_row_b', 'anthropic', ['model' => 'claude-sonnet-4-6']),
            new AiService('_row_c', 'ollama', ['model' => 'llama3']),
        ]);

        self::assertSame(
            ['_row_a', '_row_b'],
            array_column($this->subject(['openai', 'anthropic'])->toOptionArray(), 'value')
        );
    }

    /**
     * An empty dropdown would leave the administrator guessing which of ten providers the module
     * wants. The placeholder names it, and carries the empty value a consumer already treats as
     * "nothing chosen".
     */
    public function test_names_the_missing_provider_when_none_of_its_rows_is_configured(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'openai', ['model' => 'gpt-4o']),
        ]);

        $options = $this->subject(['anthropic'])->toOptionArray();

        self::assertCount(1, $options);
        self::assertSame('', $options[0]['value']);
        self::assertSame(
            'No Anthropic service configured yet. Add one under Stores > Configuration > Mage-OS > AI Configuration.',
            (string) $options[0]['label']
        );
    }

    public function test_the_placeholder_names_every_restricted_provider_as_alternatives(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([]);

        self::assertStringContainsString(
            'No OpenAI or Anthropic service configured yet.',
            (string) $this->subject(['openai', 'anthropic'])->toOptionArray()[0]['label']
        );
        self::assertStringContainsString(
            'No OpenAI, Anthropic or Ollama service configured yet.',
            (string) $this->subject(['openai', 'anthropic', 'ollama'])->toOptionArray()[0]['label']
        );
    }

    /**
     * The placeholder stands in for an empty dropdown and nothing else. One matching row is a
     * usable dropdown, so the providers without rows are not mentioned next to it.
     */
    public function test_one_matching_row_is_listed_without_a_placeholder_for_the_other_providers(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'openai', ['model' => 'gpt-4o']),
        ]);

        $options = $this->subject(['openai', 'anthropic'])->toOptionArray();

        self::assertSame(['_row_a'], array_column($options, 'value'));
        self::assertStringNotContainsString('Anthropic', (string) $options[0]['label']);
    }

    public function test_the_placeholder_falls_back_to_the_raw_code_of_an_unregistered_provider(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([]);

        self::assertStringContainsString(
            'No somevendor service configured yet.',
            (string) $this->subject(['somevendor'])->toOptionArray()[0]['label']
        );
    }

    /**
     * "Automatic" is already the empty-valued fallback; a second empty-valued option next to it
     * could not be told apart once saved.
     */
    public function test_restricted_automatic_variant_offers_only_automatic_when_nothing_matches(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'openai', ['model' => 'gpt-4o']),
        ]);

        $options = $this->automaticSubject(['anthropic'])->toOptionArray();

        self::assertCount(1, $options);
        self::assertSame('Automatic (first usable service)', (string) $options[0]['label']);
    }

    public function test_restricted_automatic_variant_lists_automatic_before_the_matching_rows(): void
    {
        $this->serviceSelector->method('getAll')->willReturn([
            new AiService('_row_a', 'openai', ['model' => 'gpt-4o']),
            new AiService('_row_b', 'anthropic', ['model' => 'claude-sonnet-4-6']),
        ]);

        self::assertSame(
            ['', '_row_b'],
            array_column($this->automaticSubject(['anthropic'])->toOptionArray(), 'value')
        );
    }

    /**
     * @param list<string> $serviceCodes
     */
    private function subject(array $serviceCodes = []): ConfiguredService
    {
        return new ConfiguredService(
            $this->serviceSelector,
            $this->services(),
            $this->bridges(),
            $this->createStub(RequestInterface::class),
            $this->scopeResolver,
            $this->serviceScope,
            $serviceCodes,
        );
    }

    /**
     * @param list<string> $serviceCodes
     */
    private function automaticSubject(array $serviceCodes = []): ConfiguredServiceWithAutomatic
    {
        return new ConfiguredServiceWithAutomatic(
            $this->serviceSelector,
            $this->services(),
            $this->bridges(),
            $this->createStub(RequestInterface::class),
            $this->scopeResolver,
            $this->serviceScope,
            $serviceCodes,
        );
    }

    private function services(): ServiceRegistry
    {
        return new ServiceRegistry([
            $this->service('openai', 'OpenAI'),
            $this->service('anthropic', 'Anthropic'),
            $this->service('ollama', 'Ollama'),
        ]);
    }

    private function service(string $code, string $name): AiServiceConfigurationInterface
    {
        $service = $this->createMock(AiServiceConfigurationInterface::class);
        $service->method('getCode')->willReturn($code);
        $service->method('getName')->willReturn($name);

        return $service;
    }

    private function bridges(): BridgeRegistry
    {
        return new BridgeRegistry([
            'openai' => ['factory' => InstalledBridgeFactory::class, 'package' => 'symfony/ai-open-ai-platform'],
            'anthropic' => ['factory' => InstalledBridgeFactory::class, 'package' => 'symfony/ai-anthropic-platform'],
            'ollama' => ['factory' => 'Absent\\Ollama\\Factory', 'package' => 'symfony/ai-ollama-platform'],
        ]);
    }
}

/**
 * Stand-in for an installed Symfony AI bridge Factory.
 */
final class InstalledBridgeFactory
{
    public static function createPlatform(string $apiKey): object
    {
        return new \stdClass();
    }
}
