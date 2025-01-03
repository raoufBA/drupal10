<?php

namespace Drupal\custom_field_permissions_instance\Plugin;

use Drupal\Component\Plugin\DerivativeInspectionInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * A field permission type plugin interface.
 */
interface FieldPermissionTypeInterface extends PluginInspectionInterface, DerivativeInspectionInterface {

  /**
   * Indicates that a field does not have field-specific access control.
   *
   * Public field access is not implemented as a plugin because it effectively
   * means this module does not process any access control for fields with this
   * type of permission.
   */
  const ACCESS_PUBLIC = 'public';

  /**
   * Indicates that a field is using the private access permission type.
   *
   * Private fields are never displayed, and are only editable by the author
   * (and by site administrators with the 'access private fields' permission).
   *
   * @see \Drupal\custom_field_permissions_instance\Plugin\FieldPermissionType\PrivateAccess
   * @internal
   *
   * This is here as a helper since there are still special handling of the
   * various plugins throughout this module.
   *
   */
  const ACCESS_PRIVATE = 'private';

  /**
   * Indicates that a field is using the custom permission type.
   *
   * @see \Drupal\custom_field_permissions_instance\Plugin\FieldPermissionType\RoleAccess
   * @internal
   *
   * This is here as a helper since there are still special handling of the
   * various plugins throughout this module.
   *
   */
  const ACCESS_CUSTOM = 'custom';

  /**
   * The permission type label.
   *
   * @return string
   *   The field permission type label.
   */
  public function getLabel();

  /**
   * The permission type description.
   *
   * @return string
   *   The field permission type description.
   */
  public function getDescription();

  /**
   * Determine if access to the field is granted for a given account.
   *
   * @param string $operation
   *   The operation to check. Either 'view' or 'edit'.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity the field is attached to.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user to check access for.
   *
   * @return bool
   *   The access result.
   */
  public function hasFieldAccess($operation, EntityInterface $entity, AccountInterface $account);

  /**
   * Determine if access to the field is granted for a given account for every
   * entity.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   * The user to check access for.
   *
   * @return bool
   * The access result.
   */
  public function hasFieldViewAccessForEveryEntity(AccountInterface $account): bool;

  /**
   * Checks whether this plugin can be applied to a certain field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   *
   * @return bool
   *   Whether this plugin can be applied to a certain field.
   */
  public function appliesToField(FieldDefinitionInterface $field_definition): bool;

}
