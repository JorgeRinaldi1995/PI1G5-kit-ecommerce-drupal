<?php

declare(strict_types=1);

namespace Drupal\kit_catalog\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\product_kit\Entity\ProductInterface;

/**
 * "Add to cart" button for one product's purchase page.
 */
class ProductAddToCartForm extends AddToCartFormBase {

  protected ?ProductInterface $product = NULL;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'kit_catalog_product_add_to_cart_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\product_kit\Entity\ProductInterface|null $product
   *   The product this button adds to the cart.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ProductInterface $product = NULL): array {
    $this->product = $product;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function itemLabel(): string {
    return $this->product->label();
  }

  /**
   * {@inheritdoc}
   */
  protected function canBeSold(): bool {
    return $this->product->canBeSold();
  }

  /**
   * {@inheritdoc}
   */
  protected function addToCart(int $uid, string $idempotencyKey): array {
    return $this->cartManager->addProductToCart($uid, (int) $this->product->id(), $idempotencyKey);
  }

}
