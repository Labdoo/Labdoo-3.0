<?php

declare(strict_types=1);

namespace Labdoo\Quality;

use Drupal\Core\Database\Query\SelectInterface;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;

/**
 * Preserves the actual receiver returned by Select::forUpdate().
 */
final class FluentSelectReturnTypeExtension implements DynamicMethodReturnTypeExtension {

  /**
   * {@inheritdoc}
   */
  public function getClass(): string {
    return SelectInterface::class;
  }

  /**
   * {@inheritdoc}
   */
  public function isMethodSupported(MethodReflection $methodReflection): bool {
    return $methodReflection->getName() === 'forUpdate';
  }

  /**
   * {@inheritdoc}
   */
  public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type {
    return $scope->getType($methodCall->var);
  }

}
