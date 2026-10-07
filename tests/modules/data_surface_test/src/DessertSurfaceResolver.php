<?php

declare(strict_types=1);

namespace Drupal\data_surface_test;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceCoordinate;
use Drupal\data_surface\DataSurfaceFactoryInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DataSurfaceResolverInterface;

/**
 * Resolves the dessert fixture: a child surface addressed by coordinate.
 *
 * One host, `dessert:cake`, with its own refinement edge, its own locked
 * key and its own cache tag, so a parent mounting it by coordinate can
 * be asked whether all three survived the mount.
 */
final class DessertSurfaceResolver implements DataSurfaceResolverInterface {

  /**
   * The host id this resolver serves.
   */
  public const HOST_ID = 'dessert:cake';

  /**
   * The cache tag the child declares.
   */
  public const TAG = 'data_surface_test:dessert';

  /**
   * Constructs a DessertSurfaceResolver.
   *
   * @param \Drupal\data_surface\DataSurfaceFactoryInterface $factory
   *   The factory the child is built through.
   */
  public function __construct(
    protected readonly DataSurfaceFactoryInterface $factory,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function applies(DataSurfaceCoordinate $coordinate): bool {
    return $coordinate->hostId === self::HOST_ID;
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(DataSurfaceCoordinate $coordinate): DataSurfaceInterface {
    if ($coordinate->operation !== 'configure') {
      throw new \InvalidArgumentException(sprintf('The cake has no %s operation.', $coordinate->operation));
    }
    $builder = new DataSurfaceBuilder(refiner: new LocalFrameRefiner());
    $builder->setDefinition('size', DataDefinition::create('string')
      ->setLabel('Size')
      ->setRequired(TRUE)
      ->addConstraint('Choice', ['choices' => ['small', 'large']]));
    $builder->setDefault('size', 'large');
    $builder->setDefinition('topping', DataDefinition::create('string')
      ->setLabel('Topping')
      ->addConstraint('Choice', ['choices' => ['cherry', 'nut', 'sprinkles']]));
    $builder->addRefinement('topping', ['size']);
    $builder->setDefinition('serial', DataDefinition::create('string')->setLabel('Serial'));
    $builder->setDefault('serial', 'fixed');
    $builder->lock('serial');
    $builder->addCacheableDependency((new CacheableMetadata())->addCacheTags([self::TAG]));
    return $this->factory->build($builder, self::class, self::HOST_ID);
  }

}
