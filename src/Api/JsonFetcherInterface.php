<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api;

use Magento\Framework\Exception\LocalizedException;

/**
 * GET a URL and decode its JSON body, with errors an administrator can read.
 *
 * What a provider's {@see ModelListProviderInterface::fetchModels()} uses to call the provider's
 * model listing endpoint. Failures name the provider's host, never the full URL, since a
 * self-hosted base URL can carry a token in its query string, and the message ends up on the
 * admin page.
 *
 * Stable to call, not meant to be implemented: methods may be added in a minor release. To change
 * what it does, write a plugin on it instead of replacing it.
 *
 * @api
 */
interface JsonFetcherInterface
{
    /**
     * Perform a GET request and decode the JSON response body.
     *
     * @param string $url
     * @param array<string,string> $headers Header name => value
     * @return array<mixed> Decoded JSON response
     * @throws LocalizedException On transport failure, non-2xx status or invalid JSON
     */
    public function getJson(string $url, #[\SensitiveParameter] array $headers = []): array;
}
