<?php

declare(strict_types=1);

namespace Drupal\product_kit;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Grants 'view' of an enabled kit or product to anyone, not just admins.
 *
 * The default EntityAccessControlHandler (core) gates *every* operation,
 * 'view' included, behind the entity type's admin_permission
 * (`administer product kit` here) whenever one is set
 * (EntityAccessControlHandler::checkAccess()). That is correct for
 * update/delete/create, but wrong for 'view': a kit or product with
 * `status = 1` is meant to be seen by a storefront visitor, not just staff.
 * Left as shipped, nothing using the entity access system directly (a
 * canonical route's `_entity_access` requirement, a view builder, a future
 * JSON:API resource) can grant that without an admin login.
 *
 * This only overrides 'view'; every other operation still falls straight
 * through to the admin-permission-gated parent behaviour, unchanged.
 *
 * One caveat this does *not* fix: Drupal's entity query access filtering
 * (`->accessCheck(TRUE)` on `getQuery()`) works by tagging the SQL query
 * (`{entity_type}_access`) for a `hook_query_TAG_alter()` to act on. Neither
 * `kit` nor `product` has one, so a listing query's `accessCheck(TRUE)` is
 * inert either way -- this handler changes what happens when something
 * checks access on an already-loaded entity, not what a query returns. Code
 * that lists these entities (e.g. `kit_catalog`'s `KitGridForm`) still has
 * to filter by `status` itself; see that module's README.
 */
class ProductKitAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($operation !== 'view') {
      return parent::checkAccess($entity, $operation, $account);
    }

    // Published entities are public; an unpublished one (or an entity type
    // that, unexpectedly, isn't publishable) still needs the admin
    // permission parent::checkAccess() already enforces.
    if ($entity instanceof EntityPublishedInterface && $entity->isPublished()) {
      return AccessResult::allowed()->addCacheableDependency($entity);
    }

    return parent::checkAccess($entity, $operation, $account)->addCacheableDependency($entity);
  }

}
