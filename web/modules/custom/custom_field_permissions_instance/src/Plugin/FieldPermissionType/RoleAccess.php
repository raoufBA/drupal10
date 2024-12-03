<?php

namespace Drupal\custom_field_permissions_instance\Plugin\FieldPermissionType;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\custom_field_permissions_instance\Annotation\CustomFieldPermissionInstanceType;
use Drupal\custom_field_permissions_instance\Plugin\AdminFormSettingsInterface;
use Drupal\custom_field_permissions_instance\Plugin\CustomPermissionsInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\RoleStorageInterface;
use Drupal\user\UserInterface;

/**
 * Defines custom access for fields.
 *
 * @CustomFieldPermissionInstanceType(
 *   id = "custom",
 *   title = @Translation("Custom permissions"),
 *   description = @Translation("Define custom permissions for this field."),
 *   weight = 50
 * )
 */
class RoleAccess extends Base implements CustomPermissionsInterface, AdminFormSettingsInterface {

  const KEY_CONFIG_PERMISSIONS = 'role_permissions';

  const FORM_ID = 'permissions';

  /**
   * {@inheritdoc}
   */
  public function hasFieldAccess($operation, EntityInterface $entity, AccountInterface $account) {
    assert(in_array($operation, [
      "edit",
      "view",
    ]), 'The operation is either "edit" or "view", "' . $operation . '" given instead.');

    $configPermission = $this->getConfigPermissions(self::KEY_CONFIG_PERMISSIONS);
    $bundle = $entity->bundle();

    if (!isset($configPermission[$bundle])) {
      return true;
    }

    $permissions = $configPermission[$bundle];

    $entity_permissions = $permissions;

    if ($operation === 'edit' && $entity->isNew()) {
      return $this->UserHasPermission($account, $entity_permissions[$operation]);
    }

    if ($this->UserHasPermission($account, $entity_permissions[$operation])) {
      return true;
    }
    else {
      // User entities don't implement `EntityOwnerInterface`.
      if ($entity instanceof UserInterface) {
        return $entity->id() == $account->id() && $this->UserHasPermission($account, $entity_permissions[$operation . ' own']);
      }
      elseif ($entity instanceof EntityOwnerInterface) {
        return $entity->getOwnerId() === $account->id() && $this->UserHasPermission($account, $entity_permissions[$operation . ' own']);
      }
    }
    // Default to deny since access can be explicitly granted (edit field_name),
    // even if this entity type doesn't implement the EntityOwnerInterface.
    return false;
  }

  /**
   * {@inheritdoc}
   */
  public function hasFieldViewAccessForEveryEntity(AccountInterface $account): bool {
    return false;
    //    $field_name = $this->fieldStorage->getName();
    //    return $account->hasPermission('view ' . $field_name);
  }

  /**
   * {@inheritdoc}
   */
  public function buildAdminForm(array &$form, FormStateInterface $form_state, RoleStorageInterface $role_storage) {
    $this->addPermissionsGrid($form, $form_state, $role_storage);
  }

  /**
   * Attach a permissions grid to the field edit form.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   * @param \Drupal\user\RoleStorageInterface $role_storage
   *   The user role storage.
   */
  protected function addPermissionsGrid(array &$form, FormStateInterface $form_state, RoleStorageInterface $role_storage) {
    /** @var \Drupal\user\RoleInterface[] $roles */
    $roles = $role_storage->loadMultiple();
    $permissions = $this->getPermissions();
    $options = array_keys($permissions);

    // The permissions table.
    $form['permissions'] = [
      '#type' => 'table',
      '#header' => [$this->t('Permission')],
      '#id' => 'permissions',
      '#attributes' => ['class' => ['permissions', 'js-permissions']],
      '#sticky' => true,
    ];
    foreach ($roles as $role) {
      $form['permissions']['#header'][] = [
        'data' => $role->label(),
        'class' => ['checkbox'],
      ];
    }

    $field_config = $form_state->getFormObject()->getEntity();

    $current_bundle = $field_config->getTargetBundle();

    $lodData = $this->getConfigPermissions(self::KEY_CONFIG_PERMISSIONS);
    // Ensure we are only working with the current bundle's data.
    if (!isset($lodData[$current_bundle])) {
      $lodData[$current_bundle] = [];
    }

    $lodData = $lodData[$current_bundle];

    foreach ($permissions as $provider => $permission) {
      $form['permissions'][$provider]['description'] = [
        '#type' => 'inline_template',
        '#template' => '<div class="permission"><span class="title">{{ title }}</span>{% if description or warning %}<div class="description">{% if warning %}<em class="permission-warning">{{ warning }}</em> {% endif %}{{ description }}</div>{% endif %}</div>',
        '#context' => [
          'title' => $permission["title"],
        ],
      ];
      $options[$provider] = '';
      foreach ($roles as $name => $role) {
        $form['permissions'][$provider][$name] = [
          '#title' => $name . ': ' . $permission["title"],
          '#title_display' => 'invisible',
          '#type' => 'checkbox',
          '#parents' => ['permissions', $current_bundle, $provider, $name],
          '#attributes' => ['class' => ['rid-' . $name, 'js-rid-' . $name]],
          '#wrapper_attributes' => [
            'class' => ['checkbox'],
          ],
        ];

        // Set default values based on existing permissions.
        if (!empty($lodData[$provider][$name])) {
          $form['permissions'][$provider][$name]['#default_value'] = in_array($provider, $lodData[$provider]);
        }

        if ($role->isAdmin()) {
          $form['permissions'][$provider][$name]['#disabled'] = true;
          $form['permissions'][$provider][$name]['#default_value'] = true;
        }
      }
    }
    // Attach the Drupal user permissions library.
    $form['#attached']['library'][] = 'user/drupal.user.permissions';
  }

}
