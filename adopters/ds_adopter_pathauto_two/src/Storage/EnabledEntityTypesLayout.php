<?php

declare(strict_types=1);

namespace Drupal\ds_adopter_pathauto_two\Storage;

use Drupal\data_surface\Storage\StoredSchemaInterface;

/**
 * Stores the enabled entity types as pathauto does: the list of those checked.
 *
 * Asked for as a checkbox per entity type, `{node: true, user: false}`;
 * stored as `[node]`.
 */
final class EnabledEntityTypesLayout implements StoredSchemaInterface {

  /**
   * {@inheritdoc}
   */
  public function toStorage(array $values): array {
    $key = PathautoSettingsStorage::ENABLED;
    if (is_array($values[$key] ?? NULL)) {
      $values[$key] = array_map('strval', array_keys(array_filter($values[$key])));
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function fromStorage(array $settings): array {
    $key = PathautoSettingsStorage::ENABLED;
    if (is_array($settings[$key] ?? NULL)) {
      $settings[$key] = array_fill_keys(array_map('strval', $settings[$key]), TRUE);
    }
    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public function storedSchema(): array {
    return [
      PathautoSettingsStorage::ENABLED => [
        'type' => 'sequence',
        'sequence' => ['type' => 'string'],
      ],
    ];
  }

}
