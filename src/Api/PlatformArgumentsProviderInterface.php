<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api;

/**
 * The positional arguments a provider's Symfony AI bridge factory takes to build its platform.
 *
 * Bridge factories disagree on what `createPlatform()` takes first: a hosted provider wants its API
 * key, a local runtime its base URL, Azure an endpoint, deployment, API version and key. Knowing
 * that belongs with the provider, not with the client factory, or every provider with an unusual
 * signature needs a change to this module. The client factory passes these first and then adds
 * the optional named arguments the bridge declares (HTTP client, model catalogue), so only the
 * leading positional ones belong here.
 *
 * An optional capability a provider opts into next to {@see Data\AiServiceConfigurationInterface};
 * {@see \MageOS\AiBase\AiServices\AbstractAiService} implements it with the API key alone, which is
 * what most hosted providers take. Its methods do not change within a major version.
 *
 * @api
 */
interface PlatformArgumentsProviderInterface
{
    /**
     * Positional arguments for the bridge factory's `createPlatform()`, built from a stored row.
     *
     * @param array<string,mixed> $configuration The configured row's fields, credentials decrypted
     * @return list<mixed>
     */
    public function getPlatformArguments(#[\SensitiveParameter] array $configuration): array;
}
