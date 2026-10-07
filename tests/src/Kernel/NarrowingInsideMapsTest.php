<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\Refinement\Narrowing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the narrowing check inside maps and lists.
 *
 * A refined map may not add or remove properties, and every property is
 * held to the same table as a top level key, at any depth. The one way a
 * shape may appear is out of `any`, which advertised nothing to be
 * contradicted — the slot placeholder resolving to its variant.
 *
 * A kernel test only because core's data definitions ask the typed data
 * manager for their default constraints when they are read.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class NarrowingInsideMapsTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'data_surface'];

  /**
   * Builds a map with a choice-bearing and a bounded property.
   *
   * @return \Drupal\Core\TypedData\MapDataDefinition
   *   The map.
   */
  protected function map(): MapDataDefinition {
    $map = MapDataDefinition::create();
    $map->setPropertyDefinition('mode', DataDefinition::create('string')
      ->addConstraint('Choice', ['choices' => ['a', 'b', 'c']]));
    $map->setPropertyDefinition('count', DataDefinition::create('integer')
      ->addConstraint('Range', ['min' => 1, 'max' => 10]));
    return $map;
  }

  /**
   * Asserts that a refinement is refused, saying what widened.
   *
   * @param \Drupal\Core\TypedData\DataDefinition $before
   *   The definition handed over.
   * @param \Drupal\Core\TypedData\DataDefinition $after
   *   What came back.
   * @param string $widening
   *   Words the refusal must contain.
   */
  protected function assertRefused(DataDefinition $before, DataDefinition $after, string $widening): void {
    try {
      Narrowing::assertNarrows('key', 'a test', $before, $after);
      $this->fail('The refinement was accepted.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString($widening, $e->getMessage());
    }
  }

  /**
   * Tests that a map may not gain a property.
   */
  public function testMapMayNotGainProperty(): void {
    $after = $this->map();
    $after->setPropertyDefinition('extra', DataDefinition::create('string'));
    $this->assertRefused($this->map(), $after, 'the map gained the properties extra');
  }

  /**
   * Tests that a map may not lose a property.
   */
  public function testMapMayNotLoseProperty(): void {
    $after = MapDataDefinition::create();
    $after->setPropertyDefinition('mode', $this->map()->getPropertyDefinition('mode'));
    $this->assertRefused($this->map(), $after, 'the map lost the properties count');
  }

  /**
   * Tests that every property is held to the table, at any depth.
   */
  public function testPropertiesAreCheckedRecursively(): void {
    $wider = $this->map();
    $wider->getPropertyDefinition('mode')->addConstraint('Choice', ['choices' => ['a', 'b', 'c', 'd']]);
    $this->assertRefused($this->map(), $wider, 'inside the mode property, the Choice constraint gained the values d');

    $retyped = $this->map();
    $retyped->setPropertyDefinition('count', DataDefinition::create('string'));
    $this->assertRefused($this->map(), $retyped, 'inside the count property, the data type changed from integer to string');

    $outer = MapDataDefinition::create();
    $outer->setPropertyDefinition('inner', $this->map());
    $loosened = MapDataDefinition::create();
    $inner = $this->map();
    $inner->getPropertyDefinition('count')->addConstraint('Range', ['min' => 0, 'max' => 10]);
    $loosened->setPropertyDefinition('inner', $inner);
    $this->assertRefused($outer, $loosened, 'inside the inner property, inside the count property, the min of the Range constraint moved from 1 to 0');
  }

  /**
   * Tests that narrowing a property is narrowing the map.
   */
  public function testNarrowingPropertyIsAllowed(): void {
    $narrower = $this->map();
    $narrower->getPropertyDefinition('mode')->addConstraint('Choice', ['choices' => ['a']]);
    $narrower->getPropertyDefinition('count')->addConstraint('Range', ['min' => 2, 'max' => 5]);
    Narrowing::assertNarrows('key', 'a test', $this->map(), $narrower);
    $this->addToAssertionCount(1);
  }

  /**
   * Tests that a list's items are held to the same table.
   */
  public function testListItemsAreChecked(): void {
    $item = DataDefinition::create('string')->addConstraint('Choice', ['choices' => ['x']]);
    $wider = DataDefinition::create('string')->addConstraint('Choice', ['choices' => ['x', 'y']]);
    $before = new ListDataDefinition([], $item);
    $after = new ListDataDefinition([], $wider);
    $this->assertRefused($before, $after, 'inside the list items, the Choice constraint gained the values y');
  }

  /**
   * Tests the one way a shape may appear: out of a placeholder.
   */
  public function testAnyMayResolveToMap(): void {
    Narrowing::assertNarrows('key', 'a test', DataDefinition::create('any'), $this->map());
    $this->addToAssertionCount(1);
  }

}
