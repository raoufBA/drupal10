<?php

namespace Drupal\custom_field_permissions_instance\Plugin\FieldPermissionType;

use Drupal;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\custom_field_permissions_instance\AwfInstanceHelper;
use Drupal\custom_field_permissions_instance\CustomFieldPermissionsServiceInterface;
use Drupal\custom_field_permissions_instance\Plugin\AdminFormSettingsInterface;
use Drupal\custom_field_permissions_instance\Plugin\CustomPermissionsInterface;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\RoleInterface;
use Drupal\user\RoleStorageInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines custom access for fields.
 *
 * @CustomFieldPermissionInstanceType(
 *   id = "custom_instance",
 *   title = @Translation("Custom instance permissions"),
 *   description = @Translation("Define custom permissions for this field in a
 *   instance context."), weight = 51
 * )
 */
class InstanceAccess extends Base implements CustomPermissionsInterface, AdminFormSettingsInterface {

  // File to get all instances.
  const HOSTS_FILE = DRUPAL_ROOT . '/../config/hosts.yaml';

  const KEY_CONFIG_PERMISSIONS = 'instance_permissions';

  const FORM_ID = 'instance_perms';

  /**
   * The permission service.
   *
   * @var \Drupal\custom_field_permissions_instance\CustomFieldPermissionsServiceInterface
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
   * @param \Drupal\custom_field_permissions_instance\CustomFieldPermissionsServiceInterface $permissions_service
   *   The permissions service
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, FieldStorageConfigInterface $field_storage, CustomFieldPermissionsServiceInterface $permissions_service) {
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
   * @return \Drupal\custom_field_permissions_instance\Plugin\FieldPermissionType\InstanceAccess|\Drupal\custom_field_permissions_instance\Plugin\FieldPermissionType\Base|static
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, FieldStorageConfigInterface $field_storage = null) {
    return new static($configuration, $plugin_id, $plugin_definition, $field_storage, $container->get('custom_field_permissions_instance.permissions_service'),);
  }

  /**
   * {@inheritdoc}
   */
  public function hasFieldAccess($operation, EntityInterface $entity, AccountInterface $account): bool {
    assert(in_array($operation, [
      "edit",
      "view",
    ]), 'The operation is either "edit" or "view", "' . $operation . '" given instead.');

    $configPermission = $this->getConfigPermissions(self::KEY_CONFIG_PERMISSIONS);
    $current_instance = AwfInstanceHelper::getMosaicId();
    $bundle = $entity->bundle();
    if (!$current_instance || !$permissions = $configPermission[$bundle]) {
      return true;
    }

    $entity_permissions = $permissions[$current_instance];

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
  public function buildAdminForm(array &$form, FormStateInterface $form_state, RoleStorageInterface $role_storage): void {
    $this->addPermissionsGrid($form, $form_state, $role_storage);

    // Only display the permissions matrix if this type is selected.
    $form['#attached']['library'][] = 'custom_field_permissions_instance/custom_field_permissions_instance';
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
  protected function addPermissionsGrid(array &$form, FormStateInterface $form_state, RoleStorageInterface $role_storage): void {
    /** @var RoleInterface[] $roles */
    $roles = $role_storage->loadMultiple();
    $permissions = $this->getPermissions();

    $field_name = $this->fieldStorage->getName();
    // Charger la configuration du stockage de champ.
    if (!$this->fieldStorage) {
      $this->messenger->addError(t('The field @field_name does not exist.', ['@field_name' => $field_name]));

      return;
    }

    $field_config = $form_state->getFormObject()->getEntity();

    $current_bundle = $field_config->getTargetBundle();

    $lodData = $this->getConfigPermissions(self::KEY_CONFIG_PERMISSIONS);
    // Ensure we are only working with the current bundle's data.
    if (!isset($lodData[$current_bundle])) {
      $lodData[$current_bundle] = [];
    }

    $form['fpi_details'] = [
      '#type' => 'details',
      '#title' => $this->t('Instance types'),
      '#open' => true,
      '#id' => 'instance_perms',
    ];

    $instances = $this->permissionsService->getPrdInstances();

    foreach ($instances as $instance) {
      $open = false;

      // Iterate over the permissions of the current instance for the current bundle
      foreach ($lodData[$current_bundle][$instance] ?? [] as $role_permissions) {
        // Check for any non-admin role with a value of '1'
        if (count(array_diff($role_permissions, ["1"])) != 3) {
          $open = true;
          break; // Stop once a valid permission is found
        }
      }

      $form['fpi_details'][$instance] = [
        '#type' => 'details',
        '#title' => $instance,
        '#open' => $open,
      ];

      // Permissions table.
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

      foreach ($permissions as $permission_key => $permission) {
        $form['fpi_details'][$instance]['instance_perms'][$permission_key]['description'] = [
          '#type' => 'inline_template',
          '#template' => '<div class="permission"><span class="title">{{ title }}</span>{% if description or warning %}<div class="description">{% if warning %}<em class="permission-warning">{{ warning }}</em> {% endif %}{{ description }}</div>{% endif %}</div>',
          '#context' => [
            'title' => $permission["title"],
          ],
        ];

        foreach ($roles as $role_id => $role) {
          $form['fpi_details'][$instance]['instance_perms'][$permission_key][$role_id] = [
            '#title' => $role_id . ': ' . $permission["title"],
            '#title_display' => 'invisible',
            '#type' => 'checkbox',
            '#attributes' => [
              'class' => ['rid-' . $role_id, 'js-rid-' . $role_id],
            ],
            '#wrapper_attributes' => [
              'class' => ['checkbox'],
            ],
            // Use the structure to generate the correct name attribute.
            '#parents' => [
              'instance_perms',
              $current_bundle,
              $instance,
              $permission_key,
              $role_id,
            ],
          ];

          // Set default values based on existing permissions.
          if (!empty($lodData[$current_bundle][$instance][$permission_key][$role_id])) {
            $form['fpi_details'][$instance]['instance_perms'][$permission_key][$role_id]['#default_value'] = "1";
          }

          // Disable for admin roles.
          if ($role->isAdmin()) {
            $form['fpi_details'][$instance]['instance_perms'][$permission_key][$role_id]['#disabled'] = true;
            $form['fpi_details'][$instance]['instance_perms'][$permission_key][$role_id]['#default_value'] = "1";
          }
        }
      }
    }

    // Attach the library for styling.
    $form['#attached']['library'][] = 'custom_field_permissions_instance/custom_field_permissions_instance';
  }

}
