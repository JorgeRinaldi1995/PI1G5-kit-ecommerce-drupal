<?php

declare(strict_types=1);

namespace Drupal\product_kit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\TransactionOutOfOrderException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Error;
use Drupal\product_kit\Entity\KitInterface;
use Drupal\product_kit\Entity\KitProductInterface;
use Drupal\product_kit\Entity\ProductInterface;
use Psr\Log\LoggerInterface;

/**
 * Owns the many-to-many relationship between products and kits.
 *
 * The rules it keeps, in one place, for every entry point (forms, hooks, Drush):
 *
 *  - a kit has at least one product — a product does *not* need to be in any
 *    kit, this requirement runs one way only;
 *  - a product appears once per kit (KitProduct::quantity carries multiples);
 *  - a line asks for at most as many units as the product has in stock (one
 *    unit is always allowed, so a product that is out of stock can still be
 *    placed in a kit — the kit's derived stock is then simply 0).
 *
 * A product with no kit is a valid state: it just sits outside every bundle
 * until it is added to one. A kit is never left with zero products, whichever
 * path removes the last one — the kit form, the product form, deleting a
 * product, or deleting a single line.
 *
 * "Rows" are the intermediate form the kit and product forms work with: a
 * list of arrays with a "target" (the product ID for a kit, the kit ID for a
 * product) and a "quantity".
 */
class KitCompositionManager {

  use StringTranslationTrait;

  /**
   * Depth of the operations that have already checked the integrity rules.
   *
   * While above zero the delete guards let deletions through; the guards exist
   * for callers that did not check (Drush, API), not for this class's own
   * cascades and syncs.
   */
  protected int $suspended = 0;

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly Connection $database,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * Gets the most units of a product a single kit line may ask for.
   *
   * Never below one: a product with no stock can still be placed in a kit.
   */
  public function getMaxQuantity(ProductInterface $product): int {
    return max(1, $product->getStock());
  }

  /**
   * Validates and normalizes the rows of a kit's product list.
   *
   * Rows that repeat a product are merged into one whose quantity is the sum,
   * so the same product can be added as many times as its stock allows.
   *
   * @param array<int, array{target: int|string|null, quantity: int}> $rows
   *   The submitted rows, keyed by their position in the form.
   *
   * @return array{rows: array<int, array{target: int, quantity: int, index: int, merged: bool}>, errors: list<array{index: int, element: string, message: \Drupal\Core\StringTranslation\TranslatableMarkup}>}
   *   The normalized rows (keyed by product ID) and the problems found, each
   *   pointing at a row position and the element in it ("product" or
   *   "quantity"). An empty list of rows is not itself an error.
   */
  public function prepareKitRows(array $rows): array {
    return $this->prepareRows($rows, 'product', NULL);
  }

  /**
   * Validates and normalizes the rows of a product's kit list.
   *
   * @param \Drupal\product_kit\Entity\ProductInterface $product
   *   The product, with the stock it is about to be saved with.
   * @param array<int, array{target: int|string|null, quantity: int}> $rows
   *   The submitted rows, keyed by their position in the form.
   *
   * @return array{rows: array<int, array{target: int, quantity: int, index: int, merged: bool}>, errors: list<array{index: int, element: string, message: \Drupal\Core\StringTranslation\TranslatableMarkup}>}
   *   Like ::prepareKitRows(), keyed by kit ID; the element is "kit" or
   *   "quantity".
   */
  public function prepareProductRows(ProductInterface $product, array $rows): array {
    return $this->prepareRows($rows, 'kit', $product);
  }

  /**
   * Shared implementation of ::prepareKitRows() and ::prepareProductRows().
   */
  protected function prepareRows(array $rows, string $target_type, ?ProductInterface $owner_product): array {
    $errors = [];

    $merged = [];
    foreach ($rows as $index => $row) {
      if (empty($row['target'])) {
        continue;
      }
      $quantity = (int) $row['quantity'];
      if ($quantity < 1) {
        $errors[] = ['index' => $index, 'element' => 'quantity', 'message' => $this->t('Quantity must be at least 1.')];
        continue;
      }

      $id = (int) $row['target'];
      if (isset($merged[$id])) {
        $merged[$id]['quantity'] += $quantity;
        $merged[$id]['merged'] = TRUE;
      }
      else {
        $merged[$id] = ['target' => $id, 'quantity' => $quantity, 'index' => $index, 'merged' => FALSE];
      }
    }

    $targets = $merged ? $this->entityTypeManager->getStorage($target_type)->loadMultiple(array_keys($merged)) : [];
    foreach ($merged as $id => $row) {
      $target = $targets[$id] ?? NULL;
      if (!$target) {
        $errors[] = ['index' => $row['index'], 'element' => $target_type, 'message' => $this->t('The selected @type does not exist.', ['@type' => $target_type])];
        unset($merged[$id]);
        continue;
      }

      $product = $owner_product ?? $target;
      $max = $this->getMaxQuantity($product);
      if ($row['quantity'] > $max) {
        $errors[] = [
          'index' => $row['index'],
          'element' => 'quantity',
          'message' => $row['merged']
            ? $this->t('@product has @stock unit(s) in stock, but this kit asks for @quantity of it across several rows.', [
              '@product' => $product->label(),
              '@stock' => $product->getStock(),
              '@quantity' => $row['quantity'],
            ])
            : $this->t('@product has only @stock unit(s) in stock; a kit cannot ask for @quantity.', [
              '@product' => $product->label(),
              '@stock' => $product->getStock(),
              '@quantity' => $row['quantity'],
            ]),
        ];
      }
    }

    return ['rows' => $merged, 'errors' => $errors];
  }

  /**
   * Gets the kits that would be left without any product.
   *
   * @param \Drupal\product_kit\Entity\ProductInterface $product
   *   The product leaving kits.
   * @param int[]|null $keep_kit_ids
   *   The kits the product stays in. NULL means none: the product is going
   *   away entirely.
   *
   * @return \Drupal\product_kit\Entity\KitInterface[]
   *   Keyed by kit ID.
   */
  public function getEmptiedKits(ProductInterface $product, ?array $keep_kit_ids = NULL): array {
    $empty_ids = [];
    foreach ($product->getLines() as $line) {
      $kit_id = (int) $line->get('kit')->target_id;
      if ($kit_id && !in_array($kit_id, $keep_kit_ids ?? [], TRUE)
        && !$this->countLines('kit', $kit_id, 'product', $product->id())) {
        $empty_ids[$kit_id] = $kit_id;
      }
    }

    /** @var \Drupal\product_kit\Entity\KitInterface[] $kits */
    $kits = $empty_ids ? $this->entityTypeManager->getStorage('kit')->loadMultiple($empty_ids) : [];
    return $kits;
  }

  /**
   * Makes a kit's lines match the given rows.
   *
   * Call it inside ::transactional(), after the rows passed ::prepareKitRows().
   * An empty $rows array is fine: it leaves the kit with no lines.
   *
   * @param \Drupal\product_kit\Entity\KitInterface $kit
   *   The saved kit.
   * @param array<int, array{target: int, quantity: int}> $rows
   *   The prepared rows, keyed by product ID.
   */
  public function syncKitLines(KitInterface $kit, array $rows): void {
    $this->syncLines('kit', $kit->id(), 'product', $rows);
  }

  /**
   * Makes a product's lines match the given rows.
   *
   * Call it inside ::transactional(), after the rows passed
   * ::prepareProductRows(). An empty $rows array is fine: it leaves the
   * product in no kit.
   *
   * @param \Drupal\product_kit\Entity\ProductInterface $product
   *   The saved product.
   * @param array<int, array{target: int, quantity: int}> $rows
   *   The prepared rows, keyed by kit ID.
   */
  public function syncProductLines(ProductInterface $product, array $rows): void {
    $this->syncLines('product', $product->id(), 'kit', $rows);
  }

  /**
   * Shared implementation of the two sync methods.
   *
   * New and changed lines are written before the dropped ones are removed, so
   * a kit never dips below one line along the way. The per-line delete guard
   * is suspended throughout: the caller (KitForm/ProductForm) has already
   * validated the end result, and the guard would otherwise trip on an
   * intermediate state (e.g. a kit's last old line being removed a moment
   * before its replacement is added).
   */
  protected function syncLines(string $owner_field, int|string $owner_id, string $target_field, array $rows): void {
    $storage = $this->entityTypeManager->getStorage('kit_product');

    $this->suspended++;
    try {
      $existing = [];
      $stale = [];
      foreach ($this->loadLines($owner_field, $owner_id) as $line) {
        $target_id = (int) $line->get($target_field)->target_id;
        if (isset($existing[$target_id])) {
          // A leftover duplicate; the first line for the target is the one kept.
          $stale[] = $line;
        }
        else {
          $existing[$target_id] = $line;
        }
      }

      foreach ($rows as $target_id => $row) {
        $line = $existing[$target_id] ?? NULL;
        if ($line) {
          if ($line->getQuantity() !== $row['quantity']) {
            $line->set('quantity', $row['quantity'])->save();
          }
          unset($existing[$target_id]);
          continue;
        }

        $storage->create([
          $owner_field => $owner_id,
          $target_field => $target_id,
          'quantity' => $row['quantity'],
        ])->save();
      }

      foreach ([...$existing, ...$stale] as $line) {
        $line->delete();
      }
    }
    finally {
      $this->suspended--;
    }
  }

  /**
   * Deletes a product, optionally with the kits that only it made up.
   *
   * @return int
   *   How many kits were deleted along with the product.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   If kits would be left without a product and $delete_kits is FALSE.
   */
  public function deleteProduct(ProductInterface $product, bool $delete_kits = FALSE): int {
    return $this->transactional(function () use ($product, $delete_kits): int {
      $emptied = $this->getEmptiedKits($product);
      if ($emptied && !$delete_kits) {
        throw new EntityStorageException($this->emptiedMessage($product, $emptied));
      }

      $this->suspended++;
      try {
        $product->delete();
        foreach ($emptied as $kit) {
          $kit->delete();
        }
      }
      finally {
        $this->suspended--;
      }
      return count($emptied);
    });
  }

  /**
   * Cascades a kit's deletion to its lines.
   *
   * Called from hook_ENTITY_TYPE_predelete(). The products that were only in
   * this kit are simply left with one fewer kit (possibly none); a product
   * does not need to be in any kit, so that is not guarded.
   */
  public function preDeleteKit(KitInterface $kit): void {
    $this->deleteLines('kit', $kit->id());
  }

  /**
   * Guards a product deletion and cascades it to the product's lines.
   *
   * Refuses a deletion that would leave a kit without a product, unless the
   * caller already confirmed and is bypassing the guard (see ::deleteProduct()).
   * Called from hook_ENTITY_TYPE_predelete().
   */
  public function preDeleteProduct(ProductInterface $product): void {
    if (!$this->suspended && ($emptied = $this->getEmptiedKits($product))) {
      throw new EntityStorageException($this->emptiedMessage($product, $emptied));
    }
    $this->deleteLines('product', $product->id());
  }

  /**
   * Guards the deletion of a single line.
   *
   * A line cannot go if it is the last product of its kit. Called from
   * hook_ENTITY_TYPE_predelete().
   */
  public function preDeleteLine(KitProductInterface $line): void {
    if ($this->suspended) {
      return;
    }
    if ($reason = $this->getLineDeletionBlocker($line)) {
      throw new EntityStorageException((string) $reason);
    }
  }

  /**
   * Explains why a line cannot be deleted, or returns NULL if it can.
   */
  public function getLineDeletionBlocker(KitProductInterface $line): ?string {
    $kit_id = $line->get('kit')->target_id;
    if ($kit_id && !$this->countLines('kit', $kit_id, 'id', $line->id())) {
      return (string) $this->t('This is the only product in the kit %kit, and a kit must contain at least one product. Add another product to the kit first, or delete the whole kit.', [
        '%kit' => $line->getKit()?->label() ?? $kit_id,
      ]);
    }
    return NULL;
  }

  /**
   * Finds products that are in no kit.
   *
   * A valid, unremarkable state; this is informational (`drush
   * product-kit:audit`), not a guard.
   *
   * @return int[]
   *   Product IDs.
   */
  public function findOrphanedProductIds(): array {
    $in_kits = $this->database->select('kit_product', 'kp')
      ->fields('kp', ['product'])
      ->distinct()
      ->execute()
      ->fetchCol();

    $query = $this->entityTypeManager->getStorage('product')->getQuery()->accessCheck(FALSE)->sort('id');
    if ($in_kits) {
      $query->condition('id', $in_kits, 'NOT IN');
    }
    return array_values($query->execute());
  }

  /**
   * Finds kits that have no product.
   *
   * A valid, unremarkable state (a kit with no lines has price and stock 0);
   * this is informational (`drush product-kit:audit`), not a guard.
   *
   * @return int[]
   *   Kit IDs.
   */
  public function findEmptyKitIds(): array {
    $with_products = $this->database->select('kit_product', 'kp')
      ->fields('kp', ['kit'])
      ->distinct()
      ->execute()
      ->fetchCol();

    $query = $this->entityTypeManager->getStorage('kit')->getQuery()->accessCheck(FALSE)->sort('id');
    if ($with_products) {
      $query->condition('id', $with_products, 'NOT IN');
    }
    return array_values($query->execute());
  }

  /**
   * Runs a callback in a database transaction.
   *
   * On failure everything is rolled back and the entity caches are dropped:
   * entities loaded or saved inside the transaction were cached, but the rows
   * they describe no longer exist.
   *
   * @template T
   * @param callable(): T $callback
   *   The unit of work.
   *
   * @return T
   *   Whatever the callback returns.
   */
  public function transactional(callable $callback): mixed {
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
      catch (TransactionOutOfOrderException $rollback_error) {
        Error::logException($this->logger, $rollback_error);
      }
      foreach (['product', 'kit', 'kit_product'] as $entity_type_id) {
        $this->entityTypeManager->getStorage($entity_type_id)->resetCache();
      }
      throw $e;
    }
  }

  /**
   * Loads the lines where a field references the given entity, oldest first.
   *
   * @return \Drupal\product_kit\Entity\KitProductInterface[]
   *   Keyed by line ID.
   */
  public function loadLines(string $field, int|string|null $id): array {
    if (!$id) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('kit_product');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition($field, $id)
      ->sort('id')
      ->execute();

    /** @var \Drupal\product_kit\Entity\KitProductInterface[] $lines */
    $lines = $ids ? $storage->loadMultiple($ids) : [];
    return $lines;
  }

  /**
   * Deletes every line that references the given entity.
   *
   * The per-line delete guard is suspended: this is always cascading the
   * removal of the kit or product the lines belong to, so "is this the last
   * product of its kit" no longer matters by the time this runs.
   */
  protected function deleteLines(string $field, int|string|null $id): void {
    $this->suspended++;
    try {
      foreach ($this->loadLines($field, $id) as $line) {
        $line->delete();
      }
    }
    finally {
      $this->suspended--;
    }
  }

  /**
   * Counts lines where $field references $id, leaving out one condition.
   *
   * @param string $field
   *   The referencing field ("kit" or "product").
   * @param int|string $id
   *   The referenced entity ID.
   * @param string $except_field
   *   A field to exclude by ("kit", "product" or "id").
   * @param int|string|null $except_id
   *   The value of $except_field to leave out.
   */
  protected function countLines(string $field, int|string $id, string $except_field, int|string|null $except_id): int {
    $query = $this->entityTypeManager->getStorage('kit_product')->getQuery()
      ->accessCheck(FALSE)
      ->condition($field, $id);
    if ($except_id !== NULL) {
      $query->condition($except_field, $except_id, '<>');
    }
    return (int) $query->count()->execute();
  }

  /**
   * Builds the error for a product whose deletion would empty kits.
   *
   * @param \Drupal\product_kit\Entity\KitInterface[] $emptied
   *   The kits that would be left without a product.
   */
  protected function emptiedMessage(ProductInterface $product, array $emptied): string {
    return (string) $this->t('Product %product cannot be deleted: these kits would be left without a product: @kits. Add another product to them first, or delete them together with the product.', [
      '%product' => $product->label(),
      '@kits' => implode(', ', array_map(static fn (KitInterface $k) => (string) $k->label(), $emptied)),
    ]);
  }

}
