<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;

/**
 * One contributed shape on one key: the shape, its id, and whose it is.
 *
 * What the builder writes onto the canonical definition at seal, through
 * DefinitionMetadata::setShapes(), so the shapes travel wherever the
 * definition does — nested inside a map, into a refined surface, through
 * a policy filter that may take one away — and are read back off the
 * definition by whatever accepts, displays or advertises the key.
 *
 * Also the one home of the matching rule's static half: what makes two
 * readings of one key indistinguishable, which sealing refuses, and how
 * a payload names the reading it means.
 *
 * @see \Drupal\data_surface\DataSurfaceShapeInterface
 * @see docs/shapes.md
 */
final class SurfaceShape {

  /**
   * The key the canonical reading is listed under beside shape ids.
   *
   * In the reserved "@" namespace, because a shape id never starts with
   * "@" and so can never be mistaken for it.
   */
  public const CANONICAL = '@canonical';

  /**
   * Constructs a SurfaceShape.
   *
   * @param string $id
   *   The shape's id, unique on its key.
   * @param \Drupal\data_surface\DataSurfaceShapeInterface $shape
   *   The shape.
   * @param string $contributor
   *   The module that contributed it, or DataSurfaceInterface::OWNER.
   */
  public function __construct(
    public readonly string $id,
    public readonly DataSurfaceShapeInterface $shape,
    public readonly string $contributor = DataSurfaceInterface::OWNER,
  ) {
  }

  /**
   * Answers whether a value names its shape explicitly.
   *
   * The selector is a map holding the reserved DataSurfacePipeline
   * Interface::SHAPE key, and DataSurfacePipelineInterface::SHAPE_VALUE
   * beside it for the value itself. No definition names a key with a
   * leading "@", so a map carrying one cannot be a value of any shape.
   *
   * @param mixed $value
   *   The value.
   *
   * @return bool
   *   TRUE for a selector.
   */
  public static function isSelected(mixed $value): bool {
    return is_array($value) && array_key_exists(DataSurfacePipelineInterface::SHAPE, $value);
  }

  /**
   * Builds the selector naming one shape for one value.
   *
   * @param string $id
   *   The shape id.
   * @param mixed $value
   *   The value, in that shape.
   *
   * @return array
   *   The selector.
   */
  public static function select(string $id, mixed $value): array {
    return [
      DataSurfacePipelineInterface::SHAPE => $id,
      DataSurfacePipelineInterface::SHAPE_VALUE => $value,
    ];
  }

  /**
   * Says how a value for a definition is told apart from other values.
   *
   * The structure the matching rule reads. Two definitions with the same
   * signature could both read one input, so which of them a caller meant
   * would be a guess, and sealing refuses the pair. Integers and floats
   * share one, because 3 is both; the string types share one; a map is
   * its property names, and two maps overlap when they share any, since a
   * partial map naming only the shared property fits both.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return array{0: string, 1: string[]}
   *   The kind — number, string, boolean, map, list, or the data type —
   *   and, for a map, its property names.
   */
  public static function signature(DataDefinitionInterface $definition): array {
    if ($definition instanceof ListDataDefinitionInterface) {
      return ['list', []];
    }
    $properties = $definition instanceof ComplexDataDefinitionInterface ? $definition->getPropertyDefinitions() : [];
    if ($properties !== [] || $definition->getDataType() === 'map') {
      return ['map', array_map('strval', array_keys($properties))];
    }
    $kind = match ($definition->getDataType()) {
      'integer', 'float' => 'number',
      'string', 'email', 'uri' => 'string',
      default => $definition->getDataType(),
    };
    return [$kind, []];
  }

  /**
   * Answers whether one input could be read by two definitions.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $a
   *   One reading.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $b
   *   The other.
   *
   * @return bool
   *   TRUE when they cannot be told apart by structure.
   */
  public static function overlaps(DataDefinitionInterface $a, DataDefinitionInterface $b): bool {
    [$kind_a, $properties_a] = static::signature($a);
    [$kind_b, $properties_b] = static::signature($b);
    if ($kind_a !== $kind_b) {
      return FALSE;
    }
    if ($kind_a !== 'map' || $properties_a === [] || $properties_b === []) {
      return TRUE;
    }
    return array_intersect($properties_a, $properties_b) !== [];
  }

  /**
   * Names a shape for a message: its id, and whose it is.
   *
   * @return string
   *   The id, with the contributor when it is not the owner.
   */
  public function describe(): string {
    return $this->contributor === DataSurfaceInterface::OWNER
      ? $this->id
      : sprintf('%s (from %s)', $this->id, $this->contributor);
  }

}
