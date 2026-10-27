<?php

declare(strict_types=1);

namespace Drupal\ds_adopter_pathauto_two\Storage;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Storage\ConfigObjectStorage;
use Drupal\data_surface\Storage\StorageLayoutInterface;

/**
 * Keeps the settings in their config object.
 *
 * The enabled entity types are asked for as a checkbox per entity type and
 * stored as pathauto stores them, the list of the types checked
 * (EnabledEntityTypesLayout), less the types whose path field another
 * module provides.
 */
final class PathautoSettingsStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'ds_adopter_pathauto_two.settings';

  /**
   * The key asked for as a map and stored as a list.
   */
  public const ENABLED = 'enabled_entity_types';

  /**
   * Constructs the storage.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfig,
    LanguageManagerInterface $languageManager,
    TranslationInterface $string_translation,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
  ) {
    parent::__construct($configFactory, $typedConfig, $languageManager, $string_translation);
  }

  /**
   * {@inheritdoc}
   */
  protected function layout(): StorageLayoutInterface {
    return new EnabledEntityTypesLayout();
  }

  /**
   * {@inheritdoc}
   *
   * Pathauto's own rule: a type whose path field another module provides
   * is never stored, since this form cannot turn it off.
   */
  public function prepare(OperationContext $context, array $values, AccountInterface $account): array {
    if (is_array($values[self::ENABLED] ?? NULL)) {
      foreach (array_keys($values[self::ENABLED]) as $entity_type_id) {
        $path = $this->entityFieldManager->getBaseFieldDefinitions((string) $entity_type_id)['path'] ?? NULL;
        if ($path !== NULL && $path->getProvider() !== 'pathauto') {
          $values[self::ENABLED][$entity_type_id] = FALSE;
        }
      }
    }
    return parent::prepare($context, $values, $account);
  }

}
