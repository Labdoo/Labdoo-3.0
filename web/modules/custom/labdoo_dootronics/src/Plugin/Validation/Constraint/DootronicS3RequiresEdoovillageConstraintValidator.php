<?php

declare(strict_types=1);

namespace Drupal\labdoo_dootronics\Plugin\Validation\Constraint;

use Drupal\node\NodeInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates that S3 dootronics have a destination village.
 */
class DootronicS3RequiresEdoovillageConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($entity, Constraint $constraint): void {
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'dootronic') {
      return;
    }

    $status_item = $entity->get('field_dootronic_status')->first();
    $status = $status_item ? ($status_item->getValue()['value'] ?? NULL) : NULL;
    if ($status !== 'S3') {
      return;
    }
    // A non-empty target ID can still refer to a school absent after migration.
    $destinations = $entity->get('field_edoovillage_destination')->referencedEntities();
    $destination = reset($destinations);
    if (!$destination instanceof NodeInterface || !$destination->id() || $destination->bundle() !== 'edoovillage') {
      $this->context->buildViolation($constraint->message)
        ->atPath('field_edoovillage_destination')
        ->addViolation();
    }
  }

}
