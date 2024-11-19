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
  public function __construct(array $configuration, $plugin_id, $plugin_definition,
    FieldStorageConfigInterface $field_storage,
    FieldPermissionsServiceInterface $permissions_service) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $field_storage);
    $this->permissionsService = $permissions_service;
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function hasFieldAccess($operation, EntityInterface $entity, AccountInterface $account) {
    assert(in_array($operation, ["edit", "view"]), 'The operation is either "edit" or "view", "' . $operation . '" given instead.');

    $field_name = $this->fieldStorage->getName();
    $current_instance = 'amtt';
    if ($operation === 'edit' && $entity->isNew()) {
      return $account->hasPermission($current_instance.' create ' . $field_name);
    }

    if ($account->hasPermission($current_instance.' '.$operation . ' ' . $field_name)) {
      return TRUE;
    }
    else {
      // User entities don't implement `EntityOwnerInterface`.
      if ($entity instanceof UserInterface) {
        return $entity->id() == $account->id() && $account->hasPermission($current_instance.' '.$operation . ' own ' . $field_name);
      }
      elseif ($entity instanceof EntityOwnerInterface) {
        return $entity->getOwnerId() === $account->id() && $account->hasPermission($current_instance.' '.$operation . ' own ' . $field_name);
      }
    }

    // Default to deny since access can be explicitly granted (edit field_name),
    // even if this entity type doesn't implement the EntityOwnerInterface.
    return FALSE;
  }


  protected function transposeArray(array $original) {
    $transpose = [];
    foreach ($original as $row => $columns) {
      foreach ($columns as $column => $value) {
        $transpose[$column][$row] = $value;
      }
    }
    return $transpose;
  }

  /**
   * {@inheritdoc}
   */
  public function hasFieldViewAccessForEveryEntity(AccountInterface $account) {
    $field_name = $this->fieldStorage->getName();
    $current_instance = 'amtt';
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
  public function getPermissions() {
    $permissions = [];
    $field_name = $this->fieldStorage->getName();
    $permission_list = $this->permissionsService->getList($field_name);
    $perms_name = array_keys($permission_list);
    $instances = $this->getPrdInstances();
    foreach ($perms_name as $perm_name) {
      $name = $perm_name.' '.$field_name;
      $permissions[$name] = $permission_list[$perm_name];
      foreach ($instances as $instance) {
        $name = $instance.' '. $perm_name.' '.$field_name;
        $permissions[$name] = $permission_list[$perm_name];
      }
    }

    return $permissions;
  }


  /**
   * {@inheritdoc}
   */
  public function submitAdminForm(array &$form, FormStateInterface $form_state, RoleStorageInterface $role_storage) {

    $this_plugin_applies = $form_state->getValue('type') === $this->getPluginId();
    $custom_permissions = $form_state->getValue('instance_perms');

//    dump('permission', $custom_permissions);
    $keys = array_keys($custom_permissions);
    $custom_permissions = $this->transposeArray($custom_permissions);
    foreach ($role_storage->loadMultiple() as $role) {
      $permissions = $role->getPermissions();
      $removed = array_values(array_intersect($permissions, $keys));
      $added = $this_plugin_applies ? array_keys(array_filter($custom_permissions[$role->id()])) : [];
      // Permissions in role object are sorted on save. Permissions on form are
      // not in same order (the 'any' and 'own' items are flipped) but need to
      // be as array equality tests keys and values. So sort the added items.
      sort($added);
      if ($removed != $added) {
        // Rule #1 Do NOT save something that is not changed.
        // Like field storage, delete existing items then add current items.
        $permissions = array_diff($permissions, $removed);
        $permissions = array_merge($permissions, $added);
        $permissions;
        $role->set('permissions', $permissions);
        dump('ins',$role);
          $role->trustData()->save();
        dump('end');
//        }
      }
    }


//    if ($form_state->hasValue('instance_perms')) {
//      $user_role_storage = $role_storage->loadMultiple();
//
//      $custom_permissions = $form_state->getValue('instance_perms');
//
//      /** @var \Drupal\group\Entity\UserRoleInterface[] $roles */
//      $roles = [];
//      foreach ($custom_permissions as $permission_name => $field_perm) {
//        foreach ($field_perm as $role_name => $role_permission) {
//          if (empty($roles[$role_name])) {
//            $roles[$role_name] = $user_role_storage[$role_name];
//          }
//          // If using this plugin, set permissions to the value submitted in the
//          // form, else remove all permissions as they will no longer exist.
//          $role_permission = $form_state->getValue('type') === $this->getPluginId() ? $role_permission : FALSE;
//          if ($role_permission) {
//            $roles[$role_name]->grantPermission($permission_name);
//          }
//          else {
//            $roles[$role_name]->revokePermission($permission_name);
//          }
//        }
//      }
////      dump($roles);
////      exit();
//
////      exit();
//      // Save all roles.
//     foreach ($roles as $role) {
//        $role->trustData()->save();
//      }
//    }
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
//    dump($permissions);/
//    exit();

    $options = array_keys($permissions);

    $test = $this->permissionsService->getPermissionsByRole();
    dump($test);


    $form['fpi_details'] = [
      '#type' => 'details',
      '#title' => $this->t('Instance types'),
      '#open' => true,
      '#id' => 'instance_perms',
    ];

    $instances = $this->getPrdInstances();

    foreach ($instances as $instance) {
//      $options = array_keys($permissions);
      // Make the permissions table for each group type into a separate panel.
      $open = false;
      foreach ($test as $role_name =>$pers){
        if($role_name != 'administrator') {
          foreach ($pers as $per) {

            if (str_contains($per, $instance)){
              dump($instance , $per);
              $open = true;
              break 2;
            }
        }
        }
      }

      $form['fpi_details'][$instance] = [
        '#type' => 'details',
        '#title' => $instance,
        '#open' => $open,
      ];
      // The permissions table.
      $form['fpi_details'][$instance]['instance_perms'] = [
        '#type' => 'table',
        '#header' => [$this->t('Permission')],
       // '#attributes' => ['class' => ['permissions', 'js-permissions']],
        '#sticky' => true,
      ];
      foreach ($roles as $role) {
          $form['fpi_details'][$instance]['instance_perms']['#header'][] = [
            'data' => $role->label(),
            'class' => ['checkbox'],
          ];
      }
      $permission_instance = array_filter($permissions , function ($permission) use ($instance){
        if(str_contains($permission, $instance)) return $permission;
      },ARRAY_FILTER_USE_KEY);


      foreach ($permission_instance as $provider => $permission) {
        $form['fpi_details'][$instance]['instance_perms'][$provider]['description'] = [
          '#type' => 'inline_template',
          '#template' => '<div class="permission"><span class="title">{{ title }}</span>{% if description or warning %}<div class="description">{% if warning %}<em class="permission-warning">{{ warning }}</em> {% endif %}{{ description }}</div>{% endif %}</div>',
          '#context' => [
            'title' => $permission["title"],
          ],
        ];

        $options[$provider] = '';

        foreach ($roles as $name => $role) {
            $form['fpi_details'][$instance]['instance_perms'][$provider][$name] = [
              '#title' => $name . ': ' . $permission["title"],
              '#title_display' => 'invisible',
              '#type' => 'checkbox',
              '#attributes' => ['class' => ['rid-' . $name, 'js-rid-' . $name]],
              '#wrapper_attributes' => [
                'class' => ['checkbox'],
              ],
            ];
            if (!empty($test[$name]) && in_array($provider, $test[$name])) {
              $form['fpi_details'][$instance]['instance_perms'][$provider][$name]['#default_value'] = in_array($provider, $test[$name]);
            }

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
