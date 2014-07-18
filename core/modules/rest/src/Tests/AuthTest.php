<?php

/**
 * @file
 * Definition of Drupal\rest\test\AuthTest.
 */

namespace Drupal\rest\Tests;

use Drupal\rest\Tests\RESTTestBase;

/**
 * Tests authentication provider restrictions.
 *
 * @group rest
 */
class AuthTest extends RESTTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('basic_auth', 'hal', 'rest', 'entity_test', 'comment');

  /**
   * Tests reading from an authenticated resource.
   */
  public function testRead() {
    $entity_type = 'entity_test';

    // Enable a test resource through GET method and basic HTTP authentication.
    $this->enableService('entity:' . $entity_type, 'GET', NULL, array('basic_auth'));

    // Create an entity programmatically.
    $entity = $this->entityCreate($entity_type);
    $entity->save();

    // Try to read the resource as an anonymous user, which should not work.
    $this->httpRequest($entity->getSystemPath(), 'GET', NULL, $this->defaultMimeType);
    $this->assertResponse('401', 'HTTP response code is 401 when the request is not authenticated and the user is anonymous.');
    $this->assertText('A fatal error occurred: No authentication credentials provided.');

    // Ensure that Guzzle settings/headers aren't carried over to next request.
    $this->initGuzzle();

    // Create a user account that has the required permissions to read
    // resources via the REST API, but the request is authenticated
    // with session cookies.
    $permissions = $this->entityPermissions($entity_type, 'view');
    $permissions[] = 'restful get entity:' . $entity_type;
    $account = $this->drupalCreateUser($permissions);
    $this->drupalLogin($account);

    // Try to read the resource with session cookie authentication, which is
    // not enabled and should not work.
    $this->httpRequest($entity->getSystemPath(), 'GET', NULL, $this->defaultMimeType);
    $this->assertResponse('401', 'HTTP response code is 401 when the request is authenticated but not authorized.');

    // Ensure that Guzzle settings/headers aren't carried over to next request.
    $this->initGuzzle();

    // Now read it with the Basic authentication which is enabled and should
    // work.
    $this->httpRequest($entity->getSystemPath(), 'GET', NULL, $this->defaultMimeType, array($account->getUsername(), $account->pass_raw));
    $this->assertResponse('200', 'HTTP response code is 200 for successfully authorized requests.');
  }

}
