<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DataSurfaceCoordinate;
use Drupal\data_surface\DataSurfaceFactoryInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Refinement\ChoiceSet;
use Drupal\data_surface\Target\MountTarget;
use Drupal\data_surface\Target\StateTarget;
use Drupal\data_surface_test\DessertSurfaceResolver;
use Drupal\data_surface_test\LocalFrameRefiner;
use Drupal\data_surface_test\RecordingTarget;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests nested surfaces: mounts, slots, and the values that cross them.
 *
 * The rule under test is that a surface declares its shape statically
 * and refinement only ever tightens values. A mount is a shape known
 * from an address; a slot is a shape chosen by a sibling out of a set
 * declared up front. Neither lets anything appear at refinement time
 * that was not advertised at seal.
 *
 * @see docs/nesting.md
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class NestedSurfaceTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'data_surface', 'data_surface_test'];

  /**
   * Gets the surface factory.
   *
   * @return \Drupal\data_surface\DataSurfaceFactoryInterface
   *   The factory.
   */
  protected function factory(): DataSurfaceFactoryInterface {
    return $this->container->get('data_surface.factory');
  }

  /**
   * The coordinate of the dessert fixture.
   *
   * @return \Drupal\data_surface\DataSurfaceCoordinate
   *   The coordinate.
   */
  protected function cake(): DataSurfaceCoordinate {
    return new DataSurfaceCoordinate(DessertSurfaceResolver::HOST_ID, 'configure', 'birthday');
  }

  /**
   * Builds a parent mounting the dessert fixture by coordinate.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The sealed parent.
   */
  protected function parentWithDessert(): DataSurfaceInterface {
    $builder = new DataSurfaceBuilder(refiner: new LocalFrameRefiner());
    $builder->setDefinition('title', DataDefinition::create('string')->setLabel('Title'));
    $builder->setDefinition('dessert', MapDataDefinition::create()->setLabel('Dessert'));
    $builder->mount('dessert', $this->cake());
    return $this->factory()->build($builder, static::class, 'test:party');
  }

  /**
   * Builds a parent with a slot whose variants are declared inline.
   *
   * @param \Drupal\Core\TypedData\DataDefinition|null $discriminator
   *   The discriminator's definition, or NULL for an open string.
   *
   * @return \Drupal\data_surface\DataSurfaceBuilderInterface
   *   The unsealed builder.
   */
  protected function slotBuilder(?DataDefinition $discriminator = NULL): DataSurfaceBuilderInterface {
    $builder = new DataSurfaceBuilder();
    $builder->setDefinition('layout', ($discriminator ?? DataDefinition::create('string'))->setLabel('Layout'));
    $builder->setDefault('layout', 'list');
    $builder->setDefinition('layout_settings', MapDataDefinition::create()->setLabel('Layout settings'));
    $builder->mountVariants('layout_settings', 'layout', [
      'list' => static function (DataSurfaceBuilderInterface $list): void {
        $list->setDefinition('show_summary', DataDefinition::create('boolean')->setLabel('Show summaries'));
        $list->setDefault('show_summary', TRUE);
      },
      'grid' => static function (DataSurfaceBuilderInterface $grid): void {
        $grid->setDefinition('columns', DataDefinition::create('integer')
          ->setLabel('Columns')
          ->setRequired(TRUE)
          ->addConstraint('Range', ['min' => 1, 'max' => 6]));
        $grid->setDefault('columns', 3);
      },
    ]);
    return $builder;
  }

  /**
   * Tests that a mount named by coordinate advertises the child itself.
   */
  public function testMountByCoordinateAdvertisesTheChild(): void {
    $parent = $this->parentWithDessert();
    $entry = $parent->getDefinitions()->entry('dessert');

    // The address is kept, so whatever advertises the parent can name
    // the surface sitting at the key.
    $this->assertNotNull($entry?->mount);
    $this->assertEquals($this->cake(), $entry->mount->coordinate);
    $this->assertSame('dessert:cake/configure/birthday', (string) $entry->mount->coordinate);

    // The map's properties are the child's definitions, in its order,
    // under the label the parent gave the key.
    $definition = $entry->definition;
    $this->assertInstanceOf(ComplexDataDefinitionInterface::class, $definition);
    $this->assertSame('Dessert', (string) $definition->getLabel());
    $this->assertSame(['size', 'topping', 'serial'], array_keys($definition->getPropertyDefinitions()));
    $this->assertSame(
      $entry->mount->child->getDefinition('topping'),
      $definition->getPropertyDefinitions()['topping'],
    );

    // The child's cacheability became the parent's.
    $this->assertContains(DessertSurfaceResolver::TAG, $parent->getCacheTags());

    // And the child's defaults are the key's default.
    $this->assertSame(['size' => 'large', 'topping' => NULL, 'serial' => 'fixed'], $parent->getDefault('dessert'));
  }

  /**
   * Tests that only the factory can resolve a coordinate.
   */
  public function testCoordinateMountNeedsTheFactory(): void {
    $builder = new DataSurfaceBuilder();
    $builder->mount('dessert', $this->cake());
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('only the factory resolves a coordinate');
    $builder->seal();
  }

  /**
   * Tests that a coordinate no resolver serves is refused by address.
   */
  public function testUnresolvableCoordinateIsRefused(): void {
    $builder = new DataSurfaceBuilder();
    $builder->mount('dessert', new DataSurfaceCoordinate('pudding:sticky'));
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('pudding:sticky/configure');
    $this->factory()->build($builder, static::class, 'test:party');
  }

  /**
   * Tests that a child refines in its own frame, under its own names.
   */
  public function testChildRefinersRunInTheirOwnFrame(): void {
    $parent = $this->parentWithDessert();
    LocalFrameRefiner::$names = [];

    $refined = $parent->refine(['title' => 'Party', 'dessert' => ['size' => 'small']]);

    $dessert = $refined->getDefinition('dessert');
    $this->assertInstanceOf(MapDataDefinition::class, $dessert);
    $topping = $dessert->getPropertyDefinitions()['topping'];
    $this->assertSame(['cherry'], ChoiceSet::of($topping)?->values);
    // The child's refiner was handed its own key name; nothing was ever
    // dispatched under a parent path, and the parent's refiner — the
    // same class, bound to the parent — was never handed a child key.
    $this->assertSame(['topping'], LocalFrameRefiner::$names);
    // The advertised surface is untouched.
    $advertised_dessert = $parent->getDefinition('dessert');
    $this->assertInstanceOf(MapDataDefinition::class, $advertised_dessert);
    $advertised = $advertised_dessert->getPropertyDefinitions()['topping'];
    $this->assertSame(['cherry', 'nut', 'sprinkles'], ChoiceSet::of($advertised)?->values);
  }

  /**
   * Tests that a mounted value is accepted and judged by its child.
   */
  public function testMountedValuesAreJudgedByTheChild(): void {
    $parent = $this->parentWithDessert();
    $pipeline = $this->pipeline();

    $values = $pipeline->accept($parent, [
      'dessert' => ['size' => 'small', 'topping' => 'nut', 'serial' => 'moved'],
    ]);
    // The child's lock held through the mount.
    $this->assertSame(['size' => 'small', 'topping' => 'nut', 'serial' => 'fixed'], $values['dessert']);

    // The child's refinement narrowed the topping, and the refusal is
    // filed under the mount with the child's key at the front of the
    // path.
    $violations = $pipeline->validate($parent, $values);
    $this->assertSame(['dessert'], $violations->keys());
    $this->assertSame('dessert.topping', $violations->byKey('dessert')[0]->fullPath());

    // An unknown key inside the mount is refused on its full path.
    $result = $pipeline->submit($parent, ['dessert' => ['icing' => 'pink']], new RecordingTarget('party', new \ArrayObject()));
    $this->assertFalse($result->isValid());
    $this->assertSame('dessert.icing', $result->violations->byKey('dessert')[0]->fullPath());
  }

  /**
   * Tests a child declared inline as part of its parent.
   */
  public function testInlineMountIsWholeSurface(): void {
    $builder = new DataSurfaceBuilder();
    $builder->mount('box', static function (DataSurfaceBuilderInterface $box): void {
      $box->setDefinition('ribbon', DataDefinition::create('string')->setLabel('Ribbon'));
      $box->setDefault('ribbon', 'red');
      $box->addCacheableDependency((new CacheableMetadata())->addCacheTags(['box']));
    });
    $surface = $builder->seal();

    $this->assertNull($surface->getDefinitions()->entry('box')?->mount?->coordinate);
    $this->assertSame(['ribbon' => 'red'], $surface->getDefaultValues()['box']);
    $this->assertContains('box', $surface->getCacheTags());
  }

  /**
   * Tests that a key already holding a value of its own cannot be mounted.
   */
  public function testMountRefusesKeyWithShapeOfItsOwn(): void {
    $builder = new DataSurfaceBuilder();
    $builder->setDefinition('box', DataDefinition::create('string'));
    $this->expectException(\InvalidArgumentException::class);
    $builder->mount('box', static function (DataSurfaceBuilderInterface $box): void {});
  }

  /**
   * Tests what a slot advertises before anything is chosen.
   */
  public function testSlotAdvertisesEveryVariant(): void {
    $surface = $this->slotBuilder()->seal();
    $definitions = $surface->getDefinitions();

    // The discriminator gained the complete set of allowed values.
    $this->assertSame(['list', 'grid'], ChoiceSet::of($definitions->get('layout'))?->values);
    // And the slot depends on it, which is what wires the form's AJAX.
    $this->assertSame(['layout'], $definitions->dependencies('layout_settings'));

    // The placeholder says it is a slot, and which key decides.
    $placeholder = $definitions->get('layout_settings');
    $this->assertSame('any', $placeholder->getDataType());
    $this->assertSame('layout', DefinitionMetadata::slotOf($placeholder));
    $this->assertSame('Layout settings', (string) $placeholder->getLabel());

    // The variant table travels on the entry, every shape in it.
    $slot = $definitions->entry('layout_settings')?->slot;
    $this->assertNotNull($slot);
    $this->assertSame(['list', 'grid'], $slot->variantIds());
    $this->assertSame(['columns'], $slot->variant('grid')->child->getDefinitions()->names());

    // The slot starts from the variant the discriminator's default
    // chooses.
    $this->assertSame(['layout' => 'list', 'layout_settings' => ['show_summary' => TRUE]], $surface->getDefaultValues());
  }

  /**
   * Tests that a value for the discriminator resolves the slot exactly.
   */
  public function testSlotResolvesToTheChosenVariant(): void {
    $surface = $this->slotBuilder()->seal();

    $grid = $surface->refine(['layout' => 'grid'])->getDefinition('layout_settings');
    $this->assertInstanceOf(MapDataDefinition::class, $grid);
    $this->assertSame(['columns'], array_keys($grid->getPropertyDefinitions()));
    $this->assertSame('Layout settings', (string) $grid->getLabel());
    $this->assertNull(DefinitionMetadata::slotOf($grid));

    $list = $surface->refine(['layout' => 'list'])->getDefinition('layout_settings');
    $this->assertInstanceOf(MapDataDefinition::class, $list);
    $this->assertSame(['show_summary'], array_keys($list->getPropertyDefinitions()));

    // Nothing chosen, or something no variant answers to: the
    // placeholder stands.
    $this->assertSame('any', $surface->refine(['layout' => NULL])->getDefinition('layout_settings')->getDataType());
    $this->assertSame('any', $surface->refine(['layout' => 'carousel'])->getDefinition('layout_settings')->getDataType());
  }

  /**
   * Tests that a discriminator's own list is narrowed, never widened.
   */
  public function testSlotNarrowsAnExistingChoice(): void {
    $labeled = DataDefinition::create('string')->addConstraint('LabeledChoice', [
      'choices' => ['list' => 'List', 'grid' => 'Grid', 'table' => 'Table'],
    ]);
    $definitions = $this->slotBuilder($labeled)->seal()->getDefinitions();

    $set = ChoiceSet::of($definitions->get('layout'));
    $this->assertSame('LabeledChoice', $set?->constraint);
    $this->assertSame(['list', 'grid'], $set->values);
    $this->assertSame(['list' => 'List', 'grid' => 'Grid'], $set->labels);

    // A variant the discriminator never allowed could never be chosen.
    $narrow = DataDefinition::create('string')->addConstraint('Choice', ['choices' => ['list']]);
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('grid');
    $this->slotBuilder($narrow);
  }

  /**
   * Tests that a value contributed to the discriminator needs a variant.
   */
  public function testContributedDiscriminatorValueWithoutVariantIsRefused(): void {
    $builder = $this->slotBuilder();
    $builder->extendChoices('layout', ['carousel'], 'data_surface_test');
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('every value the discriminator allows needs a variant');
    $builder->seal();
  }

  /**
   * Tests that a payload for another variant is refused on the slot.
   */
  public function testVariantMismatchIsPathAwareViolation(): void {
    $surface = $this->slotBuilder()->seal();
    $target = new RecordingTarget('layout', new \ArrayObject());

    $result = $this->pipeline()->submit($surface, [
      'layout' => 'grid',
      'layout_settings' => ['show_summary' => FALSE],
    ], $target);

    $this->assertFalse($result->isValid());
    $this->assertFalse($result->committed);
    $violation = $result->violations->byKey('layout_settings')[0];
    $this->assertSame('layout_settings.show_summary', $violation->fullPath());
    $this->assertSame(
      'layout_settings.show_summary belongs to the list variant, but layout chose grid.',
      (string) $violation->message,
    );

    // validate() says the same to a caller who skipped accept().
    $violations = $this->pipeline()->validate($surface, [
      'layout' => 'grid',
      'layout_settings' => ['columns' => 2, 'show_summary' => FALSE],
    ]);
    $this->assertSame(['layout_settings.show_summary'], array_map(
      static fn ($violation): string => $violation->fullPath(),
      $violations->byKey('layout_settings'),
    ));
  }

  /**
   * Tests that the chosen variant's own rules hold, by path.
   */
  public function testTheChosenVariantJudgesTheSlot(): void {
    $surface = $this->slotBuilder()->seal();
    $pipeline = $this->pipeline();

    $values = $pipeline->accept($surface, ['layout' => 'grid', 'layout_settings' => ['columns' => '9']]);
    // Cast by the variant's own definition on the way in.
    $this->assertSame(['columns' => 9], $values['layout_settings']);
    $violations = $pipeline->validate($surface, $values);
    $this->assertSame('layout_settings.columns', $violations->byKey('layout_settings')[0]->fullPath());

    // A value with nothing to choose its shape is refused as such.
    $violations = $pipeline->validate($surface, ['layout' => NULL, 'layout_settings' => ['columns' => 2]]);
    $this->assertSame(
      'Layout settings depends on Layout, which holds no value.',
      (string) $violations->byKey('layout_settings')[0]->message,
    );
  }

  /**
   * Tests that changing the discriminator resets what the slot held.
   *
   * The stored settings were written for the list. Choosing the grid
   * without saying anything about its settings starts it from the
   * grid's own defaults, rather than carrying a summary flag a grid has
   * no use for into storage.
   */
  public function testChangingTheDiscriminatorStartsFromTheVariantDefaults(): void {
    $surface = $this->slotBuilder()->seal();
    $stored = ['layout' => 'list', 'layout_settings' => ['show_summary' => FALSE]];

    $values = $this->pipeline()->accept($surface, ['layout' => 'grid'], $stored);
    $this->assertSame(['layout' => 'grid', 'layout_settings' => ['columns' => 3]], $values);
    $this->assertTrue($this->pipeline()->validate($surface, $values, $stored)->isEmpty());

    // Staying on the list keeps what the list held.
    $values = $this->pipeline()->accept($surface, ['layout' => 'list'], $stored);
    $this->assertSame(['show_summary' => FALSE], $values['layout_settings']);
  }

  /**
   * Tests that a mount's value is stored through the child's own target.
   */
  public function testMountTargetRoutesToTheChildTarget(): void {
    $parent = $this->parentWithDessert();
    $target = new MountTarget(['dessert' => new StateTarget($this->container->get('state'), 'data_surface_test.dessert')]);

    $result = $this->pipeline()->submit($parent, ['dessert' => ['size' => 'small', 'topping' => 'cherry']], $target);
    // The title is not the mount target's, so it is the parent's to
    // store elsewhere; here the mount is the whole write.
    $this->assertTrue($result->isValid(), (string) $result->violations->count());
    $this->assertSame(
      ['size' => 'small', 'topping' => 'cherry', 'serial' => 'fixed'],
      $this->container->get('state')->get('data_surface_test.dessert'),
    );
    $this->assertSame(['dessert' => ['size' => 'small', 'topping' => 'cherry', 'serial' => 'fixed']], $target->load($parent));
  }

}
