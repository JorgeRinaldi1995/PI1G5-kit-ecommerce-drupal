<?php

declare(strict_types=1);

namespace Drupal\product_kit\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\product_kit\Field\KitDerivedItemList;
use Drupal\product_kit\Field\LineItemList;
use Drupal\product_kit\Form\KitForm;
use Drupal\product_kit\KitListBuilder;
use Drupal\product_kit\ProductKitAccessControlHandler;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

/**
 * Defines the Kit content entity.
 *
 * A kit bundles one or more products (through kit_product lines). It stores
 * only its own data (name, description, status...): price and stock are
 * derived from the products every time they are read, never persisted. See
 * \Drupal\product_kit\KitCalculator.
 */
#[ContentEntityType(
  id: 'kit',
  label: new TranslatableMarkup('Kit'),
  label_collection: new TranslatableMarkup('Kits'),
  label_singular: new TranslatableMarkup('kit'),
  label_plural: new TranslatableMarkup('kits'),
  label_count: [
    'singular' => '@count kit',
    'plural' => '@count kits',
  ],
  handlers: [
    'view_builder' => EntityViewBuilder::class,
    'list_builder' => KitListBuilder::class,
    'views_data' => EntityViewsData::class,
    'access' => ProductKitAccessControlHandler::class,
    'form' => [
      'default' => KitForm::class,
      'add' => KitForm::class,
      'edit' => KitForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  base_table: 'kit',
  admin_permission: 'administer product kit',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
    'published' => 'status',
    'owner' => 'uid',
    'langcode' => 'langcode',
  ],
  links: [
    'canonical' => '/admin/content/kit/{kit}',
    'add-form' => '/admin/content/kit/add',
    'edit-form' => '/admin/content/kit/{kit}/edit',
    'delete-form' => '/admin/content/kit/{kit}/delete',
    'collection' => '/admin/content/kit',
  ],
  field_ui_base_route: 'entity.kit.settings',
)]
class Kit extends ContentEntityBase implements KitInterface {

  use EntityChangedTrait;
  use EntityPublishedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function getPrice(): int {
    return $this->isNew() ? 0 : \Drupal::service('product_kit.calculator')->getPrice($this);
  }

  /**
   * {@inheritdoc}
   */
  public function getStock(): int {
    return $this->isNew() ? 0 : \Drupal::service('product_kit.calculator')->getStock($this);
  }

  /**
   * {@inheritdoc}
   */
  public function canBeSold(): bool {
    return $this->isPublished() && $this->getStock() > 0;
  }

  /**
   * {@inheritdoc}
   */
  public function getLines(): array {
    return $this->isNew()
      ? []
      : \Drupal::service('product_kit.composition_manager')->loadLines('kit', $this->id());
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::publishedBaseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('ID'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(new TranslatableMarkup('UUID'))
      ->setReadOnly(TRUE);

    $fields['langcode'] = BaseFieldDefinition::create('language')
      ->setLabel(new TranslatableMarkup('Language'));

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -5])
      ->setDisplayOptions('view', ['label' => 'hidden', 'type' => 'string', 'weight' => -5])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status']
      ->setLabel(new TranslatableMarkup('Enabled'))
      ->setDescription(new TranslatableMarkup('Disabled kits are hidden from catalogues.'))
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 10])
      ->setDisplayConfigurable('form', TRUE);

    // Derived from the kit's products on every read; there is no column.
    $fields['price'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Price'))
      ->setDescription(new TranslatableMarkup('Sum of (product price * quantity) over the kit\'s products, in minor currency units. Derived, not stored.'))
      ->setComputed(TRUE)
      ->setReadOnly(TRUE)
      ->setClass(KitDerivedItemList::class)
      ->setDisplayOptions('view', ['type' => 'product_kit_price', 'weight' => 0])
      ->setDisplayConfigurable('view', TRUE);

    $fields['stock'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Stock'))
      ->setDescription(new TranslatableMarkup('How many complete kits can be assembled from the products in stock. Derived, not stored.'))
      ->setComputed(TRUE)
      ->setReadOnly(TRUE)
      ->setClass(KitDerivedItemList::class)
      ->setDisplayOptions('view', ['type' => 'number_integer', 'weight' => 1])
      ->setDisplayConfigurable('view', TRUE);

    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'weight' => 2])
      ->setDisplayOptions('view', ['type' => 'text_default', 'weight' => 2])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['items'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Products'))
      ->setDescription(new TranslatableMarkup('The kit_product lines that make up this kit.'))
      ->setSetting('target_type', 'kit_product')
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setComputed(TRUE)
      ->setClass(LineItemList::class)
      ->setDisplayOptions('view', ['type' => 'entity_reference_label', 'weight' => 5])
      ->setDisplayConfigurable('view', TRUE);

    $fields['uid']
      ->setLabel(new TranslatableMarkup('Author'))
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 20])
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

}
