<?php

declare(strict_types=1);

namespace Drupal\data_surface\Target;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Pipeline\PreparedValues;
use Drupal\data_surface\SurfaceMount;

/**
 * Stores each mounted child's values through the child's own target.
 *
 * A mount fixes somebody else's surface at a key, and that surface
 * usually comes with a destination of its own: a field type's settings
 * are written to the field config by a FieldSettingsTarget, which reads
 * the field type's surface for its secrets and its storage shape. This
 * target routes the mount's value there, handing the child target the
 * child surface — not the parent — so everything it learns from the
 * surface it is handed is about the values it is actually writing.
 *
 * Only the mounted keys it was given are its own. A surface that holds
 * other keys beside its mounts pairs this with the targets for those
 * inside a CompositeTarget, which is what checks that every key is
 * stored exactly once.
 *
 * @see \Drupal\data_surface\DataSurfaceBuilderInterface::mount()
 * @see docs/nesting.md
 */
final class MountTarget implements DataSurfaceTargetInterface {

  use DependencySerializationTrait;

  /**
   * Constructs a MountTarget.
   *
   * @param array<string, \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface> $targets
   *   The child targets, keyed by the surface key their child is
   *   mounted at.
   */
  public function __construct(
    protected readonly array $targets,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function load(DataSurfaceInterface $surface): array {
    $values = [];
    foreach ($this->targets as $key => $target) {
      $values[$key] = $target->load($this->mountAt($surface, (string) $key)->child);
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(DataSurfaceInterface $surface, array $values): PreparedValues {
    $prepared = [];
    $dependencies = [];
    foreach ($this->targets as $key => $target) {
      $value = $values[$key] ?? [];
      $child = $target->prepare($this->mountAt($surface, (string) $key)->child, is_array($value) ? $value : []);
      $prepared[$key] = $child;
      foreach ($child->dependencies as $type => $names) {
        $dependencies[$type] = array_values(array_unique(array_merge($dependencies[$type] ?? [], (array) $names)));
      }
    }
    return new PreparedValues($values, $prepared, $dependencies);
  }

  /**
   * {@inheritdoc}
   */
  public function commit(PreparedValues $prepared): void {
    foreach ($this->targets as $key => $target) {
      $child = is_array($prepared->artifact) ? ($prepared->artifact[$key] ?? NULL) : NULL;
      if (!$child instanceof PreparedValues) {
        throw new \InvalidArgumentException('The prepared values did not come from this mount target.');
      }
      $target->commit($child);
    }
  }

  /**
   * Finds the mount a child target writes for.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The parent surface.
   * @param string $key
   *   The surface key.
   *
   * @return \Drupal\data_surface\SurfaceMount
   *   The mount.
   *
   * @throws \InvalidArgumentException
   *   When the surface holds no mount at that key.
   */
  protected function mountAt(DataSurfaceInterface $surface, string $key): SurfaceMount {
    return $surface->getDefinitions()->entry($key)->mount
      ?? throw new \InvalidArgumentException(sprintf('The surface holds no mount at "%s", so there is no child to store through.', $key));
  }

}
