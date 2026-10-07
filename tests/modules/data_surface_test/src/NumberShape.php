<?php

declare(strict_types=1);

namespace Drupal\data_surface_test;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurfaceShapeInterface;

/**
 * A shape over a number that is another number: minutes for seconds.
 *
 * Indistinguishable from a numeric canonical by structure, which is what
 * the ambiguity tests need; on a canonical of another kind it is an
 * ordinary shape. Typed 'map', it takes {"minutes": n} instead.
 */
final class NumberShape implements DataSurfaceShapeInterface {

  /**
   * Constructs a NumberShape.
   *
   * @param int $factor
   *   What one unit of the shape is in the canonical.
   * @param string $type
   *   The input's data type, or 'map' for a map of one minutes key.
   */
  public function __construct(
    protected readonly int $factor = 60,
    protected readonly string $type = 'integer',
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getInputDefinition(): DataDefinitionInterface {
    if ($this->type === 'map') {
      return MapDataDefinition::create()
        ->setLabel('Minutes')
        ->setPropertyDefinition('minutes', DataDefinition::create('integer')->setLabel('Minutes'));
    }
    return DataDefinition::create($this->type)->setLabel('Minutes');
  }

  /**
   * {@inheritdoc}
   */
  public function toCanonical(mixed $input): mixed {
    if (is_array($input)) {
      $input = $input['minutes'] ?? NULL;
    }
    return is_numeric($input) ? (int) $input * $this->factor : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function fromCanonical(mixed $stored): mixed {
    $minutes = is_int($stored) && $stored % $this->factor === 0 ? intdiv($stored, $this->factor) : NULL;
    return $this->type === 'map' ? ['minutes' => $minutes] : $minutes;
  }

  /**
   * {@inheritdoc}
   */
  public function isLossy(): bool {
    return FALSE;
  }

}
