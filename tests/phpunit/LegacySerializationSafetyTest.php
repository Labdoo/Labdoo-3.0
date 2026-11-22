<?php

declare(strict_types=1);

namespace Labdoo\Tests\Unit;

use Drupal\labdoo_migrate\Services\SpecialFieldTypes\SpecialFieldTypeMetatags;
use Drupal\queue_manager\Model\QueueDataModel;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Legacy serialized arrays must not instantiate payload-supplied classes.
 */
class LegacySerializationSafetyTest extends UnitTestCase {

  /**
   * Supplies both legacy deserialization entry points.
   */
  public static function importers(): array {
    return [['queue'], ['metatags']];
  }

  /**
   * Ordinary arrays survive while class loading from payloads stays disabled.
   */
  #[DataProvider('importers')]
  public function testPayloadCannotLoadClasses(string $kind): void {
    $class = 'LabdooUnsafeLegacyPayload';
    $autoloaded = FALSE;
    $loader = static function (string $name) use ($class, &$autoloaded): void {
      if ($name === $class) {
        $autoloaded = TRUE;
      }
    };
    spl_autoload_register($loader, TRUE, TRUE);
    try {
      $payload = 'a:2:{s:4:"text";s:4:"safe";s:7:"payload";O:' . strlen($class) . ':"' . $class . '":0:{}}';
      if ($kind === 'queue') {
        $model = new QueueDataModel();
        $model->__unserialize(['queue_id' => 'unit', 'timestamp' => NULL, 'data' => $payload]);
        $result = $model->getData();
      }
      else {
        $result = (new \ReflectionMethod(SpecialFieldTypeMetatags::class, 'processSingleValue'))->invoke(new SpecialFieldTypeMetatags(), $payload);
      }
      $this->assertFalse($autoloaded, 'Payload-supplied classes must never invoke an autoloader.');
      $this->assertSame('safe', $result['text']);
      $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $result['payload']);
    }
    finally {
      spl_autoload_unregister($loader);
    }
  }

}
