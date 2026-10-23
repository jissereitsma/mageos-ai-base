<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Config;

use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\StateException;
use Magento\Framework\Serialize\Serializer\Json;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Api\ServiceImporterInterface;
use MageOS\AiBase\Model\AiServiceSelector;
use MageOS\AiBase\Model\ServiceRegistry;
use Psr\Log\LoggerInterface;

/**
 * {@see ServiceImporterInterface}: adds a row to the default-scope services value directly.
 *
 * Not through the config model: a data patch has no admin session, and the backend model's save
 * path exists to restore masked placeholders and to refuse a half-rendered form, neither of which
 * an import has. What the backend model does to a row that matters (which fields are encrypted,
 * and how) comes from SensitiveDataProcessor, the same as there, and the write goes through
 * StoredServicesStorageInterface, conditional on the value it read, the same as the re-encryptor.
 * So there is still one definition of what a stored row looks like.
 */
class ServiceImporter implements ServiceImporterInterface
{
    /**
     * What the admin form's enable toggle posts for a row that is on.
     */
    private const ENABLED = '1';

    /**
     * @param ServiceRegistry $serviceRegistry Decides which codes exist and which fields they declare
     * @param SensitiveDataProcessor $sensitiveDataProcessor Encrypts and decrypts rows as the form does
     * @param StoredServicesStorageInterface $storage The raw stored services value, written conditionally
     * @param ReinitableConfigInterface $config Read for importFromConfig() and reinitialised after a
     *        write. Reinitialised rather than only cleaning the cache: the config keeps the default
     *        scope in memory once read, so a cleaned cache alone leaves this process serving the
     *        value from before the import.
     * @param EncryptorInterface $encryptor Decrypts another module's `obscure` values, which Magento
     *        encrypts with this same encryptor
     * @param SettingChecker $settingChecker Whether the services are pinned in deployment configuration
     * @param Json $json The serializer the config backend model stores the rows with
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ServiceRegistry $serviceRegistry,
        private readonly SensitiveDataProcessor $sensitiveDataProcessor,
        private readonly StoredServicesStorageInterface $storage,
        private readonly ReinitableConfigInterface $config,
        private readonly EncryptorInterface $encryptor,
        private readonly SettingChecker $settingChecker,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function import(
        string $serviceCode,
        #[\SensitiveParameter] array $configuration,
        ?string $label = null,
    ): string {
        $values = $this->validate($this->getService($serviceCode), $configuration);
        $this->assertNotPinned();
        $stored = $this->getStoredDefault();
        $rows = $stored === null ? [] : $this->decode($stored);

        $existingId = $this->findMatchingRow($rows, $serviceCode, $values);
        if ($existingId !== null) {
            return $existingId;
        }

        $rowId = $this->generateRowId($rows);
        $rows[$rowId] = [$serviceCode => $this->sensitiveDataProcessor->encryptRow(
            $serviceCode,
            $values + $this->describeRow($label),
        )];
        $this->write($stored, $this->json->serialize($rows));

        return $rowId;
    }

    /**
     * @inheritdoc
     */
    public function importFromConfig(string $serviceCode, array $fieldPaths, ?string $label = null): ?string
    {
        $service = $this->getService($serviceCode);
        $encryptedFields = $this->getEncryptedFieldNames($service);
        $values = [];
        foreach ($fieldPaths as $field => $path) {
            $values[$field] = in_array($field, $encryptedFields, true)
                ? $this->readCredential($path)
                : $this->readValue($path);
        }

        if (!$this->hasAnythingToImport($values, $encryptedFields)) {
            return null;
        }

        return $this->import($serviceCode, $values, $label);
    }

    /**
     * The registered backend for a code, or a refusal the administrator running setup can act on.
     *
     * An unregistered code would store a row no form field, option source or client understands.
     *
     * @param string $serviceCode
     * @return AiServiceConfigurationInterface
     * @throws NoSuchEntityException
     */
    private function getService(string $serviceCode): AiServiceConfigurationInterface
    {
        return $this->serviceRegistry->get($serviceCode) ?? throw new NoSuchEntityException(__(
            'The AI service "%1" cannot be imported because no provider with that code is registered.',
            $serviceCode,
        ));
    }

    /**
     * The values to store: declared fields only, strings only, empty ones left out.
     *
     * Unknown keys are refused rather than dropped, see ServiceImporterInterface::import(). An
     * empty value is left out because the form treats an absent field and an empty one alike, and
     * leaving it out keeps it from deciding whether an existing row matches. Nothing left at all is
     * refused too: an empty row would match every existing row of the service and import nothing.
     * An endpoint that is not an http(s) URL is refused by the same rule the admin form applies.
     *
     * @param AiServiceConfigurationInterface $service
     * @param array<mixed> $configuration
     * @return array<string,string>
     * @throws InputException
     */
    private function validate(
        AiServiceConfigurationInterface $service,
        #[\SensitiveParameter] array $configuration,
    ): array {
        $declared = $this->getFieldNames($service);
        $unknown = array_diff(array_map('strval', array_keys($configuration)), $declared);
        if ($unknown !== []) {
            throw new InputException(__(
                'The AI service "%1" has no field named %2. Its fields are: %3.',
                $service->getCode(),
                implode(', ', $unknown),
                implode(', ', $declared),
            ));
        }
        $values = [];
        foreach ($configuration as $field => $value) {
            if (!is_string($value)) {
                throw new InputException(__(
                    'Every value imported into the AI service "%1" has to be a string.',
                    $service->getCode(),
                ));
            }
            if ($value !== '') {
                $values[(string) $field] = $value;
            }
        }
        if ($values === []) {
            throw new InputException(__(
                'There is nothing to import into the AI service "%1": every value is empty.',
                $service->getCode(),
            ));
        }
        $invalidFields = $this->sensitiveDataProcessor->getNonHttpEndpointFieldNames($service->getCode(), $values);
        if ($invalidFields !== []) {
            throw new InputException(__(
                'The "%1" field of the %2 AI service has to be an http:// or https:// URL.',
                implode('", "', $invalidFields),
                $service->getCode(),
            ));
        }

        return $values;
    }

    /**
     * Refuse to write a value deployment configuration overrides.
     *
     * With the services pinned in `app/etc/env.php` or `config.php`, a row written to the database
     * is never read, so returning its id would hand the caller a reference that resolves to
     * nothing.
     *
     * @return void
     * @throws StateException
     */
    private function assertNotPinned(): void
    {
        if (!$this->settingChecker->isReadOnly(
            AiServiceSelector::CONFIG_PATH_AI_SERVICES,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
        )) {
            return;
        }

        throw new StateException(__(
            'The AI service was not imported because the AI services are set in the deployment '
            . 'configuration (app/etc/env.php or config.php). Add it there instead.'
        ));
    }

    /**
     * The stored default-scope services value, or null when there is none yet.
     *
     * Read raw, not through the config: the write is conditional on exactly this value, and the
     * config may still hold a copy from earlier in the process.
     *
     * @return StoredServicesValue|null
     */
    private function getStoredDefault(): ?StoredServicesValue
    {
        foreach ($this->storage->getAll() as $stored) {
            if ($stored->scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT && $stored->scopeId === 0) {
                return $stored;
            }
        }

        return null;
    }

    /**
     * The stored rows, or a refusal when the value is not the JSON object the backend model writes.
     *
     * Writing a new row over a value this class cannot read would replace data it does not
     * understand, so the import stops instead.
     *
     * @param StoredServicesValue $stored
     * @return array<array-key,mixed>
     * @throws StateException
     */
    private function decode(StoredServicesValue $stored): array
    {
        try {
            $rows = $this->json->unserialize($stored->value);
        } catch (\InvalidArgumentException) {
            $rows = null;
        }
        if (is_array($rows)) {
            return $rows;
        }

        throw new StateException(__(
            'The AI service was not imported because the stored AI services configuration is not '
            . 'valid JSON. Save the AI Configuration form once and run the import again.'
        ));
    }

    /**
     * The id of a row of this service that already holds every imported value, if there is one.
     *
     * "Every imported value" rather than "the same row": an administrator saving the form adds
     * the fields the import left out (an empty model, a default base URL), and the row is still
     * the one this import created. Disabled rows count too, so running an import again never
     * brings back a row an administrator turned off as a second, enabled copy.
     *
     * @param array<array-key,mixed> $rows
     * @param string $serviceCode
     * @param array<string,string> $values
     * @return string|null
     */
    private function findMatchingRow(array $rows, string $serviceCode, #[\SensitiveParameter] array $values): ?string
    {
        foreach ($rows as $rowId => $row) {
            $configuration = is_array($row) ? ($row[$serviceCode] ?? null) : null;
            if (!is_array($configuration) || array_key_first($row) !== $serviceCode) {
                continue;
            }
            if ($this->holdsEvery($this->sensitiveDataProcessor->decryptRow($serviceCode, $configuration), $values)) {
                return (string) $rowId;
            }
        }

        return null;
    }

    /**
     * Whether a decrypted row holds each of the values, exactly.
     *
     * @param array<array-key,mixed> $decrypted
     * @param array<string,string> $values
     * @return bool
     */
    private function holdsEvery(#[\SensitiveParameter] array $decrypted, #[\SensitiveParameter] array $values): bool
    {
        return array_filter(
            $values,
            static fn (string $value, string $field): bool => ($decrypted[$field] ?? null) !== $value,
            ARRAY_FILTER_USE_BOTH,
        ) === [];
    }

    /**
     * A new row id in the shape the admin form gives a row it adds: `_<epoch ms>_<ms part>`.
     *
     * The same shape keeps an imported row indistinguishable from one an administrator added; the
     * form never parses an id, but it is what anyone reading the stored JSON expects to see. Moved
     * on by a millisecond while it collides, which only two imports in the same millisecond do.
     *
     * @param array<array-key,mixed> $rows
     * @return string
     */
    private function generateRowId(array $rows): string
    {
        $milliseconds = (int) floor(microtime(true) * 1000);
        while (array_key_exists($this->formatRowId($milliseconds), $rows)) {
            $milliseconds++;
        }

        return $this->formatRowId($milliseconds);
    }

    /**
     * Format a row id the way the admin form's script does (`'_' + getTime() + '_' + getMilliseconds()`).
     *
     * @param int $milliseconds
     * @return string
     */
    private function formatRowId(int $milliseconds): string
    {
        return '_' . $milliseconds . '_' . ($milliseconds % 1000);
    }

    /**
     * The keys the form stores next to the provider fields: the row's name, and that it is on.
     *
     * Enabled is written out rather than left absent, as the form's toggle always posts it.
     *
     * @param string|null $label
     * @return array<string,string>
     */
    private function describeRow(?string $label): array
    {
        $name = trim((string) $label);

        return ($name === '' ? [] : [AiServiceInterface::CONFIGURATION_LABEL => $name])
            + [AiServiceInterface::CONFIGURATION_ENABLED => self::ENABLED];
    }

    /**
     * Store the new services value and make this process read it from now on.
     *
     * @param StoredServicesValue|null $stored What was read, or null when nothing was stored
     * @param bool|string $serialized
     * @return void
     * @throws StateException When the stored value changed since it was read
     */
    private function write(?StoredServicesValue $stored, bool|string $serialized): void
    {
        $value = (string) $serialized;
        $isWritten = $stored === null ? $this->storage->addDefault($value) : $this->storage->replace($stored, $value);
        if (!$isWritten) {
            throw new StateException(__(
                'The AI service was not imported because the AI services configuration was saved '
                . 'while it was being added. Run the import again.'
            ));
        }

        $this->config->reinit();
    }

    /**
     * A credential another module stored, in the clear, or '' when it cannot be read.
     *
     * Only a value shaped like a Magento ciphertext is decrypted. Anything else is a plaintext key
     * (saved before the field was encrypted, or already decrypted by a backend model declared in
     * that module's config.xml) and taken as it is; passing it to the encryptor would not fail but
     * return garbage. A ciphertext that does not decrypt is under a key this install no longer
     * has: there is nothing usable to import, which is logged rather than thrown, so a data patch
     * calling this does not block setup:upgrade over the other module's broken credential.
     *
     * @param string $path
     * @return string
     */
    private function readCredential(string $path): string
    {
        $value = $this->readValue($path);
        if (!$this->sensitiveDataProcessor->isEncrypted($value)) {
            return $value;
        }

        try {
            $plaintext = (string) $this->encryptor->decrypt($value);
        } catch (\Exception) {
            $plaintext = '';
        }
        if ($plaintext === '') {
            $this->logger->warning(
                'An AI credential was not imported because it could not be decrypted with the current '
                . 'encryption keys.',
                ['path' => $path],
            );
        }

        return $plaintext;
    }

    /**
     * A config value at default scope as a string, '' when unset or not a scalar.
     *
     * @param string $path
     * @return string
     */
    private function readValue(string $path): string
    {
        $value = $this->config->getValue($path, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Whether the values hold something worth a row.
     *
     * A row without its credential is not a working service, so a provider that has credential
     * fields needs one of them filled. A provider without any (Ollama, LM Studio) is judged by all
     * of its mapped values instead, or it could never be imported.
     *
     * @param array<array-key,string> $values
     * @param list<string> $encryptedFields
     * @return bool
     */
    private function hasAnythingToImport(#[\SensitiveParameter] array $values, array $encryptedFields): bool
    {
        $relevant = $encryptedFields === [] ? $values : array_intersect_key($values, array_flip($encryptedFields));

        return array_filter($relevant, static fn (string $value): bool => $value !== '') !== [];
    }

    /**
     * Names of every field the provider declares.
     *
     * @param AiServiceConfigurationInterface $service
     * @return list<string>
     */
    private function getFieldNames(AiServiceConfigurationInterface $service): array
    {
        return array_values(array_map(
            static fn (FieldDescriptorInterface $field): string => $field->getName(),
            $service->getConfigurationFields(),
        ));
    }

    /**
     * Names of the fields the provider stores encrypted, its credentials.
     *
     * @param AiServiceConfigurationInterface $service
     * @return list<string>
     */
    private function getEncryptedFieldNames(AiServiceConfigurationInterface $service): array
    {
        return array_values(array_map(
            static fn (FieldDescriptorInterface $field): string => $field->getName(),
            array_filter(
                $service->getConfigurationFields(),
                static fn (FieldDescriptorInterface $field): bool => $field->isEncrypted(),
            ),
        ));
    }
}
