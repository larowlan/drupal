<?php

/**
 * @file
 * Definition of Drupal\rest\test\RESTTestBase.
 */

namespace Drupal\rest\Tests;

use Drupal\Core\Session\AccountInterface;
use Drupal\simpletest\HttpTestBase;
use Drupal\simpletest\WebTestBase;

/**
 * Test helper class that provides a REST client method to send HTTP requests.
 */
abstract class RESTTestBase extends HttpTestBase {

  /**
   * The default serialization format to use for testing REST operations.
   *
   * @var string
   */
  protected $defaultFormat;

  /**
   * The entity type to use for testing.
   *
   * @var string
   */
  protected $testEntityType = 'entity_test';

  /**
   * The default authentication provider to use for testing REST operations.
   *
   * @var array
   */
  protected $defaultAuth;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('rest', 'entity_test', 'node');

  protected function setUp() {
    parent::setUp();
    $this->defaultFormat = 'hal_json';
    $this->defaultMimeType = 'application/hal+json';
    $this->defaultAuth = array('cookie');
    // Create a test content type for node testing.
    $this->drupalCreateContentType(array('name' => 'resttest', 'type' => 'resttest'));
  }

  /**
   * Creates entity objects based on their types.
   *
   * @param string $entity_type
   *   The type of the entity that should be created.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The new entity object.
   */
  protected function entityCreate($entity_type) {
    return entity_create($entity_type, $this->entityValues($entity_type));
  }

  /**
   * Provides an array of suitable property values for an entity type.
   *
   * Required properties differ from entity type to entity type, so we keep a
   * minimum mapping here.
   *
   * @param string $entity_type
   *   The type of the entity that should be created.
   *
   * @return array
   *   An array of values keyed by property name.
   */
  protected function entityValues($entity_type) {
    switch ($entity_type) {
      case 'entity_test':
        return array(
          'name' => $this->randomName(),
          'user_id' => 1,
          'field_test_text' => array(0 => array(
            'value' => $this->randomString(),
            'format' => 'plain_text',
          )),
        );
      case 'node':
        return array('title' => $this->randomString(), 'type' => 'resttest');
      case 'node_type':
        return array(
          'type' => 'article',
          'name' => $this->randomName(),
        );
      case 'user':
        return array('name' => $this->randomName());
      default:
        return array();
    }
  }

  /**
   * Enables the REST service interface for a specific entity type.
   *
   * @param string|FALSE $resource_type
   *   The resource type that should get REST API enabled or FALSE to disable all
   *   resource types.
   * @param string $method
   *   The HTTP method to enable, e.g. GET, POST etc.
   * @param string $format
   *   (Optional) The serialization format, e.g. hal_json.
   * @param array $auth
   *   (Optional) The list of valid authentication methods.
   */
  protected function enableService($resource_type, $method = 'GET', $format = NULL, $auth = NULL) {
    // Enable REST API for this entity type.
    $config = \Drupal::config('rest.settings');
    $settings = array();

    if ($resource_type) {
      if ($format == NULL) {
        $format = $this->defaultFormat;
      }
      $settings[$resource_type][$method]['supported_formats'][] = $format;

      if ($auth == NULL) {
        $auth = $this->defaultAuth;
      }
      $settings[$resource_type][$method]['supported_auth'] = $auth;
    }
    $config->set('resources', $settings);
    $config->save();
    $this->rebuildCache();
  }

  /**
   * Rebuilds routing caches.
   */
  protected function rebuildCache() {
    // Rebuild routing cache, so that the REST API paths are available.
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Provides the necessary user permissions for entity operations.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string $operation
   *   The operation, one of 'view', 'create', 'update' or 'delete'.
   *
   * @return array
   *   The set of user permission strings.
   */
  protected function entityPermissions($entity_type, $operation) {
    switch ($entity_type) {
      case 'entity_test':
        switch ($operation) {
          case 'view':
            return array('view test entity');
          case 'create':
          case 'update':
          case 'delete':
            return array('administer entity_test content');
        }
        break;
      case 'node':
        switch ($operation) {
          case 'view':
            return array('access content');
          case 'create':
            return array('create resttest content');
          case 'update':
            return array('edit any resttest content');
          case 'delete':
            return array('delete any resttest content');
        }
        break;
    }
    return array();
  }

  /**
   * Loads an entity based on the location URL returned in the location header.
   *
   * @param string $location_url
   *   The URL returned in the Location header.
   *
   * @return \Drupal\Core\Entity\Entity|FALSE.
   *   The entity or FALSE if there is no matching entity.
   */
  protected function loadEntityFromLocationHeader($location_url) {
    $url_parts = explode('/', $location_url);
    $id = end($url_parts);
    return entity_load($this->testEntityType, $id);
  }

}
