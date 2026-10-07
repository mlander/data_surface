<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\TypedData\MapDataDefinition;

/**
 * A sealed child surface fixed at one key of its parent.
 *
 * What DataSurfaceBuilderInterface::mount() leaves on the entry it
 * declares, and what each variant of a slot is. The child is a whole
 * surface, not a copy of its definitions: its own refiners, locked keys,
 * secrets and defaults travel with it, so the pipeline and the form can
 * hand a mounted value to the child and let it answer for itself, and
 * its refiners run in the child's own frame, under the names the child
 * gave its keys, never under a parent path.
 *
 * The coordinate is kept when the child was named by one, so whatever
 * advertises the parent can say which surface sits at the key; an inline
 * child, declared as part of its parent, has no address of its own.
 *
 * @see \Drupal\data_surface\DataSurfaceBuilderInterface::mount()
 * @see \Drupal\data_surface\SurfaceSlot
 */
final class SurfaceMount {

  /**
   * Constructs a SurfaceMount.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $child
   *   The sealed child surface, as it was advertised.
   * @param \Drupal\data_surface\DataSurfaceCoordinate|null $coordinate
   *   The address the child was resolved from, or NULL for a child that
   *   was declared inline or handed over already sealed.
   */
  public function __construct(
    public readonly DataSurfaceInterface $child,
    public readonly ?DataSurfaceCoordinate $coordinate = NULL,
  ) {
  }

  /**
   * Builds the map a mounted child is advertised as.
   *
   * The map's property definitions ARE the child's definitions, in the
   * child's order: what the parent says about the key is what the child
   * says about itself, under the label the parent gave the key.
   *
   * @param \Drupal\Core\TypedData\MapDataDefinition $shell
   *   The map describing the key itself: its label, description,
   *   required flag and constraints. Not changed; a clone is filled.
   * @param \Drupal\data_surface\DataSurfaceInterface $child
   *   The child, advertised or refined.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map.
   */
  public static function mapOf(MapDataDefinition $shell, DataSurfaceInterface $child): MapDataDefinition {
    return static::fill(clone $shell, $child->getDefinitions());
  }

  /**
   * Writes definitions into a map as its properties, in order.
   *
   * The one way this module turns a group of definitions into a map's
   * properties, shared by mounted children and by the third-party
   * namespaces the builder assembles.
   *
   * @param \Drupal\Core\TypedData\MapDataDefinition $map
   *   The map to fill.
   * @param iterable<string, \Drupal\Core\TypedData\DataDefinitionInterface> $definitions
   *   The definitions, keyed by property name.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The same map.
   */
  public static function fill(MapDataDefinition $map, iterable $definitions): MapDataDefinition {
    foreach ($definitions as $name => $definition) {
      $map->setPropertyDefinition((string) $name, $definition);
    }
    return $map;
  }

}
