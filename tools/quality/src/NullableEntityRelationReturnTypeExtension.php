<?php

declare(strict_types=1);

namespace Labdoo\Quality;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Models observed nullable entity relations without duplicate interface stubs.
 */
final class NullableEntityRelationReturnTypeExtension implements DynamicMethodReturnTypeExtension {

  /**
   * Constructs an extension for one dependency relation.
   *
   * @param class-string $className
   *   The interface declaring the relation.
   * @param string $methodName
   *   The relation getter whose actual implementation may return NULL.
   */
  public function __construct(private string $className, private string $methodName) {}

  /**
   * {@inheritdoc}
   */
  public function getClass(): string {
    return $this->className;
  }

  /**
   * {@inheritdoc}
   */
  public function isMethodSupported(MethodReflection $methodReflection): bool {
    return $methodReflection->getName() === $this->methodName;
  }

  /**
   * {@inheritdoc}
   */
  public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type {
    return TypeCombinator::addNull($methodReflection->getVariants()[0]->getReturnType());
  }

}
