<?php

namespace Drupal\custom_field_permissions_instance\Plugin\FieldPermissionType;

use Drupal;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\custom_field_permissions_instance\AwfInstanceHelper;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\field_permissions\FieldPermissionsServiceInterface;
use Drupal\field_permissions\Plugin\AdminFormSettingsInterface;
use Drupal\field_permissions\Plugin\CustomPermissionsInterface;
use Drupal\field_permissions\Plugin\FieldPermissionType\Base;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\RoleInterface;
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

    $configPermission = $this->getConfigPermissions();
    $current_instance = AwfInstanceHelper::getMosaicId();
    $bundle = $entity->bundle();
    if(!$current_instance  || !$permissions = $configPermission[$bundle]){
      return true;
    }

    $entity_permissions = $permissions[$current_instance];

    if ($operation === 'edit' && $entity->isNew()) {
      return $this->UserHasPermission($account, $entity_permissions[$operation]);
    }

    if ($this->UserHasPermission($account, $entity_permissions[$operation])) {
      return true;
    } else {
      // User entities don't implement `EntityOwnerInterface`.
      if ($entity instanceof UserInterface) {
        return $entity->id() == $account->id() && $this->UserHasPermission($account,$entity_permissions[$operation.' own']);
      } elseif ($entity instanceof EntityOwnerInterface) {
        return $entity->getOwnerId() === $account->id() && $this->UserHasPermission($account,$entity_permissions[$operation.' own']
          );
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
    /** @var RoleInterface[] $roles */
    $roles = $role_storage->loadMultiple();
    $permissions = $this->getPermissions();

    $field_name = $this->fieldStorage->getName();
    // Charger la configuration du stockage de champ.
    if (!$this->fieldStorage) {
      Drupal::messenger()->addError(t('The field @field_name does not exist.', ['@field_name' => $field_name]));

      return;
    }

    $lodData = $this->getConfigPermissions();
    $field_config = $form_state->getFormObject()->getEntity();


    $current_bundle = $field_config->getTargetBundle();

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
            '#title' => $role_id.': '.$permission["title"],
            '#title_display' => 'invisible',
            '#type' => 'checkbox',
            '#attributes' => [
              'class' => ['rid-'.$role_id, 'js-rid-'.$role_id],
            ],
            '#wrapper_attributes' => [
              'class' => ['checkbox'],
            ],
            // Use the structure to generate the correct name attribute.
            '#parents' => ['instance_perms', $current_bundle, $instance, $permission_key, $role_id],
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
    foreach ($perms_name as $perm_name) {
        $permissions[$perm_name] = $permission_list[$perm_name];
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
    // Récupérer les valeurs du formulaire.
    $field_name = $this->fieldStorage->getName();
    $target_entity_type_id = $this->fieldStorage->getTargetEntityTypeId();



    $instance_permissions = $form_state->getValue('instance_perms');

    // Charger la configuration du stockage de champ.
    $this->fieldStorage = FieldStorageConfig::loadByName($target_entity_type_id, $field_name);
    if (!$this->fieldStorage) {
      Drupal::messenger()->addError(t('The field @field_name does not exist.', ['@field_name' => $field_name]));
      return;
    }
    if($this_plugin_applies){
      $current_config = $this->getConfigPermissions();

      $current_config = array_merge($current_config, $instance_permissions);
    }else{
      $current_config=   [];
    }



    // Vérifier que le champ supporte les "third_party_settings".
    if (method_exists($this->fieldStorage, 'setThirdPartySetting')) {
      // Enregistrer les permissions dans les "third_party_settings".
      $this->fieldStorage->setThirdPartySetting('custom_field_permissions_instance', 'instance_permissions', $current_config);
      // Charger les permissions.
      $this->fieldStorage->save();

      Drupal::messenger()->addMessage(t('Permissions have been saved for the field @field_name.', ['@field_name' => $field_name]));
    }
    else {
      Drupal::messenger()->addError(t('The field @field_name does not support third party settings.', ['@field_name' => $field_name]));
    }
  }

  /**
   * @return mixed
   */
  public function getConfigPermissions(): mixed
  {
    return $this->fieldStorage->getThirdPartySetting('custom_field_permissions_instance', 'instance_permissions', []);
  }

  /**
   * @param AccountInterface $account
   * @param $operation
   * @return bool
   */
  public function UserHasPermission(AccountInterface $account, $operation): bool
  {
    foreach ($account->getRoles() as $role) {
      if ($operation[$role]) {
        return true;
      }
    }

    return false;
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
