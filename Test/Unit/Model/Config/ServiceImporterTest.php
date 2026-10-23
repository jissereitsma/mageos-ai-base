<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Config;

require_once __DIR__ . '/../../Stubs/FakeAiServiceFactory.php';
require_once __DIR__ . '/../../Stubs/FakeFieldDescriptorFactory.php';
require_once __DIR__ . '/../../Stubs/FakeSettingChecker.php';
require_once __DIR__ . '/../../Stubs/InMemoryReinitableConfig.php';
require_once __DIR__ . '/../../Stubs/InMemoryStoredServicesStorage.php';
require_once __DIR__ . '/../../Stubs/RecordingLogger.php';
require_once __DIR__ . '/../../Stubs/VersionedEncryptor.php';

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\StateException;
use Magento\Framework\Serialize\Serializer\Json;
use MageOS\AiBase\AiServices\Ollama;
use MageOS\AiBase\AiServices\OpenAi;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\JsonFetcherInterface;
use MageOS\AiBase\Model\AiServiceSelector;
use MageOS\AiBase\Model\Config\SensitiveDataProcessor;
use MageOS\AiBase\Model\Config\ServiceImporter;
use MageOS\AiBase\Model\ServiceRegistry;
use MageOS\AiBase\Model\ServiceScope;
use MageOS\AiBase\Test\Unit\Stubs\FakeAiServiceFactory;
use MageOS\AiBase\Test\Unit\Stubs\FakeFieldDescriptorFactory;
use MageOS\AiBase\Test\Unit\Stubs\FakeSettingChecker;
use MageOS\AiBase\Test\Unit\Stubs\InMemoryReinitableConfig;
use MageOS\AiBase\Test\Unit\Stubs\InMemoryStoredServicesStorage;
use MageOS\AiBase\Test\Unit\Stubs\RecordingLogger;
use MageOS\AiBase\Test\Unit\Stubs\VersionedEncryptor;
use PHPUnit\Framework\TestCase;

/**
 * Moving another module's saved credentials into the AI services configuration.
 *
 * Runs against the real OpenAI and Ollama providers, so "which fields exist and which are
 * encrypted" is the shipped schema rather than one made up for the test.
 */
final class ServiceImporterTest extends TestCase
{
    private const LEGACY_KEY_PATH = 'my_module/general/api_key';
    private const LEGACY_MODEL_PATH = 'my_module/general/model';

    private InMemoryStoredServicesStorage $storage;
    private InMemoryReinitableConfig $config;
    private FakeSettingChecker $settingChecker;
    private RecordingLogger $logger;
    private SensitiveDataProcessor $sensitiveDataProcessor;
    private ServiceImporter $subject;

    protected function setUp(): void
    {
        $encryptor = new VersionedEncryptor([0]);
        $registry = $this->createRegistry();
        $this->storage = new InMemoryStoredServicesStorage();
        $this->config = new InMemoryReinitableConfig();
        $this->settingChecker = new FakeSettingChecker();
        $this->logger = new RecordingLogger();
        $this->sensitiveDataProcessor = new SensitiveDataProcessor($encryptor, $registry);
        $this->subject = new ServiceImporter(
            $registry,
            $this->sensitiveDataProcessor,
            $this->storage,
            $this->config,
            $encryptor,
            $this->settingChecker,
            new Json(),
            $this->logger,
        );
    }

    /**
     * The point of the import: a consumer reading the row the normal way gets the key back.
     */
    public function test_an_imported_key_is_stored_encrypted_and_reads_back_decrypted_through_the_selector(): void
    {
        $rowId = $this->subject->import('openai', ['api_key' => 'sk-imported', 'model' => 'gpt-4o']);

        self::assertSame('0:3:enc(sk-imported)', $this->storedRows()[$rowId]['openai']['api_key']);
        $service = $this->createSelector()->getById($rowId);
        self::assertNotNull($service);
        self::assertSame('openai', $service->getCode());
        self::assertSame('sk-imported', $service->getConfiguration()['api_key']);
        self::assertSame('gpt-4o', $service->getConfiguration()['model']);
    }

    public function test_the_imported_row_is_enabled_and_carries_the_label(): void
    {
        $rowId = $this->subject->import('openai', ['api_key' => 'sk-imported'], '  Translations  ');

        $service = $this->createSelector()->getById($rowId);
        self::assertNotNull($service);
        self::assertTrue($service->isEnabled());
        self::assertSame('Translations', $service->getLabel());
        self::assertSame('1', $this->storedRows()[$rowId]['openai']['_enabled']);
    }

    public function test_an_import_without_a_label_leaves_the_row_unnamed(): void
    {
        $rowId = $this->subject->import('openai', ['api_key' => 'sk-imported']);

        self::assertArrayNotHasKey('_label', $this->storedRows()[$rowId]['openai']);
    }

    /**
     * The form treats any id as opaque, but an imported row should look like one it added.
     */
    public function test_the_row_id_has_the_shape_the_admin_form_generates(): void
    {
        $rowId = $this->subject->import('openai', ['api_key' => 'sk-imported']);

        self::assertMatchesRegularExpression('/^_(\d+)_(\d{1,3})$/', $rowId);
    }

    public function test_rows_already_stored_are_kept_as_they_were(): void
    {
        $existing = ['_1' => ['anthropic' => ['api_key' => '0:3:enc(sk-ant)', 'model' => 'claude']]];
        $this->storage->withValue(7, 'default', 0, json_encode($existing, JSON_THROW_ON_ERROR));

        $rowId = $this->subject->import('openai', ['api_key' => 'sk-imported']);

        self::assertSame(['_1', $rowId], array_keys($this->storedRows()));
        self::assertSame($existing['_1'], $this->storedRows()['_1']);
    }

    /**
     * A data patch that runs again, or two modules importing the same key, must not add a row.
     */
    public function test_importing_the_same_values_again_returns_the_first_row_id(): void
    {
        $first = $this->subject->import('openai', ['api_key' => 'sk-imported', 'model' => 'gpt-4o'], 'First');

        $second = $this->subject->import('openai', ['api_key' => 'sk-imported', 'model' => 'gpt-4o'], 'Second');

        self::assertSame($first, $second);
        self::assertCount(1, $this->storedRows());
    }

    /**
     * Saving the form adds the fields an import left out; the row is still the imported one.
     */
    public function test_a_row_the_admin_saved_since_still_counts_as_already_imported(): void
    {
        $this->storage->withValue(1, 'default', 0, json_encode([
            '_1' => ['openai' => ['api_key' => '0:3:enc(sk-imported)', 'model' => 'gpt-4o', '_enabled' => '0']],
        ], JSON_THROW_ON_ERROR));

        $rowId = $this->subject->import('openai', ['api_key' => 'sk-imported']);

        self::assertSame('_1', $rowId);
    }

    public function test_a_different_key_for_the_same_service_adds_a_second_row(): void
    {
        $first = $this->subject->import('openai', ['api_key' => 'sk-one']);

        $second = $this->subject->import('openai', ['api_key' => 'sk-two']);

        self::assertNotSame($first, $second);
        self::assertCount(2, $this->storedRows());
    }

    public function test_the_configuration_is_reinitialised_after_a_write_so_this_process_reads_it(): void
    {
        $this->subject->import('openai', ['api_key' => 'sk-imported']);

        self::assertSame(1, $this->config->getReinitCount());
    }

    public function test_an_unregistered_service_code_is_refused(): void
    {
        $this->expectException(NoSuchEntityException::class);

        $this->subject->import('not_a_provider', ['api_key' => 'sk-imported']);
    }

    /**
     * A misspelt field dropped silently would import a row without its key.
     */
    public function test_a_field_the_provider_does_not_declare_is_refused(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage('apikey');

        $this->subject->import('openai', ['apikey' => 'sk-imported']);
    }

    public function test_an_import_whose_base_url_is_not_http_is_refused(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage('"base_url"');

        $this->subject->import('ollama', ['base_url' => 'ftp://ollama:11434']);
    }

    public function test_an_import_with_only_empty_values_is_refused(): void
    {
        $this->expectException(InputException::class);

        $this->subject->import('openai', ['api_key' => '', 'model' => '']);
    }

    /**
     * A row written to the database is never read while deployment configuration pins the value.
     */
    public function test_an_import_is_refused_while_the_services_are_pinned_in_deployment_configuration(): void
    {
        $this->settingChecker->givenLocked('mageos_ai/services/configuration');

        $this->expectException(StateException::class);

        $this->subject->import('openai', ['api_key' => 'sk-imported']);
    }

    /**
     * Writing over an admin save that happened in between would silently undo it.
     */
    public function test_an_import_is_refused_when_the_stored_value_changed_while_adding_it(): void
    {
        $this->storage
            ->withValue(1, 'default', 0, '{}')
            ->givenChangedOnWrite(1, '{"_9":{"anthropic":{"api_key":"0:3:enc(sk-ant)"}}}');

        try {
            $this->subject->import('openai', ['api_key' => 'sk-imported']);
            self::fail('The import overwrote a concurrent save.');
        } catch (StateException) {
        }

        self::assertSame('{"_9":{"anthropic":{"api_key":"0:3:enc(sk-ant)"}}}', $this->storage->getDefaultValue());
    }

    /**
     * What an `obscure` field with the Encrypted backend model holds is a Magento ciphertext.
     */
    public function test_import_from_config_decrypts_an_obscure_value(): void
    {
        $this->config
            ->withValue(self::LEGACY_KEY_PATH, '0:3:enc(sk-obscure)')
            ->withValue(self::LEGACY_MODEL_PATH, 'gpt-4o-mini');

        $rowId = $this->subject->importFromConfig(
            'openai',
            ['api_key' => self::LEGACY_KEY_PATH, 'model' => self::LEGACY_MODEL_PATH],
            'Translations',
        );

        self::assertNotNull($rowId);
        $service = $this->createSelector()->getById($rowId);
        self::assertNotNull($service);
        self::assertSame('sk-obscure', $service->getConfiguration()['api_key']);
        self::assertSame('gpt-4o-mini', $service->getConfiguration()['model']);
        self::assertSame('Translations', $service->getLabel());
    }

    /**
     * A key saved before the field was encrypted is plaintext, which the encryptor would mangle.
     */
    public function test_import_from_config_takes_a_legacy_plaintext_key_as_it_is(): void
    {
        $this->config->withValue(self::LEGACY_KEY_PATH, 'sk-plaintext');

        $rowId = $this->subject->importFromConfig('openai', ['api_key' => self::LEGACY_KEY_PATH]);

        self::assertNotNull($rowId);
        self::assertSame('sk-plaintext', $this->createSelector()->getById($rowId)?->getConfiguration()['api_key']);
    }

    /**
     * So a data patch can call it whether or not the merchant ever filled in the old field.
     */
    public function test_import_from_config_imports_nothing_when_the_key_is_empty(): void
    {
        $this->config->withValue(self::LEGACY_MODEL_PATH, 'gpt-4o');

        $rowId = $this->subject->importFromConfig(
            'openai',
            ['api_key' => self::LEGACY_KEY_PATH, 'model' => self::LEGACY_MODEL_PATH],
        );

        self::assertNull($rowId);
        self::assertNull($this->storage->getDefaultValue());
        self::assertSame(0, $this->config->getReinitCount());
    }

    /**
     * A ciphertext under a key this install no longer has: nothing usable, said in the log.
     */
    public function test_import_from_config_imports_nothing_and_logs_when_the_key_does_not_decrypt(): void
    {
        $this->config->withValue(self::LEGACY_KEY_PATH, '5:3:enc(sk-lost)');

        $rowId = $this->subject->importFromConfig('openai', ['api_key' => self::LEGACY_KEY_PATH]);

        self::assertNull($rowId);
        self::assertSame(['path' => self::LEGACY_KEY_PATH], $this->logger->getRecords()[0]['context']);
        self::assertStringNotContainsString('sk-lost', json_encode($this->logger->getRecords(), JSON_THROW_ON_ERROR));
    }

    /**
     * Without credential fields the mapped values decide, or a local provider could never move.
     */
    public function test_import_from_config_imports_a_provider_without_credentials_by_its_values(): void
    {
        $this->config->withValue('my_module/ollama/url', 'http://ollama:11434');

        $rowId = $this->subject->importFromConfig('ollama', ['base_url' => 'my_module/ollama/url']);

        self::assertNotNull($rowId);
        self::assertSame('http://ollama:11434', $this->storedRows()[$rowId]['ollama']['base_url']);
    }

    public function test_import_from_config_twice_returns_the_same_row_id(): void
    {
        $this->config->withValue(self::LEGACY_KEY_PATH, '0:3:enc(sk-obscure)');

        $first = $this->subject->importFromConfig('openai', ['api_key' => self::LEGACY_KEY_PATH]);
        $second = $this->subject->importFromConfig('openai', ['api_key' => self::LEGACY_KEY_PATH]);

        self::assertSame($first, $second);
        self::assertCount(1, $this->storedRows());
    }

    /**
     * The rows as stored at default scope, credentials still encrypted.
     *
     * @return array<string,array<string,array<string,string>>>
     */
    private function storedRows(): array
    {
        return json_decode((string) $this->storage->getDefaultValue(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The consumer read path, over whatever the import stored.
     */
    private function createSelector(): AiServiceSelectorInterface
    {
        return new AiServiceSelector(
            (new InMemoryReinitableConfig())->withValue(
                AiServiceSelector::CONFIG_PATH_AI_SERVICES,
                $this->storage->getDefaultValue(),
            ),
            new FakeAiServiceFactory(),
            $this->sensitiveDataProcessor,
            new ServiceScope(),
        );
    }

    private function createRegistry(): ServiceRegistry
    {
        $fieldFactory = new FakeFieldDescriptorFactory();
        $fetcher = new class implements JsonFetcherInterface {
            public function getJson(string $url, array $headers = []): array
            {
                return [];
            }
        };

        return new ServiceRegistry([new OpenAi($fieldFactory, $fetcher), new Ollama($fieldFactory, $fetcher)]);
    }
}
