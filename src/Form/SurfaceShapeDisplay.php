<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DefinitionMap;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\SurfaceShape;

/**
 * Renders a key in a shape a form display chose, and reads it back.
 *
 * The display chooses. A key that takes contributed shapes renders as
 * its canonical definition unless the host's form display names one of
 * them for it, by dotted key:
 *
 * @code
 * ['third_party_settings.my_module.review_deadline' => 'amount_unit']
 * @endcode
 *
 * That array is the whole setting: small, serializable, and cosmetic. It
 * changes how a value is asked for and never what is stored, so a choice
 * naming a key or a shape the surface does not have is ignored rather
 * than refused — the module contributing the shape may simply not be
 * installed.
 *
 * A chosen shape is rendered by swapping the key's definition, at
 * whatever depth it sits, for the shape's input definition wearing the
 * key's label, so the widgets — which read a definition and nothing
 * else — draw it with no idea a shape is involved. The stored value is
 * shown through the shape's fromCanonical(), and a lossy shape says it
 * is showing the nearest exact spelling. Extraction reads the element
 * through the same swapped definition and hands the pipeline the value
 * wrapped in the selector naming the shape, so a form never relies on
 * the matching rule.
 *
 * Static, and holding nothing: the effective choice rides on the
 * container under ELEMENT_KEY as a plain array, per the serialization
 * rule, and extraction reads it from there.
 *
 * @see docs/shapes.md
 */
final class SurfaceShapeDisplay {

  /**
   * The render key the effective choice is carried on.
   */
  public const ELEMENT_KEY = '#data_surface_shape_display';

  /**
   * Keeps the choices that name a shape the key actually takes.
   *
   * @param \Drupal\data_surface\DefinitionMap $definitions
   *   The surface's definitions, refined for the values being shown, so
   *   a shape a policy filter removed is not offered.
   * @param array $requested
   *   The host's choice: dotted key => shape id.
   *
   * @return array<string, string>
   *   The choices that apply, in the same spelling.
   */
  public static function resolve(DefinitionMap $definitions, array $requested): array {
    $display = [];
    foreach ($requested as $path => $id) {
      $definition = static::at($definitions, (string) $path);
      if ($definition !== NULL && is_string($id) && isset(DefinitionMetadata::getShapes($definition)[$id])) {
        $display[(string) $path] = $id;
      }
    }
    return $display;
  }

  /**
   * Gets the definition a key is rendered and read through.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition, canonical.
   * @param string $path
   *   The key's dotted path from the top of the surface.
   * @param array<string, string> $display
   *   The effective choice.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The definition itself when nothing at or below it is displayed in
   *   a shape, otherwise a copy with each chosen shape swapped in.
   */
  public static function definition(DataDefinitionInterface $definition, string $path, array $display): DataDefinitionInterface {
    $shape = static::chosen($definition, $path, $display);
    if ($shape !== NULL) {
      return static::displayed($definition, $shape);
    }
    if (!$definition instanceof MapDataDefinition || !static::below($path, $display)) {
      return $definition;
    }
    $copy = clone $definition;
    foreach ($copy->getPropertyDefinitions() as $property => $property_definition) {
      $copy->setPropertyDefinition((string) $property, static::definition($property_definition, $path . '.' . $property, $display));
    }
    return $copy;
  }

  /**
   * Says a value the way its displayed definition holds it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition, canonical.
   * @param mixed $value
   *   The value, canonical — or, on a rebuild, a selector already naming
   *   the displayed shape.
   * @param string $path
   *   The key's dotted path.
   * @param array<string, string> $display
   *   The effective choice.
   *
   * @return mixed
   *   The value, with each displayed key said in its shape.
   */
  public static function value(DataDefinitionInterface $definition, mixed $value, string $path, array $display): mixed {
    $shape = static::chosen($definition, $path, $display);
    if ($shape !== NULL) {
      if (SurfaceShape::isSelected($value)) {
        // Already said in a shape, which is what a rebuilt form holds.
        return $value[DataSurfacePipelineInterface::SHAPE] === $shape->id
          ? ($value[DataSurfacePipelineInterface::SHAPE_VALUE] ?? NULL)
          : $shape->shape->fromCanonical(NULL);
      }
      return $shape->shape->fromCanonical($value);
    }
    if (!is_array($value) || !$definition instanceof ComplexDataDefinitionInterface || !static::below($path, $display)) {
      return $value;
    }
    foreach (array_intersect_key($definition->getPropertyDefinitions(), $value) as $property => $property_definition) {
      $value[$property] = static::value($property_definition, $value[$property], $path . '.' . $property, $display);
    }
    return $value;
  }

  /**
   * Wraps what a displayed key submitted in the selector naming it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition, canonical.
   * @param mixed $raw
   *   What the element tree submitted for the key.
   * @param string $path
   *   The key's dotted path.
   * @param array<string, string> $display
   *   The effective choice.
   *
   * @return mixed
   *   The raw value, with each displayed key's value wrapped.
   */
  public static function wrap(DataDefinitionInterface $definition, mixed $raw, string $path, array $display): mixed {
    $shape = static::chosen($definition, $path, $display);
    if ($shape !== NULL) {
      return SurfaceShape::select($shape->id, $raw);
    }
    if (!is_array($raw) || !$definition instanceof ComplexDataDefinitionInterface || !static::below($path, $display)) {
      return $raw;
    }
    foreach (array_intersect_key($definition->getPropertyDefinitions(), $raw) as $property => $property_definition) {
      $raw[$property] = static::wrap($property_definition, $raw[$property], $path . '.' . $property, $display);
    }
    return $raw;
  }

  /**
   * Finds the shape chosen for a key, if it takes it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   * @param string $path
   *   The key's dotted path.
   * @param array<string, string> $display
   *   The effective choice.
   *
   * @return \Drupal\data_surface\SurfaceShape|null
   *   The shape, or NULL when the key is displayed as its canonical.
   */
  protected static function chosen(DataDefinitionInterface $definition, string $path, array $display): ?SurfaceShape {
    return isset($display[$path]) ? (DefinitionMetadata::getShapes($definition)[$display[$path]] ?? NULL) : NULL;
  }

  /**
   * Builds the definition a chosen shape is rendered through.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $canonical
   *   The key's canonical definition.
   * @param \Drupal\data_surface\SurfaceShape $shape
   *   The chosen shape.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   A copy of the shape's input definition, labeled as the key, with
   *   the key's required flag, and the shape's description or else the
   *   key's.
   */
  protected static function displayed(DataDefinitionInterface $canonical, SurfaceShape $shape): DataDefinitionInterface {
    $displayed = clone $shape->shape->getInputDefinition();
    if (!$displayed instanceof DataDefinition) {
      return $displayed;
    }
    $description = $displayed->getDescription() ?? $canonical->getDescription();
    if ($shape->shape->isLossy()) {
      $note = new TranslatableMarkup('What is stored is shown in its nearest exact spelling, which may not be the one it was entered in.');
      $description = $description === NULL
        ? $note
        : new TranslatableMarkup('@description @note', ['@description' => $description, '@note' => $note]);
    }
    return $displayed
      ->setLabel($canonical->getLabel())
      ->setDescription($description)
      ->setRequired($canonical->isRequired());
  }

  /**
   * Answers whether anything below a path is displayed in a shape.
   *
   * @param string $path
   *   The dotted path.
   * @param array<string, string> $display
   *   The effective choice.
   *
   * @return bool
   *   TRUE when a chosen key sits inside it.
   */
  protected static function below(string $path, array $display): bool {
    foreach (array_keys($display) as $chosen) {
      if (str_starts_with((string) $chosen, $path . '.')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Finds the definition at a dotted path.
   *
   * @param \Drupal\data_surface\DefinitionMap $definitions
   *   The surface's definitions.
   * @param string $path
   *   The dotted path.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The definition, or NULL when nothing is there.
   */
  protected static function at(DefinitionMap $definitions, string $path): ?DataDefinitionInterface {
    $segments = explode('.', $path);
    $definition = $definitions->get((string) array_shift($segments));
    foreach ($segments as $segment) {
      $definition = $definition instanceof ComplexDataDefinitionInterface ? $definition->getPropertyDefinition($segment) : NULL;
    }
    return $definition;
  }

}
