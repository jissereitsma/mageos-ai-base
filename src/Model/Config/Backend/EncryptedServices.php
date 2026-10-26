<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Config\Backend;

use Magento\Config\Model\Config\Backend\Serialized\ArraySerialized;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\ValidatorException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use MageOS\AiBase\Model\Config\SensitiveDataProcessor;
use MageOS\AiBase\Model\Config\UnregisteredRowKeeper;

/**
 * Serialized services config with credential fields encrypted at rest.
 *
 * Credentials are never decrypted into the admin form: after load, sensitive
 * values are replaced with an obscured placeholder. On save, a submitted
 * placeholder restores the previously stored (encrypted) value, so admins can
 * save the form without retyping credentials.
 *
 * Row shape: [rowId => [serviceCode => [field => value, ...]]]
 *
 * A form post is only accepted once the form's JavaScript finished rendering it. See
 * {@see RENDERED_MARKER} for why.
 *
 * Stored rows whose provider is no longer registered are kept unless the post deletes them by id,
 * because the form cannot render inputs for them and so never posts them. See
 * {@see UnregisteredRowKeeper} and {@see DELETED_MARKER}.
 */
class EncryptedServices extends ArraySerialized
{
    /**
     * Key the admin form always posts, rendered server side, so an empty list still reaches the save.
     *
     * Its presence is how this model tells a post from the admin form apart from a programmatic
     * save (a data patch, `Magento\Config\Model\Config::setGroups()` in a test), which never sends it.
     */
    public const EMPTY_MARKER = '__empty';

    /**
     * Key the admin form's JavaScript adds as its very last step, after every stored row is rendered.
     *
     * The rows are built in JavaScript, while the `__empty` input is plain markup. If the script
     * fails, the post still carries `__empty` and no rows, and saving that stores an empty list:
     * every provider and every credential gone, credentials an administrator cannot read back. A
     * script that fails halfway is worse, because it silently drops only the rows after the failure.
     * Requiring this marker next to `__empty` turns both into a refused save with a message, instead
     * of a data loss reported as "You saved the configuration".
     */
    public const RENDERED_MARKER = '__rendered';

    /**
     * Key under which the admin form posts the ids of unregistered-provider rows deleted on purpose.
     *
     * Such a row posts no fields, so leaving it out of the post cannot mean "delete it" (that is
     * exactly what every unrelated save does). Its delete button posts its id here instead, which
     * is the one way such a row leaves the stored value. The ids travel as values rather than keys,
     * so a stored id is never parsed as part of a field name.
     */
    public const DELETED_MARKER = '__deleted';

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param SensitiveDataProcessor $sensitiveDataProcessor
     * @param Json $jsonSerializer
     * @param UnregisteredRowKeeper $unregisteredRowKeeper
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array<string,mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly SensitiveDataProcessor $sensitiveDataProcessor,
        private readonly Json $jsonSerializer,
        private readonly UnregisteredRowKeeper $unregisteredRowKeeper,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $config,
            $cacheTypeList,
            $resource,
            $resourceCollection,
            $data,
            $jsonSerializer
        );
    }

    /**
     * Restore placeholder-masked credentials from stored config, then encrypt before persisting.
     *
     * Stored rows of unregistered providers are added back after the posted rows are encrypted,
     * so they skip encryptRow() and land byte for byte as they were stored.
     *
     * @return $this
     * @throws ValidatorException When the admin form posted without having finished rendering
     */
    public function beforeSave()
    {
        $value = $this->getValue();
        if (is_array($value)) {
            $this->assertFormRendered($value);
            $deletedRowIds = $this->getDeletedRowIds($value);
            unset($value[self::RENDERED_MARKER], $value[self::DELETED_MARKER]);
            $stored = $this->getStoredRows();
            $this->setValue($this->unregisteredRowKeeper->keep(
                $this->mapRows(
                    $value,
                    fn (array $row, string $rowId, string $service): array => $this->sensitiveDataProcessor->encryptRow(
                        $service,
                        $this->sensitiveDataProcessor->restoreRow(
                            $service,
                            $this->assertHttpEndpoints($service, $row),
                            $this->storedRow($stored, $rowId, $service)
                        )
                    ),
                ),
                $stored,
                $deletedRowIds,
            ));
        }

        return parent::beforeSave();
    }

    /**
     * Refuse a row whose base URL or endpoint is not an http(s) URL, and pass it through otherwise.
     *
     * Throwing for the same reason as assertFormRendered(): the whole save rolls back and the
     * administrator sees which field to fix. See SensitiveDataProcessor::getNonHttpEndpointFieldNames().
     *
     * @param string $service
     * @param array<array-key,mixed> $row
     * @return array<array-key,mixed>
     * @throws ValidatorException
     */
    private function assertHttpEndpoints(string $service, #[\SensitiveParameter] array $row): array
    {
        $invalidFields = $this->sensitiveDataProcessor->getNonHttpEndpointFieldNames($service, $row);
        if ($invalidFields !== []) {
            throw new ValidatorException(__(
                'The "%1" field of the %2 AI service has to be an http:// or https:// URL.',
                implode('", "', $invalidFields),
                $service,
            ));
        }

        return $row;
    }

    /**
     * Refuse a post from the admin form whose script never finished rendering the rows.
     *
     * Throwing rather than quietly keeping the stored value: the exception rolls back the whole
     * section's save and Magento shows its message, so the administrator learns the page was broken
     * instead of believing an edit took. See {@see RENDERED_MARKER}.
     *
     * @param array<array-key,mixed> $value
     * @return void
     * @throws ValidatorException
     */
    private function assertFormRendered(array $value): void
    {
        if (!array_key_exists(self::EMPTY_MARKER, $value) || array_key_exists(self::RENDERED_MARKER, $value)) {
            return;
        }

        throw new ValidatorException(__(
            'The AI services were not saved because the form did not finish loading, and saving it '
            . 'would have removed services that were not shown. Reload the page and try again. If it '
            . 'keeps happening, check the browser console for a script error.'
        ));
    }

    /**
     * Row ids the post asks to delete through {@see DELETED_MARKER}.
     *
     * Anything that is not a list of strings there is ignored rather than refused: the marker can
     * only remove rows, so ignoring a malformed one errs on the side of keeping data.
     *
     * @param array<array-key,mixed> $value
     * @return list<string>
     */
    private function getDeletedRowIds(array $value): array
    {
        $deleted = $value[self::DELETED_MARKER] ?? [];

        return is_array($deleted) ? array_values(array_filter($deleted, 'is_string')) : [];
    }

    /**
     * Mask credential fields with the obscured placeholder for the admin form.
     *
     * @return $this
     */
    protected function _afterLoad()
    {
        parent::_afterLoad();

        $value = $this->getValue();
        if (is_array($value)) {
            $this->setValue($this->mapRows(
                $value,
                fn (array $row, string $rowId, string $service): array =>
                    $this->sensitiveDataProcessor->maskRow($service, $row),
            ));
        }

        return $this;
    }

    /**
     * Apply a row processor to each service configuration row.
     *
     * The processor is called with the row configuration, the row ID and the service code.
     *
     * @param array<array-key,mixed> $value
     * @param callable $processor
     * @return array<array-key,mixed>
     */
    private function mapRows(#[\SensitiveParameter] array $value, callable $processor): array
    {
        foreach ($value as $rowId => $row) {
            if (!is_array($row)) {
                continue;
            }
            $service = array_key_first($row);
            if ($service === null || !is_array($row[$service])) {
                continue;
            }
            $row[$service] = $processor($row[$service], (string)$rowId, (string)$service);
            $value[$rowId] = $row;
        }

        return $value;
    }

    /**
     * Previously stored service rows, decoded from the old (raw, still encrypted) config value.
     *
     * @return array<array-key,mixed>
     */
    private function getStoredRows(): array
    {
        $old = $this->getOldValue();
        if ($old === '') {
            return [];
        }
        try {
            $decoded = $this->jsonSerializer->unserialize($old);
        } catch (\InvalidArgumentException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Extract a single stored service configuration row, if present.
     *
     * @param array<array-key,mixed> $stored
     * @param string $rowId
     * @param string $service
     * @return array<array-key,mixed>
     */
    private function storedRow(array $stored, string $rowId, string $service): array
    {
        $row = $stored[$rowId] ?? null;
        if (!is_array($row)) {
            return [];
        }
        $configuration = $row[$service] ?? null;

        return is_array($configuration) ? $configuration : [];
    }
}
