<?php

declare(strict_types=1);

namespace Drupal\ds_adopter_pathauto_two\Shape;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\Resource\Attribute\Shape;
use Drupal\data_surface\Resource\ShapeInterface;
use Drupal\pathauto\PathautoGeneratorInterface;

/**
 * What the alias cleaner does with one punctuation character.
 */
#[Shape('adopter.pathauto_two.punctuation_action')]
final class PunctuationAction implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function define(): DataDefinition {
    return DataDefinition::create('integer')
      ->setLabel(t('Punctuation action'))
      ->addConstraint('LabeledChoice', [
        'labels' => [
          PathautoGeneratorInterface::PUNCTUATION_REMOVE => t('Remove'),
          PathautoGeneratorInterface::PUNCTUATION_REPLACE => t('Replace by separator'),
          PathautoGeneratorInterface::PUNCTUATION_DO_NOTHING => t('No action (do not replace)'),
        ],
      ]);
  }

}
