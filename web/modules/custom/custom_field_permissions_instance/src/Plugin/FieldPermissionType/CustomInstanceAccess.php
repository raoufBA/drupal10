<?php

namespace Drupal\custom_field_permissions_instance\Plugin\FieldPermissionType;

use Drupal;
use Drupal\Core\Cache\Cache;
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
use Symfony\Component\Yaml\Yaml;

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

 // File to get all instances.
  const HOSTS_FILE = DRUPAL_ROOT.'/../config/hosts.yaml';

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
          return true;
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
              return true;
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
        return true;
      }
      else {
        // User entities don't implement `EntityOwnerInterface`.
        if ($entity instanceof UserInterface) {
          if ($entity->id() == $account->id() && $account->hasPermission($operation . ' own ' . $field_name)) {
            return true;
          }
        }
        elseif ($entity instanceof EntityOwnerInterface) {
          if ($entity->getOwnerId() == $account->id() && $membership->hasPermission($operation . ' own ' . $field_name)) {
            return true;
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
    $this_plugin_applies = $form_state->getValue('type') === $this->getPluginId();
    $custom_permissions = $form_state->getValue('instance_perms');
$all_permissions_by_role = $this->getInstancePermissionsByRole();
//    $custom_permissions = $this->transposeArray($custom_permissions);
    $roles = $role_storage->loadMultiple();
    unset($roles['administrator']);
    foreach ($roles as $role) {
      if(!array_key_exists($role->id(), $custom_permissions)){
        continue;
      }
      $keys =  $all_permissions_by_role[$role->id()];

      $permissions = $role->getPermissions();

      $removed = array_values(array_intersect($permissions, $keys));
      $added = $this_plugin_applies ? array_keys(array_filter($custom_permissions[$role->id()])) : [];

      // Permissions in role object are sorted on save. Permissions on form are
      // not in same order (the 'any' and 'own' items are flipped) but need to
      // be as array equality tests keys and values. So sort the added items.
      if ($removed != $added) {
        // Rule #1 Do NOT save something that is not changed.
        // Like field storage, delete existing items then add current items.
        $permissions = array_diff($permissions, $removed);
        $permissions = array_merge($permissions, $added);

        $role->set('permissions', $permissions);
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

    /** @var \Drupal\user\RoleInterface[] $roles */
    $roles = $role_storage->loadMultiple();
    $permissions = $this->getPermissions();
    $options = array_keys($permissions);

    $test = $this->permissionsService->getPermissionsByRole();
    $form['fpi_details'] = [
      '#type' => 'details',
      '#title' => $this->t('Instance types'),
      '#open' => true,
      '#id' => 'instance_perms',
    ];

    $instances = $this->getPrdInstances();

    foreach ($instances as $instance) {
      // Make the permissions table for each group type into a separate panel.
      $form['fpi_details'][$instance] = [
        '#type' => 'details',
        '#title' => $instance,
        '#open' => false,
      ];
      // The permissions table.
      $form['fpi_details'][$instance]['instance_perms'] = [
        '#type' => 'table',
        '#header' => [$this->t('Permission')],
        '#attributes' => ['class' => ['permissions', 'js-permissions']],
        '#sticky' => true,
      ];
      foreach ($roles as $role) {
          $form['fpi_details'][$instance]['instance_perms']['#header'][] = [
            'data' => $role->label(),
            'class' => ['checkbox'],
          ];
      }

      foreach ($permissions as $provider => $permission) {
        $form['fpi_details'][$instance]['instance_perms'][$provider]['description'] = [
          '#type' => 'inline_template',
          '#template' => '<div class="permission"><span class="title">{{ title }}</span>{% if description or warning %}<div class="description">{% if warning %}<em class="permission-warning">{{ warning }}</em> {% endif %}{{ description }}</div>{% endif %}</div>',
          '#context' => [
            'title' => $permission["title"],
          ],
        ];

        $options[$provider] = '';

        /** @var \Drupal\group\Entity\GroupRole $role */
        foreach ($roles as $name => $role) {
            $form['fpi_details'][$instance]['instance_perms'][$provider][$name] = [
              '#title' => $name . ': ' . $permission["title"],
              '#title_display' => 'invisible',
              '#type' => 'checkbox',
              '#attributes' => ['class' => ['rid-' . $name, 'js-rid-' . $name]],
              '#wrapper_attributes' => [
                'class' => ['checkbox'],
              ],
              '#name' => "instance_perms[$name][$instance $provider]",
              '#default_value' => 0,
              //instance_perms[create field_color][content_editor]
            ];
//            if (!empty($test[$name]) && in_array($provider, $test[$name])) {
//              $form['fpi_details'][$instance]['instance_perms'][$provider][$name]['#default_value'] = in_array($provider, $test[$name]);
//            }

          if ($role->isAdmin()) {
            $form['fpi_details'][$instance]['instance_perms'][$provider][$name]['#disabled'] = TRUE;
            $form['fpi_details'][$instance]['instance_perms'][$provider][$name]['#default_value'] = TRUE;
          }
        }
      }
    }
    // Attach the field_permissions_group library.
    $form['#attached']['library'][] = 'custom_field_permissions_instance/field_permissions';
  }

  /**
   * {@inheritdoc}
   */
  public function getInstancePermissionsByRole() {
    $field_field_permissions = [];
    $instances = $this->getPrdInstances();
    $permissions = $this->getPermissions();
    $roles = $this->fieldPermissionsService->getRoles();
    // Delete administrator role.
    unset($roles['administrator']);
    foreach ($roles as $name => $role) {
    foreach ($instances as $instance) {
        foreach ($permissions as $provider => $permission) {
          $field_field_permissions[$name][] =  "$instance $provider";
        }
      }
    }
    return $field_field_permissions;
  }

  /**
   * @return array
   */
  public function getPrdInstances(): array {
    // Access the cache service
    $cache = Drupal::cache();
    // Define a unique cache key to store and retrieve this data
    $cache_key = 'custom_field_permissions_instance';

    // Retrieve the cached data using the same cache key
    if ($cached_data = $cache->get($cache_key)) {
      // Cache data was found; retrieve it from the 'data' property
      return $cached_data->data;
    }

    $instances = [];

    if (file_exists(self::HOSTS_FILE)) {
      $donnees = Yaml::parseFile(self::HOSTS_FILE);

      // Accéder aux données sous la clé 'prd'
      if (isset($donnees['prd']) && is_array($donnees['prd'])) {
        foreach ($donnees['prd'] as $key => $valeur) {
          $instances[] = $key;
        }
        if ($instances) {
          sort($instances);
          // Store the data in the cache
          $cache->set($cache_key, $instances, Cache::PERMANENT);
        }
      } else {
        Drupal::logger('mon_module')->error('La clé "prd" est introuvable ou n\'est pas un tableau dans hosts.yaml.');
      }
    } else {
      Drupal::logger('mon_module')->error(
        'Le fichier YAML n\'a pas été trouvé au chemin : @chemin', ['@chemin' => self::HOSTS_FILE]
      );
    }

    return $instances;
  }



}
