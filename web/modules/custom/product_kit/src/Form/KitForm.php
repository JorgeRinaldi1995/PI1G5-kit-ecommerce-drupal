<?php

declare(strict_types=1);

namespace Drupal\product_kit\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Add/edit form for kits, with inline management of the kit's products.
 *
 * The kit and its lines are saved together in one transaction, so a kit is
 * never persisted without at least one product. The same product may be
 * entered on several rows (or with a quantity above 1) as long as its stock
 * covers it. The price and stock shown are derived, never stored.
 */
class KitForm extends CompositionFormBase {

  /**
   * {@inheritdoc}
   */
  protected function targetType(): string {
    return 'product';
  }

  /**
   * {@inheritdoc}
   */
  protected function initialRows(): array {
    /** @var \Drupal\product_kit\Entity\KitInterface $kit */
    $kit = $this->entity;

    $rows = [];
    foreach ($kit->getLines() as $line) {
      $rows[] = [
        'target' => (int) $line->get('product')->target_id ?: NULL,
        'quantity' => $line->getQuantity(),
      ];
    }
    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  protected function rowHint(EntityInterface $target): ?string {
    /** @var \Drupal\product_kit\Entity\ProductInterface $target */
    return (string) $this->t('@price each · @stock in stock', [
      '@price' => $this->priceFormatter->format($target->getPrice()),
      '@stock' => $target->getStock(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function buildSummary(array $rows): array {
    $lines = array_map(
      static fn (array $row): array => ['product' => $row['target'], 'quantity' => $row['quantity']],
      $rows,
    );

    return [
      'price' => [
        '#type' => 'item',
        '#title' => $this->t('Kit price (calculated)'),
        '#markup' => $this->priceFormatter->format($this->calculator->priceForLines($lines)),
        '#description' => $this->t('Sum of (unit price × quantity) over the products above. Never stored: it always follows the products.'),
      ],
      'stock' => [
        '#type' => 'item',
        '#title' => $this->t('Kit stock (calculated)'),
        '#markup' => (string) $this->calculator->stockForLines($lines),
        '#description' => $this->t('How many complete kits the products in stock can make: the lowest of (product stock ÷ quantity) over the products above.'),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    $form['lines'] = $this->buildRows([
      '#title' => $this->t('Products in this kit'),
      '#description' => $this->t('A kit needs at least one product. To include several units of a product, raise its quantity or enter it on more than one row; the total cannot exceed the units in stock (one unit is always allowed).'),
      '#weight' => 6,
    ], $form_state, (string) $this->t('Add another product'));

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $prepared = $this->composition->prepareKitRows($this->readRowsFromValues($form_state));
    $this->reportRowErrors($prepared['errors'], $form, $form_state);

    if (!$prepared['rows'] && !$prepared['errors']) {
      $form_state->setError($form['lines'], $this->t('A kit must contain at least one product.'));
    }

    $form_state->set('composition_prepared', $prepared['rows']);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\product_kit\Entity\KitInterface $kit */
    $kit = $this->entity;
    $rows = $form_state->get('composition_prepared');

    // The kit and its lines are one unit of work: a failure part-way through
    // must not leave a kit behind with only some (or none) of its products.
    $result = $this->composition->transactional(function () use ($form, $form_state, $kit, $rows): int {
      $result = parent::save($form, $form_state);
      $this->composition->syncKitLines($kit, $rows);
      return $result;
    });

    $this->reportMergedRows($rows);
    $this->messenger()->addStatus($this->t('The kit %label has been saved with @count product(s). Price: @price. Stock: @stock.', [
      '%label' => $kit->label(),
      '@count' => count($rows),
      '@price' => $this->priceFormatter->format($kit->getPrice()),
      '@stock' => $kit->getStock(),
    ]));

    $form_state->setRedirectUrl($kit->toUrl('collection'));

    return $result;
  }

}
