<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_duration\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * An ISO 8601 duration in weeks, days and hours only.
 *
 * Checked on a string the duration pattern already took, so what it says
 * is about the units: months and years are refused because they are not
 * a fixed length, and minutes and seconds because the duration is
 * counted in whole hours.
 */
#[Constraint(
  id: 'DataSurfaceDemoDurationFixedLength',
  label: new TranslatableMarkup('Fixed length ISO 8601 duration', [], ['context' => 'Validation']),
)]
final class FixedLengthDurationConstraint extends SymfonyConstraint {

  /**
   * The message for a duration in months or years.
   *
   * @var string
   */
  public string $notFixedMessage = '@value counts months or years, which are not a fixed length of time, so it has no one number of seconds. Say it in weeks, days or hours, such as P1W, P3D or PT12H.';

  /**
   * The message for a duration in minutes or seconds.
   *
   * @var string
   */
  public string $notWholeHoursMessage = '@value counts minutes or seconds. Say it in whole weeks, days or hours, such as P1W, P3D or PT12H.';

}
