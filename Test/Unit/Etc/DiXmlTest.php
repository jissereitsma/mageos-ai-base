<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * Guards the `BridgeRegistry` `bridges` argument in di.xml, the one place that decides which
 * provider's cache tokens the client normalizes as living outside the reported prompt count.
 */
final class DiXmlTest extends TestCase
{
    private SimpleXMLElement $config;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 3) . '/src/etc/di.xml';
        $this->config = new SimpleXMLElement((string) file_get_contents($path));
    }

    /**
     * Anthropic's Messages API is the one bridge known today whose reported prompt count
     * excludes cache reads and writes, so only its bridge entry may declare the flag true.
     */
    public function test_it_declares_cache_outside_prompt_true_for_anthropic_only(): void
    {
        $bridgeItems = $this->config->xpath(
            '//type[@name="MageOS\AiBase\Model\Client\BridgeRegistry"]/arguments/argument[@name="bridges"]/item',
        );
        self::assertNotEmpty($bridgeItems);

        foreach ($bridgeItems as $bridgeItem) {
            $serviceCode = (string) $bridgeItem['name'];
            $flag = $bridgeItem->xpath('item[@name="cache_outside_prompt"]')[0] ?? null;

            if ($serviceCode === 'anthropic') {
                self::assertNotNull($flag, $serviceCode);
                self::assertSame('true', (string) $flag);
                continue;
            }

            self::assertNull($flag, $serviceCode);
        }
    }

    /**
     * A bridge naming a dialect that is not declared is passed through untouched, which for an
     * opencode server means a caller's `max_tokens` is sent as a body field the server silently
     * ignores. The empty map is what turns that into an error instead, so its absence has to fail.
     */
    public function test_the_opencode_server_dialect_is_declared_with_an_empty_map(): void
    {
        $dialect = $this->config->xpath(
            '//type[@name="MageOS\AiBase\Model\Client\BridgeRegistry"]/arguments/argument[@name="bridges"]'
            . '/item[@name="opencode-custom"]/item[@name="dialect"]',
        )[0] ?? null;
        self::assertSame('opencode_server', (string) $dialect);

        $map = $this->config->xpath(
            '//type[@name="MageOS\AiBase\Model\Client\OptionNormalizer"]/arguments/argument[@name="dialects"]'
            . '/item[@name="opencode_server"]/item[@name="map"]',
        );
        self::assertCount(1, $map, 'The opencode_server dialect must declare a map.');
        self::assertCount(0, $map[0]->children(), 'The opencode_server map must stay empty.');
    }
}
