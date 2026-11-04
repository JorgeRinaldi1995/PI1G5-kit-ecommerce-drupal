<?php

declare(strict_types=1);

namespace Drupal\kit_catalog;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\TransactionOutOfOrderException;

/**
 * Race-safe, idempotent cart of kits and products, for logged-in users.
 *
 * A kit and a bare product are sold through the same door: `product.stock`.
 * A kit's lines resolve to products through `kit_product`; a direct product
 * line *is* a product. Both are derived/shared, never stored per sale, and
 * several people can click "Add to cart" on any of them at the same moment.
 * Treated naively (read the current stock, check it, then write a new cart
 * row) that is exactly the read-then-write race the `product_kit` README
 * warns about for the eventual checkout stock decrement -- it just shows up
 * one step earlier, at "add to cart", where it would let two different
 * carts both believe they reserved the last unit of a product, whether they
 * got there through the same kit, two different kits that happen to share
 * that product, or one kit and one bare-product line.
 *
 * `addKitToCart()` and `addProductToCart()` both close that race the same
 * way the README prescribes for the decrement, through the shared
 * `reserveAgainstProducts()`: lock every `product` row either one could
 * draw from first, in a stable order (`ORDER BY id`, so two adds that touch
 * an overlapping set of products can never deadlock each other by locking
 * in opposite orders). Every concurrent call that touches one of those
 * products -- another add for the same kit or product, a different kit
 * sharing one of its products, a bare-product add for a product a kit also
 * uses, or (later) a checkout trying to sell it -- has to wait for that
 * lock, so `reservedUnitsByProduct()`'s read afterwards can never be
 * invalidated by another in-flight request.
 *
 * It is also idempotent: the caller supplies a key that identifies *this
 * one submission* (not the kit/product, not the user -- a fresh value per
 * genuine click). That key is inserted into a uniquely-keyed ledger as the
 * very first step; a retried or duplicated POST for the same click
 * (double-submit, network retry) collides with its own earlier row instead
 * of reserving stock a second time, and is answered with whatever that
 * earlier row's outcome actually was -- a replayed success is reported as
 * a success, a replayed rejection as the same rejection, never silently
 * flipped to a false "added".
 *
 * What this does not do: decrement `product.stock`, or expire abandoned
 * reservations. There is no checkout yet (see this module's README), so
 * nothing here is final -- but letting stock sit reserved by abandoned
 * carts forever is a known limitation to revisit once checkout exists.
 */
class CartManager {

  public function __construct(
    protected readonly Connection $database,
    protected readonly TimeInterface $time,
  ) {}

  /**
   * Adds units of a kit to a user's cart, enforcing its products' stock.
   *
   * @param int $uid
   *   The owning, authenticated user. Callers are responsible for checking
   *   the user actually is authenticated; this method does not re-check.
   * @param int $kitId
   *   The kit entity ID.
   * @param string $idempotencyKey
   *   A value unique to this one submission. Supplying the same value again
   *   is how a caller marks "this might be the same click as before, not a
   *   new one"; a genuinely new "Add to cart" click must use a new value.
   * @param int $quantity
   *   How many units (kits) this submission asks to add.
   *
   * @return array{status: string, available?: int}
   *   See reserveAgainstProducts() for what 'status' can be.
   */
  public function addKitToCart(int $uid, int $kitId, string $idempotencyKey, int $quantity = 1): array {
    return $this->inTransaction(fn () => $this->doAddKit($uid, $kitId, $idempotencyKey, $quantity));
  }

  /**
   * Adds units of a product, bought on its own, to a user's cart.
   *
   * Draws from the exact same `product.stock` a kit containing this product
   * would, and is checked against the same, shared reservation total: see
   * the class docblock.
   *
   * @param int $uid
   *   The owning, authenticated user. Callers are responsible for checking
   *   the user actually is authenticated; this method does not re-check.
   * @param int $productId
   *   The product entity ID.
   * @param string $idempotencyKey
   *   See addKitToCart().
   * @param int $quantity
   *   How many units of the product this submission asks to add.
   *
   * @return array{status: string, available?: int}
   *   See reserveAgainstProducts() for what 'status' can be.
   */
  public function addProductToCart(int $uid, int $productId, string $idempotencyKey, int $quantity = 1): array {
    return $this->inTransaction(fn () => $this->doAddProduct($uid, $productId, $idempotencyKey, $quantity));
  }

  /**
   * Runs a unit of work in a database transaction, rolling back on failure.
   *
   * @template T
   * @param callable(): T $callback
   *
   * @return T
   */
  protected function inTransaction(callable $callback): mixed {
    $transaction = $this->database->startTransaction();
    try {
      $result = $callback();
      $transaction->commitOrRelease();
      return $result;
    }
    catch (\Throwable $e) {
      try {
        $transaction->rollBack();
      }
      catch (TransactionOutOfOrderException) {
        // Already unwound by an earlier rollback further down the stack.
      }
      throw $e;
    }
  }

  /**
   * The unit of work run inside addKitToCart()'s transaction.
   */
  protected function doAddKit(int $uid, int $kitId, string $idempotencyKey, int $quantity): array {
    if ($claimed = $this->claimIdempotencyKey($idempotencyKey, 'kit', $kitId, $uid)) {
      return $claimed;
    }

    // The kit's lines: how many units of each product one kit consumes.
    $units_per_product = $this->database->select('kit_product', 'kp')
      ->fields('kp', ['product', 'quantity'])
      ->condition('kit', $kitId)
      ->execute()
      ->fetchAllKeyed(0, 1);

    if (!$units_per_product) {
      return $this->recordOutcome($idempotencyKey, ['status' => 'unavailable']);
    }

    return $this->reserveAgainstProducts(
      $idempotencyKey,
      $units_per_product,
      $quantity,
      fn () => $this->upsertCartLine('kit_catalog_cart_item', 'kit_id', $uid, $kitId, $quantity),
    );
  }

  /**
   * The unit of work run inside addProductToCart()'s transaction.
   */
  protected function doAddProduct(int $uid, int $productId, string $idempotencyKey, int $quantity): array {
    if ($claimed = $this->claimIdempotencyKey($idempotencyKey, 'product', $productId, $uid)) {
      return $claimed;
    }

    // A bare product line consumes exactly one unit of itself per unit
    // added -- the same shape of "units per product" a kit's lines are, so
    // it can go through the same reservation logic unmodified.
    return $this->reserveAgainstProducts(
      $idempotencyKey,
      [$productId => 1],
      $quantity,
      fn () => $this->upsertCartLine('kit_catalog_cart_product_item', 'product_id', $uid, $productId, $quantity),
    );
  }

  /**
   * Locks the given products, checks their shared stock, then reserves.
   *
   * Shared by doAddKit() and doAddProduct(): both ultimately need "lock
   * these products, work out how many more of the thing I'm adding would
   * fit given what every other cart already holds of these same products,
   * then either reject or apply the reservation" -- a kit's "thing" just
   * consumes more than one product, at more than one unit each.
   *
   * @param string $idempotencyKey
   *   The claimed key (claimIdempotencyKey() must already have succeeded).
   * @param array<int, int> $unitsPerProduct
   *   Product ID => how many units of that product one unit of the thing
   *   being added consumes. A bare product add is `[$productId => 1]`; a
   *   kit add is its `kit_product` lines' product/quantity pairs.
   * @param int $quantity
   *   How many units of the thing being added (kits, or product units)
   *   this submission asks for.
   * @param callable(): void $apply
   *   Reserves $quantity, once it's confirmed to fit. Only called when it
   *   does.
   *
   * @return array{status: string, available?: int}
   *   'status' is one of:
   *   - 'added': the quantity was reserved for this user.
   *   - 'insufficient_stock': adding $quantity would exceed shared stock;
   *     'available' is how many more units of the thing being added could
   *     fit right now.
   *   - 'unavailable': one of $unitsPerProduct's products no longer exists.
   */
  protected function reserveAgainstProducts(string $idempotencyKey, array $unitsPerProduct, int $quantity, callable $apply): array {
    // Lock every affected product, lowest ID first. Anything else -- an add
    // for the same kit/product, a different kit sharing one of these
    // products, a bare-product add for one of them, a future checkout --
    // that needs one of these same rows has to queue behind this
    // transaction before it can read or write anything derived from them.
    $product_ids = array_keys($unitsPerProduct);
    sort($product_ids, SORT_NUMERIC);

    $stocks = $this->database->select('product', 'p')
      ->fields('p', ['id', 'stock'])
      ->condition('id', $product_ids, 'IN')
      ->orderBy('id')
      ->forUpdate()
      ->execute()
      ->fetchAllKeyed(0, 1);

    if (array_diff_key($unitsPerProduct, $stocks)) {
      // A product this add needs no longer exists.
      return $this->recordOutcome($idempotencyKey, ['status' => 'unavailable']);
    }

    // How many units of each of those products every cart already claims --
    // across every kit that uses it *and* every bare-product line, not just
    // the thing this call is adding. A different kit (or a direct line)
    // sharing one of these products pulls from the exact same physical
    // stock, so ignoring it would let two claims jointly oversell a product
    // they share. Safe to read plainly here: every writer that could change
    // any of these numbers has to take the lock above on the same product
    // rows first, and this transaction holds it now.
    $reserved_by_product = $this->reservedUnitsByProduct($product_ids);

    // How many more units of the thing being added could still fit, net of
    // every one of those existing claims. Deliberately not the same number
    // a storefront card would show (`Kit::getStock()`/`Product::getStock()`
    // equivalent, stock with no awareness of carts at all) -- those can
    // show more than this, because other visitors' carts already hold some
    // of it. That gap is expected: the storefront number is a catalogue
    // fact, this one is "what can *you* still add", and the two only have
    // to agree once nothing is reserved.
    $addable = NULL;
    foreach ($unitsPerProduct as $product_id => $units_needed) {
      $product_stock = max(0, (int) $stocks[$product_id]);
      $already_reserved = (int) ($reserved_by_product[$product_id] ?? 0);
      $available_units = max(0, $product_stock - $already_reserved);
      $possible = intdiv($available_units, (int) $units_needed);
      $addable = $addable === NULL ? $possible : min($addable, $possible);
    }
    $addable ??= 0;

    if ($quantity > $addable) {
      return $this->recordOutcome($idempotencyKey, [
        'status' => 'insufficient_stock',
        'available' => $addable,
      ]);
    }

    $apply();

    return $this->recordOutcome($idempotencyKey, ['status' => 'added']);
  }

  /**
   * Sums how many units of each given product every cart already reserves.
   *
   * Two sources draw from the same `product.stock`, so both are counted:
   * kit lines (`kit_catalog_cart_item`, weighted by that kit's per-product
   * quantity from `kit_product`, across *every* kit using the product) and
   * bare product lines (`kit_catalog_cart_product_item`). Two queries
   * merged in PHP rather than one UNION: the aggregation itself -- the part
   * that most needs to stay obviously correct -- reads as a plain sum per
   * source instead of a type-conditional join.
   *
   * @param int[] $productIds
   *
   * @return array<int, int>
   *   Reserved units, keyed by product ID. A product with no reservation
   *   at all from either source is simply absent, not zero.
   */
  protected function reservedUnitsByProduct(array $productIds): array {
    $from_kits = $this->database->select('kit_product', 'kp');
    $from_kits->innerJoin('kit_catalog_cart_item', 'ci', 'ci.kit_id = kp.kit');
    $from_kits->condition('kp.product', $productIds, 'IN');
    $from_kits->addExpression('SUM(ci.quantity * kp.quantity)', 'reserved_units');
    $from_kits->fields('kp', ['product']);
    $from_kits->groupBy('kp.product');
    $totals = $from_kits->execute()->fetchAllKeyed(0, 1);

    $from_products = $this->database->select('kit_catalog_cart_product_item', 'cpi');
    $from_products->condition('cpi.product_id', $productIds, 'IN');
    $from_products->addExpression('SUM(cpi.quantity)', 'reserved_units');
    $from_products->fields('cpi', ['product_id']);
    $from_products->groupBy('cpi.product_id');
    foreach ($from_products->execute()->fetchAllKeyed(0, 1) as $product_id => $units) {
      $totals[$product_id] = ($totals[$product_id] ?? 0) + (int) $units;
    }

    return $totals;
  }

  /**
   * Increments (or creates) one user's reservation row in a cart table.
   *
   * Not an upsert query: a plain select-then-insert-or-update. Still
   * correct under concurrency, because every writer that could race this
   * one for the same product(s) is serialized behind the product lock
   * reserveAgainstProducts() takes first.
   */
  protected function upsertCartLine(string $table, string $itemIdColumn, int $uid, int $itemId, int $quantity): void {
    $existing_id = $this->database->select($table, 't')
      ->fields('t', ['id'])
      ->condition('uid', $uid)
      ->condition($itemIdColumn, $itemId)
      ->execute()
      ->fetchField();

    if ($existing_id) {
      $this->database->update($table)
        ->expression('quantity', 'quantity + :qty', [':qty' => $quantity])
        ->fields(['changed' => $this->time->getRequestTime()])
        ->condition('id', $existing_id)
        ->execute();
    }
    else {
      $this->database->insert($table)
        ->fields([
          'uid' => $uid,
          $itemIdColumn => $itemId,
          'quantity' => $quantity,
          'changed' => $this->time->getRequestTime(),
        ])
        ->execute();
    }
  }

  /**
   * Claims an idempotency key, or hands back what it already resolved to.
   *
   * The unique key makes this atomic under a race: if two requests carrying
   * the same key run concurrently, exactly one of the inserts below wins;
   * the loser blocks on the key until the winner's transaction resolves,
   * then either inserts too (if it rolled back) or, almost always, lands in
   * the catch once the winner has committed -- by which point the winner's
   * row has already been updated with its real outcome (recordOutcome()),
   * so reading it back there always reflects what actually happened, never
   * a stale placeholder.
   *
   * @return array{status: string, available?: int}|null
   *   NULL if this key was successfully claimed and the caller should
   *   proceed; otherwise the outcome already recorded for it, to return
   *   as-is without doing anything else.
   */
  protected function claimIdempotencyKey(string $idempotencyKey, string $itemType, int $itemId, int $uid): ?array {
    try {
      $this->database->insert('kit_catalog_add_request')
        ->fields([
          'idempotency_key' => $idempotencyKey,
          'uid' => $uid,
          'item_type' => $itemType,
          'item_id' => $itemId,
          'status' => '',
          'created' => $this->time->getRequestTime(),
        ])
        ->execute();
      return NULL;
    }
    catch (IntegrityConstraintViolationException) {
      return $this->loadRequestOutcome($idempotencyKey);
    }
  }

  /**
   * Stores this request's outcome on its ledger row, then returns it.
   *
   * So that a later retry of the same $idempotencyKey -- caught by
   * claimIdempotencyKey()'s unique-key violation -- can be told the truth
   * about what actually happened, instead of a generic "already handled".
   */
  protected function recordOutcome(string $idempotencyKey, array $result): array {
    $this->database->update('kit_catalog_add_request')
      ->fields([
        'status' => $result['status'],
        'available' => $result['available'] ?? NULL,
      ])
      ->condition('idempotency_key', $idempotencyKey)
      ->execute();
    return $result;
  }

  /**
   * Reads back the outcome an earlier submission recorded for this key.
   *
   * Only reached from claimIdempotencyKey()'s unique-key violation: the row
   * this reads was inserted (and, in all but a vanishingly unlucky
   * interleaving, already updated with its outcome by recordOutcome())
   * before this call ever started.
   */
  protected function loadRequestOutcome(string $idempotencyKey): array {
    $row = $this->database->select('kit_catalog_add_request', 'r')
      ->fields('r', ['status', 'available'])
      ->condition('idempotency_key', $idempotencyKey)
      ->execute()
      ->fetchObject();

    if (!$row || $row->status === '') {
      // The owning transaction hasn't reached recordOutcome() yet -- only
      // possible if this is itself racing that transaction rather than
      // arriving after it, which the product lock should already have
      // ruled out by the time an INSERT can even fail here.
      return ['status' => 'unavailable'];
    }

    $result = ['status' => $row->status];
    if ($row->available !== NULL) {
      $result['available'] = (int) $row->available;
    }
    return $result;
  }

  /**
   * Gets a user's kit lines.
   *
   * @return array<int, int>
   *   Quantity keyed by kit ID.
   */
  public function getKitItems(int $uid): array {
    // array_map('intval', ...): the database layer returns both the key and
    // the value as strings (PDO's default for numeric columns), which
    // fetchAllKeyed() doesn't correct -- harmless to a caller that just
    // sums them (PHP coerces for arithmetic), but a caller passing one to a
    // strict_types-declared `int` parameter (as CartPageController does)
    // would hit a TypeError without this.
    return array_map('intval', $this->database->select('kit_catalog_cart_item', 'c')
      ->fields('c', ['kit_id', 'quantity'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAllKeyed());
  }

  /**
   * Gets a user's bare product lines.
   *
   * @return array<int, int>
   *   Quantity keyed by product ID.
   */
  public function getProductItems(int $uid): array {
    // See getKitItems()'s comment on array_map('intval', ...).
    return array_map('intval', $this->database->select('kit_catalog_cart_product_item', 'c')
      ->fields('c', ['product_id', 'quantity'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAllKeyed());
  }

  /**
   * Gets the total number of units in a user's cart, kit and product lines.
   */
  public function getItemCount(int $uid): int {
    return array_sum($this->getKitItems($uid)) + array_sum($this->getProductItems($uid));
  }

}
