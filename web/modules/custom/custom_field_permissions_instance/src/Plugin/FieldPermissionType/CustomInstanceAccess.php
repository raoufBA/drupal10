<?php

namespace Drupal\custom_field_permissions_instance\Plugin\FieldPermissionType;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\field_permissions\FieldPermissionsServiceInterface;
use Drupal\field_permissions\Plugin\AdminFormSettingsInterface;
use Drupal\field_permissions\Plugin\CustomPermissionsInterface;
use Drupal\field_permissions\Plugin\FieldPermissionType\Base;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationship;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\PermissionScopeInterface;
use Drupal\group\Plugin\Group\Relation\GroupRelationTypeManagerInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\RoleStorageInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines custom access for fields.
 *
 * @FieldPermissionType(
 *   id = "custom_instance",
 *   title = @Translation("Custom instance permissions"),
 *   description = @Translation("Define custom permissions for this field in a
 *   instance context."), weight = 51
 * )
 */
class CustomInstanceAccess extends Base implements CustomPermissionsInterface, AdminFormSettingsInterface {

  /**
   * The permissions service.
   */
  var $permissionsService;

  /**
   * The GroupContentEnabler plugin.
   */
  var $groupContentEnablerManager;

  /**
   * Constructs the plugin.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\field\FieldStorageConfigInterface  $field_storage
   *   The field storage.
   * @param \Drupal\field_permissions\FieldPermissionsServiceInterface $permissions_service
   *   The permissions service
   * @param \Drupal\group\Plugin\Group\Relation\GroupRelationTypeManagerInterface $group_content_enabler_manager
   *   The group_content enabler manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, FieldStorageConfigInterface $field_storage, FieldPermissionsServiceInterface $permissions_service, GroupRelationTypeManagerInterface $group_content_enabler_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $field_storage);
    $this->permissionsService = $permissions_service;
    $this->groupContentEnablerManager = $group_content_enabler_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, FieldStorageConfigInterface $field_storage = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $field_storage,
      $container->get('field_permissions.permissions_service'),
      $container->get('group_relation_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function hasFieldAccess($operation, EntityInterface $entity, AccountInterface $account) {
    assert(in_array($operation, ["edit", "view"]), 'The operation is either "edit" or "view", "' . $operation . '" given instead.');

    // Change 'edit' operation to 'create' if required.
    if ($operation == 'edit' && $entity->isNew()) {
      $operation = 'create';
    }

    $memberships = [];

    $field_name = $this->fieldStorage->getName();

    if ($entity instanceof GroupInterface) {
      if ($entity->isNew()) {
        // New group entity, check to see if account has required permission.
        if ($entity->hasPermission($operation . ' ' . $field_name, $account)) {
          return TRUE;
        }
      }
      else {
        // Load group membership for this account, if any.
        if ($membership = $entity->getMember($account)) {
          $memberships[] = $membership;
        }
      }
    }
    elseif ($entity instanceof GroupRelationshipInterface) {
      // Load group membership for this account, if any.
      if ($membership = $entity->getGroup()->getMember($account)) {
        $memberships[] = $membership;
      }
    }
    elseif ($entity instanceof ContentEntityInterface) {
      // Note that a given content entity may belong to more than one group, so need to check them all.
      $plugin_id = 'group_' . $entity->getEntityTypeId();
      $plugin_id .= $entity->bundle() ? ':' . $entity->bundle() : '';

      $plugin_ids = $this->groupContentEnablerManager->getPluginGroupRelationshipTypeMap();
      if (isset($plugin_ids[$plugin_id])) {

        $ids = \Drupal::entityQuery('group_relationship')
          ->accessCheck(FALSE)
          ->condition('type', $plugin_ids[$plugin_id], 'IN')
          ->condition('entity_id', $entity->id())
          ->execute();

        $relations = GroupRelationship::loadMultiple($ids);

        foreach ($relations as $relation) {
          $group = $relation->getGroup();
          if ($group_membership = $group->getMember($account)) {
            $memberships[] = $group_membership;
          }
          else {
            // Anonymous account will not have membership but permission can be checked directly.
            if ($group->hasPermission($operation . ' ' . $field_name, $account)) {
              return TRUE;
            }
          }
        }
      }

    }
    else {
      // Account is not associated with group or group_content or content entity.
      return FALSE;
    }

    foreach ($memberships as $membership) {
      if ($membership->hasPermission($operation . ' ' . $field_name)) {
        return TRUE;
      }
      else {
        // User entities don't implement `EntityOwnerInterface`.
        if ($entity instanceof UserInterface) {
          if ($entity->id() == $account->id() && $account->hasPermission($operation . ' own ' . $field_name)) {
            return TRUE;
          }
        }
        elseif ($entity instanceof EntityOwnerInterface) {
          if ($entity->getOwnerId() == $account->id() && $membership->hasPermission($operation . ' own ' . $field_name)) {
            return TRUE;
          }
        }
      }
    }

    // Default to deny since access can be explicitly granted (edit field_name),
    // even if this entity type doesn't implement the EntityOwnerInterface.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function hasFieldViewAccessForEveryEntity(AccountInterface $account) {
    $field_name = $this->fieldStorage->getName();
    return $account->hasPermission('view ' . $field_name);
  }

  /**
   * {@inheritdoc}
   */
  public function buildAdminForm(array &$form, FormStateInterface $form_state, RoleStorageInterface $role_storage) {
    $this->addPermissionsGrid($form, $form_state, $role_storage);


    // Only display the permissions matrix if this type is selected.
    $form['#attached']['library'][] = 'field_permissions/field_permissions';
  }

  /**
   * {@inheritdoc}
   */
  public function submitAdminForm(array &$form, FormStateInterface $form_state, RoleStorageInterface $role_storage) {
    if ($form_state->hasValue('instance_perms')) {
      $group_role_storage = \Drupal::entityTypeManager()->getStorage('group_role');

      $custom_permissions = $form_state->getValue('instance_perms');
      /** @var \Drupal\group\Entity\GroupRoleInterface[] $roles */
      $roles = [];
      foreach ($custom_permissions as $permission_name => $field_perm) {
        foreach ($field_perm as $role_name => $role_permission) {
          if (empty($roles[$role_name])) {
            $roles[$role_name] = $group_role_storage->load($role_name);
          }
          // If using this plugin, set permissions to the value submitted in the
          // form, else remove all permissions as they will no longer exist.
          $role_permission = $form_state->getValue('type') === $this->getPluginId() ? $role_permission : FALSE;
          if ($role_permission) {
            $roles[$role_name]->set('permissions', array_unique(array_merge($roles[$role_name]->getPermissions(), [$permission_name])));
          }
          else {
            $roles[$role_name]->set('permissions',  array_diff($roles[$role_name]->getPermissions(), [$permission_name]));
          }
        }
      }
      // Save all roles.
      foreach ($roles as $role) {
        $role->trustData()->save();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getPermissions() {
    $permissions = [];
    $field_name = $this->fieldStorage->getName();
    $permission_list = $this->permissionsService->getList($field_name);
    $perms_name = array_keys($permission_list);
    foreach ($perms_name as $perm_name) {
      $name = $perm_name . ' ' . $field_name;
      $permissions[$name] = $permission_list[$perm_name];
    }
    return $permissions;
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

    $group_types = \Drupal::entityTypeManager()->getStorage('group_type')->loadMultiple();
    $group_role_storage = \Drupal::entityTypeManager()->getStorage('group_role');
    /** @var \Drupal\group\Entity\GroupRoleInterface[] $roles */
    $roles = $group_role_storage->loadMultiple();
    $permissions = $this->getPermissions();
    $options = array_keys($permissions);

    $test = $this->permissionsService->getGroupPermissionsByRole();
    $form['fpi_details'] = [
      '#type' => 'details',
      '#title' => $this->t('Instance types'),
      '#open' => TRUE,
      '#id' => 'instance_perms',
    ];

    $lables = ['Retail', 'Instit'];
    $i = 0;
    foreach ($group_types as $group_type) {
      // Make the permissions table for each group type into a separate panel.
      $form['fpi_details'][$group_type->id()] = [
        '#type' => 'details',
        '#title' => $lables[$i],
        '#open' => true,
      ];
      $i++;
      // The permissions table.
      $form['fpi_details'][$group_type->id()]['instance_perms'] = [
        '#type' => 'table',
        '#header' => [$this->t('Permission')],
        '#attributes' => ['class' => ['permissions', 'js-permissions']],
        '#sticky' => TRUE,
      ];
      foreach ($roles as $role) {
      //  if ($role->getGroupTypeId() == $group_type->id() && $role->getScope() === PermissionScopeInterface::INSIDER_ID) {
          $form['fpi_details'][$group_type->id()]['instance_perms']['#header'][] = [
            'data' => $role->label(),
            'class' => ['checkbox'],
          ];
       // }
      }

      foreach ($permissions as $provider => $permission) {
        $form['fpi_details'][$group_type->id()]['instance_perms'][$provider]['description'] = [
          '#type' => 'inline_template',
          '#template' => '<div class="permission"><span class="title">{{ title }}</span>{% if description or warning %}<div class="description">{% if warning %}<em class="permission-warning">{{ warning }}</em> {% endif %}{{ description }}</div>{% endif %}</div>',
          '#context' => [
            'title' => $permission["title"],
          ],
        ];

        $options[$provider] = '';

        /** @var \Drupal\group\Entity\GroupRole $role */
        foreach ($roles as $name => $role) {
         // if ($role->getGroupTypeId() == $group_type->id() && $role->getScope() === PermissionScopeInterface::INSIDER_ID) {
            $form['fpi_details'][$group_type->id()]['instance_perms'][$provider][$name] = [
              '#title' => $name . ': ' . $permission["title"],
              '#title_display' => 'invisible',
              '#type' => 'checkbox',
              '#attributes' => ['class' => ['rid-' . $name, 'js-rid-' . $name]],
              '#wrapper_attributes' => [
                'class' => ['checkbox'],
              ],
            ];
            if (!empty($test[$name]) && in_array($provider, $test[$name])) {
              $form['fpi_details'][$group_type->id()]['instance_perms'][$provider][$name]['#default_value'] = in_array($provider, $test[$name]);
            }
        //  }
        }
      }

    }

    // Attach the field_permissions_group library.
    $form['#attached']['library'][] = 'custom_field_permissions_instance/field_permissions';
  }

}
