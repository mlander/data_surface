<?php

declare(strict_types=1);

namespace Drupal\ds_adopter_pathauto_two\Keys;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\data_surface\Resource\SiteList;
use Drupal\data_surface\Resource\SiteListInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the entity types pathauto can add a path field to.
 *
 * A fieldable entity type with a canonical link. One with a path field
 * starts checked; it is fixed when another module provides that field, or
 * when pathauto provides it and a pattern uses the type.
 */
final class PathautoEntityTypes implements SiteListInterface, ContainerInjectionInterface {

  /**
   * Constructs the provider.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'), $container->get('entity_field.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    $keys = (new SiteList())->addCacheTags(['entity_types', 'entity_field_info', 'config:pathauto_pattern_list']);
    $patterns = $this->entityTypeManager->getStorage('pathauto_pattern');
    foreach ($this->entityTypeManager->getDefinitions() as $id => $type) {
      if (!is_subclass_of($type->getClass(), FieldableEntityInterface::class) || !$type->hasLinkTemplate('canonical')) {
        continue;
      }
      $path = $this->entityFieldManager->getBaseFieldDefinitions($id)['path'] ?? NULL;
      $used = $patterns->getQuery()->accessCheck(FALSE)->condition('type', 'canonical_entities:' . $id)->count()->execute();
      $locked = $path !== NULL && ($path->getProvider() !== 'pathauto' || $used);
      $keys->add($id, $type->getLabel(), default: $path !== NULL, locked: $locked);
    }
    return $keys;
  }

}
