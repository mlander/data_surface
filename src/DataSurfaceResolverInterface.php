<?php

declare(strict_types=1);

namespace Drupal\data_surface;

/**
 * Turns the coordinate of one kind of host into that host's surface.
 *
 * A resolver speaks for one host type, and is the one place allowed to
 * parse that host type's subject: a field type resolver knows a subject
 * names a field instance, a block resolver knows its host is its own
 * subject. The factory asks every registered resolver in priority order
 * and takes the first that applies.
 *
 * Registered as a service tagged `data_surface.surface_resolver`. The
 * factory holds only the service ids and instantiates a resolver the
 * first time a coordinate needs one, so a resolver may depend on
 * providers that themselves build through the factory.
 *
 * @see \Drupal\data_surface\DataSurfaceFactoryInterface::resolve()
 * @see \Drupal\data_surface\DataSurfaceCoordinate
 */
interface DataSurfaceResolverInterface {

  /**
   * Answers whether this resolver serves a coordinate.
   *
   * @param \Drupal\data_surface\DataSurfaceCoordinate $coordinate
   *   The coordinate.
   *
   * @return bool
   *   TRUE when resolve() can answer for it.
   */
  public function applies(DataSurfaceCoordinate $coordinate): bool;

  /**
   * Builds the surface a coordinate addresses.
   *
   * The surface comes through the factory, like every surface: a
   * resolver asks the provider it speaks for, and the provider builds
   * through the factory, so the child's own build event has run and its
   * contributors and filters are part of what is returned.
   *
   * @param \Drupal\data_surface\DataSurfaceCoordinate $coordinate
   *   The coordinate, one this resolver applies to.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   *
   * @throws \InvalidArgumentException
   *   When the coordinate names an operation or a subject the host
   *   cannot place.
   */
  public function resolve(DataSurfaceCoordinate $coordinate): DataSurfaceInterface;

}
