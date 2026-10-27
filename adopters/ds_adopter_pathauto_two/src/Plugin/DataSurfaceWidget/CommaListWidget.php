<?php

declare(strict_types=1);

namespace Drupal\ds_adopter_pathauto_two\Plugin\DataSurfaceWidget;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceWidget;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;

/**
 * Draws a list of strings as one text area, the items separated by commas.
 */
#[DataSurfaceWidget(
  id: 'ds_adopter_pathauto_two_comma_list',
  label: new TranslatableMarkup('Comma separated list'),
  weight: 7,
)]
final class CommaListWidget extends DataSurfaceWidgetBase {

  /**
   * {@inheritdoc}
   */
  public function isApplicable(DataDefinitionInterface $definition): bool {
    return $definition instanceof ListDataDefinitionInterface
      && $definition->getSetting('widget') === $this->getPluginId();
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    return $this->baseElement($definition) + [
      '#type' => 'textarea',
      '#default_value' => is_array($value) ? implode(', ', $value) : '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function extractValue(DataDefinitionInterface $definition, array $element, FormStateInterface $form_state, array $fallback_parents): mixed {
    $raw = $this->rawValue($element, $form_state, $fallback_parents);
    if (!is_string($raw)) {
      return $raw;
    }
    return array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
  }

}
