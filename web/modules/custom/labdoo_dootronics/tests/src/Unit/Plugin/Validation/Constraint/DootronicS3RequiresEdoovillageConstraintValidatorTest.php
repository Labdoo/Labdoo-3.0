<?php

declare(strict_types=1);

namespace Drupal\Tests\labdoo_dootronics\Unit\Plugin\Validation\Constraint;

use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\node\NodeInterface;
use Drupal\labdoo_dootronics\Plugin\Validation\Constraint\DootronicS3RequiresEdoovillageConstraint;
use Drupal\labdoo_dootronics\Plugin\Validation\Constraint\DootronicS3RequiresEdoovillageConstraintValidator;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Tests the S3 dootronic destination requirement.
 *
 * @group labdoo_dootronics
 */
class DootronicS3RequiresEdoovillageConstraintValidatorTest extends UnitTestCase {

  /**
   * Tests missing and dangling school references are rejected.
   *
   * @dataProvider missingDestinationCases
   */
  public function testS3RequiresDestination(bool $empty, bool $dangling): void {
    $validator = new DootronicS3RequiresEdoovillageConstraintValidator();
    $context = $this->createMock(ExecutionContextInterface::class);
    $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
    $builder->expects($this->once())->method('atPath')->with('field_edoovillage_destination')->willReturnSelf();
    $builder->expects($this->once())->method('addViolation');
    $context->expects($this->once())
      ->method('buildViolation')
      ->with('An Edoovillage destination is required when a dootronic is in status S3.')
      ->willReturn($builder);
    $validator->initialize($context);

    $validator->validate($this->dootronic('S3', $empty, $dangling), new DootronicS3RequiresEdoovillageConstraint());
  }

  /**
   * Provides empty and dangling reference cases.
   */
  public static function missingDestinationCases(): array {
    return ['empty' => [TRUE, FALSE], 'dangling' => [FALSE, TRUE]];
  }

  /**
   * Tests an S3 dootronic with a destination passes validation.
   */
  public function testS3WithDestinationPasses(): void {
    $validator = new DootronicS3RequiresEdoovillageConstraintValidator();
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');
    $validator->initialize($context);

    $validator->validate($this->dootronic('S3', FALSE), new DootronicS3RequiresEdoovillageConstraint());
  }

  /**
   * Tests unrelated node bundles are ignored.
   */
  public function testOtherBundlesAreIgnored(): void {
    $validator = new DootronicS3RequiresEdoovillageConstraintValidator();
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');
    $validator->initialize($context);
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('page');

    $validator->validate($node, new DootronicS3RequiresEdoovillageConstraint());
  }

  /**
   * Creates a mock dootronic node.
   */
  private function dootronic(string $status, bool $destination_is_empty, bool $dangling = FALSE): NodeInterface {
    $status_item = $this->createMock(FieldItemInterface::class);
    $status_item->method('getValue')->willReturn(['value' => $status]);
    $status_list = $this->createMock(FieldItemListInterface::class);
    $status_list->method('first')->willReturn($status_item);
    $destination_list = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $destination_list->method('isEmpty')->willReturn($destination_is_empty);
    $school = $this->createMock(NodeInterface::class);
    $school->method('bundle')->willReturn('edoovillage');
    $school->method('id')->willReturn(123);
    $destination_list->method('referencedEntities')->willReturn($destination_is_empty || $dangling ? [] : [$school]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('dootronic');
    $node->method('get')->willReturnMap([
      ['field_dootronic_status', $status_list],
      ['field_edoovillage_destination', $destination_list],
    ]);
    return $node;
  }

}
