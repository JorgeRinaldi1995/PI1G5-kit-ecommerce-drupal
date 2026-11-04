<?php

declare(strict_types=1);

namespace Drupal\kit_catalog\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * The site's storefront home page (kit_catalog.storefront).
 *
 * Deliberately empty. The hero banner and the kit grid are placed as
 * ordinary blocks via Block Layout, visibility-restricted to this page --
 * the same way any Drupal site composes a front page without a dedicated
 * node. This route exists only to give Block Layout and
 * system.site:page.front something to target.
 */
class StorefrontController extends ControllerBase {

  /**
   * Builds the page.
   */
  public function build(): array {
    return [];
  }

}
