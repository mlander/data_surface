<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\data_surface\DataSurfaceInterface;
use Drupal\tool\TypedData\MapInputDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests what a mount and a slot become in the Tool API's vocabulary.
 *
 * A slot whose discriminator holds a value is exactly that variant's
 * map. One whose discriminator holds nothing is the union of its
 * variants: the widest schema the Tool API can carry honestly, since it
 * has no way to say "one of these, chosen by that sibling".
 *
 * @see \Drupal\data_surface_tool\SurfaceInputDefinitions::fromSlot()
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class NestedSurfaceEmissionTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_demo',
    'data_surface_tool',
  ];

  /**
   * Gets the demo block's surface, the shipped slot.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   */
  protected function demoSurface(): DataSurfaceInterface {
    $this->installEntitySchema('user');
    return $this->container->get('plugin.manager.block')
      ->createInstance('data_surface_demo')
      ->getDataSurface();
  }

  /**
   * Converts a surface and normalizes it the way an invoker does.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface.
   *
   * @return array
   *   The JSON Schema of the converted map.
   */
  protected function schemaOf(DataSurfaceInterface $surface): array {
    $definition = $this->container->get('data_surface_tool.input_definitions')
      ->fromSurface($surface, 'Settings', '', TRUE);
    return $this->container->get('serializer')->normalize($definition, 'json_schema');
  }

  /**
   * Tests that an unresolved slot is the union of its variants.
   */
  public function testUnresolvedSlotIsTheUnionOfItsVariants(): void {
    $surface = $this->demoSurface();
    $converted = $this->container->get('data_surface_tool.input_definitions')
      ->fromKey($surface, 'presentation_settings');
    $this->assertInstanceOf(MapInputDefinition::class, $converted);
    $this->assertSame(['show_summary', 'columns'], array_keys($converted->getPropertyDefinitions()));
    // A key of one variant is absent from the other, so none is required.
    $this->assertFalse($converted->getPropertyDefinitions()['columns']->isRequired());

    $schema = $this->schemaOf($surface)['properties'];
    // The discriminator carries the complete set of variants.
    $this->assertSame(['list', 'grid'], array_values(array_filter($schema['presentation']['enum'], 'is_string')));
    $slot = $schema['presentation_settings'];
    $this->assertStringContainsString('object', json_encode($slot['type']));
    $this->assertSame(['show_summary', 'columns'], array_keys($slot['properties']));
    $this->assertArrayNotHasKey('required', $slot);
    // Each property says which variant it belongs to; the slot names the
    // whole table, which is everything the Tool API leaves room to say.
    $this->assertStringContainsString('Only when presentation is grid.', $slot['properties']['columns']['description']);
    $this->assertStringContainsString('Only when presentation is list.', $slot['properties']['show_summary']['description']);
    $this->assertStringContainsString(
      'Its shape is chosen by presentation: list takes show_summary; grid takes columns.',
      $slot['description'],
    );
    // A variant's own bounds still travel.
    $this->assertSame(1, $slot['properties']['columns']['minimum']);
    $this->assertSame(6, $slot['properties']['columns']['maximum']);
  }

  /**
   * Tests that a resolved slot is exactly the chosen variant.
   */
  public function testResolvedSlotIsExactlyTheChosenVariant(): void {
    $schema = $this->schemaOf($this->demoSurface()->refine(['presentation' => 'grid']))['properties'];
    $slot = $schema['presentation_settings'];

    $this->assertSame(['columns'], array_keys($slot['properties']));
    // Required on the surface, and satisfied by its default of three, so
    // a payload may leave it out.
    $this->assertSame(3, $slot['properties']['columns']['default']);
    $this->assertSame('Columns', $slot['properties']['columns']['title']);
    $this->assertStringNotContainsString('Only when', $slot['properties']['columns']['description']);
  }

}
