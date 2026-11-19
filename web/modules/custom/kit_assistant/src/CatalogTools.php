<?php

declare(strict_types=1);

namespace Drupal\kit_assistant;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\kit_catalog\CartManager;
use Drupal\product_kit\Entity\KitInterface;
use Drupal\product_kit\PriceFormatter;

/**
 * The tools Cleiton (the chat assistant) can call, and their schemas.
 *
 * Every tool reads or writes through the same services the rest of the
 * storefront uses -- \Drupal\product_kit\Entity\Kit/Product for derived
 * price/stock, \Drupal\kit_catalog\CartManager for cart writes -- so the
 * assistant can never see or do anything a logged-in visitor browsing the
 * site by hand couldn't already see or do themselves. It never queries the
 * database directly and never invents a kit/product: every ID it acts on
 * is looked up and validated here first, never trusted from the model.
 */
class CatalogTools {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly PriceFormatter $priceFormatter,
    protected readonly CartManager $cartManager,
  ) {}

  /**
   * OpenAI function-calling tool definitions for every tool below.
   */
  public function getToolDefinitions(): array {
    return [
      $this->toolDef(
        'list_available_kits',
        'Lista todos os kits profissionalizantes à venda na loja agora, com nome, descrição, preço e estoque. Use sempre esta ferramenta antes de recomendar qualquer kit -- nunca invente kits ou preços que não vieram daqui.',
        ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
      ),
      $this->toolDef(
        'get_kit_details',
        'Retorna a composição completa de um kit (quais produtos e quantidades) pelo ID do kit, incluindo preço e estoque. Use depois de list_available_kits para detalhar um kit específico que o cliente demonstrou interesse.',
        [
          'type' => 'object',
          'properties' => [
            'kit_id' => ['type' => 'integer', 'description' => 'ID do kit, obtido em list_available_kits.'],
          ],
          'required' => ['kit_id'],
        ],
      ),
      $this->toolDef(
        'add_kit_to_cart',
        'Adiciona um kit ao carrinho de compras do cliente. Só funciona para clientes autenticados. Confirme com o cliente qual kit e quantidade antes de chamar.',
        [
          'type' => 'object',
          'properties' => [
            'kit_id' => ['type' => 'integer', 'description' => 'ID do kit a adicionar.'],
            'quantity' => ['type' => 'integer', 'description' => 'Quantidade de kits, no mínimo 1.', 'minimum' => 1],
          ],
          'required' => ['kit_id'],
        ],
      ),
      $this->toolDef(
        'add_product_to_cart',
        'Adiciona um produto avulso (fora de um kit) ao carrinho do cliente. Só funciona para clientes autenticados. Use apenas quando o cliente pedir um item específico avulso, não um kit inteiro.',
        [
          'type' => 'object',
          'properties' => [
            'product_id' => ['type' => 'integer', 'description' => 'ID do produto a adicionar.'],
            'quantity' => ['type' => 'integer', 'description' => 'Quantidade de unidades, no mínimo 1.', 'minimum' => 1],
          ],
          'required' => ['product_id'],
        ],
      ),
      $this->toolDef(
        'get_cart_summary',
        'Retorna o conteúdo atual do carrinho do cliente autenticado (kits e produtos, quantidades, total). Use para confirmar o que já está no carrinho antes ou depois de adicionar algo.',
        ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
      ),
    ];
  }

  /**
   * Builds one tool definition in OpenAI's function-calling schema.
   */
  protected function toolDef(string $name, string $description, array $parameters): array {
    return [
      'type' => 'function',
      'function' => [
        'name' => $name,
        'description' => $description,
        'parameters' => $parameters,
      ],
    ];
  }

  /**
   * Runs one tool call by name, with uid NULL meaning "anonymous visitor".
   *
   * @return array
   *   JSON-serializable result. Always an array, never throws -- a bad
   *   argument or a cart rule rejecting the request is a normal tool
   *   result ({"error": "..."} ), not an exception, so the model can read
   *   it and explain it to the visitor instead of the turn failing.
   */
  public function execute(string $name, array $arguments, ?int $uid): array {
    return match ($name) {
      'list_available_kits' => $this->listAvailableKits(),
      'get_kit_details' => $this->getKitDetails($this->intArg($arguments, 'kit_id')),
      'add_kit_to_cart' => $this->addKitToCart($uid, $this->intArg($arguments, 'kit_id'), $this->intArg($arguments, 'quantity', 1)),
      'add_product_to_cart' => $this->addProductToCart($uid, $this->intArg($arguments, 'product_id'), $this->intArg($arguments, 'quantity', 1)),
      'get_cart_summary' => $this->getCartSummary($uid),
      default => ['error' => 'unknown_tool'],
    };
  }

  /**
   * Reads an integer argument the model supplied, defaulting if absent/invalid.
   */
  protected function intArg(array $arguments, string $key, int $default = 0): int {
    return isset($arguments[$key]) && is_numeric($arguments[$key]) ? (int) $arguments[$key] : $default;
  }

  /**
   * Lists every enabled, sellable kit -- grounding data for recommendations.
   */
  protected function listAvailableKits(): array {
    $storage = $this->entityTypeManager->getStorage('kit');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->sort('name')
      ->execute();

    if (!$ids) {
      return ['kits' => []];
    }

    /** @var \Drupal\product_kit\Entity\KitInterface[] $kits */
    $kits = $storage->loadMultiple($ids);
    return ['kits' => array_values(array_map($this->summarizeKit(...), $kits))];
  }

  /**
   * The same summary shape list_available_kits and get_kit_details both return.
   */
  protected function summarizeKit(KitInterface $kit): array {
    return [
      'id' => (int) $kit->id(),
      'name' => $kit->label(),
      'description' => $this->plainText($kit->get('description')->value ?? ''),
      'price' => $this->priceFormatter->format($kit->getPrice(), FALSE),
      'stock' => $kit->getStock(),
      'available' => $kit->canBeSold(),
    ];
  }

  /**
   * Loads one kit's full product/quantity breakdown.
   */
  protected function getKitDetails(int $kitId): array {
    if ($kitId <= 0) {
      return ['error' => 'invalid_argument'];
    }

    /** @var \Drupal\product_kit\Entity\KitInterface|null $kit */
    $kit = $this->entityTypeManager->getStorage('kit')->load($kitId);
    if (!$kit || !$kit->isPublished()) {
      return ['error' => 'not_found'];
    }

    $lines = [];
    foreach ($kit->getLines() as $line) {
      $product = $line->getProduct();
      if (!$product) {
        continue;
      }
      $lines[] = [
        'product_id' => (int) $product->id(),
        'product_name' => $product->label(),
        'quantity' => $line->getQuantity(),
        'unit_price' => $this->priceFormatter->format($product->getPrice(), FALSE),
      ];
    }

    return [
      'kit' => $this->summarizeKit($kit),
      'lines' => $lines,
    ];
  }

  /**
   * Reserves a kit in an authenticated visitor's cart, via CartManager.
   */
  protected function addKitToCart(?int $uid, int $kitId, int $quantity): array {
    if ($uid === NULL) {
      return ['error' => 'login_required'];
    }
    if ($kitId <= 0) {
      return ['error' => 'invalid_argument'];
    }
    $quantity = max(1, $quantity);

    /** @var \Drupal\product_kit\Entity\KitInterface|null $kit */
    $kit = $this->entityTypeManager->getStorage('kit')->load($kitId);
    if (!$kit || !$kit->isPublished()) {
      return ['error' => 'not_found'];
    }

    $idempotencyKey = 'kit_assistant:' . $uid . ':' . bin2hex(random_bytes(8));
    $result = $this->cartManager->addKitToCart($uid, $kitId, $idempotencyKey, $quantity);
    return $this->cartResultToToolResult($result, $uid, kitName: $kit->label());
  }

  /**
   * Reserves a bare product in an authenticated visitor's cart.
   */
  protected function addProductToCart(?int $uid, int $productId, int $quantity): array {
    if ($uid === NULL) {
      return ['error' => 'login_required'];
    }
    if ($productId <= 0) {
      return ['error' => 'invalid_argument'];
    }
    $quantity = max(1, $quantity);

    /** @var \Drupal\product_kit\Entity\ProductInterface|null $product */
    $product = $this->entityTypeManager->getStorage('product')->load($productId);
    if (!$product || !$product->isPublished()) {
      return ['error' => 'not_found'];
    }

    $idempotencyKey = 'kit_assistant:' . $uid . ':' . bin2hex(random_bytes(8));
    $result = $this->cartManager->addProductToCart($uid, $productId, $idempotencyKey, $quantity);
    return $this->cartResultToToolResult($result, $uid, productName: $product->label());
  }

  /**
   * Shapes a CartManager result (plus the entity's name) as a tool result.
   */
  protected function cartResultToToolResult(array $result, int $uid, ?string $kitName = NULL, ?string $productName = NULL): array {
    $out = [
      'status' => $result['status'],
      'cart_item_count' => $this->cartManager->getItemCount($uid),
    ];
    if ($kitName !== NULL) {
      $out['kit_name'] = $kitName;
    }
    if ($productName !== NULL) {
      $out['product_name'] = $productName;
    }
    if (isset($result['available'])) {
      $out['max_addable_now'] = $result['available'];
    }
    return $out;
  }

  /**
   * Reads an authenticated visitor's current cart, with names/totals resolved.
   */
  protected function getCartSummary(?int $uid): array {
    if ($uid === NULL) {
      return ['error' => 'login_required'];
    }

    $kitStorage = $this->entityTypeManager->getStorage('kit');
    $productStorage = $this->entityTypeManager->getStorage('product');

    $lines = [];
    $total = 0;

    foreach ($this->cartManager->getKitItems($uid) as $kitId => $quantity) {
      /** @var \Drupal\product_kit\Entity\KitInterface|null $kit */
      $kit = $kitStorage->load($kitId);
      if (!$kit) {
        continue;
      }
      $subtotal = $kit->getPrice() * $quantity;
      $total += $subtotal;
      $lines[] = [
        'type' => 'kit',
        'name' => $kit->label(),
        'quantity' => $quantity,
        'unit_price' => $this->priceFormatter->format($kit->getPrice(), FALSE),
        'subtotal' => $this->priceFormatter->format($subtotal, FALSE),
      ];
    }

    foreach ($this->cartManager->getProductItems($uid) as $productId => $quantity) {
      /** @var \Drupal\product_kit\Entity\ProductInterface|null $product */
      $product = $productStorage->load($productId);
      if (!$product) {
        continue;
      }
      $subtotal = $product->getPrice() * $quantity;
      $total += $subtotal;
      $lines[] = [
        'type' => 'product',
        'name' => $product->label(),
        'quantity' => $quantity,
        'unit_price' => $this->priceFormatter->format($product->getPrice(), FALSE),
        'subtotal' => $this->priceFormatter->format($subtotal, FALSE),
      ];
    }

    return [
      'lines' => $lines,
      'total' => $this->priceFormatter->format($total, FALSE),
      'item_count' => $this->cartManager->getItemCount($uid),
    ];
  }

  /**
   * Strips markup from a stored description before handing it to the model.
   */
  protected function plainText(string $html): string {
    return trim(Html::decodeEntities(strip_tags($html)));
  }

}
