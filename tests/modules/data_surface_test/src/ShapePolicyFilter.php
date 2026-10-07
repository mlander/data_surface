<?php

declare(strict_types=1);

namespace Drupal\data_surface_test;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceFilterInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\SurfaceShape;

/**
 * A policy filter taking a contributed shape away, or adding one.
 *
 * "This site does not take that spelling here" is a policy, and a
 * policy may only remove; told to add, it is how the tests check that
 * the remove-only rule holds for shapes too.
 */
final class ShapePolicyFilter implements DataSurfaceFilterInterface {

  /**
   * Constructs a ShapePolicyFilter.
   *
   * @param string $key
   *   The dotted key whose shape this policy has an opinion about.
   * @param string $remove
   *   The shape it takes away.
   * @param \Drupal\data_surface\SurfaceShape|null $add
   *   A shape it puts on instead, which the narrowing check must refuse.
   */
  public function __construct(
    protected readonly string $key,
    protected readonly string $remove,
    protected readonly ?SurfaceShape $add = NULL,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function filterDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    $segments = explode('.', $this->key);
    if (array_shift($segments) !== $name) {
      return $definition;
    }
    $target = $definition;
    foreach ($segments as $segment) {
      $target = $target instanceof ComplexDataDefinitionInterface ? $target->getPropertyDefinition($segment) : NULL;
    }
    if ($target === NULL) {
      return $definition;
    }
    DefinitionMetadata::withoutShape($target, $this->remove);
    if ($this->add !== NULL) {
      DefinitionMetadata::setShapes($target, DefinitionMetadata::getShapes($target) + [$this->add->id => $this->add]);
    }
    return $definition;
  }

}
