<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Client;

use MageOS\AiBase\Model\Client\BridgeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Model\Client\BridgeRegistry
 */
final class BridgeRegistryTest extends TestCase
{
    /**
     * "Supported" and "available" are deliberately different questions. A provider with no
     * bridge released upstream can never be made to work with the bundled client, whereas one
     * whose package is simply absent is one composer require away, and the admin form says
     * something different for each.
     */
    public function test_a_service_with_no_bridge_entry_is_neither_supported_nor_available(): void
    {
        $registry = new BridgeRegistry([]);

        self::assertFalse($registry->isSupported('acmeai'));
        self::assertFalse($registry->isAvailable('acmeai'));
        self::assertNull($registry->getPackage('acmeai'));
        self::assertNull($registry->getFactoryClass('acmeai'));
    }

    public function test_a_registered_but_uninstalled_bridge_is_supported_and_not_available(): void
    {
        $registry = new BridgeRegistry(['openai' => [
            'factory' => 'MageOS\\AiBase\\Test\\Unit\\Model\\Client\\NotInstalledFactory',
            'package' => 'symfony/ai-open-ai-platform',
        ]]);

        self::assertTrue($registry->isSupported('openai'));
        self::assertFalse($registry->isAvailable('openai'));
        self::assertSame('symfony/ai-open-ai-platform', $registry->getPackage('openai'));
    }

    public function test_an_installed_bridge_is_available(): void
    {
        $registry = new BridgeRegistry(['openai' => [
            'factory' => FakePlatformFactory::class,
            'package' => 'symfony/ai-open-ai-platform',
        ]]);

        self::assertTrue($registry->isAvailable('openai'));
        self::assertSame(FakePlatformFactory::class, $registry->getFactoryClass('openai'));
    }

    /**
     * A class that exists but has no createPlatform() cannot build a platform, so it must not
     * be reported as available; otherwise the admin is told to expect a working provider and
     * the failure surfaces later as an undefined-method error.
     */
    public function test_a_class_without_create_platform_is_not_available(): void
    {
        $registry = new BridgeRegistry(['openai' => [
            'factory' => self::class,
            'package' => 'symfony/ai-open-ai-platform',
        ]]);

        self::assertTrue($registry->isSupported('openai'));
        self::assertFalse($registry->isAvailable('openai'));
    }

    public function test_a_malformed_bridge_entry_does_not_report_a_package_or_factory(): void
    {
        $registry = new BridgeRegistry(['openai' => ['factory' => '', 'package' => '']]);

        self::assertNull($registry->getFactoryClass('openai'));
        self::assertNull($registry->getPackage('openai'));
        self::assertFalse($registry->isAvailable('openai'));
    }

    /**
     * Opper's entry fixes the generic bridge's endpoint paths and name; the registry hands them
     * back by parameter name so the client factory can pass them as named arguments.
     */
    public function test_it_reports_the_fixed_arguments_a_bridge_entry_declares(): void
    {
        $registry = new BridgeRegistry(['opper' => [
            'factory' => FakePlatformFactory::class,
            'arguments' => ['completionsPath' => '/v3/compat/chat/completions', 'supportsEmbeddings' => false],
        ]]);

        self::assertSame(
            ['completionsPath' => '/v3/compat/chat/completions', 'supportsEmbeddings' => false],
            $registry->getArguments('opper')
        );
    }

    public function test_it_reports_no_fixed_arguments_when_the_entry_declares_none(): void
    {
        $registry = new BridgeRegistry(['openai' => ['factory' => FakePlatformFactory::class]]);

        self::assertSame([], $registry->getArguments('openai'));
        self::assertSame([], $registry->getArguments('acmeai'));
    }

    /**
     * di.xml can only express scalars here, and a factory parameter of this kind takes nothing
     * else, so a nested array or an unnamed entry is a misregistration and must not reach the
     * factory as a named argument.
     */
    public function test_it_drops_fixed_arguments_that_are_not_named_scalars(): void
    {
        $registry = new BridgeRegistry(['opper' => [
            'arguments' => ['name' => 'opper', 'nested' => ['a' => 'b'], 0 => 'positional'],
        ]]);

        self::assertSame(['name' => 'opper'], $registry->getArguments('opper'));
    }

    /**
     * Anthropic reports cache reads and writes outside its prompt count, unlike every other
     * bridge, so the client needs a per-bridge signal to normalize on.
     */
    public function test_it_reports_cache_outside_prompt_for_a_bridge_that_declares_it(): void
    {
        $registry = new BridgeRegistry(['anthropic' => ['cache_outside_prompt' => true]]);

        self::assertTrue($registry->isCacheOutsidePrompt('anthropic'));
    }

    /**
     * A bridge that omits the flag is assumed to include cache tokens in its reported prompt
     * count, which is true for every bridge except Anthropic today.
     */
    public function test_it_reports_cache_inside_prompt_when_the_flag_is_missing(): void
    {
        $registry = new BridgeRegistry(['openai' => ['factory' => 'SomeFactory']]);

        self::assertFalse($registry->isCacheOutsidePrompt('openai'));
    }

    public function test_it_reports_cache_inside_prompt_for_an_unknown_service_code(): void
    {
        $registry = new BridgeRegistry([]);

        self::assertFalse($registry->isCacheOutsidePrompt('acmeai'));
    }

    /**
     * di.xml only has a boolean xsi:type interpreter built in; a third-party bridge registered
     * from its own di.xml with xsi:type="string" still needs to be read correctly.
     */
    public function test_it_reads_the_flag_from_a_string_boolean_as_di_xml_provides_it(): void
    {
        $registry = new BridgeRegistry(['acmeai' => ['cache_outside_prompt' => 'true']]);

        self::assertTrue($registry->isCacheOutsidePrompt('acmeai'));
    }

    /**
     * Every bridge that takes the model per request allows an override, so a bridge that says
     * nothing about it must too.
     */
    public function test_it_allows_a_model_override_when_the_flag_is_missing(): void
    {
        $registry = new BridgeRegistry(['openai' => ['factory' => 'SomeFactory']]);

        self::assertTrue($registry->allowsModelOverride('openai'));
    }

    /**
     * Azure's platform is built for one deployment, so di.xml switches overrides off for it.
     */
    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    #[\PHPUnit\Framework\Attributes\TestWith(['false'])]
    public function test_it_refuses_a_model_override_for_a_bridge_that_switches_it_off(bool|string $flag): void
    {
        $registry = new BridgeRegistry(['azure' => ['model_override' => $flag]]);

        self::assertFalse($registry->allowsModelOverride('azure'));
    }
}
