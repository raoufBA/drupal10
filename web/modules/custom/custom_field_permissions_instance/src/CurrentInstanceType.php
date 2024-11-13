<?php

namespace Drupal\custom_field_permissions_instance;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\group\Annotation\GroupRelationType;
use Drupal\group\Entity\GroupContentType;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipType;
use Drupal\group\Entity\GroupType;

class CurrentInstanceType {

  /**
   * Current route match service
   */
  var $currentRouteMatch;

  /*
   * Entity type manager service
   */
  var $entityTypeManager;

  /**
   * CurrentGroup constructor.
   *
   * @param \Drupal\Core\Routing\CurrentRouteMatch $current_route_match
   * @param \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
   */
  public function __construct(CurrentRouteMatch $current_route_match, EntityTypeManager $entity_type_manager) {
    $this->currentRouteMatch = $current_route_match;
    $this->entityTypeManager = $entity_type_manager;
  }


  /**
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *
   * @return bool|\Drupal\group\Entity\GroupInterface
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   */
  public function getGroupByEntity(EntityInterface $entity) {
    $group = false;
    if ($entity instanceof GroupInterface) {
      return $entity;
    }
    $entity_type = $entity->getEntityTypeId();
    $group_content_type = 'project-group_' . $entity_type . '-' . $entity->bundle();
    // Load all the group content for this entity.
    /** @var \Drupal\group\Entity\GroupRelationshipInterface $group_content */
    $group_content = $this->entityTypeManager->getStorage('group_relationship')

      ->loadByProperties([
        'type' => $group_content_type,
        'entity_id' => $entity->id(),
      ]);
    // Assuming that the content can be related only to 1 group.
    $group_content = reset($group_content);
    if (!empty($group_content)) {
      $group = $group_content->getGroup();
    }
    return $group;
  }

  /**
   * Get the group type id from the current route match.
   *
   * @return bool|\Drupal\group\Entity\GroupType
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   */
  public function getGroupTypeFromRoute() {
    $entity = false;

    $parameters = $this->currentRouteMatch->getParameters()->all();
    // Check if there is a "group" parameter?
    if (isset($parameters['entity_type_id'])) {
      if ($parameters['entity_type_id'] == 'group' && isset($parameters['group_type'])) {
        return $parameters['group_type'];
      }

      if ($parameters['entity_type_id'] == 'group_relationship') {
        /** @var \Drupal\group\Entity\GroupRelationshipTypeInterface $group_content_type */
        $group_content_type = $parameters['group_relationship_type'];
        $group_type_id = $group_content_type->getGroupTypeId();
        return GroupType::load($group_type_id);
      }

      $group_content_types = GroupRelationshipType::loadByEntityTypeId($parameters['entity_type_id']);
      $plugin_id = 'undefined';

      if ($parameters['entity_type_id'] == 'node') {
        $plugin_id = 'group_node:' . $parameters['bundle'];
      }

      /** @var \Drupal\group\Entity\GroupRelationshipTypeInterface $group_content_type */
      foreach ($group_content_types as $group_content_type) {
        if ($group_content_type->getPluginId() == $plugin_id) {
          return $group_content_type->getGroupType();
        }
      }
    }

    // No group associations discovered for current route.
    return false;
  }

}
