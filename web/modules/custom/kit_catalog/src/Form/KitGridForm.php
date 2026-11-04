<?php

declare(strict_types=1);

namespace Drupal\kit_catalog\Form;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Component\Utility\Xss;
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
use Drupal\product_kit\PriceFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the kit grid and handles its "Add to cart" buttons.
 *
 * One form holds every card so a single AJAX round-trip can add an item and
 * refresh the grid. Adding to cart requires being logged in -- an anonymous
 * visitor gets a "Log in to add to cart" link instead of a working button,
 * enforced again server-side in submitForm() in case that link is bypassed.
 * This only ever writes to \Drupal\kit_catalog\CartManager; there is no
 * checkout, order or stock decrement here yet.
 */
class KitGridForm extends FormBase {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly PriceFormatter $priceFormatter,
    protected readonly CartManager $cartManager,
    protected readonly AccountInterface $currentUser,
    protected readonly UuidInterface $uuid,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = new static(
      $container->get('entity_type.manager'),
      $container->get('product_kit.price_formatter'),
      $container->get('kit_catalog.cart_manager'),
      $container->get('current_user'),
      $container->get('uuid'),
    );
    $form->setRedirectDestination($container->get('redirect.destination'));
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'kit_catalog_grid_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param int $limit
   *   Maximum number of kits to show, or 0 for every enabled kit.
   * @param int $columns
   *   Number of grid columns, for the CSS class.
   */
  public function buildForm(array $form, FormStateInterface $form_state, int $limit = 0, int $columns = 3): array {
    $form['#attached']['library'][] = 'kit_catalog/storefront';

    $form['wrapper'] = [
      '#type' => 'container',
      // So each card's hidden idempotency token ends up at
      // $form_state->getValue(['wrapper', $kit_id, 'token']), not flattened.
      '#tree' => TRUE,
      '#attributes' => [
        'id' => 'kit-catalog-grid-wrapper',
        'class' => ['kit-catalog-grid', 'kit-catalog-grid--cols-' . $columns],
      ],
    ];

    // Lives inside the element the AJAX callback replaces, same as
    // AddToCartFormBase's equivalent, so it re-reads CartManager and shows
    // the correct combined (kit + product) count after every add, not just
    // on the next full page load. Spans the full grid row, same technique
    // the "no kits" message below already uses.
    if ($this->currentUser->isAuthenticated()) {
      $count = $this->cartManager->getItemCount((int) $this->currentUser->id());
      $form['wrapper']['cart_summary'] = [
        '#type' => 'link',
        '#title' => $this->formatPlural($count, 'Cart: @count item', 'Cart: @count items'),
        '#url' => Url::fromRoute('kit_catalog.cart_page'),
        '#attributes' => ['class' => ['kit-catalog-grid__cart-summary']],
      ];
    }

    $kits = $this->loadSellableKits($limit);
    foreach ($kits as $id => $kit) {
      $form['wrapper'][$id] = $this->buildCard($kit);
    }

    if (!$kits) {
      $form['wrapper']['empty'] = [
        '#markup' => '<p class="kit-catalog-grid__empty">' . $this->t('No kits are available right now.') . '</p>',
      ];
    }

    return $form;
  }

  /**
   * Builds one grid card for a kit.
   */
  protected function buildCard(KitInterface $kit): array {
    $id = (int) $kit->id();
    $can_sell = $kit->canBeSold();

    $card = [
      '#type' => 'container',
      '#attributes' => ['class' => ['kit-catalog-card']],
    ];

    // Links to the kit's own detail page (kit_catalog.kit_page), where the
    // "what's in this kit" breakdown lives, each line pointing at its own
    // product page -- the grid card itself stays a lightweight entry
    // point, not a second place that repeats that breakdown.
    $card['name'] = [
      '#type' => 'link',
      '#title' => $kit->label(),
      '#url' => Url::fromRoute('kit_catalog.kit_page', ['kit' => $id]),
      '#attributes' => ['class' => ['kit-catalog-card__name']],
    ];

    $description = (string) $kit->get('description')->value;
    if ($description !== '') {
      $card['description'] = [
        '#markup' => '<div class="kit-catalog-card__description">' . Xss::filter($description) . '</div>',
      ];
    }

    $card['price'] = [
      '#markup' => '<div class="kit-catalog-card__price">' . $this->priceFormatter->format($kit->getPrice()) . '</div>',
    ];

    $card['stock'] = [
      '#markup' => '<div class="kit-catalog-card__stock">'
      . ($can_sell
        ? $this->t('@count in stock', ['@count' => $kit->getStock()])
        : $this->t('Out of stock'))
      . '</div>',
    ];

    if (!$this->currentUser->isAuthenticated()) {
      $card['login'] = [
        '#type' => 'link',
        '#title' => $this->t('Log in to add to cart'),
        '#url' => Url::fromRoute('user.login', [], ['query' => $this->getDestinationArray()]),
        '#attributes' => ['class' => ['kit-catalog-card__cta', 'kit-catalog-card__cta--login', 'button']],
      ];
      return $card;
    }

    // #default_value, not #value: #value would be forced back to a fresh
    // uuid on every build, including the implicit rebuild submitForm() runs
    // on, which would throw away the very token the client just posted.
    // #default_value only seeds this field's *first* render; once a value
    // has been submitted, Form API redisplays that submitted value on
    // further rebuilds of the same form instead -- which is exactly why
    // kit_catalog/storefront's JS (kit-catalog-add-to-cart.js) mints a new one on
    // every "Add to cart" mousedown: without it, every click after the
    // first, on the same kit, in the same page view, would be
    // indistinguishable from a replay of that first click. A visitor
    // without JS still gets a correct, if coarser, default: each full page
    // load runs buildForm() with no prior input, so it gets a genuinely
    // fresh token; a resubmission of that same page (e.g. the browser's
    // "confirm form resubmission") replays the same one, which is the
    // retry case CartManager::addKitToCart() is meant to catch.
    $card['token'] = [
      '#type' => 'hidden',
      '#default_value' => $this->uuid->generate(),
    ];

    $card['add_to_cart'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add to cart'),
      '#name' => 'kit_catalog_add_' . $id,
      '#kit_id' => $id,
      '#disabled' => !$can_sell,
      '#attributes' => ['class' => ['kit-catalog-card__cta']],
      '#ajax' => [
        'callback' => '::addToCartAjax',
        'wrapper' => 'kit-catalog-grid-wrapper',
      ],
      // Every button submits the same form; scope validation (and value
      // population -- an empty array here would skip that too, silently
      // dropping this card's own token) to just this card, ignoring the
      // others.
      '#limit_validation_errors' => [['wrapper', $id]],
    ];

    return $card;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Defense in depth: anonymous visitors are never rendered a working
    // button (see buildCard()), but a crafted request could still try to
    // trigger this.
    if (!$this->currentUser->isAuthenticated()) {
      $this->messenger()->addError($this->t('Log in to add items to your cart.'));
      $form_state->setRebuild();
      return;
    }

    $trigger = $form_state->getTriggeringElement();
    $kit_id = isset($trigger['#kit_id']) ? (int) $trigger['#kit_id'] : NULL;

    if ($kit_id) {
      $kit = $this->entityTypeManager->getStorage('kit')->load($kit_id);
      $token = (string) $form_state->getValue(['wrapper', $kit_id, 'token'], '');

      if (!$kit instanceof KitInterface || !$kit->canBeSold()) {
        $this->messenger()->addError($this->t('That kit is no longer available.'));
      }
      elseif ($token === '') {
        // Only reachable via a request that skipped buildForm()'s markup.
        $this->messenger()->addError($this->t('Could not add that kit to your cart. Please try again.'));
      }
      else {
        $result = $this->cartManager->addKitToCart((int) $this->currentUser->id(), $kit_id, $token);
        $this->reportAddResult($result, $kit);
      }
    }

    $form_state->setRebuild();
  }

  /**
   * Turns a CartManager::addKitToCart() result into a status/error message.
   *
   * A retried/duplicated submission (same idempotency token as one already
   * processed) is resolved by CartManager to the *original* outcome before
   * it ever reaches here, so this never needs a separate "duplicate" case:
   * a replayed success is reported as 'added', a replayed rejection as
   * 'insufficient_stock', exactly as the first attempt was.
   */
  protected function reportAddResult(array $result, KitInterface $kit): void {
    switch ($result['status']) {
      case 'added':
        $this->messenger()->addStatus($this->t('%kit added to cart.', ['%kit' => $kit->label()]));
        break;

      case 'insufficient_stock':
        $this->messenger()->addError($this->t('Only @available more unit(s) of %kit can be added right now.', [
          '@available' => $result['available'] ?? 0,
          '%kit' => $kit->label(),
        ]));
        break;

      default:
        $this->messenger()->addError($this->t('That kit is no longer available.'));
    }
  }

  /**
   * AJAX callback for the "Add to cart" buttons: refreshes the grid in place.
   */
  public function addToCartAjax(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new PrependCommand('#kit-catalog-grid-wrapper', ['#type' => 'status_messages']));
    $response->addCommand(new ReplaceCommand('#kit-catalog-grid-wrapper', $form['wrapper']));
    return $response;
  }

  /**
   * Loads the kits this storefront grid may offer.
   *
   * Queries with access checking disabled -- still correct after
   * product_kit's \Drupal\product_kit\ProductKitAccessControlHandler
   * started granting public 'view' of enabled kits, because entity query
   * access filtering (`accessCheck(TRUE)`) only tags the SQL query for a
   * `hook_query_TAG_alter()` to act on; `kit` has none, so the flag would be
   * inert either way. Restricting to `status = 1` here is the actual (and
   * only) filter keeping this from exposing a disabled kit.
   *
   * @return \Drupal\product_kit\Entity\KitInterface[]
   *   Enabled kits, keyed by ID, newest-listed first.
   */
  protected function loadSellableKits(int $limit): array {
    $storage = $this->entityTypeManager->getStorage('kit');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->sort('name');

    if ($limit > 0) {
      $query->range(0, $limit);
    }

    $ids = $query->execute();
    if (!$ids) {
      return [];
    }

    /** @var \Drupal\product_kit\Entity\KitInterface[] $kits */
    $kits = $storage->loadMultiple($ids);
    return $kits;
  }

}
