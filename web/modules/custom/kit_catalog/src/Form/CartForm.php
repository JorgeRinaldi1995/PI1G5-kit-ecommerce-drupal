<?php

declare(strict_types=1);

namespace Drupal\kit_catalog\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\PrependCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\kit_catalog\CartManager;
use Drupal\product_kit\Entity\KitInterface;
use Drupal\product_kit\Entity\ProductInterface;
use Drupal\product_kit\PriceFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The cart page (kit_catalog.cart_page): a table of lines, each removable.
 *
 * Replaces what used to be a plain read-only render array
 * (CartPageController) -- once the table needed a working "Remover" button
 * per line, a Form became the natural shape (CSRF-protected submit, AJAX
 * refresh in place), the same choice KitGridForm/AddToCartFormBase already
 * made for "Adicionar ao carrinho". Table rows are built as real form
 * children (the same "checkbox per cell" shape core's
 * \Drupal\user\Form\UserPermissionsForm uses), not '#rows' -- a `#type`
 * table's '#rows' property is for static markup only; anything nested
 * inside it is skipped by Form API's own build/process recursion (keys
 * starting with '#' are never treated as child elements), so a submit
 * button placed there would never get wired up as a real, clickable
 * triggering element.
 *
 * Still deliberately not checkout: removing a line entirely is the only
 * write this form does. No quantity edit, no "place order" -- see this
 * module's README for what's still missing.
 */
class CartForm extends FormBase {

  public function __construct(
    protected readonly CartManager $cartManager,
    protected readonly PriceFormatter $priceFormatter,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('kit_catalog.cart_manager'),
      $container->get('product_kit.price_formatter'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'kit_catalog_cart_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // This page's whole content is "whatever this one user's cart holds" --
    // without the 'user' cache context, Dynamic Page Cache would key its
    // cached response on the URL alone and serve one user's cart to the
    // next authenticated visitor who hits /cart; verified live in
    // development (a second test user was shown the first's cart and
    // total) before this line existed on the controller this form
    // replaced.
    $form['#cache']['contexts'] = ['user'];
    $form['#attached']['library'][] = 'kit_catalog/storefront';

    $uid = (int) $this->currentUser->id();

    $form['wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'kit-catalog-cart-wrapper'],
    ];

    $table_rows = [];
    $total = $this->buildKitRows($uid, $table_rows) + $this->buildProductRows($uid, $table_rows);

    if (!$table_rows) {
      $form['wrapper']['empty'] = [
        '#markup' => '<p>' . $this->t('Seu carrinho está vazio.') . '</p>',
      ];
      return $form;
    }

    $form['wrapper']['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Item'),
        $this->t('Quantidade'),
        $this->t('Preço unitário'),
        $this->t('Subtotal'),
        $this->t('Remover'),
      ],
      '#attributes' => ['class' => ['kit-catalog-cart__table']],
    ] + $table_rows;

    $form['wrapper']['total'] = [
      '#markup' => '<p class="kit-catalog-cart__total">' . $this->t('Total: @amount', [
        '@amount' => $this->priceFormatter->format($total),
      ]) . '</p>',
    ];

    return $form;
  }

  /**
   * Appends the user's kit lines to $tableRows, returns their combined total.
   */
  protected function buildKitRows(int $uid, array &$tableRows): int {
    $quantities = $this->cartManager->getKitItems($uid);
    if (!$quantities) {
      return 0;
    }

    /** @var \Drupal\product_kit\Entity\KitInterface[] $kits */
    $kits = $this->entityTypeManager->getStorage('kit')->loadMultiple(array_keys($quantities));

    $total = 0;
    foreach ($quantities as $kit_id => $quantity) {
      $kit = $kits[$kit_id] ?? NULL;
      if (!$kit instanceof KitInterface) {
        continue;
      }
      $subtotal = $kit->getPrice() * $quantity;
      $total += $subtotal;
      $tableRows['kit_' . $kit_id] = $this->buildRow(
        $kit->label(),
        'kit_catalog.kit_page',
        ['kit' => $kit_id],
        $quantity,
        $kit->getPrice(),
        $subtotal,
        'kit',
        $kit_id,
      );
    }
    return $total;
  }

  /**
   * Appends the user's bare product lines to $tableRows, returns their total.
   */
  protected function buildProductRows(int $uid, array &$tableRows): int {
    $quantities = $this->cartManager->getProductItems($uid);
    if (!$quantities) {
      return 0;
    }

    /** @var \Drupal\product_kit\Entity\ProductInterface[] $products */
    $products = $this->entityTypeManager->getStorage('product')->loadMultiple(array_keys($quantities));

    $total = 0;
    foreach ($quantities as $product_id => $quantity) {
      $product = $products[$product_id] ?? NULL;
      if (!$product instanceof ProductInterface) {
        continue;
      }
      $subtotal = $product->getPrice() * $quantity;
      $total += $subtotal;
      $tableRows['product_' . $product_id] = $this->buildRow(
        $product->label(),
        'kit_catalog.product_page',
        ['product' => $product_id],
        $quantity,
        $product->getPrice(),
        $subtotal,
        'product',
        $product_id,
      );
    }
    return $total;
  }

  /**
   * Builds one table row: four plain cells plus a real "Remover" button.
   */
  protected function buildRow(string $label, string $route, array $routeParameters, int $quantity, int $unitPrice, int $subtotal, string $itemType, int $itemId): array {
    return [
      'item' => [
        '#type' => 'link',
        '#title' => $label,
        '#url' => Url::fromRoute($route, $routeParameters),
      ],
      'quantity' => ['#plain_text' => (string) $quantity],
      'unit_price' => ['#plain_text' => $this->priceFormatter->format($unitPrice)],
      'subtotal' => ['#plain_text' => $this->priceFormatter->format($subtotal)],
      'remove' => [
        '#type' => 'submit',
        '#value' => $this->t('Remover'),
        // Unique per row -- several of these submit buttons share one
        // form, exactly like KitGridForm's per-card "Adicionar ao
        // carrinho" buttons.
        '#name' => 'kit_catalog_cart_remove_' . $itemType . '_' . $itemId,
        '#kit_catalog_remove_type' => $itemType,
        '#kit_catalog_remove_id' => $itemId,
        // Nothing on this form needs validating -- every other cell is
        // plain display markup, not an input.
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => '::removeAjax',
          'wrapper' => 'kit-catalog-cart-wrapper',
        ],
        '#attributes' => ['class' => ['kit-catalog-cart__remove']],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $item_type = $trigger['#kit_catalog_remove_type'] ?? NULL;
    $item_id = isset($trigger['#kit_catalog_remove_id']) ? (int) $trigger['#kit_catalog_remove_id'] : NULL;

    $uid = (int) $this->currentUser->id();

    if ($item_type === 'kit' && $item_id) {
      $this->cartManager->removeKitLine($uid, $item_id);
      $this->messenger()->addStatus($this->t('Kit removido do carrinho.'));
    }
    elseif ($item_type === 'product' && $item_id) {
      $this->cartManager->removeProductLine($uid, $item_id);
      $this->messenger()->addStatus($this->t('Produto removido do carrinho.'));
    }

    $form_state->setRebuild();
  }

  /**
   * AJAX callback for the "Remover" buttons: refreshes the table in place.
   */
  public function removeAjax(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new PrependCommand('#kit-catalog-cart-wrapper', ['#type' => 'status_messages']));
    $response->addCommand(new ReplaceCommand('#kit-catalog-cart-wrapper', $form['wrapper']));
    return $response;
  }

}
