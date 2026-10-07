<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\SurfaceShape;
use Drupal\data_surface_test\NumberShape;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests what sealing refuses about contributed shapes.
 *
 * A shape is checked when the surface is sealed, not when it is added:
 * a contributor may add one before the key's owner has declared it, and
 * whether two readings of a key can be told apart is a property of the
 * whole set, which nobody sees until every contributor has spoken.
 *
 * @see docs/shapes.md
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class SurfaceShapeSealTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'data_surface', 'data_surface_test'];

  /**
   * A builder holding one canonical integer of seconds.
   *
   * @return \Drupal\data_surface\DataSurfaceBuilderInterface
   *   The builder.
   */
  protected function seconds(): DataSurfaceBuilderInterface {
    return (new DataSurfaceBuilder())
      ->setDefinition('seconds', DataDefinition::create('integer')->setLabel('Seconds'));
  }

  /**
   * Tests that two readings one input could fit are refused, by name.
   */
  public function testAmbiguityIsRefusedAtSeal(): void {
    $cases = [
      'an integer shape on an integer canonical' => [
        ['minutes' => new NumberShape(60, 'integer')],
        'The canonical and the minutes (from test_minutes) readings of "seconds" could both read the same input',
      ],
      'a float shape on an integer canonical' => [
        ['minutes' => new NumberShape(60, 'float')],
        'The canonical and the minutes (from test_minutes) readings of "seconds"',
      ],
      'two string shapes' => [
        ['words' => new NumberShape(1, 'string'), 'digits' => new NumberShape(1, 'string')],
        'The words (from test_words) and the digits (from test_digits) readings of "seconds"',
      ],
      'two maps sharing a property' => [
        ['minutes' => new NumberShape(60, 'map'), 'again' => new NumberShape(60, 'map')],
        'The minutes (from test_minutes) and the again (from test_again) readings of "seconds"',
      ],
    ];
    foreach ($cases as $case => [$shapes, $message]) {
      $builder = $this->seconds();
      foreach ($shapes as $id => $shape) {
        $builder->addShape('seconds', $id, $shape, 'test_' . $id);
      }
      try {
        $builder->seal();
        $this->fail($case . ' was sealed.');
      }
      catch (\LogicException $e) {
        $this->assertStringContainsString($message, $e->getMessage(), $case);
      }
    }

    // Readings of different kinds are told apart by the matching rule.
    $surface = $this->seconds()
      ->addShape('seconds', 'words', new NumberShape(1, 'string'), 'test_words')
      ->addShape('seconds', 'minutes', new NumberShape(60, 'map'), 'test_minutes')
      ->seal();
    $shapes = DefinitionMetadata::getShapes($surface->getDefinition('seconds'));
    $this->assertSame(['words', 'minutes'], array_keys($shapes));
    $this->assertSame('test_minutes', $shapes['minutes']->contributor);
  }

  /**
   * Tests that a shape may be contributed before its key exists.
   */
  public function testShapeMayArriveBeforeItsKey(): void {
    $builder = new DataSurfaceBuilder();
    $builder->addShape('third_party_settings.later.seconds', 'minutes', new NumberShape(60, 'map'), 'test_minutes');
    $builder->setThirdPartyDefinition('later', 'seconds', DataDefinition::create('integer')->setLabel('Seconds'));
    $surface = $builder->seal();
    $mounted = $surface->getDefinition('third_party_settings');
    $this->assertInstanceOf(MapDataDefinition::class, $mounted);
    $later = $mounted->getPropertyDefinition('later');
    $this->assertInstanceOf(MapDataDefinition::class, $later);
    $nested = $later->getPropertyDefinition('seconds');
    $this->assertNotNull($nested);
    $this->assertSame(['minutes'], array_keys(DefinitionMetadata::getShapes($nested)));

    // Accepted, converted and judged at its own depth.
    $values = $this->pipeline()->accept($surface, ['third_party_settings' => ['later' => ['seconds' => ['minutes' => 5]]]]);
    $this->assertSame(SurfaceShape::select('minutes', ['minutes' => 5]), $values['third_party_settings']['later']['seconds']);
    $this->assertTrue($this->pipeline()->validate($surface, $values)->isEmpty());
    $this->assertSame(300, $this->pipeline()->canonical($surface, $values)['third_party_settings']['later']['seconds']);
  }

  /**
   * Tests the keys sealing will not let take a shape.
   */
  public function testKeysThatCannotTakeShapes(): void {
    foreach (static::refusals() as $case => [$declare, $message]) {
      $builder = new DataSurfaceBuilder();
      $declare($builder);
      try {
        $builder->seal();
        $this->fail($case . ' was sealed.');
      }
      catch (\LogicException $e) {
        $this->assertStringContainsString($message, $e->getMessage(), $case);
      }
    }
  }

  /**
   * Surfaces whose shape sealing refuses.
   *
   * @return array<string, array{0: callable, 1: string}>
   *   The declaration and the refusal.
   */
  protected static function refusals(): array {
    $shape = new NumberShape(60, 'map');
    return [
      'an undeclared key' => [
        static fn (DataSurfaceBuilderInterface $b) => $b->addShape('nowhere', 'minutes', $shape),
        'A shape was contributed to "nowhere", which this surface does not declare.',
      ],
      'a key inside a mount' => [
        static fn (DataSurfaceBuilderInterface $b) => $b
          ->mount('child', static fn (DataSurfaceBuilderInterface $c) => $c->setDefinition('seconds', DataDefinition::create('integer')))
          ->addShape('child.seconds', 'minutes', $shape),
        'inside the mount at "child"',
      ],
      'an any key' => [
        static fn (DataSurfaceBuilderInterface $b) => $b
          ->setDefinition('loose', DataDefinition::create('any'))
          ->addShape('loose', 'minutes', $shape),
        '"loose" cannot take shapes: it is typed any',
      ],
      'a list' => [
        static fn (DataSurfaceBuilderInterface $b) => $b
          ->setDefinition('many', ListDataDefinition::create('integer'))
          ->addShape('many', 'minutes', $shape),
        '"many" cannot take shapes: it is a list',
      ],
      'a secret' => [
        static function (DataSurfaceBuilderInterface $b) use ($shape): void {
          $secret = DataDefinition::create('integer');
          DefinitionMetadata::setSecret($secret);
          $b->setDefinition('pin', $secret)->addShape('pin', 'minutes', $shape);
        },
        '"pin" cannot take shapes: it is secret',
      ],
      'a key another refines against' => [
        static fn (DataSurfaceBuilderInterface $b) => $b
          ->setDefinition('seconds', DataDefinition::create('integer'))
          ->setDefinition('label', DataDefinition::create('string'))
          ->addRefinement('label', ['seconds'])
          ->addShape('seconds', 'minutes', $shape),
        '"seconds" cannot take shapes: it is a key another key refines against',
      ],
      'a shape taking anything' => [
        static fn (DataSurfaceBuilderInterface $b) => $b
          ->setDefinition('seconds', DataDefinition::create('integer'))
          ->addShape('seconds', 'anything', new NumberShape(1, 'any'), 'test_any'),
        'The anything (from test_any) shape of "seconds" accepts anything',
      ],
      'a map nobody declared a property of' => [
        static fn (DataSurfaceBuilderInterface $b) => $b
          ->setDefinition('box', MapDataDefinition::create()->setPropertyDefinition('seconds', DataDefinition::create('integer')))
          ->addShape('box.minutes', 'minutes', $shape),
        'A shape was contributed to "box.minutes", which this surface does not declare.',
      ],
    ];
  }

  /**
   * Tests what addShape() itself refuses.
   */
  public function testIdsAreUniqueAndOutsideTheReservedPrefix(): void {
    $builder = $this->seconds()->addShape('seconds', 'minutes', new NumberShape(60, 'map'), 'test_one');
    try {
      $builder->addShape('seconds', 'minutes', new NumberShape(60, 'map'), 'test_two');
      $this->fail('A second shape of the same name was added.');
    }
    catch (\LogicException $e) {
      $this->assertSame('The "seconds" key already has a shape named "minutes", contributed by test_one, so test_two cannot contribute another under that name.', $e->getMessage());
    }
    $this->expectException(\InvalidArgumentException::class);
    $builder->addShape('seconds', '@shape', new NumberShape(60, 'map'));
  }

}
