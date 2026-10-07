<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_duration;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceShapeInterface;

/**
 * A number of seconds said as an ISO 8601 duration: P1W, P3D, PT12H.
 *
 * A shape for any key whose canonical is a whole number of seconds. It
 * takes weeks, days and hours, the units that are always the same
 * length, and nothing else: a month or a year is not a fixed number of
 * seconds, so P1M has no one canonical value, and the shape's own
 * constraint refuses it by saying so rather than picking one.
 *
 * Converted with PHP's DateInterval, which reads a week as seven days.
 * fromCanonical() says stored seconds in the largest of weeks, days and
 * hours that divides them exactly, so the duration always round-trips
 * and the spelling does not always: P1W2D is stored as nine days and
 * comes back as P9D. That is what makes the shape lossy.
 *
 * The input definition is handed in, built where translation is
 * injected, so the shape itself stays a plain serializable value.
 */
final class Iso8601DurationShape implements DataSurfaceShapeInterface {

  /**
   * The id this shape is contributed under.
   */
  public const ID = 'iso8601';

  /**
   * What an ISO 8601 duration looks like, any unit at all.
   *
   * Months, years, minutes and seconds match on purpose: they are ISO
   * 8601 durations, and the fixed length constraint refuses them with a
   * sentence that says why, which a pattern that simply did not match
   * them could not. No modifier, so the pattern travels as written.
   */
  public const PATTERN = '/^P(?=\d|T\d)(?:\d+Y)?(?:\d+M)?(?:\d+W)?(?:\d+D)?(?:T(?=\d)(?:\d+H)?(?:\d+M)?(?:\d+S)?)?$/';

  /**
   * The units stored seconds are said back in, largest first.
   */
  protected const UNITS = [
    'W' => 604800,
    'D' => 86400,
    'H' => 3600,
  ];

  /**
   * Constructs an Iso8601DurationShape.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The string definition carrying the pattern and the fixed length
   *   constraint.
   */
  public function __construct(
    protected readonly DataDefinitionInterface $definition,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getInputDefinition(): DataDefinitionInterface {
    return $this->definition;
  }

  /**
   * {@inheritdoc}
   */
  public function toCanonical(mixed $input): mixed {
    if (!is_string($input) || !preg_match(self::PATTERN, $input)) {
      return NULL;
    }
    try {
      $interval = new \DateInterval($input);
    }
    catch (\Exception) {
      return NULL;
    }
    if ($interval->y !== 0 || $interval->m !== 0 || $interval->i !== 0 || $interval->s !== 0) {
      // Not a fixed length, which the constraint has already refused.
      return NULL;
    }
    return $interval->d * self::UNITS['D'] + $interval->h * self::UNITS['H'];
  }

  /**
   * {@inheritdoc}
   */
  public function fromCanonical(mixed $stored): mixed {
    if (!is_int($stored) || $stored <= 0) {
      return NULL;
    }
    foreach (self::UNITS as $unit => $size) {
      if ($stored % $size === 0) {
        $amount = intdiv($stored, $size);
        return $unit === 'H' ? 'PT' . $amount . 'H' : 'P' . $amount . $unit;
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function isLossy(): bool {
    return TRUE;
  }

}
