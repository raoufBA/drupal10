<?php

namespace Drupal\custom_field_permissions_instance\Plugin\FieldPermissionType;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\UserInterface;

/**
 * Defines a private field type.
 *
 * @CustomFieldPermissionInstanceType(
 *   id = "private",
 *   title = @Translation("Private"),
 *   description = @Translation("Only author and administrators can edit and
 *   view."), weight = 25
 * )
 */
class PrivateAccess extends Base {

  /**
   * {@inheritdoc}
   */
  public function hasFieldAccess($operation, EntityInterface $entity, AccountInterface $account) {
    if ($account->hasPermission('access private fields')) {
      return true;
    }

    // Users can access the field when creating new entities.
    if ($entity->isNew()) {
      return true;
    }

    // Special handling for 'user' entities.
    if ($entity instanceof UserInterface) {
      return $entity->id() == $account->id();
    }
    elseif ($entity instanceof EntityOwnerInterface) {
      return $entity->getOwnerId() === $account->id();
    }

    return true;
  }

  /**
   * {@inheritdoc}
   */
  public function hasFieldViewAccessForEveryEntity(AccountInterface $account): bool {
    return $account->hasPermission('access private fields');
  }

}
