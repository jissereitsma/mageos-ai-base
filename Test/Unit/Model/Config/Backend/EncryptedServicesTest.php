<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Config\Backend;

require_once __DIR__ . '/../../../Stubs/FakeAiServiceConfiguration.php';
require_once __DIR__ . '/../../../Stubs/FixedAreaScope.php';
require_once __DIR__ . '/../../../Stubs/InMemoryReinitableConfig.php';
require_once __DIR__ . '/../../../Stubs/NullCache.php';
require_once __DIR__ . '/../../../Stubs/NullCacheTypeList.php';
require_once __DIR__ . '/../../../Stubs/NullEventManager.php';
require_once __DIR__ . '/../../../Stubs/RecordingLogger.php';
require_once __DIR__ . '/../../../Stubs/VersionedEncryptor.php';

use Magento\Framework\App\State;
use Magento\Framework\Exception\ValidatorException;
use Magento\Framework\Model\ActionValidator\RemoveAction;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Model\Config\Backend\EncryptedServices;
use MageOS\AiBase\Model\Config\SensitiveDataProcessor;
use MageOS\AiBase\Model\Config\UnregisteredRowKeeper;
use MageOS\AiBase\Model\FieldDescriptor;
use MageOS\AiBase\Model\ServiceRegistry;
use MageOS\AiBase\Test\Unit\Stubs\FakeAiServiceConfiguration;
use MageOS\AiBase\Test\Unit\Stubs\FixedAreaScope;
use MageOS\AiBase\Test\Unit\Stubs\InMemoryReinitableConfig;
use MageOS\AiBase\Test\Unit\Stubs\NullCache;
use MageOS\AiBase\Test\Unit\Stubs\NullCacheTypeList;
use MageOS\AiBase\Test\Unit\Stubs\NullEventManager;
use MageOS\AiBase\Test\Unit\Stubs\RecordingLogger;
use MageOS\AiBase\Test\Unit\Stubs\VersionedEncryptor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The admin form's rows are built by JavaScript, while its `__empty` input is plain markup. A script
 * that never ran, or stopped halfway, posted `__empty` with none or only some of the rows, and the
 * save stored exactly that: every provider and credential after the failure was deleted. The form
 * now adds a marker as its last step, and a form post without it is refused.
 *
 * The refusal is checked on a model built without its constructor, because it happens before the
 * model touches anything the constructor provides. The rest builds the real model on in-memory
 * fakes and runs its whole beforeSave(), parents included, to pin what a save stores for rows of a
 * provider that is no longer registered: the form cannot post those, so leaving them out of the
 * post must not delete them (GitHub issue #65). The full save path through Magento's config model
 * is covered in `Test/Integration/Model/Config/CredentialStorageTest`.
 *
 * @covers \MageOS\AiBase\Model\Config\Backend\EncryptedServices
 */
final class EncryptedServicesTest extends TestCase
{
    private const PATH = 'mageos_ai/services/configuration';

    private const OPENAI_ROW = ['openai' => ['api_key' => '1:3:enc(sk-openai)', 'model' => 'gpt-4o']];

    private const ACME_ROW = ['acme_removed' => ['api_key' => '1:3:enc(sk-acme)', '_label' => 'Old <gateway>']];

    /**
     * What the admin form posts for the OpenAI row: the credential masked, everything else as shown.
     */
    private const POSTED_OPENAI_ROW = ['openai' => ['api_key' => '******', 'model' => 'gpt-4o']];

    public function test_an_unrelated_save_keeps_an_unregistered_row_byte_for_byte(): void
    {
        $model = $this->savingModel($this->storedJson());
        $model->setValue($this->formPost(['_openai' => self::POSTED_OPENAI_ROW]));

        $model->beforeSave();

        self::assertSame($this->storedJson(), $model->getValue());
    }

    /**
     * An administrator who deleted every row they could see posts only the markers. The rows they
     * could not see were never theirs to delete that way.
     */
    public function test_a_post_of_only_the_markers_keeps_unregistered_rows(): void
    {
        $model = $this->savingModel($this->storedJson());
        $model->setValue($this->formPost([]));

        $model->beforeSave();

        self::assertSame(['_acme' => self::ACME_ROW], $this->savedRows($model));
    }

    public function test_a_registered_row_left_out_of_the_post_is_still_deleted(): void
    {
        $model = $this->savingModel($this->storedJson());
        $model->setValue($this->formPost([]));

        $model->beforeSave();

        self::assertArrayNotHasKey('_openai', $this->savedRows($model));
    }

    public function test_the_deletion_marker_removes_an_unregistered_row_and_is_not_stored(): void
    {
        $model = $this->savingModel($this->storedJson());
        $model->setValue($this->formPost([
            '_openai' => self::POSTED_OPENAI_ROW,
            EncryptedServices::DELETED_MARKER => ['_acme'],
        ]));

        $model->beforeSave();

        self::assertSame(['_openai' => self::OPENAI_ROW], $this->savedRows($model));
    }

    /**
     * Kept rows bypass encryption, so even a credential stored before encryption existed is carried
     * over exactly as it was rather than being rewritten by a save that never touched it.
     */
    public function test_a_kept_row_is_not_re_encrypted(): void
    {
        $plain = ['_legacy' => ['acme_removed' => ['api_key' => 'sk-plaintext']]];
        $model = $this->savingModel(json_encode($plain, JSON_THROW_ON_ERROR));
        $model->setValue($this->formPost([]));

        $model->beforeSave();

        self::assertSame($plain, $this->savedRows($model));
    }

    /**
     * Reinstalling the provider makes the kept row an ordinary one again: same id, and its masked
     * credential restores to the ciphertext that was kept for it.
     */
    public function test_a_kept_row_works_again_once_its_provider_is_registered(): void
    {
        $model = $this->savingModel($this->storedJson(), [new FakeAiServiceConfiguration('acme_removed', [
            new FieldDescriptor('api_key', 'API Key', FieldDescriptorInterface::TYPE_PASSWORD, encrypted: true),
        ])]);
        $model->setValue($this->formPost(['_acme' => ['acme_removed' => ['api_key' => '******', '_label' => 'Old']]]));

        $model->beforeSave();

        self::assertSame(
            ['_acme' => ['acme_removed' => ['api_key' => '1:3:enc(sk-acme)', '_label' => 'Old']]],
            $this->savedRows($model)
        );
    }

    /**
     * @param array<string, mixed> $posted
     */
    #[DataProvider('form_posts_from_a_form_that_did_not_finish_rendering')]
    public function test_a_form_post_without_the_rendered_marker_is_refused(array $posted): void
    {
        $model = $this->model();
        $model->setValue($posted);

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('the form did not finish loading');

        $model->beforeSave();
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function form_posts_from_a_form_that_did_not_finish_rendering(): array
    {
        return [
            'script never ran, no rows' => [['__empty' => '']],
            'script stopped after the first row' => [[
                '__empty' => '',
                '_first' => ['openai' => ['api_key' => '******', 'model' => 'gpt-4o']],
            ]],
        ];
    }

    public function test_a_row_whose_base_url_is_not_http_is_refused_and_named(): void
    {
        $model = $this->savingModel($this->storedJson());
        $model->setValue($this->formPost([
            '_openai' => ['openai' => ['api_key' => 'sk-new', 'model' => 'gpt-4o', 'base_url' => 'ftp://files.test']],
        ]));

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('The "base_url" field of the openai AI service has to be an http:// or https:// URL.');

        $model->beforeSave();
    }

    private function model(): EncryptedServices
    {
        return (new \ReflectionClass(EncryptedServices::class))->newInstanceWithoutConstructor();
    }

    /**
     * The real backend model over in-memory collaborators, with `$stored` as the value at its scope.
     *
     * OpenAI is always registered; `acme_removed` only when a test passes a provider for it.
     *
     * @param string $stored
     * @param list<FakeAiServiceConfiguration> $extraServices
     */
    private function savingModel(string $stored, array $extraServices = []): EncryptedServices
    {
        $registry = new ServiceRegistry([
            new FakeAiServiceConfiguration('openai', [
                new FieldDescriptor('api_key', 'API Key', FieldDescriptorInterface::TYPE_PASSWORD, encrypted: true),
                new FieldDescriptor('model', 'Model', FieldDescriptorInterface::TYPE_TEXT),
            ]),
            ...$extraServices,
        ]);
        $model = new EncryptedServices(
            new Context(
                new RecordingLogger(),
                new NullEventManager(),
                new NullCache(),
                new State(new FixedAreaScope()),
                new RemoveAction(new Registry()),
            ),
            new Registry(),
            (new InMemoryReinitableConfig())->withValue(self::PATH, $stored),
            new NullCacheTypeList(),
            new SensitiveDataProcessor(new VersionedEncryptor([1]), $registry),
            new Json(),
            new UnregisteredRowKeeper($registry),
        );
        $model->setPath(self::PATH);

        return $model;
    }

    /**
     * A post the way the fully rendered admin form sends it, markers included.
     *
     * @param array<string, mixed> $rows
     * @return array<string, mixed>
     */
    private function formPost(array $rows): array
    {
        return [EncryptedServices::EMPTY_MARKER => '', EncryptedServices::RENDERED_MARKER => '1'] + $rows;
    }

    private function storedJson(): string
    {
        return json_encode(['_openai' => self::OPENAI_ROW, '_acme' => self::ACME_ROW], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function savedRows(EncryptedServices $model): array
    {
        $value = $model->getValue();
        self::assertIsString($value, 'The model did not serialize the rows it is about to save.');

        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }
}
