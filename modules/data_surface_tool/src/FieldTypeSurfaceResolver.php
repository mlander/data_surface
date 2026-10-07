<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool;

use Drupal\data_surface\DataSurfaceCoordinate;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DataSurfaceResolverInterface;
use Drupal\data_surface\Form\FieldSurfaceProviderInterface;

/**
 * Resolves a field type's settings surface from its address.
 *
 * The coordinate is `field_type:<type>`, operation `field_settings`, and
 * a field config id as the subject: the field type is the host, its
 * settings are the operation, and the subject says which instance, saved
 * or about to be added. That is enough to know the shape — the field
 * type decides it — without anybody holding a field item, which is what
 * lets a parent mount a field type's settings by address alone.
 *
 * A field item is its own subject, so it cannot be asked about a field
 * id; this resolver is the code that turns the id into the item bound to
 * that field, through the locator, and asks the item.
 *
 * @see \Drupal\data_surface_tool\FieldInstanceSurfaceProvider
 */
final class FieldTypeSurfaceResolver implements DataSurfaceResolverInterface {

  /**
   * The host type this resolver serves.
   */
  public const HOST_TYPE = 'field_type';

  /**
   * Constructs a FieldTypeSurfaceResolver.
   *
   * @param \Drupal\data_surface_tool\FieldSurfaceLocator $locator
   *   The service that finds a field instance and its surface.
   */
  public function __construct(
    protected readonly FieldSurfaceLocator $locator,
  ) {
  }

  /**
   * Builds the coordinate of one field instance's settings surface.
   *
   * @param string $field_type
   *   The field type plugin id.
   * @param string $field_id
   *   The field config id, `<entity type>.<bundle>.<field>`.
   *
   * @return \Drupal\data_surface\DataSurfaceCoordinate
   *   The coordinate.
   */
  public static function coordinate(string $field_type, string $field_id): DataSurfaceCoordinate {
    return new DataSurfaceCoordinate(
      self::HOST_TYPE . ':' . $field_type,
      FieldSurfaceProviderInterface::OPERATION_FIELD_SETTINGS,
      $field_id,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function applies(DataSurfaceCoordinate $coordinate): bool {
    return $coordinate->hostType() === self::HOST_TYPE
      && $coordinate->operation === FieldSurfaceProviderInterface::OPERATION_FIELD_SETTINGS
      && $coordinate->subject !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(DataSurfaceCoordinate $coordinate): DataSurfaceInterface {
    $field = $this->locator->fieldAt((string) $coordinate->subject);
    if ($field === NULL) {
      throw new \InvalidArgumentException(sprintf('%s names no field instance, saved or addable.', $coordinate));
    }
    if (self::HOST_TYPE . ':' . $field->getType() !== $coordinate->hostId) {
      throw new \InvalidArgumentException(sprintf(
        '%s names a %s field, not one of the field type the coordinate addresses.',
        $coordinate,
        $field->getType(),
      ));
    }
    return $this->locator->surfaceFor($field)
      ?? throw new \InvalidArgumentException(sprintf('The %s field type declares no surface for its settings, so %s addresses nothing.', $field->getType(), $coordinate));
  }

}
