<?php

declare(strict_types=1);

namespace Labdoo\Tests\Unit;

use Drupal\Core\State\StateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\geocoder\GeocoderInterface;
use Drupal\geocoder\GeocoderProviderInterface;
use Drupal\labdoo_notifications\Service\EmailProcessor;
use Drupal\labdoo_notifications\Service\EntityProcessor\DootripProcessor;
use Drupal\labdoo_notifications\Service\EntityProcessor\LaptopProcessor;
use Drupal\labdoo_notifications\Service\EntityProcessor\TeamProcessor;
use Drupal\labdoo_notifications\Service\NotificationManager;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Drupal\webform\WebformSubmissionInterface;
use Geocoder\Model\AddressCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Covers notification dispatch boundaries and missing related entities.
 */
#[Group('labdoo_notifications')]
class NotificationContractTest extends UnitTestCase {

  /**
   * Provides node bundles and their notification entry points.
   */
  public static function processors(): array {
    return [
      'laptop' => [LaptopProcessor::class, 'dootronic', 'sendLaptopEventEmail'],
      'trip' => [DootripProcessor::class, 'dootrip', 'sendDootripEventEmail'],
      'post' => [TeamProcessor::class, 'team_post', 'sendTeamEventEmail'],
      'event' => [TeamProcessor::class, 'event', 'sendTeamEventEmail'],
      'task' => [TeamProcessor::class, 'task_team', 'sendTeamEventEmail'],
    ];
  }

  /**
   * Generic hooks must reject non-nodes and unrelated bundles before dispatch.
   */
  #[DataProvider('processors')]
  public function testRejectedEntities(string $class, string $bundle, string $method): void {
    $manager = $this->createMock(NotificationManager::class);
    $manager->expects($this->never())->method($method);
    $processor = new $class($manager);
    $entity = $this->createMock(EntityInterface::class);
    $entity->expects($this->never())->method('bundle');
    $processor->process($entity, 'insert');
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('wiki');
    $processor->process($node, 'insert');
  }

  /**
   * Supported nodes must preserve the operation and comment at dispatch.
   */
  #[DataProvider('processors')]
  public function testSupportedEntities(string $class, string $bundle, string $method): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn($bundle);
    $manager = $this->createMock(NotificationManager::class);
    $arguments = $class === TeamProcessor::class ? [$node, NULL] : [$node, 'update'];
    $manager->expects($this->once())->method($method)->with(...$arguments);
    (new $class($manager))->process($node, 'update');
  }

  /**
   * Builds controlled services without a database or mail transport.
   */
  private function manager(EntityTypeManagerInterface $entities, string $current = 'en', ?GeocoderInterface $geocoder = NULL): NotificationManager {
    $account = $this->createMock(AccountInterface::class);
    $account->method('getPreferredLangcode')->willReturn($current);
    $languages = $this->createMock(LanguageManagerInterface::class);
    $languages->method('getDefaultLanguage')->willReturn(new Language(['id' => 'en']));
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    return new NotificationManager($account, $languages, $this->createMock(ModuleHandlerInterface::class), $this->createMock(FileSystemInterface::class), $loggerFactory, $this->createMock(EmailProcessor::class), $entities, $this->createMock(ConfigFactoryInterface::class), $geocoder ?? $this->createMock(GeocoderInterface::class), $this->createMock(StateInterface::class));
  }

  /**
   * Missing first travelers must fall back to the owner, not a later traveler.
   */
  public function testMissingFirstTravelerUsesOwnerLanguage(): void {
    $owner = $this->createMock(UserInterface::class);
    $owner->method('getPreferredLangcode')->willReturn('es');
    $field = $this->createMock(FieldItemList::class);
    $field->method('getValue')->willReturn([['target_id' => 42], ['target_id' => 43]]);
    $field->method('isEmpty')->willReturn(FALSE);
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('dootrip');
    $node->method('hasField')->with('field_dootripper_s')->willReturn(TRUE);
    $node->method('get')->willReturn($field);
    $node->method('getOwner')->willReturn($owner);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())->method('load')->with(42)->willReturn(NULL);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->with('user')->willReturn($storage);
    $this->assertSame('es', (new \ReflectionMethod(NotificationManager::class, 'getUserPreferredLanguage'))->invoke($this->manager($entities), $node));
  }

  /**
   * Deleted authors must not prevent current/default language fallbacks.
   */
  public function testMissingOwnerLanguageFallbacks(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('dootrip');
    $node->method('hasField')->willReturn(FALSE);
    $node->method('getOwner')->willReturn(NULL);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->expects($this->never())->method('getStorage');
    $method = new \ReflectionMethod(NotificationManager::class, 'getUserPreferredLanguage');
    $this->assertSame('de', $method->invoke($this->manager($entities, 'de'), $node));
    $this->assertSame('en', $method->invoke($this->manager($entities, ''), $node));
  }

  /**
   * Missing users and duplicate references must not create invalid recipients.
   */
  public function testRelatedRecipientsWithMissingReferences(): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('getEmail')->willReturn('traveler@example.invalid');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturnMap([[42, $user], [43, NULL]]);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->with('user')->willReturn($storage);
    $field = $this->createMock(FieldItemList::class);
    $field->method('getValue')->willReturn([['target_id' => 42], ['target_id' => 43], ['target_id' => 42], [
      'target_id' => NULL,
    ]]);
    $node = $this->createMock(NodeInterface::class);
    $node->method('getOwner')->willReturn(NULL);
    $node->method('hasField')->willReturn(TRUE);
    $node->method('get')->willReturn($field);
    $method = new \ReflectionMethod(NotificationManager::class, 'getDootripRelatedEmails');
    $this->assertSame(',traveler@example.invalid', $method->invoke($this->manager($entities), $node));
  }

  /**
   * Current geocoder APIs require provider objects and may return no addresses.
   */
  public function testProviderObjectsAndEmptyGeocoding(): void {
    $provider = $this->createMock(GeocoderProviderInterface::class);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with('googlemaps')->willReturn($provider);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->with('geocoder_provider')->willReturn($storage);
    $geocoder = $this->createMock(GeocoderInterface::class);
    $geocoder->expects($this->once())->method('reverse')->with('40.4', '-3.7', [$provider])->willReturn(new AddressCollection([]));
    $method = new \ReflectionMethod(NotificationManager::class, 'getCountryCodeFromCoords');
    $this->assertNull($method->invoke($this->manager($entities, 'en', $geocoder), '40.4', '-3.7'));
  }

  /**
   * Provides absent submissions and missing-form contact submissions.
   */
  public static function contacts(): array {
    return [
      'absent submission' => [NULL, 'en', 'contact@labdoo.org', FALSE],
      'Spain without copy' => [['country' => 'Spain'], 'en', 'contact@labdoo.org', FALSE],
      'German contact' => [
        ['country' => ['country' => 'DE', 'city' => 'Berlin']],
        'de',
        'Labdoo-DACH@Labdoo.org',
        FALSE,
      ],
      'Spanish copy' => [['name' => 'Ana', 'email_address' => 'ana@example.invalid', 'country' => [
        'country' => 'Spain',
        'city' => 'Madrid',
      ], 'message' => 'Mensaje conservado', 'copy' => TRUE], 'es', 'contact@labdoo.org', TRUE],
      'English copy' => [
        ['name' => 'Ana', 'message' => 'Mensaje conservado', 'copy' => TRUE],
        'en',
        'contact@labdoo.org',
        TRUE,
      ],
    ];
  }

  /**
   * Invalid token data must be rejected before resolving dependent services.
   */
  public function testInvalidContactSubmission(): void {
    require_once dirname(__DIR__, 2) . '/web/modules/custom/labdoo_notifications/labdoo_notifications.module';
    \Drupal::setContainer(new ContainerBuilder());
    $result = labdoo_notifications_tokens('labdoo_notifications', [
      'contact_body' => '[body]',
    ], ['webform_submission' => new \stdClass()]);
    $this->assertSame([], $result);
  }

  /**
   * Contact routing and localized copies must work even without a source form.
   */
  #[DataProvider('contacts')]
  public function testOptionalContactSubmission(?array $values, string $langcode, string $recipient, bool $copy): void {
    require_once dirname(__DIR__, 2) . '/web/modules/custom/labdoo_notifications/labdoo_notifications.module';
    $languages = $this->createMock(LanguageManagerInterface::class);
    $languages->method('getCurrentLanguage')->willReturn(new Language(['id' => $langcode]));
    $site = $this->createMock(ImmutableConfig::class);
    $site->method('get')->with('mail')->willReturn('site@example.invalid');
    $config = $this->createMock(ConfigFactoryInterface::class);
    $config->method('get')->with('system.site')->willReturn($site);
    $email = $this->createMock(EmailProcessor::class);
    $email->method('loadTemplate')->willReturn('[MESSAGE]');
    $email->method('processParameters')->willReturnCallback(static function (array $params, string &$body): void {
      foreach ($params as $key => $value) {
        $body = str_replace('[' . $key . ']', $value, $body);
      }
    });
    $container = new ContainerBuilder();
    $container->set('language_manager', $languages);
    $container->set('config.factory', $config);
    $container->set('labdoo_notifications.email_processor', $email);
    \Drupal::setContainer($container);
    $data = [];
    if ($values !== NULL) {
      $submission = $this->createMock(WebformSubmissionInterface::class);
      $submission->method('getData')->willReturn($values);
      $submission->method('getWebform')->willReturn(NULL);
      $data['webform_submission'] = $submission;
    }
    $result = labdoo_notifications_tokens('labdoo_notifications', [
      'contact_shub_to_mail' => '[to]',
      'contact_body' => '[body]',
    ], $data);
    $this->assertSame($recipient, $result['[to]']);
    if ($copy) {
      $this->assertStringContainsString($langcode === 'es' ? 'Nombre: Ana' : 'Name: Ana', $result['[body]']);
      $this->assertStringContainsString('Mensaje conservado', $result['[body]']);
      $this->assertStringNotContainsString('[MESSAGE]', $result['[body]']);
    }
    else {
      $this->assertSame('', $result['[body]']);
    }
  }

}
