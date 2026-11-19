<?php

declare(strict_types=1);

namespace Drupal\kit_catalog\Form;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\PrependCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Component\Datetime\TimeInterface;
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
    protected readonly TimeInterface $time,
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
      $container->get('datetime.time'),
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
   * @param string $heading
   *   Heading shown above the grid, empty to omit it.
   * @param string $subheading
   *   Subheading shown under the heading, empty to omit it.
   */
  public function buildForm(array $form, FormStateInterface $form_state, int $limit = 0, int $columns = 3, string $heading = '', string $subheading = ''): array {
    $form['#attached']['library'][] = 'kit_catalog/storefront';

    if ($heading !== '') {
      $form['header'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['kit-catalog-grid__header']],
        'text' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['kit-catalog-grid__header-text']],
          'heading' => [
            '#markup' => '<h2>' . Xss::filter($heading) . '</h2>',
          ],
          'subheading' => $subheading !== '' ? [
            '#markup' => '<p>' . Xss::filter($subheading) . '</p>',
          ] : [],
        ],
        // Purely visual scroll controls for the horizontal slider layout
        // the theme gives this grid on mobile -- see
        // ecommerce_barrio/js/global.js. On a layout where the theme
        // renders the grid as a plain multi-column grid instead (its own
        // CSS decides that, not this form), these simply have nothing to
        // scroll and are harmless.
        'nav' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['kit-catalog-grid__nav']],
          'prev' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#value' => '',
            '#attributes' => [
              'type' => 'button',
              'class' => ['kit-catalog-grid__nav-btn', 'kit-catalog-grid__nav-btn--prev'],
              'data-grid-scroll' => 'prev',
              'data-grid-target' => 'kit-catalog-grid-wrapper',
              'aria-label' => $this->t('Anterior'),
            ],
          ],
          'next' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#value' => '',
            '#attributes' => [
              'type' => 'button',
              'class' => ['kit-catalog-grid__nav-btn', 'kit-catalog-grid__nav-btn--next', 'kit-catalog-grid__nav-btn--active'],
              'data-grid-scroll' => 'next',
              'data-grid-target' => 'kit-catalog-grid-wrapper',
              'aria-label' => $this->t('Próximo'),
            ],
          ],
        ],
      ];
    }

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
        '#title' => $this->formatPlural($count, 'Carrinho: @count item', 'Carrinho: @count itens'),
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
        '#markup' => '<p class="kit-catalog-grid__empty">' . $this->t('Nenhum kit disponível no momento.') . '</p>',
      ];
    }
    else {
      // Outside #kit-catalog-grid-wrapper on purpose: this never needs to
      // change on an "Add to cart" AJAX refresh (the kit list/order is
      // unaffected by adding one to the cart), so it has no reason to
      // flash/re-render on every click.
      //
      // Deliberately empty here, not one dot per kit: how many *distinct*
      // scroll positions actually exist depends on how many cards fit in
      // view at once, which depends on viewport width -- something only
      // the browser knows. At this catalogue's current size (5 kits),
      // desktop shows ~4.3 cards at a time, leaving barely one card's
      // width of scrollable range: a server-rendered dot per kit left
      // three dots permanently unreachable/dead (found while testing with
      // real simulated clicks, not just by reading the code). `global.js`
      // populates this with the actually-reachable page count and keeps
      // it in sync on resize. Only visible on the front page, where the
      // grid is a slider (ecommerce_barrio/css/style.css scopes it with
      // body.path-frontpage, same as the slider CSS itself) -- elsewhere
      // this block renders as a plain multi-column grid with nothing to
      // paginate, and global.js leaves an empty container alone.
      $form['dots'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['kit-catalog-grid__dots'],
          'data-grid-target' => 'kit-catalog-grid-wrapper',
        ],
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

    // Real image when the kit has one; otherwise the same honest,
    // labelled placeholder this card always showed before `kit` had an
    // image field -- same spirit as the "Storefront hero promo"
    // block_content's "[Imagem da campanha]", not pretending a photo
    // exists when it doesn't.
    $card['photo'] = $kit->get('image')->isEmpty()
      ? ['#markup' => '<div class="kit-catalog-card__photo"><span>' . $this->t('[Foto do produto]') . '</span></div>']
      : [
        '#type' => 'container',
        '#attributes' => ['class' => ['kit-catalog-card__photo']],
        'image' => $kit->get('image')->view(['label' => 'hidden', 'type' => 'image']),
      ];

    $card['badges'] = $this->buildBadges($kit);

    // Decorative only: there is no wishlist feature behind this module yet,
    // it exists purely to match the storefront design's card layout.
    $card['wishlist'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => '',
      '#attributes' => [
        'type' => 'button',
        'class' => ['kit-catalog-card__wishlist'],
        'aria-label' => $this->t('Favoritar'),
        'tabindex' => '-1',
      ],
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
        ? $this->t('@count em estoque', ['@count' => $kit->getStock()])
        : $this->t('Fora de estoque'))
      . '</div>',
    ];

    if (!$this->currentUser->isAuthenticated()) {
      $card['login'] = [
        '#type' => 'link',
        '#title' => $this->t('Entrar para comprar'),
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
      '#value' => $this->t('Adicionar ao carrinho'),
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
   * Badges shown in a card's top-left corner.
   *
   * Only ever shows what's honestly derivable from real data -- "Novo" for
   * a kit created in roughly the last month. There is deliberately no
   * "Mais vendido" (no sales-count field exists) or discount badge (no
   * promo-price field exists); inventing either would show a number that
   * doesn't correspond to anything real.
   */
  protected function buildBadges(KitInterface $kit): array {
    $created = (int) $kit->get('created')->value;
    if ($created === 0 || ($this->time->getRequestTime() - $created) > 30 * 86400) {
      return [];
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['kit-catalog-card__badges']],
      'new' => [
        '#markup' => '<span class="kit-catalog-card__badge">' . $this->t('Novo') . '</span>',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Defense in depth: anonymous visitors are never rendered a working
    // button (see buildCard()), but a crafted request could still try to
    // trigger this.
    if (!$this->currentUser->isAuthenticated()) {
      $this->messenger()->addError($this->t('Entre para adicionar itens ao carrinho.'));
      $form_state->setRebuild();
      return;
    }

    $trigger = $form_state->getTriggeringElement();
    $kit_id = isset($trigger['#kit_id']) ? (int) $trigger['#kit_id'] : NULL;

    if ($kit_id) {
      $kit = $this->entityTypeManager->getStorage('kit')->load($kit_id);
      $token = (string) $form_state->getValue(['wrapper', $kit_id, 'token'], '');

      if (!$kit instanceof KitInterface || !$kit->canBeSold()) {
        $this->messenger()->addError($this->t('Esse kit não está mais disponível.'));
      }
      elseif ($token === '') {
        // Only reachable via a request that skipped buildForm()'s markup.
        $this->messenger()->addError($this->t('Não foi possível adicionar esse kit ao carrinho. Tente novamente.'));
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
        $this->messenger()->addStatus($this->t('%kit adicionado ao carrinho.', ['%kit' => $kit->label()]));
        break;

      case 'insufficient_stock':
        $this->messenger()->addError($this->t('Só é possível adicionar mais @available unidade(s) de %kit agora.', [
          '@available' => $result['available'] ?? 0,
          '%kit' => $kit->label(),
        ]));
        break;

      default:
        $this->messenger()->addError($this->t('Esse kit não está mais disponível.'));
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
