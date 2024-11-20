<?php

namespace Drupal\custom_field_permissions_instance\Plugin\FieldPermissionType;

use Drupal;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\field_permissions\FieldPermissionsServiceInterface;
use Drupal\field_permissions\Plugin\AdminFormSettingsInterface;
use Drupal\field_permissions\Plugin\CustomPermissionsInterface;
use Drupal\field_permissions\Plugin\FieldPermissionType\Base;
use Drupal\mosaic_app\Helper\AwfInstanceHelper;
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
  const HOSTS_FILE = DRUPAL_ROOT . '/../config/hosts.yaml';

  /**
   * The permission service.
   *
   * @var \Drupal\field_permissions\FieldPermissionsServiceInterface
   */
  protected $permissionsService;

  /**
   * Constructs the plugin.
   *
   * @param array<mixed> $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\field\FieldStorageConfigInterface $field_storage
   *   The field storage.
   * @param \Drupal\field_permissions\FieldPermissionsServiceInterface $permissions_service
   *   The permissions service
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, FieldStorageConfigInterface $field_storage, FieldPermissionsServiceInterface $permissions_service) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $field_storage);
    $this->permissionsService = $permissions_service;
  }

  /**
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   * @param array<mixed> $configuration
   * @param $plugin_id
   * @param $plugin_definition
   * @param \Drupal\field\FieldStorageConfigInterface|null $field_storage
   *
   * @return \Drupal\custom_field_permissions_instance\Plugin\FieldPermissionType\CustomInstanceAccess|\Drupal\field_permissions\Plugin\FieldPermissionType\Base|static
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, FieldStorageConfigInterface $field_storage = null) {
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
  public function hasFieldAccess($operation, EntityInterface $entity, AccountInterface $account) :bool {
    assert(in_array($operation, [
      "edit",
      "view",
    ]), 'The operation is either "edit" or "view", "' . $operation . '" given instead.');

    $field_name = $this->fieldStorage->getName();
    $current_instance = AwfInstanceHelper::getMosaicId() . ' ';
    if ($operation === 'edit' && $entity->isNew()) {
      return $account->hasPermission($current_instance . 'create ' . $field_name);
    }

    if ($account->hasPermission($current_instance . $operation . ' ' . $field_name)) {
      return true;
    }
    else {
      // User entities don't implement `EntityOwnerInterface`.
      if ($entity instanceof UserInterface) {
        return $entity->id() == $account->id() && $account->hasPermission($current_instance . $operation . ' own ' . $field_name);
      }
      elseif ($entity instanceof EntityOwnerInterface) {
        return $entity->getOwnerId() === $account->id() && $account->hasPermission($current_instance . $operation . ' own ' . $field_name);
      }
    }

    // Default to deny since access can be explicitly granted (edit field_name),
    // even if this entity type doesn't implement the EntityOwnerInterface.
    return false;
  }

  /**
   * {@inheritdoc}
   */
  public function hasFieldViewAccessForEveryEntity(AccountInterface $account) :bool {
    $field_name = $this->fieldStorage->getName();
    $current_instance = AwfInstanceHelper::getMosaicId() . ' ';

    return $account->hasPermission($current_instance . 'view ' . $field_name);
  }

  /**
   * @param array<mixed> $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @param \Drupal\user\RoleStorageInterface $role_storage
   *
   * @return void
   */
  public function buildAdminForm(array &$form, FormStateInterface $form_state, RoleStorageInterface $role_storage) :void {
    $this->addPermissionsGrid($form, $form_state, $role_storage);

    // Only display the permissions matrix if this type is selected.
    $form['#attached']['library'][] = 'field_permissions/field_permissions';
  }

  /**
   * Attach a permissions grid to the field edit form.
   *
   * @param array<mixed> $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   * @param \Drupal\user\RoleStorageInterface $role_storage
   *   The user role storage.
   */
  protected function addPermissionsGrid(array &$form, FormStateInterface $form_state, RoleStorageInterface $role_storage) : void {
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
    /* @phpstan-ignore-next-line */
    $instances = $this->permissionsService->getPrdInstances();

    foreach ($instances as $instance) {
      //      $options = array_keys($permissions);
      // Make the permissions table for each instance type into a separate panel.
      $open = false;
      foreach ($test as $role_name => $pers) {
        if ($role_name != 'administrator') {
          foreach ($pers as $per) {
            if (str_contains($per, $instance)) {
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
        '#attributes' => ['class' => ['permissions', 'js-permissions']],
        '#sticky' => true,
      ];
      foreach ($roles as $role) {
        $form['fpi_details'][$instance]['instance_perms']['#header'][] = [
          'data' => $role->label(),
          'class' => ['checkbox'],
        ];
      }
      $permission_instance = array_filter($permissions, function($permission) use ($instance) {
        if (str_contains($permission, $instance)) {
          return $permission;
        }
      }, ARRAY_FILTER_USE_KEY);

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
            $form['fpi_details'][$instance]['instance_perms'][$provider][$name]['#disabled'] = true;
            $form['fpi_details'][$instance]['instance_perms'][$provider][$name]['#default_value'] = true;
          }
        }
      }
    }
    // Attach the field_permissions_instance library.
    $form['#attached']['library'][] = 'custom_field_permissions_instance/field_permissions';
  }

  /**
   * @return array<mixed>
   */
  public function getPermissions() :array {
    $permissions = [];
    $field_name = $this->fieldStorage->getName();
    $permission_list = $this->permissionsService->getList($field_name);
    $perms_name = array_keys($permission_list);
    /* @phpstan-ignore-next-line */
    $instances = $this->permissionsService->getPrdInstances();
    foreach ($perms_name as $perm_name) {
      $name = $perm_name . ' ' . $field_name;
      $permissions[$name] = $permission_list[$perm_name];
      foreach ($instances as $instance) {
        $name = $instance . ' ' . $perm_name . ' ' . $field_name;
        $permissions[$name] = $permission_list[$perm_name];
      }
    }

    return $permissions;
  }

  /**
   * @param array<mixed> $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @param \Drupal\user\RoleStorageInterface $role_storage
   *
   * @return void
   */
  public function submitAdminForm(array &$form, FormStateInterface $form_state, RoleStorageInterface $role_storage) :void {
    $this_plugin_applies = $form_state->getValue('type') === $this->getPluginId();
    $custom_permissions = $form_state->getValue('instance_perms');
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
        $role->set('permissions', $permissions);
        $role->trustData()->save();
      }
    }
  }

  /**
   * @param array<mixed> $original
   *
   * @return array<mixed>
   */
  protected function transposeArray(array $original) :array {
    $transpose = [];
    foreach ($original as $row => $columns) {
      foreach ($columns as $column => $value) {
        $transpose[$column][$row] = $value;
      }
    }

    return $transpose;
  }

}
