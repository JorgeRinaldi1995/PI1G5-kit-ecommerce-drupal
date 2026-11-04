<?php

declare(strict_types=1);

namespace Drupal\kit_catalog\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\product_kit\Entity\KitInterface;

/**
 * "Add to cart" button for one kit's detail page.
 */
class KitAddToCartForm extends AddToCartFormBase {

  protected ?KitInterface $kit = NULL;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'kit_catalog_kit_add_to_cart_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\product_kit\Entity\KitInterface|null $kit
   *   The kit this button adds to the cart.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?KitInterface $kit = NULL): array {
    $this->kit = $kit;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function itemLabel(): string {
    return $this->kit->label();
  }

  /**
   * {@inheritdoc}
   */
  protected function canBeSold(): bool {
    return $this->kit->canBeSold();
  }

  /**
   * {@inheritdoc}
   */
  protected function addToCart(int $uid, string $idempotencyKey): array {
    return $this->cartManager->addKitToCart($uid, (int) $this->kit->id(), $idempotencyKey);
  }

}
