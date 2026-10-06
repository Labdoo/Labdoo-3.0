<?php

declare(strict_types=1);

namespace Drupal\labdoo_dootronics\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Requires a destination village for dootronics in status S3.
 *
 * @Constraint(
 *   id = "DootronicS3RequiresEdoovillage",
 *   label = @Translation("Dootronic S3 destination", context = "Validation")
 * )
 */
class DootronicS3RequiresEdoovillageConstraint extends Constraint {

  /**
   * The validation message.
   *
   * @var string
   */
  public string $message = 'An Edoovillage destination is required when a dootronic is in status S3.';

}
