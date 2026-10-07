<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\data_surface\DataSurfaceInterface;

/**
 * Chooses which keys a generated provider form asks for in a shape.
 *
 * The form display setting of a route-served provider form, beside the
 * cosmetic layer and found the same way: on the route's cosmetics
 * service, or on the provider itself. A separate interface rather than
 * a fourth cosmetic method, so a cosmetic layer written before shapes
 * existed keeps working, and one that has no opinion says nothing.
 *
 * It is presentation in exactly the cosmetic layer's sense: the key's
 * canonical definition, what it validates against and what it stores
 * are untouched, and only the input a person is shown changes. A plugin
 * host makes the same choice by overriding
 * DataSurfaceHostTrait::surfaceShapeDisplay().
 *
 * @see \Drupal\data_surface\Form\SurfaceShapeDisplay
 * @see docs/shapes.md
 */
interface DataSurfaceShapeDisplayInterface {

  /**
   * Gets the shape each key is asked for in, on this form.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface the form is built from.
   * @param string $operation
   *   The operation the form is serving.
   * @param string|null $subject
   *   The subject the form is serving, or NULL.
   *
   * @return array<string, string>
   *   Dotted surface key => shape id. A key left out renders as its
   *   canonical; a key or shape the surface does not have is ignored.
   */
  public function surfaceFormShapes(DataSurfaceInterface $surface, string $operation, ?string $subject): array;

}
