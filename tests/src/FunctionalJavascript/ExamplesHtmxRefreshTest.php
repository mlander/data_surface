<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\FunctionalJavascript;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests example 2's HTMX twin in a browser, as core tests its HTMX form.
 *
 * Mirrors core's HtmxDynamicFormTest: a select is changed, the request
 * HTMX sends is waited for, and the page is read. What is asserted is
 * what the AJAX strategy's own browser test asserts: the room follows
 * the venue and the capacity the room, the venue select the person
 * touched is the same DOM node afterwards, and the build id moves. A
 * console error fails it too, by WebDriverTestBase's own
 * $failOnJavascriptConsoleErrors.
 *
 * @see \Drupal\FunctionalJavascriptTests\Core\Htmx\HtmxDynamicFormTest
 * @see \Drupal\Tests\data_surface\Functional\ExamplesHtmxTest
 *   For the same requests over HTTP, without a browser.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ExamplesHtmxRefreshTest extends ExamplesWebDriverTestBase {

  /**
   * Tests the venue and then the room, changed in the browser.
   */
  public function testTheVenueAndTheRoomRefreshWhatTheyMoved(): void {
    $this->drupalGet('surface-examples/2/htmx');
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->assertTrue($assert->optionExists('surface[room]', 'library_reading')->isSelected());
    $build_id = $assert->hiddenFieldExists('form_build_id')->getValue();
    $this->probe(['surface[venue]']);

    $page->selectFieldOption('surface[venue]', 'harbour');
    $assert->assertExpectedAjaxRequest(1);
    $assert->waitForElement('css', 'select[name="surface[room]"] option[value="harbour_deck"]');
    $this->assertNull($page->find('css', 'select[name="surface[room]"] option[value="library_reading"]'));
    $this->assertSame('', $page->findField('surface[room]')->getValue());
    $this->assertSame('1000', $page->findField('surface[capacity]')->getAttribute('max'));
    $this->assertTrue($this->stillProbed('surface[venue]'));
    $this->assertNotEquals($build_id, $assert->hiddenFieldExists('form_build_id')->getValue());
    $assert->elementExists('css', '[name="surface[@stale]"][value="room"]');

    $page->selectFieldOption('surface[room]', 'harbour_deck');
    $assert->assertExpectedAjaxRequest(2);
    $assert->waitForText('Up to 150 for the Upper deck.');
    $this->assertSame('150', $page->findField('surface[capacity]')->getAttribute('max'));
    $assert->elementNotExists('css', '[name="surface[@stale]"]');
    $this->assertTrue($this->stillProbed('surface[venue]'));
    $assert->elementNotExists('css', 'select.error');
  }

}
