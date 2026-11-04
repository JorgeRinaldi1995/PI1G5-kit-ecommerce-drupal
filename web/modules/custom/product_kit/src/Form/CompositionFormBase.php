<?php

declare(strict_types=1);

namespace Drupal\product_kit\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\product_kit\KitCalculator;
use Drupal\product_kit\KitCompositionManager;
use Drupal\product_kit\PriceFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base of the kit and product forms: an entity form with an inline list of
 * kit_product rows managed through AJAX.
 *
 * On the kit form each row is a product with a quantity; on the product form
 * each row is a kit with a quantity. Rows live in form state as
 * ['target' => ID|NULL, 'quantity' => int], keyed by their position; the
 * submitted rows are validated and merged by the composition manager (so the
 * same product can be added on several rows, as far as its stock allows) and
 * written by the subclass in the same database transaction as the entity.
 */
abstract class CompositionFormBase extends ContentEntityForm {

  protected const WRAPPER_ID = 'composition-wrapper';

  protected KitCompositionManager $composition;

  protected KitCalculator $calculator;

  protected PriceFormatter $priceFormatter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->composition = $container->get('product_kit.composition_manager');
    $instance->calculator = $container->get('product_kit.calculator');
    $instance->priceFormatter = $container->get('product_kit.price_formatter');
    return $instance;
  }

  /**
   * The entity type picked on every row: "product" or "kit".
   *
   * It is also the name of the row's autocomplete element.
   */
  abstract protected function targetType(): string;

  /**
   * The rows the form starts with, from what is stored.
   *
   * @return array<int, array{target: int|null, quantity: int}>
   */
  abstract protected function initialRows(): array;

  /**
   * A short note shown next to a row, for the entity picked on it.
   */
  protected function rowHint(EntityInterface $target): ?string {
    return NULL;
  }

  /**
   * Extra content shown under the rows, e.g. calculated totals.
   *
   * @param array<int, array{target: int|null, quantity: int}> $rows
   *   The rows as they currently are on the form.
   */
  protected function buildSummary(array $rows): array {
    return [];
  }

  /**
   * Builds the rows fieldset.
   *
   * @param array<string, mixed> $element
   *   The fieldset's base properties (title, description, weight).
   */
  protected function buildRows(array $element, FormStateInterface $form_state, string $add_label): array {
    $type = $this->targetType();
    $storage = $this->entityTypeManager->getStorage($type);

    $element += [
      '#type' => 'fieldset',
      '#tree' => TRUE,
      '#prefix' => '<div id="' . static::WRAPPER_ID . '">',
      '#suffix' => '</div>',
    ];

    $rows = $this->getRowState($form_state);
    foreach ($rows as $i => $row) {
      $target = $row['target'] ? $storage->load($row['target']) : NULL;

      $element[$i] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['composition-row']],
      ];
      $element[$i][$type] = [
        '#type' => 'entity_autocomplete',
        '#title' => $this->entityTypeManager->getDefinition($type)->getLabel(),
        '#title_display' => $i > 0 ? 'invisible' : 'before',
        '#target_type' => $type,
        '#default_value' => $target,
      ];
      $element[$i]['quantity'] = [
        '#type' => 'number',
        '#title' => $this->t('Quantity'),
        '#title_display' => $i > 0 ? 'invisible' : 'before',
        '#min' => 1,
        '#step' => 1,
        '#default_value' => $row['quantity'],
      ];
      if ($target && ($hint = $this->rowHint($target))) {
        $element[$i]['hint'] = [
          '#markup' => $hint,
          '#prefix' => '<span class="composition-row__hint">',
          '#suffix' => '</span>',
        ];
      }
      $element[$i]['remove'] = [
        '#type' => 'submit',
        '#name' => 'remove_row_' . $i,
        '#value' => $this->t('Remove'),
        '#submit' => ['::removeRow'],
        '#composition_delta' => $i,
        '#limit_validation_errors' => [],
        '#ajax' => ['callback' => '::rowsAjax', 'wrapper' => static::WRAPPER_ID],
      ];
    }

    $element['actions'] = [
      '#type' => 'actions',
      'add' => [
        '#type' => 'submit',
        '#value' => $add_label,
        '#submit' => ['::addRow'],
        '#limit_validation_errors' => [],
        '#ajax' => ['callback' => '::rowsAjax', 'wrapper' => static::WRAPPER_ID],
      ],
      'refresh' => [
        '#type' => 'submit',
        '#value' => $this->t('Update totals'),
        '#submit' => ['::refreshRows'],
        '#limit_validation_errors' => [],
        '#ajax' => ['callback' => '::rowsAjax', 'wrapper' => static::WRAPPER_ID],
      ],
    ];

    if ($summary = $this->buildSummary($rows)) {
      $element['summary'] = $summary + ['#type' => 'container', '#attributes' => ['class' => ['composition-summary']]];
    }

    $element['#attached']['library'][] = 'product_kit/composition_form';
    return $element;
  }

  /**
   * Returns the working set of rows for the current form build.
   *
   * @return array<int, array{target: int|null, quantity: int}>
   */
  protected function getRowState(FormStateInterface $form_state): array {
    $state = $form_state->get('composition_rows');
    if ($state === NULL) {
      $state = $this->initialRows() ?: [['target' => NULL, 'quantity' => 1]];
      $form_state->set('composition_rows', $state);
    }
    return $state;
  }

  /**
   * Reads the rows from raw user input, for the AJAX handlers.
   *
   * Those buttons skip validation, so the processed form values are not
   * available; the entered text is resolved to an ID here instead. A quantity
   * left blank counts as 1; anything else is kept as typed so validation can
   * reject it later instead of it being silently changed.
   *
   * @return array<int, array{target: int|null, quantity: int}>
   */
  protected function readRowsFromInput(FormStateInterface $form_state): array {
    $type = $this->targetType();

    $rows = [];
    foreach ($form_state->getUserInput()['lines'] ?? [] as $key => $row) {
      if (!is_numeric($key) || !is_array($row)) {
        continue;
      }

      $target = NULL;
      $raw = trim((string) ($row[$type] ?? ''));
      if ($raw !== '') {
        $target = is_numeric($raw) ? (int) $raw : EntityAutocomplete::extractEntityIdFromAutocompleteInput($raw);
      }
      $quantity = trim((string) ($row['quantity'] ?? ''));

      $rows[(int) $key] = [
        'target' => $target ?: NULL,
        'quantity' => $quantity === '' ? 1 : (int) $quantity,
      ];
    }
    return $rows;
  }

  /**
   * Reads the rows from the validated form values, for validation and save.
   *
   * @return array<int, array{target: int|null, quantity: int}>
   *   Keyed by the row's position in the form.
   */
  protected function readRowsFromValues(FormStateInterface $form_state): array {
    $type = $this->targetType();

    $rows = [];
    foreach ($form_state->getValue('lines') ?? [] as $key => $row) {
      if (!is_numeric($key) || !is_array($row)) {
        continue;
      }

      $target = $row[$type] ?? NULL;
      $quantity = trim((string) ($row['quantity'] ?? ''));

      $rows[(int) $key] = [
        'target' => is_numeric($target) ? (int) $target : NULL,
        'quantity' => $quantity === '' ? 1 : (int) $quantity,
      ];
    }
    return $rows;
  }

  /**
   * Stores the rows in form state and drops the user input they replace.
   *
   * @param array<int, array{target: int|null, quantity: int}> $rows
   */
  protected function storeRowState(FormStateInterface $form_state, array $rows): void {
    $form_state->set('composition_rows', array_values($rows) ?: [['target' => NULL, 'quantity' => 1]]);

    $input = $form_state->getUserInput();
    unset($input['lines']);
    $form_state->setUserInput($input);
    $form_state->setRebuild();
  }

  /**
   * Submit handler: append an empty row.
   */
  public function addRow(array &$form, FormStateInterface $form_state): void {
    $rows = $this->readRowsFromInput($form_state);
    $rows[] = ['target' => NULL, 'quantity' => 1];
    $this->storeRowState($form_state, $rows);
  }

  /**
   * Submit handler: remove the row whose "Remove" button was pressed.
   */
  public function removeRow(array &$form, FormStateInterface $form_state): void {
    $rows = $this->readRowsFromInput($form_state);
    unset($rows[$form_state->getTriggeringElement()['#composition_delta'] ?? -1]);
    $this->storeRowState($form_state, $rows);
  }

  /**
   * Submit handler: rebuild with the rows as entered, refreshing the totals.
   */
  public function refreshRows(array &$form, FormStateInterface $form_state): void {
    $this->storeRowState($form_state, $this->readRowsFromInput($form_state));
  }

  /**
   * AJAX callback: return the rebuilt rows fieldset.
   */
  public function rowsAjax(array $form, FormStateInterface $form_state): array {
    return $form['lines'];
  }

  /**
   * Reports the errors found by the composition manager on the form.
   *
   * @param array<int, array{index: int, element: string, message: mixed}> $errors
   */
  protected function reportRowErrors(array $errors, array $form, FormStateInterface $form_state): void {
    foreach ($errors as $error) {
      $element = $form['lines'][$error['index']][$error['element']] ?? $form['lines'];
      $form_state->setError($element, $error['message']);
    }
  }

  /**
   * Tells the user which duplicate rows were combined into one.
   *
   * @param array<int, array{target: int, quantity: int, merged: bool}> $rows
   *   The prepared rows.
   */
  protected function reportMergedRows(array $rows): void {
    $storage = $this->entityTypeManager->getStorage($this->targetType());
    foreach ($rows as $row) {
      if ($row['merged'] && ($target = $storage->load($row['target']))) {
        $this->messenger()->addStatus($this->t('%label was entered on several rows; they were combined into a quantity of @quantity.', [
          '%label' => $target->label(),
          '@quantity' => $row['quantity'],
        ]));
      }
    }
  }

}
