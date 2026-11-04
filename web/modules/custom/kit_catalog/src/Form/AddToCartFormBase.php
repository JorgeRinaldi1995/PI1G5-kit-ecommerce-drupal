<?php

declare(strict_types=1);

namespace Drupal\kit_catalog\Form;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\PrependCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\kit_catalog\CartManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A single "Add to cart" button, for exactly one kit or one product.
 *
 * KitGridForm packs many of these into one <form> (one button per card,
 * '#tree'-keyed so each card's own token survives a shared rebuild); a kit
 * or product detail page only ever needs one, so this is the plain,
 * single-item version of the same idea: same card markup
 * (`.kit-catalog-card`/`.kit-catalog-card__cta`, so
 * `js/kit-catalog-add-to-cart.js` behaves identically -- it matches on
 * those classes, not on either form's shape), same
 * login-link-then-defend-again-in-submitForm() pattern, same idempotency
 * token handling. See KitGridForm::buildCard()'s comment on its `token`
 * element for why this one is `#default_value`, never `#value`.
 *
 * A subclass only has to say what the item is and how to add it:
 * itemLabel(), canBeSold() and addToCart(). Everything else -- the login
 * gate, the token, the AJAX round-trip, turning a CartManager outcome into
 * a message -- is identical whichever kind of item this is for, because
 * CartManager::reserveAgainstProducts() already made kit and product share
 * one code path; this class is that same unification one layer up, in the
 * UI.
 */
abstract class AddToCartFormBase extends FormBase {

  public function __construct(
    protected readonly CartManager $cartManager,
    protected readonly AccountInterface $currentUser,
    protected readonly UuidInterface $uuid,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = new static(
      $container->get('kit_catalog.cart_manager'),
      $container->get('current_user'),
      $container->get('uuid'),
    );
    $form->setRedirectDestination($container->get('redirect.destination'));
    return $form;
  }

  /**
   * The label to use in confirmation/error messages.
   */
  abstract protected function itemLabel(): string;

  /**
   * Whether the item can currently be sold at all (enabled and stock > 0).
   */
  abstract protected function canBeSold(): bool;

  /**
   * Calls the right CartManager entry point for this item.
   *
   * @return array{status: string, available?: int}
   *   See CartManager::reserveAgainstProducts() for what 'status' can be.
   */
  abstract protected function addToCart(int $uid, string $idempotencyKey): array;

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'kit_catalog/storefront';
    $form['#attributes']['class'][] = 'kit-catalog-card';
    $form['#attributes']['id'] = 'kit-catalog-add-to-cart-wrapper';

    if (!$this->currentUser->isAuthenticated()) {
      $form['login'] = [
        '#type' => 'link',
        '#title' => $this->t('Log in to add to cart'),
        '#url' => Url::fromRoute('user.login', [], ['query' => $this->getDestinationArray()]),
        '#attributes' => ['class' => ['kit-catalog-card__cta', 'kit-catalog-card__cta--login', 'button']],
      ];
      return $form;
    }

    $can_sell = $this->canBeSold();

    // #default_value, not #value -- see KitGridForm::buildCard()'s comment
    // on the equivalent field. js/kit-catalog-add-to-cart.js is what keeps
    // this fresh across repeated clicks on this same rendered page; without
    // it, every click after the first would look like a retry of the
    // first.
    $form['token'] = [
      '#type' => 'hidden',
      '#default_value' => $this->uuid->generate(),
    ];

    $form['add_to_cart'] = [
      '#type' => 'submit',
      '#value' => $can_sell ? $this->t('Add to cart') : $this->t('Out of stock'),
      '#disabled' => !$can_sell,
      '#attributes' => ['class' => ['kit-catalog-card__cta']],
      '#ajax' => [
        'callback' => '::addToCartAjax',
        'wrapper' => 'kit-catalog-add-to-cart-wrapper',
      ],
    ];

    $form['cart_summary'] = $this->buildCartSummaryLink((int) $this->currentUser->id());

    return $form;
  }

  /**
   * A small "Cart: N item(s)" link to kit_catalog.cart_page.
   *
   * Lives inside the element addToCartAjax() replaces, same as
   * KitGridForm's equivalent, so it re-reads CartManager and shows the
   * correct combined (kit + product) count after every add -- not just on
   * the next full page load.
   */
  protected function buildCartSummaryLink(int $uid): array {
    $count = $this->cartManager->getItemCount($uid);
    return [
      '#type' => 'link',
      '#title' => $this->formatPlural($count, 'Cart: @count item', 'Cart: @count items'),
      '#url' => Url::fromRoute('kit_catalog.cart_page'),
      '#attributes' => ['class' => ['kit-catalog-card__cart-summary']],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Defense in depth: anonymous visitors are never rendered a working
    // button (see buildForm()), but a crafted request could still try to
    // trigger this.
    if (!$this->currentUser->isAuthenticated()) {
      $this->messenger()->addError($this->t('Log in to add items to your cart.'));
      $form_state->setRebuild();
      return;
    }

    $token = (string) $form_state->getValue('token', '');

    if (!$this->canBeSold()) {
      $this->messenger()->addError($this->t('That item is no longer available.'));
    }
    elseif ($token === '') {
      // Only reachable via a request that skipped buildForm()'s markup.
      $this->messenger()->addError($this->t('Could not add that item to your cart. Please try again.'));
    }
    else {
      $this->reportResult($this->addToCart((int) $this->currentUser->id(), $token));
    }

    $form_state->setRebuild();
  }

  /**
   * Turns a CartManager outcome into a status/error message.
   *
   * A retried/duplicated submission (same idempotency token as one already
   * processed) is resolved by CartManager to the *original* outcome before
   * it ever reaches here -- a replayed success is reported as 'added', a
   * replayed rejection as 'insufficient_stock', exactly as the first
   * attempt was. See CartManager's class docblock.
   */
  protected function reportResult(array $result): void {
    switch ($result['status']) {
      case 'added':
        $this->messenger()->addStatus($this->t('%item added to cart.', ['%item' => $this->itemLabel()]));
        break;

      case 'insufficient_stock':
        $this->messenger()->addError($this->t('Only @available more unit(s) of %item can be added right now.', [
          '@available' => $result['available'] ?? 0,
          '%item' => $this->itemLabel(),
        ]));
        break;

      default:
        $this->messenger()->addError($this->t('That item is no longer available.'));
    }
  }

  /**
   * AJAX callback for the submit button: refreshes this form in place.
   */
  public function addToCartAjax(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new PrependCommand('#kit-catalog-add-to-cart-wrapper', ['#type' => 'status_messages']));
    $response->addCommand(new ReplaceCommand('#kit-catalog-add-to-cart-wrapper', $form));
    return $response;
  }

}
