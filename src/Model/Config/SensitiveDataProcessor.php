<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Config;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Model\ServiceRegistry;

/**
 * Encrypts/decrypts sensitive keys inside a single service configuration array.
 *
 * Sensitivity is decided by the registered provider field schema: a field is sensitive
 * when its descriptor reports isEncrypted(). For service codes without a registered
 * schema, or for fields the schema does not describe, a field-name heuristic is used
 * as a fallback (see SENSITIVE_NAME_SUFFIXES). Which fields name the host a row talks to is
 * decided from isEndpoint(), plus FALLBACK_ENDPOINT_KEYS for every row.
 *
 * Shared by the config backend model (write path) and the service selector (read path).
 */
class SensitiveDataProcessor
{
    /**
     * Placeholder shown in the admin form instead of a stored credential.
     */
    public const OBSCURED_PLACEHOLDER = '******';

    /**
     * Name-based fallback for fields not covered by a registered field schema.
     *
     * A field counts as a credential when its name, lowercased and with `_` and `-` removed, ends
     * in one of these. Matching the end of the name covers the conventions a third-party provider
     * is likely to use (`api_key`, `apiKey`, `client_secret`, `access_token`, `bearer_token`)
     * without catching names that merely mention one, such as `max_tokens` or `token_endpoint`.
     *
     * Kept for two reasons: stored rows may belong to a third-party provider whose module was
     * since removed (its schema is no longer registered, but its stored credentials must stay
     * protected), and as defense in depth for provider fields that hold credentials but were not
     * flagged as encrypted. It is a safety net, not the mechanism: a provider marks each credential
     * field `'encrypted' => true` in its descriptor, which is authoritative in both directions.
     */
    private const SENSITIVE_NAME_SUFFIXES = [
        'apikey',
        'accesskey',
        'secretkey',
        'privatekey',
        'authkey',
        'token',
        'secret',
        'password',
        'passwd',
        'passphrase',
        'credential',
        'credentials',
        'bearer',
    ];

    /**
     * Fields assumed to name the host a row's credentials are sent to, for a row whose provider is
     * no longer registered.
     *
     * A registered provider says which of its fields are endpoints through
     * FieldDescriptorInterface::isEndpoint(), so a third-party host field called `host`, `api_base`
     * or `url` is guarded as well. These names are guarded on top of that, for every row: a row
     * whose provider module was removed has no descriptors left but can still be edited and saved,
     * and a provider written before the endpoint flag existed declares its `base_url` without it,
     * yet had that field guarded by name all along.
     */
    private const FALLBACK_ENDPOINT_KEYS = ['base_url', 'endpoint'];

    /**
     * Magento encryptor envelope, e.g. "0:3:<base64>". Values not matching this
     * pattern are treated as plaintext so pre-encryption rows keep working.
     */
    private const ENCRYPTED_ENVELOPE_PATTERN = '/^\d+:\d+:.+$/';

    /**
     * An http or https URL with a host. See getNonHttpEndpointFieldNames() for why nothing else.
     */
    private const HTTP_URL_PATTERN = '~^https?://[^/?#\s]+~i';

    /**
     * Lazily built map of service code => [field name => descriptor].
     *
     * @var array<string,array<string,FieldDescriptorInterface>>|null
     */
    private ?array $fieldSchema = null;

    /**
     * @param EncryptorInterface $encryptor
     * @param ServiceRegistry $serviceRegistry Registered AI backends providing the field schema
     */
    public function __construct(
        private readonly EncryptorInterface $encryptor,
        private readonly ServiceRegistry $serviceRegistry,
    ) {
    }

    /**
     * Encrypt sensitive values in a service configuration row.
     *
     * @param string $serviceCode
     * @param array<array-key,mixed> $configuration
     * @return array<array-key,mixed>
     */
    public function encryptRow(string $serviceCode, #[\SensitiveParameter] array $configuration): array
    {
        return $this->processRow(
            $serviceCode,
            $configuration,
            fn (string $value) => $this->isEncrypted($value) ? $value : $this->encryptor->encrypt($value),
        );
    }

    /**
     * Decrypt sensitive values in a service configuration row.
     *
     * Plaintext values (rows saved before encryption was introduced) are returned unchanged.
     *
     * @param string $serviceCode
     * @param array<array-key,mixed> $configuration
     * @return array<array-key,mixed>
     */
    public function decryptRow(string $serviceCode, array $configuration): array
    {
        return $this->processRow(
            $serviceCode,
            $configuration,
            fn (string $value) => $this->isEncrypted($value) ? $this->encryptor->decrypt($value) : $value,
        );
    }

    /**
     * Replace sensitive values with the obscured placeholder for admin form display.
     *
     * @param string $serviceCode
     * @param array<array-key,mixed> $configuration
     * @return array<array-key,mixed>
     */
    public function maskRow(string $serviceCode, array $configuration): array
    {
        return $this->processRow(
            $serviceCode,
            $configuration,
            static fn (): string => self::OBSCURED_PLACEHOLDER,
        );
    }

    /**
     * Restore previously stored credentials where the submitted value is the placeholder.
     *
     * A submitted placeholder means "keep the stored value"; if no stored value exists
     * for the key (e.g. the row is new), the placeholder is discarded to avoid persisting
     * the literal placeholder as a credential.
     *
     * @param string $serviceCode
     * @param array<array-key,mixed> $configuration Submitted service configuration row
     * @param array<array-key,mixed> $previous Previously stored (still encrypted) configuration row
     * @return array<array-key,mixed>
     */
    public function restoreRow(string $serviceCode, #[\SensitiveParameter] array $configuration, array $previous): array
    {
        $redirected = $this->isRedirected($serviceCode, $configuration, $previous);

        foreach ($configuration as $key => $value) {
            if ($value === self::OBSCURED_PLACEHOLDER && $this->isSensitive($serviceCode, (string)$key)) {
                $stored = $previous[$key] ?? '';
                $stored = is_string($stored) ? $stored : '';
                if ($redirected && $stored !== '') {
                    throw new LocalizedException(
                        __(
                            'The endpoint of the "%1" service changed, so its %2 has to be entered '
                            . 'again. A stored credential is never carried over to a host it was '
                            . 'not issued for.',
                            $serviceCode,
                            str_replace('_', ' ', (string)$key)
                        )
                    );
                }
                $configuration[$key] = $stored;
            }
        }

        return $configuration;
    }

    /**
     * Names of the endpoint fields in a row that hold something other than an http(s) URL.
     *
     * The two HTTP clients behind a row disagree on what they accept: the chat client refuses
     * anything but http and https at call time, while Refresh Models goes through Magento's cURL
     * client, which also speaks FTP. Refusing at save time keeps one rule for both, and tells the
     * administrator while the field is still in front of them. A URL without a scheme is refused
     * too, since neither client can call it. An empty endpoint means the provider's default.
     *
     * @param string $serviceCode
     * @param array<array-key,mixed> $configuration Submitted configuration row
     * @return list<string>
     */
    public function getNonHttpEndpointFieldNames(
        string $serviceCode,
        #[\SensitiveParameter] array $configuration,
    ): array {
        return array_values(array_filter(
            $this->getEndpointFieldNames($serviceCode),
            fn (string $key): bool => $this->normalizeEndpoint($configuration[$key] ?? null) !== ''
                && preg_match(self::HTTP_URL_PATTERN, $this->normalizeEndpoint($configuration[$key])) !== 1,
        ));
    }

    /**
     * Names of the fields in a stored row that hold an encrypted credential.
     *
     * Re-encrypting after an encryption key change has to touch exactly the values encryptRow()
     * encrypted and nothing else: decrypting a plain setting would turn it into an empty string,
     * and a legacy plaintext credential has no ciphertext to re-encrypt. Deciding that here keeps
     * the one definition of "sensitive" (schema first, name heuristic for unregistered rows) and of
     * the encryptor envelope in this class.
     *
     * @param string $serviceCode
     * @param array<array-key,mixed> $configuration Stored (still encrypted) configuration row
     * @return list<string>
     */
    public function getEncryptedFieldNames(string $serviceCode, array $configuration): array
    {
        return array_map(
            'strval',
            array_keys(array_filter(
                $configuration,
                fn (mixed $value, int|string $key): bool => is_string($value)
                    && $this->isEncrypted($value)
                    && $this->isSensitive($serviceCode, (string)$key),
                ARRAY_FILTER_USE_BOTH,
            )),
        );
    }

    /**
     * Whether this save points a row at a different host than the one stored for it.
     *
     * The obscured placeholder exists so an administrator can save the form without ever seeing a
     * stored credential. An editable endpoint would hand it back to them: point the row at a host
     * you control, leave the key masked so it is restored from storage, press Test Connection, and
     * read the credential off your own server. Whoever moves the endpoint therefore has to supply
     * the credential for it, which is something only someone who already holds it can do.
     *
     * An endpoint missing from the stored row counts as empty, so filling one in where none was
     * stored is a move like any other: otherwise a stored row without that field (saved before the
     * provider gained it, or edited by hand) would let the first host typed in receive the key.
     * Empty on both sides is not a move. A brand-new row has no stored credential to leak, which
     * restoreRow() handles by only refusing when there is one.
     *
     * @param string $serviceCode
     * @param array<array-key,mixed> $configuration Submitted service configuration row
     * @param array<array-key,mixed> $previous Previously stored configuration row
     * @return bool
     */
    private function isRedirected(
        string $serviceCode,
        #[\SensitiveParameter] array $configuration,
        array $previous,
    ): bool {
        return array_filter(
            $this->getEndpointFieldNames($serviceCode),
            fn (string $key): bool => $this->normalizeEndpoint($previous[$key] ?? null)
                !== $this->normalizeEndpoint($configuration[$key] ?? null),
        ) !== [];
    }

    /**
     * The fields of a service that name the host its credentials are sent to.
     *
     * The fields the registered schema flags, plus FALLBACK_ENDPOINT_KEYS whether the provider is
     * registered or not. A field named like an endpoint is never treated as anything else: guarding
     * one that turns out not to be costs a retyped key, missing one costs the key itself.
     *
     * @param string $serviceCode
     * @return list<string>
     */
    private function getEndpointFieldNames(string $serviceCode): array
    {
        $flaggedNames = array_map(
            static fn (FieldDescriptorInterface $field): string => $field->getName(),
            array_filter(
                $this->getFieldSchema()[$serviceCode] ?? [],
                static fn (FieldDescriptorInterface $field): bool => $field->isEndpoint(),
            ),
        );

        return array_values(array_unique([...self::FALLBACK_ENDPOINT_KEYS, ...$flaggedNames]));
    }

    /**
     * An endpoint value reduced to what decides the host, so cosmetic edits are not a move.
     *
     * Surrounding space and a trailing slash do not change where a request goes; anything that is
     * not a string (absent, null, a hand-edited array) is treated as empty.
     *
     * @param mixed $value
     * @return string
     */
    private function normalizeEndpoint(mixed $value): string
    {
        return is_string($value) ? rtrim(trim($value), '/') : '';
    }

    /**
     * Apply a processor to every sensitive string value in the row.
     *
     * @param string $serviceCode
     * @param array<array-key,mixed> $configuration
     * @param callable $processor
     * @return array<array-key,mixed>
     */
    private function processRow(
        string $serviceCode,
        #[\SensitiveParameter] array $configuration,
        callable $processor,
    ): array {
        foreach ($configuration as $key => $value) {
            if (is_string($value) && $value !== '' && $this->isSensitive($serviceCode, (string)$key)) {
                $configuration[$key] = $processor($value);
            }
        }

        return $configuration;
    }

    /**
     * Whether a configuration key holds a credential.
     *
     * The registered field schema is authoritative when it describes the field;
     * otherwise the SENSITIVE_NAME_SUFFIXES name heuristic applies (see its docblock).
     *
     * @param string $serviceCode
     * @param string $key
     * @return bool
     */
    private function isSensitive(string $serviceCode, string $key): bool
    {
        $field = $this->getFieldSchema()[$serviceCode][$key] ?? null;
        if ($field !== null) {
            return $field->isEncrypted();
        }

        return $this->isNamedLikeACredential($key);
    }

    /**
     * Whether a field name alone says it holds a credential.
     *
     * @param string $key
     * @return bool
     */
    private function isNamedLikeACredential(string $key): bool
    {
        $name = str_replace(['_', '-'], '', strtolower($key));

        return array_filter(
            self::SENSITIVE_NAME_SUFFIXES,
            static fn (string $suffix): bool => str_ends_with($name, $suffix),
        ) !== [];
    }

    /**
     * Build (once) the field schema from the registered services.
     *
     * Every registered code gets an entry, even one without fields, so getEndpointFieldNames() can
     * tell a registered provider that declares no endpoint from one that is not registered at all.
     *
     * @return array<string,array<string,FieldDescriptorInterface>>
     */
    private function getFieldSchema(): array
    {
        if ($this->fieldSchema === null) {
            $this->fieldSchema = [];
            foreach ($this->serviceRegistry->getAll() as $code => $service) {
                $this->fieldSchema[$code] = [];
                foreach ($service->getConfigurationFields() as $field) {
                    $this->fieldSchema[$code][$field->getName()] = $field;
                }
            }
        }

        return $this->fieldSchema;
    }

    /**
     * Whether a value already carries the encryptor envelope.
     *
     * Public so ServiceImporter can tell a ciphertext another module stored from a plaintext key
     * with the same definition this class encrypts and decrypts by. That matters more than it
     * looks: Magento's encryptor does not refuse a plaintext, it decrypts one as a legacy
     * Blowfish value and hands back garbage.
     *
     * @param string $value
     * @return bool
     */
    public function isEncrypted(#[\SensitiveParameter] string $value): bool
    {
        return (bool)preg_match(self::ENCRYPTED_ENVELOPE_PATTERN, $value);
    }
}
