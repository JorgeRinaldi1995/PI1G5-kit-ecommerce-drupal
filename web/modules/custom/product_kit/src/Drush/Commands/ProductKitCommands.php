<?php

declare(strict_types=1);

namespace Drupal\product_kit\Drush\Commands;

use Drupal\product_kit\KitCompositionManager;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for the Product Kit module.
 */
final class ProductKitCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'product_kit.composition_manager')]
    protected readonly KitCompositionManager $composition,
  ) {}

  /**
   * Lists products that are in no kit and kits that have no product.
   *
   * Both are valid states — a product with no kit yet, a kit with no product
   * yet (price and stock 0) — so this is informational, not a check for
   * broken data; it always exits successfully.
   */
  #[CLI\Command(name: 'product-kit:audit', aliases: ['pk:audit'])]
  #[CLI\Usage(name: 'drush product-kit:audit', description: 'List products without a kit and kits without a product.')]
  public function audit(): int {
    $products = $this->composition->findOrphanedProductIds();
    $kits = $this->composition->findEmptyKitIds();

    foreach ($products as $id) {
      $this->logger()->notice(dt('Product @id is in no kit.', ['@id' => $id]));
    }
    foreach ($kits as $id) {
      $this->logger()->notice(dt('Kit @id has no product.', ['@id' => $id]));
    }

    $this->logger()->success(dt('@products product(s) with no kit, @kits kit(s) with no product.', [
      '@products' => count($products),
      '@kits' => count($kits),
    ]));
    return self::EXIT_SUCCESS;
  }

}
