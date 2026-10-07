<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_duration\EventSubscriber;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Event\DataSurfaceBuildEvent;
use Drupal\data_surface_demo_duration\Iso8601DurationShape;
use Drupal\data_surface_demo_extras\NodeTypeReviewSettings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Contributes an ISO 8601 shape to a key another module owns.
 *
 * The review deadline is data_surface_demo_extras's: it mounted the key,
 * declared its canonical — seconds — and contributed the amount and unit
 * shape. This module owns none of that and changes none of it. It adds
 * one more way to say the same stored value, from the build event, with
 * its own name on it; the extras module's code does not know this one
 * exists, and neither does the content type provider.
 *
 * Runs after the default priority, so on the content type surface the
 * shape follows the key owner's own. Nothing depends on that: a shape is
 * attached at seal, whenever it was contributed, and sealing has already
 * refused any pair of readings one input could fit, so the order only
 * decides the order the union is advertised in.
 */
final class DurationShapeSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * This module's name, which is the id its contribution carries.
   */
  public const PROVIDER = 'data_surface_demo_duration';

  /**
   * Constructs a DurationShapeSubscriber.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, which the shape's labels are built
   *   through.
   */
  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [DataSurfaceBuildEvent::class => ['onSurfaceBuild', -10]];
  }

  /**
   * Adds the ISO 8601 shape to the content type's review deadline.
   *
   * @param \Drupal\data_surface\Event\DataSurfaceBuildEvent $event
   *   The build event carrying the mutable builder.
   */
  public function onSurfaceBuild(DataSurfaceBuildEvent $event): void {
    if ($event->hostId !== NodeTypeReviewSettings::HOST_ID) {
      return;
    }
    $duration = DataDefinition::create('string')
      ->setLabel($this->t('ISO 8601 duration'))
      ->setDescription($this->t('How long an editor has to review new content of this type, as an ISO 8601 duration in weeks, days or hours, such as P1W, P3D or PT12H.'))
      ->addConstraint('Regex', [
        'pattern' => Iso8601DurationShape::PATTERN,
        'message' => 'This is not an ISO 8601 duration such as P1W, P3D or PT12H.',
      ])
      ->addConstraint('DataSurfaceDemoDurationFixedLength', []);
    DefinitionMetadata::setExamples($duration, ['P1W']);
    $event->builder->addShape(
      NodeTypeReviewSettings::DEADLINE_KEY,
      Iso8601DurationShape::ID,
      new Iso8601DurationShape($duration),
      self::PROVIDER,
    );
  }

}
