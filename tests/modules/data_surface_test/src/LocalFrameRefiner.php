<?php

declare(strict_types=1);

namespace Drupal\data_surface_test;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceRefinerInterface;
use Drupal\data_surface\Refinement\ChoiceSet;

/**
 * A refiner that records the names it is dispatched under.
 *
 * Mounted children must refine in their own frame: the child's refiner
 * is handed the child's own key names, never a parent path, and a
 * parent's refiner never sees a child key at all. The log is how a test
 * reads which names each was handed.
 */
final class LocalFrameRefiner implements DataSurfaceRefinerInterface {

  /**
   * Every name any instance was dispatched under, in order.
   *
   * @var string[]
   */
  public static array $names = [];

  /**
   * {@inheritdoc}
   *
   * A small cake takes cherries and nothing else.
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    static::$names[] = $name;
    if ($name === 'topping' && ($values['size'] ?? NULL) === 'small') {
      ChoiceSet::of($definition)?->withValues(['cherry'])->applyTo($definition);
    }
    return $definition;
  }

}
