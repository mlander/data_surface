<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Form\SurfaceShapeDisplay;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\Pipeline\DataSurfaceResult;
use Drupal\data_surface\SurfaceShape;
use Drupal\data_surface_demo_duration\EventSubscriber\DurationShapeSubscriber;
use Drupal\data_surface_demo_duration\Iso8601DurationShape;
use Drupal\data_surface_demo_extras\EventSubscriber\DemoExtrasSurfaceSubscriber;
use Drupal\data_surface_demo_extras\NodeTypeReviewSettings;
use Drupal\data_surface_demo_extras\ReviewDeadlineShape;
use Drupal\data_surface_demo_node_type\Form\NodeTypeSurfaceFormCosmetics;
use Drupal\data_surface_test\ShapePolicyFilter;
use Drupal\data_surface_tool\SurfaceProviderToolBase;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Exception\InputException;
use Drupal\tool\ExecutableResult;
use Drupal\tool\ToolResultInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests contributed shapes on the content type's review deadline.
 *
 * The key is data_surface_demo_extras's: its canonical is the stored
 * seconds, and it contributes an amount and unit shape. The duration
 * module, which owns nothing here, contributes an ISO 8601 shape. One
 * week said all three ways is one stored value, through the pipeline,
 * through the surface driven tool and through the generated form, and
 * the form asks for whichever reading its display chooses.
 *
 * @see docs/shapes.md
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class SurfaceShapesTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * One week, in seconds.
   */
  protected const WEEK = 604800;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'block',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_test',
    'data_surface_demo',
    'data_surface_demo_extras',
    'data_surface_demo_duration',
    'data_surface_demo_node_type',
    'data_surface_tool',
    'data_surface_demo_node_type_tool',
  ];

  /**
   * How many content types this test has asked for, for unique names.
   */
  protected int $sequence = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'node']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Tests that the canonical is unchanged and the shapes sit beside it.
   */
  public function testTheCanonicalIsTheStoredValue(): void {
    $deadline = $this->deadlineDefinition($this->addSurface());
    $this->assertSame('integer', $deadline->getDataType());
    $this->assertSame(
      ['min' => NodeTypeReviewSettings::DEADLINE_MIN, 'max' => NodeTypeReviewSettings::DEADLINE_MAX],
      $deadline->getConstraints()['Range'],
    );
    $shapes = DefinitionMetadata::getShapes($deadline);
    // The key's owner first, at the default priority; the module that
    // owns nothing here after it.
    $this->assertSame([ReviewDeadlineShape::ID, Iso8601DurationShape::ID], array_keys($shapes));
    $this->assertSame(DemoExtrasSurfaceSubscriber::PROVIDER, $shapes[ReviewDeadlineShape::ID]->contributor);
    $this->assertSame(DurationShapeSubscriber::PROVIDER, $shapes[Iso8601DurationShape::ID]->contributor);
  }

  /**
   * Tests that one week said three ways is one stored value.
   */
  public function testOneWeekEveryWayThroughThePipeline(): void {
    foreach ($this->oneWeek() as $how => $deadline) {
      $type = $this->nextType();
      $result = $this->submit($type, $deadline);
      $this->assertTrue($result->isValid(), $how . ': ' . $this->violations($result));
      $this->assertSame(self::WEEK, $this->stored($type), $how);
      // What a successful run reports is what was stored.
      $this->assertSame(self::WEEK, $result->values['third_party_settings'][DemoExtrasSurfaceSubscriber::PROVIDER][NodeTypeReviewSettings::DEADLINE], $how);
    }
  }

  /**
   * Tests that the surface driven tool accepts every reading too.
   */
  public function testOneWeekEveryWayThroughTheTool(): void {
    $readings = $this->oneWeek() + [
      'selector' => SurfaceShape::select(Iso8601DurationShape::ID, 'P1W'),
    ];
    foreach ($readings as $how => $deadline) {
      $type = $this->nextType();
      $result = $this->runTool($type, $deadline);
      $this->assertTrue($result->isSuccess(), $how . ': ' . $result->getMessage());
      $this->assertSame(self::WEEK, $this->stored($type), $how);
      $this->assertSame(
        self::WEEK,
        $result->getContextValues()[SurfaceProviderToolBase::VALUES]['third_party_settings'][DemoExtrasSurfaceSubscriber::PROVIDER][NodeTypeReviewSettings::DEADLINE],
        $how,
      );
    }
    $result = $this->runTool($this->nextType(), 'P1M');
    $this->assertFalse($result->isSuccess());
    $this->assertStringContainsString('P1M counts months or years, which are not a fixed length of time', (string) $result->getMessage());
  }

  /**
   * Tests what the tool advertises for the key: the union, in words.
   */
  public function testTheToolAdvertisesTheUnion(): void {
    $tool = $this->container->get('plugin.manager.tool')->createInstance('data_surface:node_type_add');
    $schema = $this->container->get('tool.definition_serializer')->normalizeInputSchema($tool);
    $deadline = $schema['properties'][SurfaceProviderToolBase::VALUES]['properties']['third_party_settings']['properties'][DemoExtrasSurfaceSubscriber::PROVIDER]['properties'][NodeTypeReviewSettings::DEADLINE];
    // Any, because every reading must reach the pipeline: no type, no
    // bound that would refuse a shape before the tool runs.
    $this->assertArrayNotHasKey('type', $deadline);
    $this->assertArrayNotHasKey('minimum', $deadline);
    $this->assertSame('Review deadline', $deadline['title']);
    foreach ([
      'its own value, an integer from 3600 to 2592000',
      'the amount_unit shape from data_surface_demo_extras, Amount and unit',
      'the iso8601 shape from data_surface_demo_duration, ISO 8601 duration',
      '{"@shape": "<shape id>", "@value": <the value>}',
    ] as $said) {
      $this->assertStringContainsString($said, $deadline['description']);
    }
    $this->assertSame([
      self::WEEK,
      [NodeTypeReviewSettings::AMOUNT => 1, NodeTypeReviewSettings::UNIT => 'weeks'],
      'P1W',
      SurfaceShape::select(ReviewDeadlineShape::ID, $this->amountUnit(1, 'weeks')),
    ], $deadline['examples']);
  }

  /**
   * Tests the explicit selector.
   */
  public function testTheSelectorNamesTheShape(): void {
    $type = $this->nextType();
    $result = $this->submit($type, SurfaceShape::select(ReviewDeadlineShape::ID, $this->amountUnit(2, 'weeks')));
    $this->assertTrue($result->isValid(), $this->violations($result));
    $this->assertSame(2 * self::WEEK, $this->stored($type));

    // Named, a value is judged as that shape and nothing else: seconds
    // sent as the ISO 8601 shape are not an ISO 8601 duration.
    $result = $this->submit($this->nextType(), SurfaceShape::select(Iso8601DurationShape::ID, (string) self::WEEK));
    $this->assertSame(
      [NodeTypeReviewSettings::DEADLINE_KEY => 'This is not an ISO 8601 duration such as P1W, P3D or PT12H.'],
      $this->messages($result),
    );

    // A shape the key does not take is refused by name.
    $result = $this->submit($this->nextType(), SurfaceShape::select('fortnights', 2));
    $this->assertSame(
      [NodeTypeReviewSettings::DEADLINE_KEY => 'Review deadline takes no fortnights shape here. It takes its own value, or one of these shapes: amount_unit (from data_surface_demo_extras), iso8601 (from data_surface_demo_duration).'],
      $this->messages($result),
    );

    // The selector holds the shape and the value, and nothing else.
    $result = $this->submit($this->nextType(), SurfaceShape::select(Iso8601DurationShape::ID, 'P1W') + ['unit' => 'weeks']);
    $this->assertSame(
      [NodeTypeReviewSettings::DEADLINE_KEY . '.unit' => 'Unknown key ' . NodeTypeReviewSettings::DEADLINE_KEY . '.unit.'],
      $this->messages($result),
    );
  }

  /**
   * Tests both gates, and what is said when no reading takes a value.
   */
  public function testBothGatesHold(): void {
    $key = NodeTypeReviewSettings::DEADLINE_KEY;
    // The shape takes it; the canonical's Range refuses what it becomes.
    $this->assertSame(
      [$key => 'This value should be between 3600 and 2592000.'],
      $this->messages($this->submit($this->nextType(), 'P45D')),
    );
    // The shape refuses it, and the canonical has nothing to add.
    $this->assertSame(
      [$key => 'P1M counts months or years, which are not a fixed length of time, so it has no one number of seconds. Say it in weeks, days or hours, such as P1W, P3D or PT12H.'],
      $this->messages($this->submit($this->nextType(), 'P1M')),
    );
    $this->assertSame(
      [$key => 'PT30M counts minutes or seconds. Say it in whole weeks, days or hours, such as P1W, P3D or PT12H.'],
      $this->messages($this->submit($this->nextType(), 'PT30M')),
    );
    // Fits the amount and unit and nothing else, so it is judged as that,
    // in the caller's own units.
    $this->assertSame(
      [$key . '.amount' => 'A review deadline is at least one hour and at most thirty days; 45 days is outside that.'],
      $this->messages($this->submit($this->nextType(), $this->amountUnit(45, 'days'))),
    );
    // Read by nothing: the canonical's refusal, and the shapes it tried.
    $this->assertSame(
      [$key => 'This value should be between 3600 and 2592000. Review deadline was read as its own value; none of its shapes (amount_unit (from data_surface_demo_extras), iso8601 (from data_surface_demo_duration)) took it either.'],
      $this->messages($this->submit($this->nextType(), 60)),
    );
    // Fits nothing at all: a map, but not the amount and unit's.
    $this->assertSame(
      [$key => 'This value must be of type integer, or one of its shapes amount_unit (from data_surface_demo_extras), iso8601 (from data_surface_demo_duration), array given.'],
      $this->messages($this->submit($this->nextType(), ['weeks' => 1])),
    );
    $this->assertNull(NodeType::load('case_1'));
  }

  /**
   * Tests that a policy filter may take a shape away, and only that.
   */
  public function testPolicyFilterRemovesShape(): void {
    $advertised = $this->addSurface();
    $surface = new DataSurface($advertised->getDefinitions(), filters: [
      new ShapePolicyFilter(NodeTypeReviewSettings::DEADLINE_KEY, Iso8601DurationShape::ID),
    ]);
    $this->assertSame(
      [ReviewDeadlineShape::ID],
      array_keys(DefinitionMetadata::getShapes($this->deadlineDefinition($surface->refine([])))),
    );
    // The advertised surface still has it; the refined one does not.
    $this->assertCount(2, DefinitionMetadata::getShapes($this->deadlineDefinition($surface)));

    $target = $this->provider()->getDataSurfaceTarget('add');
    $removed = 'Review deadline takes no iso8601 shape here. It takes its own value, or one of these shapes: amount_unit (from data_surface_demo_extras).';
    foreach (['P1W', SurfaceShape::select(Iso8601DurationShape::ID, 'P1W')] as $deadline) {
      $result = $this->pipeline()->submit($surface, $this->payload($this->nextType(), $deadline), $target);
      $this->assertSame([NodeTypeReviewSettings::DEADLINE_KEY => $removed], $this->messages($result));
    }
    // The canonical and the shapes left stand.
    $type = $this->nextType();
    $this->assertTrue($this->pipeline()->submit($surface, $this->payload($type, self::WEEK), $target)->isValid());
    $this->assertSame(self::WEEK, $this->stored($type));
    $type = $this->nextType();
    $this->assertTrue($this->pipeline()->submit($surface, $this->payload($type, $this->amountUnit(1, 'weeks')), $target)->isValid());
    $this->assertSame(self::WEEK, $this->stored($type));

    // A form display choosing the removed shape falls back to the
    // canonical.
    $form = $this->formBuilder()->buildSurfaceForm($surface, [], new FormState(), 'shapes', [NodeTypeReviewSettings::DEADLINE_KEY => Iso8601DurationShape::ID]);
    $this->assertSame([], $form[SurfaceShapeDisplay::ELEMENT_KEY]);
    $this->assertSame('number', $this->deadlineElement($form)['#type']);

    // A policy that adds a shape is a second contributor arriving late.
    $shape = DefinitionMetadata::getShapes($this->deadlineDefinition($advertised))[Iso8601DurationShape::ID];
    $widening = new DataSurface($advertised->getDefinitions(), filters: [
      new ShapePolicyFilter(NodeTypeReviewSettings::DEADLINE_KEY, ReviewDeadlineShape::ID, new SurfaceShape('again', $shape->shape, 'rogue')),
    ]);
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('the again shape was added');
    $widening->refine([]);
  }

  /**
   * Tests that the display chooses the reading, and that each round-trips.
   */
  public function testTheDisplayChooses(): void {
    $surface = $this->addSurface();
    $key = NodeTypeReviewSettings::DEADLINE_KEY;
    $cases = [
      'canonical' => [[], 'number', (string) self::WEEK],
      'amount_unit' => [[$key => ReviewDeadlineShape::ID], 'details', ['amount' => '1', 'unit' => 'weeks']],
      'iso8601' => [[$key => Iso8601DurationShape::ID], 'textfield', 'P1W'],
    ];
    foreach ($cases as $how => [$display, $element_type, $typed]) {
      $form = $this->formBuilder()->buildSurfaceForm($surface, $surface->getDefaultValues(), new FormState(), 'shapes', $display);
      $element = $this->deadlineElement($form);
      $this->assertSame($element_type, $element['#type'], $how);
      // Whatever it is drawn as, it is the key: its label, and its own
      // place in the tree.
      $this->assertSame('Review deadline', (string) $element['#title'], $how);
      $this->assertSame($display, $form[SurfaceShapeDisplay::ELEMENT_KEY], $how);

      $type = $this->nextType();
      $form_state = new FormState();
      // What the rest of the form would have submitted beside it.
      $form_state->setValues($this->payload($type, $typed) + [
        'title_label' => 'Title',
        'preview_mode' => '1',
        'status' => 1,
        'promote' => 0,
        'sticky' => 0,
        'new_revision' => 1,
        'display_submitted' => 1,
      ]);
      $values = $this->formBuilder()->extractSurfaceValues($surface, $form, $form_state);
      $extracted = $values['third_party_settings'][DemoExtrasSurfaceSubscriber::PROVIDER][NodeTypeReviewSettings::DEADLINE];
      if ($display === []) {
        // Drawn as the canonical, read as the canonical.
        $this->assertSame(self::WEEK, $extracted, $how);
      }
      else {
        // Drawn as a shape, sent with the selector naming it, so a form
        // never leans on the matching rule.
        $this->assertSame($display[$key], $extracted[DataSurfacePipelineInterface::SHAPE], $how);
      }
      $result = $this->pipeline()->submit($surface, $values, $this->provider()->getDataSurfaceTarget('add'));
      $this->assertTrue($result->isValid(), $how . ': ' . $this->violations($result));
      $this->assertSame(self::WEEK, $this->stored($type), $how);

      // And an edit shows the stored seconds in the reading the display
      // chose.
      $edit = $this->provider()->getDataSurface('edit', $type);
      $stored = $this->provider()->getDataSurfaceTarget('edit', $type)->load($edit);
      $element = $this->deadlineElement($this->formBuilder()->buildSurfaceForm($edit, $stored, new FormState(), 'shapes', $display));
      match ($how) {
        'canonical' => $this->assertSame(self::WEEK, $element['#default_value']),
        'amount_unit' => $this->assertSame([1, 'weeks'], $this->amountAndUnit($element)),
        'iso8601' => $this->assertSame('P1W', $element['#default_value']),
      };
    }

    // A choice naming a key or a shape the surface does not have is
    // ignored: presentation never breaks a form.
    $form = $this->formBuilder()->buildSurfaceForm($surface, [], new FormState(), 'shapes', [
      'nothing.here' => 'amount_unit',
      $key => 'fortnights',
    ]);
    $this->assertSame([], $form[SurfaceShapeDisplay::ELEMENT_KEY]);
    $this->assertSame('number', $this->deadlineElement($form)['#type']);

    // The generic provider form's display is its cosmetic layer's.
    $this->assertSame([$key => ReviewDeadlineShape::ID], NodeTypeSurfaceFormCosmetics::SHAPES);
  }

  /**
   * Tests a lossy inverse: the nearest exact spelling, said to be one.
   */
  public function testLossyInverseSaysSo(): void {
    $type = $this->nextType();
    $this->assertTrue($this->submit($type, $this->amountUnit(10, NodeTypeReviewSettings::BUSINESS_DAYS))->isValid());
    $this->assertSame(12 * 86400, $this->stored($type));
    $edit = $this->provider()->getDataSurface('edit', $type);
    $stored = $this->provider()->getDataSurfaceTarget('edit', $type)->load($edit);
    // The target reads back canonical: seconds, not a shape.
    $this->assertSame(12 * 86400, $stored['third_party_settings'][DemoExtrasSurfaceSubscriber::PROVIDER][NodeTypeReviewSettings::DEADLINE]);

    $element = $this->deadlineElement($this->formBuilder()->buildSurfaceForm($edit, $stored, new FormState(), 'shapes', [NodeTypeReviewSettings::DEADLINE_KEY => ReviewDeadlineShape::ID]));
    $this->assertSame([12, 'days'], $this->amountAndUnit($element));
    $this->assertStringContainsString('shown in its nearest exact spelling', (string) $element['#description']);

    $type = $this->nextType();
    $this->assertTrue($this->submit($type, 'P1W2D')->isValid());
    $this->assertSame(9 * 86400, $this->stored($type));
    $edit = $this->provider()->getDataSurface('edit', $type);
    $stored = $this->provider()->getDataSurfaceTarget('edit', $type)->load($edit);
    $element = $this->deadlineElement($this->formBuilder()->buildSurfaceForm($edit, $stored, new FormState(), 'shapes', [NodeTypeReviewSettings::DEADLINE_KEY => Iso8601DurationShape::ID]));
    $this->assertSame('P9D', $element['#default_value']);
    $this->assertStringContainsString('shown in its nearest exact spelling', (string) $element['#description']);

    // The canonical always round-trips through either inverse.
    $shapes = DefinitionMetadata::getShapes($this->deadlineDefinition($edit));
    foreach ([3600, 43200, 86400, 3 * 86400, self::WEEK, 1036800, NodeTypeReviewSettings::DEADLINE_MAX] as $seconds) {
      foreach ($shapes as $id => $shape) {
        $this->assertSame($seconds, $shape->shape->toCanonical($shape->shape->fromCanonical($seconds)), $id . ' ' . $seconds);
      }
    }
    $iso = $shapes[Iso8601DurationShape::ID]->shape;
    $seconds = [43200, 3 * 86400, 2 * self::WEEK, 25 * 3600];
    $this->assertSame(['PT12H', 'P3D', 'P2W', 'PT25H'], array_map($iso->fromCanonical(...), $seconds));
  }

  /**
   * Says one week each way a caller may.
   *
   * @return array<string, mixed>
   *   The deadline, by reading.
   */
  protected function oneWeek(): array {
    return [
      'seconds' => self::WEEK,
      'seconds as a form sends them' => (string) self::WEEK,
      'amount and unit' => $this->amountUnit(1, 'weeks'),
      'seven days' => $this->amountUnit(7, 'days'),
      'ISO 8601' => 'P1W',
      'ISO 8601 in days' => 'P7D',
      'ISO 8601 in hours' => 'PT168H',
    ];
  }

  /**
   * Builds an amount and unit.
   *
   * @param int $amount
   *   The amount.
   * @param string $unit
   *   The unit.
   *
   * @return array
   *   The deadline in that shape.
   */
  protected function amountUnit(int $amount, string $unit): array {
    return [NodeTypeReviewSettings::AMOUNT => $amount, NodeTypeReviewSettings::UNIT => $unit];
  }

  /**
   * Reads the defaults of an amount and unit element.
   *
   * @param array $element
   *   The element.
   *
   * @return array
   *   The amount's default and the unit's.
   */
  protected function amountAndUnit(array $element): array {
    return [
      $element[NodeTypeReviewSettings::AMOUNT]['#default_value'],
      $element[NodeTypeReviewSettings::UNIT]['#default_value'],
    ];
  }

  /**
   * Gets the content type provider.
   *
   * @return \Drupal\data_surface_demo_node_type\NodeTypeSurfaceProvider
   *   The provider.
   */
  protected function provider(): object {
    return $this->container->get('data_surface_demo_node_type.provider');
  }

  /**
   * Gets the surface for adding a content type.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The surface.
   */
  protected function addSurface(): DataSurfaceInterface {
    return $this->provider()->getDataSurface('add');
  }

  /**
   * Gets the deadline's definition off a surface.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The definition.
   */
  protected function deadlineDefinition(DataSurfaceInterface $surface): DataDefinitionInterface {
    $mounted = $surface->getDefinition('third_party_settings');
    $this->assertInstanceOf(ComplexDataDefinitionInterface::class, $mounted);
    $provider = $mounted->getPropertyDefinition(DemoExtrasSurfaceSubscriber::PROVIDER);
    $this->assertInstanceOf(ComplexDataDefinitionInterface::class, $provider);
    $deadline = $provider->getPropertyDefinition(NodeTypeReviewSettings::DEADLINE);
    $this->assertNotNull($deadline);
    return $deadline;
  }

  /**
   * Gets the deadline's element out of a built container.
   *
   * @param array $form
   *   The container.
   *
   * @return array
   *   The element.
   */
  protected function deadlineElement(array $form): array {
    return $form['third_party_settings'][DemoExtrasSurfaceSubscriber::PROVIDER][NodeTypeReviewSettings::DEADLINE];
  }

  /**
   * Builds a payload adding a content type with a deadline.
   *
   * @param string $type
   *   The machine name.
   * @param mixed $deadline
   *   The deadline, in any reading.
   *
   * @return array
   *   The payload.
   */
  protected function payload(string $type, mixed $deadline): array {
    return [
      'name' => ucfirst($type),
      'type' => $type,
      'third_party_settings' => [DemoExtrasSurfaceSubscriber::PROVIDER => [NodeTypeReviewSettings::DEADLINE => $deadline]],
    ];
  }

  /**
   * Adds a content type through the pipeline.
   *
   * @param string $type
   *   The machine name.
   * @param mixed $deadline
   *   The deadline, in any reading.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceResult
   *   The result.
   */
  protected function submit(string $type, mixed $deadline): DataSurfaceResult {
    return $this->pipeline()->submit($this->addSurface(), $this->payload($type, $deadline), $this->provider()->getDataSurfaceTarget('add'));
  }

  /**
   * Adds a content type through the surface driven tool.
   *
   * @param string $type
   *   The machine name.
   * @param mixed $deadline
   *   The deadline, in any reading.
   *
   * @return \Drupal\tool\ToolResultInterface
   *   The result.
   */
  protected function runTool(string $type, mixed $deadline): ToolResultInterface {
    $tool = $this->container->get('plugin.manager.tool')->createInstance('data_surface:node_type_add');
    try {
      $tool->setInputValue(SurfaceProviderToolBase::VALUES, $this->payload($type, $deadline));
    }
    catch (InputException $e) {
      return ExecutableResult::failure(new TranslatableMarkup('@message', ['@message' => $e->getMessage()]));
    }
    $tool->execute();
    return $tool->getResult();
  }

  /**
   * Reads the stored deadline.
   *
   * @param string $type
   *   The machine name.
   *
   * @return mixed
   *   The stored deadline, or FALSE when the content type does not exist.
   */
  protected function stored(string $type): mixed {
    $this->container->get('entity_type.manager')->getStorage('node_type')->resetCache();
    $node_type = NodeType::load($type);
    return $node_type === NULL ? FALSE : $node_type->getThirdPartySetting(DemoExtrasSurfaceSubscriber::PROVIDER, NodeTypeReviewSettings::DEADLINE);
  }

  /**
   * Lists a result's violations by full path, messages joined per path.
   *
   * @param \Drupal\data_surface\Pipeline\DataSurfaceResult $result
   *   The result.
   *
   * @return array<string, string>
   *   The messages, keyed by full path.
   */
  protected function messages(DataSurfaceResult $result): array {
    $messages = [];
    foreach ($result->violations as $violation) {
      $path = $violation->fullPath();
      $message = strip_tags((string) $violation->message);
      $messages[$path] = isset($messages[$path]) ? $messages[$path] . ' ' . $message : $message;
    }
    return $messages;
  }

  /**
   * Says a result's violations in one line, for assertion messages.
   *
   * @param \Drupal\data_surface\Pipeline\DataSurfaceResult $result
   *   The result.
   *
   * @return string
   *   The violations.
   */
  protected function violations(DataSurfaceResult $result): string {
    return json_encode($this->messages($result)) ?: '';
  }

  /**
   * Gets a unique machine name for the next content type.
   *
   * @return string
   *   The machine name.
   */
  protected function nextType(): string {
    return 'case_' . ++$this->sequence;
  }

}
