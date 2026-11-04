<?php

declare(strict_types=1);

namespace Drupal\Tests\kit_catalog\Kernel;

use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use Drupal\product_kit\Entity\Kit;
use Drupal\product_kit\Entity\KitProduct;
use Drupal\product_kit\Entity\Product;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests CartManager's stock integrity, idempotency and locking guarantees.
 *
 * This is the permanent, automated form of what was, through Fase 0-4 of
 * this module's development, verified by hand with real concurrent OS
 * processes against the dev database each time (see the module's README
 * and the project memory for the raw numbers from those runs). Two kinds
 * of claim are covered here, deliberately differently:
 *
 * - The *arithmetic* ("does `reserveAgainstProducts()` compute the right
 *   answer given what's already reserved, across both cart tables, across
 *   every kit sharing a product") is covered by ordinary sequential
 *   assertions. A real race reduces to some serialized order of
 *   operations once the lock is in place, so a sequential test exercising
 *   the same code path still catches a regression in the formula itself
 *   (e.g. Fase 0's bug, where the reserved-units query was scoped to one
 *   kit instead of every kit sharing a product -- testCrossKitSharedProduct()
 *   below is that exact regression, permanently).
 * - The *locking primitive itself* ("does an uncommitted transaction
 *   actually block a second session trying to read/write the same
 *   product row") is covered separately in testForUpdateLockBlocksSecondSession(),
 *   using two genuinely independent database connections -- not two PHP
 *   objects wrapping one session, but two separate connections the
 *   database server itself sees as different clients, which is what a
 *   real race needs locking to defend against. A sequential test cannot
 *   exercise this; only two real connections can.
 */
#[RunTestsInSeparateProcesses]
class CartManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'image',
    'text',
    'views',
    'product_kit',
    'kit_catalog',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('product');
    $this->installEntitySchema('kit');
    $this->installEntitySchema('kit_product');
    $this->installSchema('kit_catalog', [
      'kit_catalog_cart_item',
      'kit_catalog_cart_product_item',
      'kit_catalog_add_request',
    ]);
  }

  /**
   * Creates a product.
   */
  protected function createProduct(int $stock, int $price = 100, bool $enabled = TRUE): Product {
    $product = Product::create([
      'name' => $this->randomMachineName(),
      'price' => $price,
      'stock' => $stock,
      'status' => $enabled,
    ]);
    $product->save();
    return $product;
  }

  /**
   * Creates a kit from a map of product => quantity-per-kit.
   */
  protected function createKit(array $lines, bool $enabled = TRUE): Kit {
    $kit = Kit::create([
      'name' => $this->randomMachineName(),
      'status' => $enabled,
    ]);
    $kit->save();
    foreach ($lines as $product_id => $quantity) {
      KitProduct::create([
        'kit' => $kit->id(),
        'product' => $product_id,
        'quantity' => $quantity,
      ])->save();
    }
    return $kit;
  }

  /**
   * @covers \Drupal\kit_catalog\CartManager::addKitToCart
   */
  public function testAddKitToCartSuccess(): void {
    $product = $this->createProduct(stock: 5);
    $kit = $this->createKit([(int) $product->id() => 1]);
    $cart = $this->cartManager();

    $result = $cart->addKitToCart(1, (int) $kit->id(), 'tok-1', 2);

    $this->assertSame(['status' => 'added'], $result);
    $this->assertSame([(int) $kit->id() => 2], $cart->getKitItems(1));
  }

  /**
   * @covers \Drupal\kit_catalog\CartManager::addProductToCart
   */
  public function testAddProductToCartSuccess(): void {
    $product = $this->createProduct(stock: 5);
    $cart = $this->cartManager();

    $result = $cart->addProductToCart(1, (int) $product->id(), 'tok-1', 3);

    $this->assertSame(['status' => 'added'], $result);
    $this->assertSame([(int) $product->id() => 3], $cart->getProductItems(1));
  }

  /**
   * A kit add beyond the kit's real (derived) stock is rejected, not capped.
   */
  public function testInsufficientStockForKit(): void {
    $product = $this->createProduct(stock: 3);
    $kit = $this->createKit([(int) $product->id() => 1]);
    $cart = $this->cartManager();

    $result = $cart->addKitToCart(1, (int) $kit->id(), 'tok-1', 4);

    $this->assertSame(['status' => 'insufficient_stock', 'available' => 3], $result);
    $this->assertSame([], $cart->getKitItems(1));
  }

  /**
   * Same as above, for a bare product line.
   */
  public function testInsufficientStockForProduct(): void {
    $product = $this->createProduct(stock: 2);
    $cart = $this->cartManager();

    $result = $cart->addProductToCart(1, (int) $product->id(), 'tok-1', 5);

    $this->assertSame(['status' => 'insufficient_stock', 'available' => 2], $result);
  }

  /**
   * A kit with no lines (or a deleted product) is 'unavailable', not 0.
   */
  public function testUnavailableKitWithNoLines(): void {
    $kit = Kit::create(['name' => 'Empty', 'status' => TRUE]);
    // Bypass the kit_product entity so this kit really has zero lines --
    // Kit::save() alone doesn't enforce product_kit's "kit needs >= 1
    // product" rule (that's KitForm/KitCompositionManager's job, not the
    // entity itself), so this is a legitimate, if unusual, fixture.
    $kit->save();
    $cart = $this->cartManager();

    $result = $cart->addKitToCart(1, (int) $kit->id(), 'tok-1', 1);

    $this->assertSame(['status' => 'unavailable'], $result);
  }

  /**
   * A product ID that doesn't exist is 'unavailable', not a 0-stock reject.
   */
  public function testUnavailableNonexistentProduct(): void {
    $cart = $this->cartManager();

    $result = $cart->addProductToCart(1, 999999, 'tok-1', 1);

    $this->assertSame(['status' => 'unavailable'], $result);
  }

  /**
   * A retried submission for an already-*successful* add replays success,
   * without reserving the quantity a second time.
   */
  public function testIdempotentRetryOfSuccess(): void {
    $product = $this->createProduct(stock: 5);
    $cart = $this->cartManager();

    $first = $cart->addProductToCart(1, (int) $product->id(), 'same-token', 2);
    $retry = $cart->addProductToCart(1, (int) $product->id(), 'same-token', 2);

    $this->assertSame(['status' => 'added'], $first);
    $this->assertSame(['status' => 'added'], $retry);
    // Not 4: the retry must not have reserved a second time.
    $this->assertSame([(int) $product->id() => 2], $cart->getProductItems(1));
  }

  /**
   * A retried submission for an already-*rejected* add replays the
   * rejection -- the regression this module's Fase 2 README specifically
   * calls out: an earlier version of this logic treated every replay as a
   * generic "duplicate", which a careless message mapping turned into a
   * false "added".
   */
  public function testIdempotentRetryOfRejectionStaysRejected(): void {
    $product = $this->createProduct(stock: 1);
    $cart = $this->cartManager();

    $first = $cart->addProductToCart(1, (int) $product->id(), 'same-token', 5);
    $retry = $cart->addProductToCart(1, (int) $product->id(), 'same-token', 5);

    $this->assertSame(['status' => 'insufficient_stock', 'available' => 1], $first);
    $this->assertSame(['status' => 'insufficient_stock', 'available' => 1], $retry);
    $this->assertSame([], $cart->getProductItems(1));
  }

  /**
   * Fase 0's bug, permanently regression-tested: two kits sharing one
   * product must see *each other's* reservations, not just their own.
   *
   * Before the fix, the reserved-units query was scoped to
   * `WHERE kit_id = :thisKit`, so Kit B's check never saw what Kit A had
   * already reserved of the product they share -- each could fill up to
   * the product's raw stock independently, jointly overselling it.
   */
  public function testCrossKitSharedProductReservationIsShared(): void {
    $product = $this->createProduct(stock: 3);
    $kit_a = $this->createKit([(int) $product->id() => 1]);
    $kit_b = $this->createKit([(int) $product->id() => 1]);
    $cart = $this->cartManager();

    $this->assertSame(['status' => 'added'], $cart->addKitToCart(1, (int) $kit_a->id(), 'a-1', 2));
    // Only 1 unit of the shared product is left; kit B can take exactly it.
    $this->assertSame(['status' => 'added'], $cart->addKitToCart(1, (int) $kit_b->id(), 'b-1', 1));
    // Nothing left now -- a second unit for kit B must be rejected.
    $this->assertSame(
      ['status' => 'insufficient_stock', 'available' => 0],
      $cart->addKitToCart(1, (int) $kit_b->id(), 'b-2', 1),
    );
  }

  /**
   * Fase 2's point, permanently regression-tested: a kit and a bare
   * product line for one of its products share the exact same claim on
   * stock, not two independent pools.
   */
  public function testKitAndBareProductShareStock(): void {
    $product = $this->createProduct(stock: 3);
    $kit = $this->createKit([(int) $product->id() => 1]);
    $cart = $this->cartManager();

    $this->assertSame(['status' => 'added'], $cart->addKitToCart(1, (int) $kit->id(), 'k-1', 2));
    $this->assertSame(['status' => 'added'], $cart->addProductToCart(2, (int) $product->id(), 'p-1', 1));
    // All 3 units are now claimed, 2 by the kit line, 1 by the bare line.
    $this->assertSame(
      ['status' => 'insufficient_stock', 'available' => 0],
      $cart->addProductToCart(2, (int) $product->id(), 'p-2', 1),
    );
    $this->assertSame([(int) $kit->id() => 2], $cart->getKitItems(1));
    $this->assertSame([(int) $product->id() => 1], $cart->getProductItems(2));
  }

  /**
   * getItemCount() sums both tables for one user.
   */
  public function testGetItemCountSumsBothTables(): void {
    $product_a = $this->createProduct(stock: 5);
    $product_b = $this->createProduct(stock: 5);
    $kit = $this->createKit([(int) $product_a->id() => 1]);
    $cart = $this->cartManager();

    $cart->addKitToCart(1, (int) $kit->id(), 'tok-1', 2);
    $cart->addProductToCart(1, (int) $product_b->id(), 'tok-2', 3);

    $this->assertSame(5, $cart->getItemCount(1));
  }

  /**
   * The actual primitive the whole design leans on: a `FOR UPDATE` lock
   * taken by one session genuinely blocks another session trying to read
   * the same row with its own `FOR UPDATE`, until the first commits.
   *
   * Two independent \Drupal\Core\Database\Connection objects, registered
   * against the exact same (test-prefixed) database -- not two PHP
   * references to one connection, but two separate sessions the database
   * server itself sees as different clients, which is what a real
   * concurrent request needs the lock to defend against. This is checked
   * directly, independent of CartManager, because it is the one guarantee
   * no sequential, single-connection test can exercise.
   */
  public function testForUpdateLockBlocksSecondSession(): void {
    $product = $this->createProduct(stock: 5);
    $product_id = (int) $product->id();

    $info = Database::getConnectionInfo('default')['default'];
    Database::addConnectionInfo('default', 'kit_catalog_test_probe', $info);
    $probe = Database::getConnection('kit_catalog_test_probe', 'default');
    // A short wait so a blocked query fails fast instead of hanging the
    // test if the lock (wrongly) isn't held.
    $probe->query('SET SESSION innodb_lock_wait_timeout = 1');

    $main = Database::getConnection('default', 'default');
    $main_transaction = $main->startTransaction();
    $main->query('SELECT stock FROM {product} WHERE id = :id FOR UPDATE', [':id' => $product_id]);

    $blocked = FALSE;
    try {
      $probe->query('SELECT stock FROM {product} WHERE id = :id FOR UPDATE', [':id' => $product_id]);
    }
    catch (\Exception) {
      // Any driver exception here is the lock wait timing out -- exactly
      // what proves the row was actually locked by the other session.
      $blocked = TRUE;
    }

    // Release the lock before asserting, so a failed assertion doesn't
    // leave the next test stuck behind an open transaction.
    unset($main_transaction);
    Database::closeConnection('kit_catalog_test_probe', 'default');

    $this->assertTrue($blocked, 'A second session was blocked by the first session\'s FOR UPDATE lock.');
  }

  /**
   * The cart manager service, by its public service name.
   */
  protected function cartManager(): \Drupal\kit_catalog\CartManager {
    return \Drupal::service('kit_catalog.cart_manager');
  }

}
