<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Drupal\Core\Form\FormBuilderInterface;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tests example 2's HTMX twin over HTTP, as core's HTMX JavaScript posts.
 *
 * A changed venue is a POST of the whole form to the page, with the
 * `HX-Request` and `HX-Trigger-Name` headers and the trigger's name as
 * `_triggering_element_name`, which core's htmx-assets.js copies from
 * the header. Form API rebuilds the form as it does for AJAX, and the
 * answer is the rebuilt form: what is asserted is which elements in it
 * carry `hx-swap-oob` — the dependents, the panel, the stale marker and
 * the messages, and not the venue nor anything unrelated — what they
 * hold, and the build id core swaps the same way so the next request
 * names the form this one cached. A second request, from the room, is
 * answered against that cached form.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ExamplesHtmxTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'data_surface_examples',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The path of example 2's HTMX twin.
   */
  protected const PATH = 'surface-examples/2/htmx';

  /**
   * The id example 2's surface container is rendered with.
   */
  protected const WRAPPER = 'data-surface-configure-wrapper';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
  }

  /**
   * Posts the form as an HTMX request a changed trigger sends.
   *
   * @param string $trigger
   *   The surface key that was changed.
   * @param array $surface
   *   The surface's input, as the page holds it.
   * @param array $hidden
   *   The form's own hidden fields: build id, token and form id.
   *
   * @return \Symfony\Component\DomCrawler\Crawler
   *   The response, parsed.
   */
  protected function htmxPost(string $trigger, array $surface, array $hidden): Crawler {
    $name = 'surface[' . $trigger . ']';
    $fields = $hidden + [
      '_triggering_element_name' => $name,
      DataSurfaceFormBuilderInterface::WRAPPER_INPUT => self::WRAPPER,
    ];
    foreach ($surface as $key => $value) {
      $fields['surface[' . $key . ']'] = $value;
    }
    $response = $this->getHttpClient()->request('POST', $this->buildUrl(self::PATH, ['query' => ['_wrapper_format' => 'drupal_htmx']]), [
      'headers' => [
        FormBuilderInterface::HTMX_REQUEST => 'true',
        'HX-Trigger-Name' => $name,
        'HX-Trigger' => 'edit-surface-' . $trigger,
        'HX-Target' => 'edit-surface-' . $trigger,
      ],
      'cookies' => $this->getSessionCookies(),
      'form_params' => $fields,
      'http_errors' => FALSE,
    ]);
    $body = (string) $response->getBody();
    $this->assertSame(200, $response->getStatusCode(), $body);
    return new Crawler($body);
  }

  /**
   * Names the elements of a response marked for an out-of-band swap.
   *
   * @param \Symfony\Component\DomCrawler\Crawler $page
   *   The response.
   *
   * @return array<string, string>
   *   Each marked element's swap value, keyed by its id, or by its name
   *   for an element marked by a selector of its own.
   */
  protected function outOfBand(Crawler $page): array {
    $marked = [];
    $page->filter('[data-hx-swap-oob]')->each(function (Crawler $element) use (&$marked): void {
      $marked[(string) ($element->attr('id') ?: $element->attr('name'))] = (string) $element->attr('data-hx-swap-oob');
    });
    ksort($marked);
    return $marked;
  }

  /**
   * Lists the option values of a select in a response.
   *
   * @param \Symfony\Component\DomCrawler\Crawler $page
   *   The response.
   * @param string $name
   *   The select's name.
   *
   * @return string[]
   *   The values, in order.
   */
  protected function options(Crawler $page, string $name): array {
    return $page->filter('select[name="' . $name . '"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value'));
  }

  /**
   * Tests a changed venue, and then a changed room, over HTMX.
   */
  public function testTheVenueAndTheRoomRefreshWhatTheyMoved(): void {
    $assert = $this->assertSession();
    $before = $this->config('data_surface_examples.registration_step2')->getRawData();

    // The landing page links the twin.
    $this->drupalGet('surface-examples');
    $assert->pageTextContains('In HTMX: ');
    $assert->linkByHrefExists('surface-examples/2/htmx');
    $assert->linkByHrefExists('surface-examples/3/htmx');

    $this->drupalGet(self::PATH);
    $assert->elementAttributeContains('css', 'select[name="surface[venue]"]', 'data-hx-post', '/surface-examples/2/htmx');
    $assert->elementAttributeContains('css', 'select[name="surface[venue]"]', 'data-hx-swap', 'none');
    $assert->elementExists('css', '#' . self::WRAPPER . '__stale');
    $assert->elementExists('css', '#' . self::WRAPPER . '__messages');
    $assert->responseContains('htmx/htmx');
    $hidden = [
      'form_build_id' => $assert->hiddenFieldExists('form_build_id')->getValue(),
      'form_token' => $assert->hiddenFieldExists('form_token')->getValue(),
      'form_id' => $assert->hiddenFieldExists('form_id')->getValue(),
    ];

    // Stored at the library's reading room; the venue moves to the
    // harbour, with the reading room still posted, as the page holds it.
    $page = $this->htmxPost('venue', [
      'title' => 'Spring meetup',
      'open' => '1',
      'venue' => 'harbour',
      'room' => 'library_reading',
      'capacity' => '50',
    ], $hidden);
    $build_input = 'input[name="form_build_id"][value="' . $hidden['form_build_id'] . '"]';
    // Exactly what the AJAX callback replaces: the room and the capacity
    // that depend on the venue, the panel, the stale marker and the
    // messages. Not the venue, which shows what the person chose, and
    // nothing the venue does not reach.
    $this->assertSame([
      self::WRAPPER . '--capacity' => 'true',
      self::WRAPPER . '--data-surface-panel' => 'true',
      self::WRAPPER . '--room' => 'true',
      self::WRAPPER . '__messages' => 'true',
      self::WRAPPER . '__stale' => 'true',
      'form_build_id' => 'outerHTML:' . $build_input,
    ], $this->outOfBand($page));

    // The room offers the harbour's rooms, on its empty option, standing
    // for the stored room, which the stale marker names.
    $room = $page->filter('#' . self::WRAPPER . '--room');
    $this->assertSame(['', 'harbour_auditorium', 'harbour_deck'], $this->options($room, 'surface[room]'));
    $this->assertSame('room', $page->filter('#' . self::WRAPPER . '__stale input[name="surface[@stale]"]')->attr('value'));
    // An orphaned room caps nothing: the capacity is back to its
    // declared limit, with no room to describe.
    $capacity = $page->filter('#' . self::WRAPPER . '--capacity input[name="surface[capacity]"]');
    $this->assertSame('1000', $capacity->attr('max'));
    $this->assertStringNotContainsString('Up to', $page->filter('#' . self::WRAPPER . '--capacity')->text());
    // The panel's room row is narrowed to the harbour.
    $this->assertStringContainsString('one of Auditorium, Upper deck', $page->filter('#' . self::WRAPPER . '--data-surface-panel')->text());

    // The venue is in the response, as the whole form is, but unmarked,
    // so HTMX leaves the page's own select where it is.
    $this->assertCount(1, $page->filter('select[name="surface[venue]"]'));
    $this->assertCount(0, $page->filter('[data-hx-swap-oob] select[name="surface[venue]"]'));
    $this->assertCount(0, $page->filter('[data-hx-swap-oob] input[name="surface[title]"]'));

    // Core's build id swap: the rebuilt form was cached under a new id,
    // which replaces the old one in the page.
    $new_build_id = (string) $page->filter('input[name="form_build_id"]')->attr('value');
    $this->assertNotSame($hidden['form_build_id'], $new_build_id);

    // The room, chosen, against the form the first request cached.
    $page = $this->htmxPost('room', [
      'title' => 'Spring meetup',
      'open' => '1',
      'venue' => 'harbour',
      'room' => 'harbour_deck',
      'capacity' => '50',
    ], ['form_build_id' => $new_build_id] + $hidden);
    // The capacity follows the room. The room itself is replaced this
    // time, because the rebuild moved its options: a required select
    // loses its empty option once something is chosen. The venue is not.
    $this->assertSame([
      self::WRAPPER . '--capacity' => 'true',
      self::WRAPPER . '--data-surface-panel' => 'true',
      self::WRAPPER . '--room' => 'true',
      self::WRAPPER . '__messages' => 'true',
      self::WRAPPER . '__stale' => 'true',
      'form_build_id' => 'outerHTML:input[name="form_build_id"][value="' . $new_build_id . '"]',
    ], $this->outOfBand($page));
    $capacity = $page->filter('#' . self::WRAPPER . '--capacity');
    $this->assertSame('150', $capacity->filter('input[name="surface[capacity]"]')->attr('max'));
    $this->assertStringContainsString('Up to 150 for the Upper deck.', $capacity->text());
    $this->assertSame(['harbour_auditorium', 'harbour_deck'], $this->options($page->filter('#' . self::WRAPPER . '--room'), 'surface[room]'));
    // Nothing stands for a stale value any more: the marker is emptied.
    $this->assertCount(0, $page->filter('#' . self::WRAPPER . '__stale input'));

    // A refresh writes nothing.
    $this->container->get('config.factory')->reset();
    $this->assertSame($before, $this->config('data_surface_examples.registration_step2')->getRawData());
  }

}
