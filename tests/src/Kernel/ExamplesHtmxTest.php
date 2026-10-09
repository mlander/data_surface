<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\data_surface\Form\DataSurfaceFormBuilder;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Form\DataSurfaceSituationForm;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the HTMX refresh strategy's wiring on example 2's twin.
 *
 * `/surface-examples/2/htmx` is example 2's route with one more default,
 * `_data_surface_refresh: htmx`. What is asserted is what the page is
 * built with: the venue and the room, the two keys others refine
 * against, carry core's HTMX attributes and the library instead of an
 * #ajax, and are limited to their own value as the AJAX triggers are;
 * the capacity, which nothing refines against, carries neither; the
 * container renders the stale marker's and the messages' wrappers, which
 * an out-of-band swap needs to land on, and marks what moved at render
 * time. The AJAX route, beside it, is unchanged.
 *
 * @see \Drupal\Tests\data_surface\Functional\ExamplesHtmxTest
 *   For the request a changed venue sends, and what comes back.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ExamplesHtmxTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_examples',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Builds the form a route serves, as a GET would.
   *
   * @param string $route_name
   *   The route.
   *
   * @return array
   *   The surface container, processed.
   */
  protected function containerAt(string $route_name): array {
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath());
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
    $form_state = new FormState();
    $form = $this->container->get('form_builder')->buildForm(DataSurfaceSituationForm::class, $form_state);
    return $form[DataSurfaceSituationForm::SURFACE_KEY];
  }

  /**
   * Tests the venue and the room are HTMX triggers, and nothing else is.
   */
  public function testTheDependenciesCarryHtmxAttributes(): void {
    $container = $this->containerAt('data_surface_examples.step2_htmx');
    $wrapper = $container['#attributes']['id'];
    $this->assertSame('data-surface-configure-wrapper', $wrapper);

    foreach (['venue' => ['room', 'capacity'], 'room' => ['capacity']] as $key => $replaces) {
      $element = $container[$key];
      $attributes = $element['#attributes'];
      // The form's own page, posted back; only the main content answers.
      $this->assertStringEndsWith('/surface-examples/2/htmx', $attributes['data-hx-post'], $key);
      $this->assertTrue($attributes['data-hx-drupal-only-main-content'], $key);
      // A select asks on change, as core's #ajax does for one.
      $this->assertSame('change', $attributes['data-hx-trigger'], $key);
      // The trigger swaps nothing itself: what moved is marked in the
      // response, out of band, and the trigger is not among it.
      $this->assertSame('this', $attributes['data-hx-target'], $key);
      $this->assertSame('none ignoreTitle:true', $attributes['data-hx-swap'], $key);
      $this->assertSame([DataSurfaceFormBuilderInterface::WRAPPER_INPUT => $wrapper], json_decode($attributes['data-hx-vals'], TRUE), $key);
      $this->assertContains('core/drupal.htmx', $element['#attached']['library'], $key);
      $this->assertArrayNotHasKey('#ajax', $element, $key);
      // What the AJAX trigger carries and the response marks: its place,
      // and the closure of what depends on it.
      $this->assertSame(['path' => [$key], 'replaces' => $replaces], $element[DataSurfaceFormBuilderInterface::TRIGGER_KEY], $key);
      $this->assertSame([[DataSurfaceSituationForm::SURFACE_KEY, $key]], $element['#limit_validation_errors'], $key);
      // A wrapper of its own, which a refused value replaces.
      $this->assertSame($wrapper . '--' . $key, $element[DataSurfaceFormBuilderInterface::REFRESH_ID_KEY], $key);
    }
    // Each dependent by the wrapper the response's out-of-band swap names.
    $this->assertSame($wrapper . '--room', $container['room'][DataSurfaceFormBuilderInterface::REFRESH_ID_KEY]);
    $this->assertSame($wrapper . '--capacity', $container['capacity'][DataSurfaceFormBuilderInterface::REFRESH_ID_KEY]);
    $this->assertSame($wrapper . '--data-surface-panel', $container[DataSurfaceSituationForm::PANEL_KEY][DataSurfaceFormBuilderInterface::REFRESH_ID_KEY]);

    // Nothing refines against these.
    foreach (['title', 'open', 'capacity'] as $key) {
      $this->assertArrayNotHasKey('data-hx-post', $container[$key]['#attributes'] ?? [], $key);
      $this->assertArrayNotHasKey(DataSurfaceFormBuilderInterface::TRIGGER_KEY, $container[$key], $key);
    }

    // The stale marker and the messages are always there to be swapped,
    // empty while there is nothing to say.
    $this->assertSame($wrapper . '__stale', $container[DataSurfaceFormBuilderInterface::STALE_MARKER_KEY][DataSurfaceFormBuilderInterface::REFRESH_ID_KEY]);
    $this->assertSame('', $container[DataSurfaceFormBuilderInterface::STALE_MARKER_KEY]['#markup']);
    $this->assertSame($wrapper . '__messages', $container['@messages'][DataSurfaceFormBuilderInterface::REFRESH_ID_KEY]);
    $this->assertContains([DataSurfaceFormBuilder::class, 'preRenderHtmxRefresh'], $container['#pre_render']);
  }

  /**
   * Tests the AJAX route beside it is untouched by the strategy.
   */
  public function testTheAjaxRouteIsUnchanged(): void {
    $container = $this->containerAt('data_surface_examples.step2');
    $this->assertSame([DataSurfaceFormBuilder::class, 'refreshSurface'], $container['venue']['#ajax']['callback']);
    $this->assertArrayNotHasKey('data-hx-post', $container['venue']['#attributes'] ?? []);
    $this->assertArrayNotHasKey('@messages', $container);
    $this->assertArrayNotHasKey(DataSurfaceFormBuilderInterface::STALE_MARKER_KEY, $container);
    $this->assertNotContains([DataSurfaceFormBuilder::class, 'preRenderHtmxRefresh'], $container['#pre_render'] ?? []);
  }

}
