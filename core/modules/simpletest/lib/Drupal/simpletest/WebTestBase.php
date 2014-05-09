<?php

/**
 * @file
 * Definition of \Drupal\simpletest\WebTestBase.
 */

namespace Drupal\simpletest;

use Behat\Mink\Element\NodeElement;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Crypt;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\String;
use Drupal\Component\Utility\Xss;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\ConnectionNotDefinedException;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\UserSession;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\block\Entity\Block;
use Behat\Mink\Driver\Goutte\Client;
use Symfony\Component\HttpFoundation\Request;
use Behat\Mink\Mink;
use Behat\Mink\Session;
use Behat\Mink\Driver\GoutteDriver;
use GuzzleHttp\Client as GuzzleClient;

/**
 * Test case for typical Drupal tests.
 */
abstract class WebTestBase extends TestBase {

  /**
   * The profile to install as a basis for testing.
   *
   * @var string
   */
  protected $profile = 'testing';

  /**
   * The URL currently loaded in the internal browser.
   *
   * @var string
   */
  protected $url;

  /**
   * The handle of the current cURL connection.
   *
   * @var resource
   */
  protected $curlHandle;

  /**
   * The headers of the page currently loaded in the internal browser.
   *
   * @var Array
   */
  protected $headers;

  /**
   * Indicates that headers should be dumped if verbose output is enabled.
   *
   * Headers are dumped to verbose by drupalGet(), drupalHead(), and
   * drupalPostForm().
   *
   * @var bool
   */
  protected $dumpHeaders = FALSE;

  /**
   * The content of the page currently loaded in the internal browser.
   *
   * @var string
   */
  protected $content;

  /**
   * The plain-text content of the currently-loaded page.
   *
   * @var string
   */
  protected $plainTextContent;

  /**
   * The value of drupalSettings for the currently-loaded page.
   *
   * drupalSettings refers to the drupalSettings JavaScript variable.
   *
   * @var Array
   */
  protected $drupalSettings;

  /**
   * The parsed version of the page.
   *
   * @var \SimpleXMLElement
   */
  protected $elements = NULL;

  /**
   * The current user logged in using the internal browser.
   *
   * @var bool
   */
  protected $loggedInUser = FALSE;

  /**
   * The current cookie file used by cURL.
   *
   * We do not reuse the cookies in further runs, so we do not need a file
   * but we still need cookie handling, so we set the jar to NULL.
   */
  protected $cookieFile = NULL;

  /**
   * Additional cURL options.
   *
   * \Drupal\simpletest\WebTestBase itself never sets this but always obeys what
   * is set.
   */
  protected $additionalCurlOptions = array();

  /**
   * The original user, before it was changed to a clean uid = 1 for testing.
   *
   * @var object
   */
  protected $originalUser = NULL;

  /**
   * The original shutdown handlers array, before it was cleaned for testing.
   *
   * @var array
   */
  protected $originalShutdownCallbacks = array();

  /**
   * HTTP authentication method.
   */
  protected $httpauth_method = CURLAUTH_BASIC;

  /**
   * HTTP authentication credentials (<username>:<password>).
   */
  protected $httpauth_credentials = NULL;

  /**
   * The current session name, if available.
   */
  protected $session_name = NULL;

  /**
   * The current session ID, if available.
   */
  protected $session_id = NULL;

  /**
   * Whether the files were copied to the test files directory.
   */
  protected $generatedTestFiles = FALSE;

  /**
   * The maximum number of redirects to follow when handling responses.
   */
  protected $maximumRedirects = 5;

  /**
   * The number of redirects followed during the handling of a request.
   */
  protected $redirect_count;

  /**
   * The kernel used in this test.
   */
  protected $kernel;

  /**
   * The config directories used in this test.
   */
  protected $configDirectories = array();

  /**
   * Cookies to set on curl requests.
   *
   * @var array
   */
  protected $curlCookies = array();

  /**
   * An array of custom translations suitable for drupal_rewrite_settings().
   *
   * @var array
   */
  protected $customTranslations;

  /**
   * Current mink object.
   *
   * @var \Behat\Mink\Mink
   */
  protected $mink;

  /**
   * Guzzle connection.
   *
   * @var \
   */
  protected $guzzle;

  /**
   * Constructor for \Drupal\simpletest\WebTestBase.
   */
  function __construct($test_id = NULL) {
    parent::__construct($test_id);
    $this->skipClasses[__CLASS__] = TRUE;
  }

  /**
   * Resets the mink sessions.
   */
  protected function resetMink() {
    $this->getMink()->resetSessions();
  }

  /**
   * Initializes Mink session.
   */
  protected function initMink() {
    global $base_url;
    $defaults = array(
      'timeout' => 30,
      'verify' => FALSE,
      // @todo remove/replace
      'headers' => array(
        'User-Agent' => $this->databasePrefix,
      ),
    );
    $guzzle = new GuzzleClient(array('base_url' => $base_url, 'defaults' => $defaults));
    $client = new Client();
    $client->setClient($guzzle);
    $client->setHeader('User-Agent', $this->databasePrefix);
    $client->setMaxRedirects($this->maximumRedirects);
    if (isset($this->httpauth_credentials)) {
      list($username, $password) = explode(':', $this->httpauth_credentials);
      $client->setAuth($username, $password, $this->httpauth_method);
    }
    $this->mink = new Mink(array(
      'goutte' => new Session(new GoutteDriver($client)),
    ));
    $this->mink->setDefaultSessionName('goutte');
    // Set the User agent.
    $this->getMink()->getSession()->setRequestHeader('User-Agent', $this->databasePrefix);
    $this->session_name = session_name();
  }

  /**
   * Transforms legacy Curl options into the format expected by Guzzle.
   *
   * @param array $options
   *   Array of curl options.
   *
   * @return array
   *   Array of curl options transformed into the format expected by Guzzle.
   */
  protected function transformLegacyCurlOptions($options) {
    foreach ($options as $key => $value) {
      if (substr($key, 0, 5) == 'curl.') {
        // Already in expected format.
        continue;
      }
      $options['curl.' . $key] = $value;
      unset($options[$key]);
    }
    return $options;
  }

  /**
   * Sets Curl options on the Guzzle client used by the Goutte driver.
   *
   * @param array $options
   *   Array of curl options to be set on the Guzzle client.
   */
  protected function setClientOptions($options) {
    return;
    /** @var \Guzzle\Http\ClientInterface $guzzle_client */
    $guzzle_client = $this->getMink()->getSession()->getDriver()->getClient()->getClient();
    $existing_config = $guzzle_client->getConfig();
    $options = $this->transformLegacyCurlOptions($options);
    foreach ($options as $key => $value) {
      $existing_config[$key] = $value;
    }
    $guzzle_client->setConfig($existing_config);
  }

  /**
   * Returns the current mink object.
   *
   * @return \Behat\Mink\Mink
   */
  protected function getMink() {
    if (!isset($this->mink)) {
      $this->initMink();
    }
    return $this->mink;
  }

  /**
   * Returns the named Mink session.
   *
   * @param string $name
   *   (optional) Mink session name. Defaults to null.
   *
   * @return \Behat\Mink\Session
   */
  protected function getSession($name = null) {
    return $this->getMink()->getSession($name);
  }

  /**
   * Get a node from the database based on its title.
   *
   * @param $title
   *   A node title, usually generated by $this->randomName().
   * @param $reset
   *   (optional) Whether to reset the entity cache.
   *
   * @return \Drupal\node\NodeInterface
   *   A node entity matching $title.
   */
  function drupalGetNodeByTitle($title, $reset = FALSE) {
    if ($reset) {
      \Drupal::entityManager()->getStorage('node')->resetCache();
    }
    $nodes = entity_load_multiple_by_properties('node', array('title' => $title));
    // Load the first node returned from the database.
    $returned_node = reset($nodes);
    return $returned_node;
  }

  /**
   * Creates a node based on default settings.
   *
   * @param array $settings
   *   (optional) An associative array of settings for the node, as used in
   *   entity_create(). Override the defaults by specifying the key and value
   *   in the array, for example:
   *   @code
   *     $this->drupalCreateNode(array(
   *       'title' => t('Hello, world!'),
   *       'type' => 'article',
   *     ));
   *   @endcode
   *   The following defaults are provided:
   *   - body: Random string using the default filter format:
   *     @code
   *       $settings['body'][0] = array(
   *         'value' => $this->randomName(32),
   *         'format' => filter_default_format(),
   *       );
   *     @endcode
   *   - title: Random string.
   *   - comment: CommentItemInterface::OPEN.
   *   - promote: NODE_NOT_PROMOTED.
   *   - log: Empty string.
   *   - status: NODE_PUBLISHED.
   *   - sticky: NODE_NOT_STICKY.
   *   - type: 'page'.
   *   - langcode: Language::LANGCODE_NOT_SPECIFIED.
   *   - uid: The currently logged in user, or the user running test.
   *   - revision: 1. (Backwards-compatible binary flag indicating whether a
   *     new revision should be created; use 1 to specify a new revision.)
   *
   * @return \Drupal\node\NodeInterface
   *   The created node entity.
   */
  protected function drupalCreateNode(array $settings = array()) {
    // Populate defaults array.
    $settings += array(
      'body'      => array(array()),
      'title'     => $this->randomName(8),
      'promote'   => NODE_NOT_PROMOTED,
      'revision'  => 1,
      'log'       => '',
      'status'    => NODE_PUBLISHED,
      'sticky'    => NODE_NOT_STICKY,
      'type'      => 'page',
      'langcode'  => Language::LANGCODE_NOT_SPECIFIED,
    );

    // Use the original node's created time for existing nodes.
    if (isset($settings['created']) && !isset($settings['date'])) {
      $settings['date'] = format_date($settings['created'], 'custom', 'Y-m-d H:i:s O');
    }

    // If the node's user uid is not specified manually, use the currently
    // logged in user if available, or else the user running the test.
    if (!isset($settings['uid'])) {
      if ($this->loggedInUser) {
        $settings['uid'] = $this->loggedInUser->id();
      }
      else {
        $user = \Drupal::currentUser() ?: drupal_anonymous_user();
        $settings['uid'] = $user->id();
      }
    }

    // Merge body field value and format separately.
    $settings['body'][0] += array(
      'value' => $this->randomName(32),
      'format' => filter_default_format(),
    );

    $node = entity_create('node', $settings);
    if (!empty($settings['revision'])) {
      $node->setNewRevision();
    }
    $node->save();

    return $node;
  }

  /**
   * Creates a custom content type based on default settings.
   *
   * @param array $values
   *   An array of settings to change from the defaults.
   *   Example: 'type' => 'foo'.
   *
   * @return \Drupal\node\Entity\NodeType
   *   Created content type.
   */
  protected function drupalCreateContentType(array $values = array()) {
    // Find a non-existent random type name.
    if (!isset($values['type'])) {
      do {
        $id = strtolower($this->randomName(8));
      } while (node_type_load($id));
    }
    else {
      $id = $values['type'];
    }
    $values += array(
      'type' => $id,
      'name' => $id,
    );
    $type = entity_create('node_type', $values);
    $status = $type->save();
    \Drupal::service('router.builder')->rebuild();

    $this->assertEqual($status, SAVED_NEW, String::format('Created content type %type.', array('%type' => $type->id())));

    return $type;
  }

  /**
   * Builds the renderable view of an entity.
   *
   * Entities postpone the composition of their renderable arrays to #pre_render
   * functions in order to maximize cache efficacy. This means that the full
   * rendable array for an entity is constructed in drupal_render(). Some tests
   * require the complete renderable array for an entity outside of the
   * drupal_render process in order to verify the presence of specific values.
   * This method isolates the steps in the render process that produce an
   * entity's renderable array.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to prepare a renderable array for.
   * @param string $view_mode
   *   (optional) The view mode that should be used to build the entity.
   * @param null $langcode
   *   (optional) For which language the entity should be prepared, defaults to
   *   the current content language.
   * @param bool $reset
   *   (optional) Whether to clear the cache for this entity.
   * @return array
   *
   * @see drupal_render()
   */
  protected function drupalBuildEntityView(EntityInterface $entity, $view_mode = 'full', $langcode = NULL, $reset = FALSE) {
    $render_controller = $this->container->get('entity.manager')->getViewBuilder($entity->getEntityTypeId());
    if ($reset) {
      $render_controller->resetCache(array($entity->id()));
    }
    $elements = $render_controller->view($entity, $view_mode, $langcode);
    // If the default values for this element have not been loaded yet, populate
    // them.
    if (isset($elements['#type']) && empty($elements['#defaults_loaded'])) {
      $elements += element_info($elements['#type']);
    }

    // Make any final changes to the element before it is rendered. This means
    // that the $element or the children can be altered or corrected before the
    // element is rendered into the final text.
    if (isset($elements['#pre_render'])) {
      foreach ($elements['#pre_render'] as $callable) {
        $elements = call_user_func($callable, $elements);
      }
    }
    return $elements;
  }

  /**
   * Creates a block instance based on default settings.
   *
   * @param string $plugin_id
   *   The plugin ID of the block type for this block instance.
   * @param array $settings
   *   (optional) An associative array of settings for the block entity.
   *   Override the defaults by specifying the key and value in the array, for
   *   example:
   *   @code
   *     $this->drupalPlaceBlock('system_powered_by_block', array(
   *       'label' => t('Hello, world!'),
   *     ));
   *   @endcode
   *   The following defaults are provided:
   *   - label: Random string.
   *   - id: Random string.
   *   - region: 'sidebar_first'.
   *   - theme: The default theme.
   *   - visibility: Empty array.
   *   - cache: array('max_age' => 0).
   *
   * @return \Drupal\block\Entity\Block
   *   The block entity.
   *
   * @todo
   *   Add support for creating custom block instances.
   */
  protected function drupalPlaceBlock($plugin_id, array $settings = array()) {
    $settings += array(
      'plugin' => $plugin_id,
      'region' => 'sidebar_first',
      'id' => strtolower($this->randomName(8)),
      'theme' => \Drupal::config('system.theme')->get('default'),
      'label' => $this->randomName(8),
      'visibility' => array(),
      'weight' => 0,
      'cache' => array(
        'max_age' => 0,
      ),
    );
    foreach (array('region', 'id', 'theme', 'plugin', 'visibility', 'weight') as $key) {
      $values[$key] = $settings[$key];
      // Remove extra values that do not belong in the settings array.
      unset($settings[$key]);
    }
    $values['settings'] = $settings;
    $block = entity_create('block', $values);
    $block->save();
    return $block;
  }

  /**
   * Checks to see whether a block appears on the page.
   *
   * @param \Drupal\block\Entity\Block $block
   *   The block entity to find on the page.
   */
  protected function assertBlockAppears(Block $block) {
    $result = $this->findBlockInstance($block);
    $this->assertTrue(!empty($result), format_string('Ensure the block @id appears on the page', array('@id' => $block->id())));
  }

  /**
   * Checks to see whether a block does not appears on the page.
   *
   * @param \Drupal\block\Entity\Block $block
   *   The block entity to find on the page.
   */
  protected function assertNoBlockAppears(Block $block) {
    $result = $this->findBlockInstance($block);
    $this->assertFalse(!empty($result), format_string('Ensure the block @id does not appear on the page', array('@id' => $block->id())));
  }

  /**
   * Find a block instance on the page.
   *
   * @param \Drupal\block\Entity\Block $block
   *   The block entity to find on the page.
   *
   * @return array
   *   The result from the xpath query.
   */
  protected function findBlockInstance(Block $block) {
    return $this->getSession()->getPage()->find('css', '#block-' . $block->id());
  }

  /**
   * Gets a list files that can be used in tests.
   *
   * @param $type
   *   File type, possible values: 'binary', 'html', 'image', 'javascript',
   *   'php', 'sql', 'text'.
   * @param $size
   *   File size in bytes to match. Please check the tests/files folder.
   *
   * @return
   *   List of files that match filter.
   */
  protected function drupalGetTestFiles($type, $size = NULL) {
    if (empty($this->generatedTestFiles)) {
      // Generate binary test files.
      $lines = array(64, 1024);
      $count = 0;
      foreach ($lines as $line) {
        simpletest_generate_file('binary-' . $count++, 64, $line, 'binary');
      }

      // Generate text test files.
      $lines = array(16, 256, 1024, 2048, 20480);
      $count = 0;
      foreach ($lines as $line) {
        simpletest_generate_file('text-' . $count++, 64, $line);
      }

      // Copy other test files from simpletest.
      $original = drupal_get_path('module', 'simpletest') . '/files';
      $files = file_scan_directory($original, '/(html|image|javascript|php|sql)-.*/');
      foreach ($files as $file) {
        file_unmanaged_copy($file->uri, PublicStream::basePath());
      }

      $this->generatedTestFiles = TRUE;
    }

    $files = array();
    // Make sure type is valid.
    if (in_array($type, array('binary', 'html', 'image', 'javascript', 'php', 'sql', 'text'))) {
      $files = file_scan_directory('public://', '/' . $type . '\-.*/');

      // If size is set then remove any files that are not of that size.
      if ($size !== NULL) {
        foreach ($files as $file) {
          $stats = stat($file->uri);
          if ($stats['size'] != $size) {
            unset($files[$file->uri]);
          }
        }
      }
    }
    usort($files, array($this, 'drupalCompareFiles'));
    return $files;
  }

  /**
   * Compare two files based on size and file name.
   */
  protected function drupalCompareFiles($file1, $file2) {
    $compare_size = filesize($file1->uri) - filesize($file2->uri);
    if ($compare_size) {
      // Sort by file size.
      return $compare_size;
    }
    else {
      // The files were the same size, so sort alphabetically.
      return strnatcmp($file1->name, $file2->name);
    }
  }

  /**
   * Create a user with a given set of permissions.
   *
   * @param array $permissions
   *   Array of permission names to assign to user. Note that the user always
   *   has the default permissions derived from the "authenticated users" role.
   * @param string $name
   *   The user name.
   *
   * @return \Drupal\user\Entity\User|false
   *   A fully loaded user object with pass_raw property, or FALSE if account
   *   creation fails.
   */
  protected function drupalCreateUser(array $permissions = array(), $name = NULL) {
    // Create a role with the given permission set, if any.
    $rid = FALSE;
    if ($permissions) {
      $rid = $this->drupalCreateRole($permissions);
      if (!$rid) {
        return FALSE;
      }
    }

    // Create a user assigned to that role.
    $edit = array();
    $edit['name']   = !empty($name) ? $name : $this->randomName();
    $edit['mail']   = $edit['name'] . '@example.com';
    $edit['pass']   = user_password();
    $edit['status'] = 1;
    if ($rid) {
      $edit['roles'] = array($rid);
    }

    $account = entity_create('user', $edit);
    $account->save();

    $this->assertTrue($account->id(), String::format('User created with name %name and pass %pass', array('%name' => $edit['name'], '%pass' => $edit['pass'])), 'User login');
    if (!$account->id()) {
      return FALSE;
    }

    // Add the raw password so that we can log in as this user.
    $account->pass_raw = $edit['pass'];
    return $account;
  }

  /**
   * Creates a role with specified permissions.
   *
   * @param array $permissions
   *   Array of permission names to assign to role.
   * @param string $rid
   *   (optional) The role ID (machine name). Defaults to a random name.
   * @param string $name
   *   (optional) The label for the role. Defaults to a random string.
   * @param integer $weight
   *   (optional) The weight for the role. Defaults NULL so that entity_create()
   *   sets the weight to maximum + 1.
   *
   * @return string
   *   Role ID of newly created role, or FALSE if role creation failed.
   */
  protected function drupalCreateRole(array $permissions, $rid = NULL, $name = NULL, $weight = NULL) {
    // Generate a random, lowercase machine name if none was passed.
    if (!isset($rid)) {
      $rid = strtolower($this->randomName(8));
    }
    // Generate a random label.
    if (!isset($name)) {
      // In the role UI role names are trimmed and random string can start or
      // end with a space.
      $name = trim($this->randomString(8));
    }

    // Check the all the permissions strings are valid.
    if (!$this->checkPermissions($permissions)) {
      return FALSE;
    }

    // Create new role.
    $role = entity_create('user_role', array(
      'id' => $rid,
      'label' => $name,
    ));
    if (!is_null($weight)) {
      $role->set('weight', $weight);
    }
    $result = $role->save();

    $this->assertIdentical($result, SAVED_NEW, String::format('Created role ID @rid with name @name.', array(
      '@name' => var_export($role->label(), TRUE),
      '@rid' => var_export($role->id(), TRUE),
    )), 'Role');

    if ($result === SAVED_NEW) {
      // Grant the specified permissions to the role, if any.
      if (!empty($permissions)) {
        user_role_grant_permissions($role->id(), $permissions);
        $assigned_permissions = entity_load('user_role', $role->id())->permissions;
        $missing_permissions = array_diff($permissions, $assigned_permissions);
        if (!$missing_permissions) {
          $this->pass(String::format('Created permissions: @perms', array('@perms' => implode(', ', $permissions))), 'Role');
        }
        else {
          $this->fail(String::format('Failed to create permissions: @perms', array('@perms' => implode(', ', $missing_permissions))), 'Role');
        }
      }
      return $role->id();
    }
    else {
      return FALSE;
    }
  }

  /**
   * Checks whether a given list of permission names is valid.
   *
   * @param array $permissions
   *   The permission names to check.
   *
   * @return bool
   *   TRUE if the permissions are valid, FALSE otherwise.
   */
  protected function checkPermissions(array $permissions) {
    $available = array_keys(\Drupal::moduleHandler()->invokeAll('permission'));
    $valid = TRUE;
    foreach ($permissions as $permission) {
      if (!in_array($permission, $available)) {
        $this->fail(String::format('Invalid permission %permission.', array('%permission' => $permission)), 'Role');
        $valid = FALSE;
      }
    }
    return $valid;
  }

  /**
   * Log in a user with the internal browser.
   *
   * If a user is already logged in, then the current user is logged out before
   * logging in the specified user.
   *
   * Please note that neither the current user nor the passed-in user object is
   * populated with data of the logged in user. If you need full access to the
   * user object after logging in, it must be updated manually. If you also need
   * access to the plain-text password of the user (set by drupalCreateUser()),
   * e.g. to log in the same user again, then it must be re-assigned manually.
   * For example:
   * @code
   *   // Create a user.
   *   $account = $this->drupalCreateUser(array());
   *   $this->drupalLogin($account);
   *   // Load real user object.
   *   $pass_raw = $account->pass_raw;
   *   $account = user_load($account->id());
   *   $account->pass_raw = $pass_raw;
   * @endcode
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User object representing the user to log in.
   *
   * @see drupalCreateUser()
   */
  protected function drupalLogin(AccountInterface $account) {
    if ($this->loggedInUser) {
      $this->drupalLogout();
    }

    $edit = array(
      'name' => $account->getUsername(),
      'pass' => $account->pass_raw
    );
    $this->drupalPostForm('user', $edit, t('Log in'));

    // @see WebTestBase::drupalUserIsLoggedIn()
    if (isset($this->session_id)) {
      $account->session_id = $this->session_id;
    }
    $pass = $this->assert($this->drupalUserIsLoggedIn($account), format_string('User %name successfully logged in.', array('%name' => $account->getUsername())), 'User login');
    if ($pass) {
      $this->loggedInUser = $account;
      $this->container->get('current_user')->setAccount($account);
      // @todo Temporary workaround for not being able to use synchronized
      //   services in non dumped container.
      $this->container->get('access_subscriber')->setCurrentUser($account);
    }
  }

  /**
   * Returns whether a given user account is logged in.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user account object to check.
   */
  protected function drupalUserIsLoggedIn($account) {
    if (!isset($account->session_id)) {
      return FALSE;
    }
    // The session ID is hashed before being stored in the database.
    // @see \Drupal\Core\Session\SessionHandler::read()
    return (bool) db_query("SELECT sid FROM {users} u INNER JOIN {sessions} s ON u.uid = s.uid WHERE s.sid = :sid", array(':sid' => Crypt::hashBase64($account->session_id)))->fetchField();
  }

  /**
   * Generate a token for the currently logged in user.
   */
  protected function drupalGetToken($value = '') {
    $private_key = drupal_get_private_key();
    return Crypt::hmacBase64($value, $this->session_id . $private_key);
  }

  /**
   * Logs a user out of the internal browser and confirms.
   *
   * Confirms logout by checking the login page.
   */
  protected function drupalLogout() {
    // Make a request to the logout page, and redirect to the user page, the
    // idea being if you were properly logged out you should be seeing a login
    // screen.
    $this->drupalGet('user/logout', array('query' => array('destination' => 'user')));
    $this->assertResponse(200, 'User was logged out.');
    $pass = $this->assertField('name', 'Username field found.', 'Logout');
    $pass = $pass && $this->assertField('pass', 'Password field found.', 'Logout');

    if ($pass) {
      // @see WebTestBase::drupalUserIsLoggedIn()
      unset($this->loggedInUser->session_id);
      $this->loggedInUser = FALSE;
      $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    }
  }

  /**
   * Sets up a Drupal site for running functional and integration tests.
   *
   * Installs Drupal with the installation profile specified in
   * \Drupal\simpletest\WebTestBase::$profile into the prefixed database.

   * Afterwards, installs any additional modules specified in the static
   * \Drupal\simpletest\WebTestBase::$modules property of each class in the
   * class hierarchy.
   *
   * After installation all caches are flushed and several configuration values
   * are reset to the values of the parent site executing the test, since the
   * default values may be incompatible with the environment in which tests are
   * being executed.
   */
  protected function setUp() {
    // When running tests through the Simpletest UI (vs. on the command line),
    // Simpletest's batch conflicts with the installer's batch. Batch API does
    // not support the concept of nested batches (in which the nested is not
    // progressive), so we need to temporarily pretend there was no batch.
    // Backup the currently running Simpletest batch.
    $this->originalBatch = batch_get();

    // Define information about the user 1 account.
    $this->root_user = new UserSession(array(
      'uid' => 1,
      'name' => 'admin',
      'mail' => 'admin@example.com',
      'pass_raw' => $this->randomName(),
    ));

    // Reset the static batch to remove Simpletest's batch operations.
    $batch = &batch_get();
    $batch = array();

    // Get parameters for install_drupal() before removing global variables.
    $parameters = $this->installParameters();

    // Prepare installer settings that are not install_drupal() parameters.
    // Copy and prepare an actual settings.php, so as to resemble a regular
    // installation.
    // Not using File API; a potential error must trigger a PHP warning.
    copy(DRUPAL_ROOT . '/sites/default/default.settings.php', DRUPAL_ROOT . '/' . $this->siteDirectory . '/settings.php');

    // All file system paths are created by System module during installation.
    // @see system_requirements()
    // @see TestBase::prepareEnvironment()
    $settings['settings']['file_public_path'] = (object) array(
      'value' => $this->public_files_directory,
      'required' => TRUE,
    );
    // Save the original site directory path, so that extensions in the
    // site-specific directory can still be discovered in the test site
    // environment.
    // @see \Drupal\Core\SystemListing::scan()
    $settings['settings']['test_parent_site'] = (object) array(
      'value' => $this->originalSite,
      'required' => TRUE,
    );
    // Add the parent profile's search path to the child site's search paths.
    // @see \Drupal\Core\Extension\ExtensionDiscovery::getProfileDirectories()
    $settings['conf']['simpletest.settings']['parent_profile'] = (object) array(
      'value' => $this->originalProfile,
      'required' => TRUE,
    );
    $this->writeSettings($settings);

    // Since Drupal is bootstrapped already, install_begin_request() will not
    // bootstrap into DRUPAL_BOOTSTRAP_CONFIGURATION (again). Hence, we have to
    // reload the newly written custom settings.php manually.
    drupal_settings_initialize();

    // Execute the non-interactive installer.
    require_once DRUPAL_ROOT . '/core/includes/install.core.inc';
    install_drupal($parameters);

    // Import new settings.php written by the installer.
    drupal_settings_initialize();
    foreach ($GLOBALS['config_directories'] as $type => $path) {
      $this->configDirectories[$type] = $path;
    }

    // After writing settings.php, the installer removes write permissions
    // from the site directory. To allow drupal_generate_test_ua() to write
    // a file containing the private key for drupal_valid_test_ua(), the site
    // directory has to be writable.
    // TestBase::restoreEnvironment() will delete the entire site directory.
    // Not using File API; a potential error must trigger a PHP warning.
    chmod(DRUPAL_ROOT . '/' . $this->siteDirectory, 0777);

    $this->rebuildContainer();

    // Manually create and configure private and temporary files directories.
    // While these could be preset/enforced in settings.php like the public
    // files directory above, some tests expect them to be configurable in the
    // UI. If declared in settings.php, they would no longer be configurable.
    file_prepare_directory($this->private_files_directory, FILE_CREATE_DIRECTORY);
    file_prepare_directory($this->temp_files_directory, FILE_CREATE_DIRECTORY);
    \Drupal::config('system.file')
      ->set('path.private', $this->private_files_directory)
      ->set('path.temporary', $this->temp_files_directory)
      ->save();

    // Manually configure the test mail collector implementation to prevent
    // tests from sending out e-mails and collect them in state instead.
    // While this should be enforced via settings.php prior to installation,
    // some tests expect to be able to test mail system implementations.
    \Drupal::config('system.mail')
      ->set('interface.default', 'test_mail_collector')
      ->save();

    // By default, verbosely display all errors and disable all production
    // environment optimizations for all tests to avoid needless overhead and
    // ensure a sane default experience for test authors.
    // @see https://drupal.org/node/2259167
    \Drupal::config('system.logging')
      ->set('error_level', 'verbose')
      ->save();
    \Drupal::config('system.performance')
      ->set('css.preprocess', FALSE)
      ->set('js.preprocess', FALSE)
      ->save();

    // Restore the original Simpletest batch.
    $batch = &batch_get();
    $batch = $this->originalBatch;

    // Collect modules to install.
    $class = get_class($this);
    $modules = array();
    while ($class) {
      if (property_exists($class, 'modules')) {
        $modules = array_merge($modules, $class::$modules);
      }
      $class = get_parent_class($class);
    }
    if ($modules) {
      $modules = array_unique($modules);
      $success = \Drupal::moduleHandler()->install($modules, TRUE);
      $this->assertTrue($success, String::format('Enabled modules: %modules', array('%modules' => implode(', ', $modules))));
      $this->rebuildContainer();
    }

    // Like DRUPAL_BOOTSTRAP_CONFIGURATION above, any further bootstrap phases
    // are not re-executed by the installer, as Drupal is bootstrapped already.
    // Reset/rebuild all data structures after enabling the modules, primarily
    // to synchronize all data structures and caches between the test runner and
    // the child site.
    // Affects e.g. file_get_stream_wrappers().
    // @see _drupal_bootstrap_code()
    // @see _drupal_bootstrap_full()
    // @todo Test-specific setUp() methods may set up further fixtures; find a
    //   way to execute this after setUp() is done, or to eliminate it entirely.
    $this->resetAll();

    // Temporary fix so that when running from run-tests.sh we don't get an
    // empty current path which would indicate we're on the home page.
    $path = current_path();
    if (empty($path)) {
      _current_path('run-tests');
    }
  }

  /**
   * Returns the parameters that will be used when Simpletest installs Drupal.
   *
   * @see install_drupal()
   * @see install_state_defaults()
   */
  protected function installParameters() {
    $connection_info = Database::getConnectionInfo();
    $driver = $connection_info['default']['driver'];
    $connection_info['default']['prefix'] = $connection_info['default']['prefix']['default'];
    unset($connection_info['default']['driver']);
    unset($connection_info['default']['namespace']);
    unset($connection_info['default']['pdo']);
    unset($connection_info['default']['init_commands']);
    $parameters = array(
      'interactive' => FALSE,
      'parameters' => array(
        'profile' => $this->profile,
        'langcode' => 'en',
      ),
      'forms' => array(
        'install_settings_form' => array(
          'driver' => $driver,
          $driver => $connection_info['default'],
        ),
        'install_configure_form' => array(
          'site_name' => 'Drupal',
          'site_mail' => 'simpletest@example.com',
          'account' => array(
            'name' => $this->root_user->name,
            'mail' => $this->root_user->getEmail(),
            'pass' => array(
              'pass1' => $this->root_user->pass_raw,
              'pass2' => $this->root_user->pass_raw,
            ),
          ),
          // form_type_checkboxes_value() requires NULL instead of FALSE values
          // for programmatic form submissions to disable a checkbox.
          'update_status_module' => array(
            1 => NULL,
            2 => NULL,
          ),
        ),
      ),
    );
    return $parameters;
  }

  /**
   * Rewrites the settings.php file of the test site.
   *
   * @param array $settings
   *   An array of settings to write out, in the format expected by
   *   drupal_rewrite_settings().
   *
   * @see drupal_rewrite_settings()
   */
  protected function writeSettings(array $settings) {
    include_once DRUPAL_ROOT . '/core/includes/install.inc';
    $filename = $this->siteDirectory . '/settings.php';
    // system_requirements() removes write permissions from settings.php
    // whenever it is invoked.
    // Not using File API; a potential error must trigger a PHP warning.
    chmod($filename, 0666);
    drupal_rewrite_settings($settings, $filename);
  }

  /**
   * Queues custom translations to be written to settings.php.
   *
   * Use WebTestBase::writeCustomTranslations() to apply and write the queued
   * translations.
   *
   * @param string $langcode
   *   The langcode to add translations for.
   * @param array $values
   *   Array of values containing the untranslated string and its translation.
   *   For example:
   *   @code
   *   array(
   *     '' => array('Sunday' => 'domingo'),
   *     'Long month name' => array('March' => 'marzo'),
   *   );
   *   @endcode
   *   Pass an empty array to remove all existing custom translations for the
   *   given $langcode.
   */
  protected function addCustomTranslations($langcode, array $values) {
    // If $values is empty, then the test expects all custom translations to be
    // cleared.
    if (empty($values)) {
      $this->customTranslations[$langcode] = array();
    }
    // Otherwise, $values are expected to be merged into previously passed
    // values, while retaining keys that are not explicitly set.
    else {
      foreach ($values as $context => $translations) {
        foreach ($translations as $original => $translation) {
          $this->customTranslations[$langcode][$context][$original] = $translation;
        }
      }
    }
  }

  /**
   * Writes custom translations to the test site's settings.php.
   *
   * Use TestBase::addCustomTranslations() to queue custom translations before
   * calling this method.
   */
  protected function writeCustomTranslations() {
    $settings = array();
    foreach ($this->customTranslations as $langcode => $values) {
      $settings_key = 'locale_custom_strings_' . $langcode;

      // Update in-memory settings directly.
      $this->settingsSet($settings_key, $values);

      $settings['settings'][$settings_key] = (object) array(
        'value' => $values,
        'required' => TRUE,
      );
    }
    // Only rewrite settings if there are any translation changes to write.
    if (!empty($settings)) {
      $this->writeSettings($settings);
    }
  }

  /**
   * Rebuilds \Drupal::getContainer().
   *
   * Use this to build a new kernel and service container. For example, when the
   * list of enabled modules is changed via the internal browser, in which case
   * the test process still contains an old kernel and service container with an
   * old module list.
   *
   * @see TestBase::prepareEnvironment()
   * @see TestBase::restoreEnvironment()
   *
   * @todo Fix http://drupal.org/node/1708692 so that module enable/disable
   *   changes are immediately reflected in \Drupal::getContainer(). Until then,
   *   tests can invoke this workaround when requiring services from newly
   *   enabled modules to be immediately available in the same request.
   */
  protected function rebuildContainer($environment = 'prod') {
    // Preserve the request object after the container rebuild.
    $request = \Drupal::request();
    // When called from InstallerTestBase, the current container is the minimal
    // container from TestBase::prepareEnvironment(), which does not contain a
    // request stack.
    if (\Drupal::getContainer()->initialized('request_stack')) {
      $request_stack = \Drupal::service('request_stack');
    }

    $this->kernel = new DrupalKernel($environment, drupal_classloader(), FALSE);
    $this->kernel->boot();
    // DrupalKernel replaces the container in \Drupal::getContainer() with a
    // different object, so we need to replace the instance on this test class.
    $this->container = \Drupal::getContainer();
    // The current user is set in TestBase::prepareEnvironment().
    $this->container->set('request', $request);
    if (isset($request_stack)) {
      $this->container->set('request_stack', $request_stack);
    }
    else {
      $this->container->get('request_stack')->push($request);
    }
    $this->container->get('current_user')->setAccount(\Drupal::currentUser());

    // The request context is normally set by the router_listener from within
    // its KernelEvents::REQUEST listener. In the simpletest parent site this
    // event is not fired, therefore it is necessary to updated the request
    // context manually here.
    $this->container->get('router.request_context')->fromRequest($request);

    // Make sure the url generator has a request object, otherwise calls to
    // $this->drupalGet() will fail.
    $this->prepareRequestForGenerator();
  }

  /**
   * Resets all data structures after having enabled new modules.
   *
   * This method is called by \Drupal\simpletest\WebTestBase::setUp() after
   * enabling the requested modules. It must be called again when additional
   * modules are enabled later.
   */
  protected function resetAll() {
    // Clear all database and static caches and rebuild data structures.
    drupal_flush_all_caches();
    $this->container = \Drupal::getContainer();

    // Reset static variables and reload permissions.
    $this->refreshVariables();
  }

  /**
   * Refreshes in-memory configuration and state information.
   *
   * Useful after a page request is made that changes configuration or state in
   * a different thread.
   *
   * In other words calling a settings page with $this->drupalPostForm() with a
   * changed value would update configuration to reflect that change, but in the
   * thread that made the call (thread running the test) the changed values
   * would not be picked up.
   *
   * This method clears the cache and loads a fresh copy.
   */
  protected function refreshVariables() {
    // Clear the tag cache.
    drupal_static_reset('Drupal\Core\Cache\CacheBackendInterface::tagCache');
    drupal_static_reset('Drupal\Core\Cache\DatabaseBackend::deletedTags');
    drupal_static_reset('Drupal\Core\Cache\DatabaseBackend::invalidatedTags');

    $this->container->get('config.factory')->reset();
    $this->container->get('state')->resetCache();
  }

  /**
   * Cleans up after testing.
   *
   * Deletes created files and temporary files directory, deletes the tables
   * created by setUp(), and resets the database prefix.
   */
  protected function tearDown() {
    // Destroy the testing kernel.
    if (isset($this->kernel)) {
      $this->kernel->shutdown();
    }
    parent::tearDown();

    // Ensure that internal logged in variable and cURL options are reset.
    $this->loggedInUser = FALSE;
    $this->additionalCurlOptions = array();

    // Close the CURL handler and reset the cookies array used for upgrade
    // testing so test classes containing multiple tests are not polluted.
    $this->curlClose();
    $this->curlCookies = array();
    $this->resetMink();
  }

  /**
   * Initializes the cURL connection.
   *
   * If the simpletest_httpauth_credentials variable is set, this function will
   * add HTTP authentication headers. This is necessary for testing sites that
   * are protected by login credentials from public access.
   * See the description of $curl_options for other options.
   */
  protected function curlInitialize() {
    global $base_url;

    if (!isset($this->mink)) {
      $this->initMink();
    }
    // We set the user agent header on each request so as to use the current
    // time and a new uniqid.
    if (preg_match('/simpletest\d+/', $this->databasePrefix, $matches)) {
      $this->getMink()->getSession()->setRequestHeader('User-Agent', drupal_generate_test_ua($matches[0]));
    }
  }

  /**
   * Initializes and executes a cURL request.
   *
   * @param $curl_options
   *   An associative array of cURL options to set, where the keys are constants
   *   defined by the cURL library. For a list of valid options, see
   *   http://www.php.net/manual/function.curl-setopt.php
   * @param $redirect
   *   FALSE if this is an initial request, TRUE if this request is the result
   *   of a redirect.
   *
   * @return
   *   The content returned from the call to curl_exec().
   *
   * @see curlInitialize()
   */
  protected function curlExec($curl_options, $redirect = FALSE) {
    $this->curlInitialize();

    if (!empty($curl_options[CURLOPT_URL])) {
      // cURL incorrectly handles URLs with a fragment by including the
      // fragment in the request to the server, causing some web servers
      // to reject the request citing "400 - Bad Request". To prevent
      // this, we strip the fragment from the request.
      // TODO: Remove this for Drupal 8, since fixed in curl 7.20.0.
      if (strpos($curl_options[CURLOPT_URL], '#')) {
        $original_url = $curl_options[CURLOPT_URL];
        $curl_options[CURLOPT_URL] = strtok($curl_options[CURLOPT_URL], '#');
      }
    }

    $url = empty($curl_options[CURLOPT_URL]) ? $this->getSession()->getCurrentUrl() : $curl_options[CURLOPT_URL];

    if (!empty($curl_options[CURLOPT_POST])) {
      // This is a fix for the Curl library to prevent Expect: 100-continue
      // headers in POST requests, that may cause unexpected HTTP response
      // codes from some webservers (like lighttpd that returns a 417 error
      // code). It is done by setting an empty "Expect" header field that is
      // not overwritten by Curl.
      $curl_options[CURLOPT_HTTPHEADER][] = 'Expect:';
    }

    $cookies = array();
    if (!empty($this->curlCookies)) {
      $cookies = $this->curlCookies;
    }
    // In order to debug web tests you need to either set a cookie, have the
    // Xdebug session in the URL or set an environment variable in case of CLI
    // requests. If the developer listens to connection on the parent site, by
    // default the cookie is not forwarded to the client side, so you cannot
    // debug the code running on the child site. In order to make debuggers work
    // this bit of information is forwarded. Make sure that the debugger listens
    // to at least three external connections.
    $request = \Drupal::request();
    $cookie_params = $request->cookies;
    if ($cookie_params->has('XDEBUG_SESSION')) {
      $cookies[] = 'XDEBUG_SESSION=' . $cookie_params->get('XDEBUG_SESSION');
    }
    // For CLI requests, the information is stored in $_SERVER.
    $server = $request->server;
    if ($server->has('XDEBUG_CONFIG')) {
      // $_SERVER['XDEBUG_CONFIG'] has the form "key1=value1 key2=value2 ...".
      $pairs = explode(' ', $server->get('XDEBUG_CONFIG'));
      foreach ($pairs as $pair) {
        list($key, $value) = explode('=', $pair);
        // Account for key-value pairs being separated by multiple spaces.
        if (trim($key, ' ') == 'idekey') {
          $cookies[] = 'XDEBUG_SESSION=' . trim($value, ' ');
        }
      }
    }

    // Merge additional cookies in.
    if (!empty($cookies)) {
      $curl_options += array(
        CURLOPT_COOKIE => '',
      );
      // Ensure any existing cookie data string ends with the correct separator.
      if (!empty($curl_options[CURLOPT_COOKIE])) {
        $curl_options[CURLOPT_COOKIE] = rtrim($curl_options[CURLOPT_COOKIE], '; ') . '; ';
      }
      $curl_options[CURLOPT_COOKIE] .= implode('; ', $cookies) . ';';
    }

    // Set client options on Goutte's Guzzle client.
    $this->setClientOptions($this->additionalCurlOptions + $curl_options);

    if (!$redirect) {
      // Reset headers, the session ID and the redirect counter.
      $this->session_id = NULL;
      $this->headers = array();
      $this->redirect_count = 0;
    }

    $this->getSession()->visit($url);

    $content = $this->getSession()->getPage()->getContent();
    $status = $this->getSession()->getStatusCode();

    /**
     * May not be needed. @todo larowlan confirm
     *
    // cURL incorrectly handles URLs with fragments, so instead of
    // letting cURL handle redirects we take of them ourselves to
    // to prevent fragments being sent to the web server as part
    // of the request.
    // TODO: Remove this for Drupal 8, since fixed in curl 7.20.0.
    if (in_array($status, array(300, 301, 302, 303, 305, 307)) && $this->redirect_count < $this->maximumRedirects) {
      if ($this->drupalGetHeader('location')) {
        $this->redirect_count++;
        $curl_options = array();
        $curl_options[CURLOPT_URL] = $this->drupalGetHeader('location');
        $curl_options[CURLOPT_HTTPGET] = TRUE;
        return $this->curlExec($curl_options, TRUE);
      }
    }
    */

    $this->drupalSetContent($content, isset($original_url) ? $original_url : $this->getSession()->getCurrentUrl());
    $message_vars = array(
      '!method' => !empty($curl_options[CURLOPT_NOBODY]) ? 'HEAD' : (empty($curl_options[CURLOPT_POSTFIELDS]) ? 'GET' : 'POST'),
      '@url' => isset($original_url) ? $original_url : $url,
      '@status' => $status,
      '!length' => format_size(strlen($this->drupalGetContent()))
    );
    $message = String::format('!method @url returned @status (!length).', $message_vars);
    $this->assertTrue($this->drupalGetContent() !== FALSE, $message, 'Browser');
    return $this->drupalGetContent();
  }

  /**
   * Reads headers and registers errors received from the tested site.
   *
   * @param $curlHandler
   *   The cURL handler.
   * @param $header
   *   An header.
   *
   * @see _drupal_log_error().
   */
  protected function curlHeaderCallback($curlHandler, $header) {
    // @todo larowlan convert this to Guzzle/Mink, we need to subscribe to the
    //   request complete method on the Guzzle response, so need to subclass.
    // Header fields can be extended over multiple lines by preceding each
    // extra line with at least one SP or HT. They should be joined on receive.
    // Details are in RFC2616 section 4.
    if ($header[0] == ' ' || $header[0] == "\t") {
      // Normalize whitespace between chucks.
      $this->headers[] = array_pop($this->headers) . ' ' . trim($header);
    }
    else {
      $this->headers[] = $header;
    }

    // Errors are being sent via X-Drupal-Assertion-* headers,
    // generated by _drupal_log_error() in the exact form required
    // by \Drupal\simpletest\WebTestBase::error().
    if (preg_match('/^X-Drupal-Assertion-[0-9]+: (.*)$/', $header, $matches)) {
      // Call \Drupal\simpletest\WebTestBase::error() with the parameters from
      // the header.
      call_user_func_array(array(&$this, 'error'), unserialize(urldecode($matches[1])));
    }

    // Save cookies.
    if (preg_match('/^Set-Cookie: ([^=]+)=(.+)/', $header, $matches)) {
      $name = $matches[1];
      $parts = array_map('trim', explode(';', $matches[2]));
      $value = array_shift($parts);
      $this->cookies[$name] = array('value' => $value, 'secure' => in_array('secure', $parts));
      if ($name == $this->session_name) {
        if ($value != 'deleted') {
          $this->session_id = $value;
        }
        else {
          $this->session_id = NULL;
        }
      }
    }

    // This is required by cURL.
    return strlen($header);
  }

  /**
   * Close the cURL handler and unset the handler.
   */
  protected function curlClose() {
    if (isset($this->curlHandle)) {
      curl_close($this->curlHandle);
      unset($this->curlHandle);
    }
  }

  /**
   * Returns whether the test is being executed from within a test site.
   *
   * Mainly used by recursive tests (i.e. to test the testing framework).
   *
   * @return bool
   *   TRUE if this test was instantiated in a request within the test site,
   *   FALSE otherwise.
   *
   * @see _drupal_bootstrap_configuration()
   */
  protected function isInChildSite() {
    return DRUPAL_TEST_IN_CHILD_SITE;
  }

  /**
   * Parse content returned from curlExec using DOM and SimpleXML.
   *
   * @return
   *   A SimpleXMLElement or FALSE on failure.
   */
  protected function parse() {
    if (!$this->elements) {
      // DOM can load HTML soup. But, HTML soup can throw warnings, suppress
      // them.
      $htmlDom = new \DOMDocument();
      @$htmlDom->loadHTML('<?xml encoding="UTF-8">' . $this->drupalGetContent());
      if ($htmlDom) {
        $this->pass(String::format('Valid HTML found on "@path"', array('@path' => $this->getUrl())), 'Browser');
        // It's much easier to work with simplexml than DOM, luckily enough
        // we can just simply import our DOM tree.
        $this->elements = simplexml_import_dom($htmlDom);
      }
    }
    if (!$this->elements) {
      $this->fail('Parsed page successfully.', 'Browser');
    }

    return $this->elements;
  }

  /**
   * Retrieves a Drupal path or an absolute path.
   *
   * @param $path
   *   Drupal path or URL to load into internal browser
   * @param $options
   *   Options to be forwarded to the url generator.
   * @param $headers
   *   An array containing additional HTTP request headers, each formatted as
   *   "name: value".
   *
   * @return
   *   The retrieved HTML string, also available as $this->drupalGetContent()
   */
  protected function drupalGet($path, array $options = array(), array $headers = array()) {
    $options['absolute'] = TRUE;

    // The URL generator service is not necessarily available yet; e.g., in
    // interactive installer tests.
    if ($this->container->has('url_generator')) {
      $url = $this->container->get('url_generator')->generateFromPath($path, $options);
    }
    else {
      $url = $this->getAbsoluteUrl($path);
    }

    // We re-using a CURL connection here. If that connection still has certain
    // options set, it might change the GET into a POST. Make sure we clear out
    // previous options.
    $out = $this->curlExec(array(CURLOPT_HTTPGET => TRUE, CURLOPT_URL => $url, CURLOPT_NOBODY => FALSE, CURLOPT_HTTPHEADER => $headers));
    // Ensure that any changes to variables in the other thread are picked up.
    $this->refreshVariables();

    // Replace original page output with new output from redirected page(s).
    if ($new = $this->checkForMetaRefresh()) {
      $out = $new;
    }

    $verbose = 'GET request to: ' . $path .
               '<hr />Ending URL: ' . $this->getUrl();
    if ($this->dumpHeaders) {
      $verbose .= '<hr />Headers: <pre>' . check_plain(var_export(array_map('trim', $this->headers), TRUE)) . '</pre>';
    }
    $verbose .= '<hr />' . $out;

    $this->verbose($verbose);
    return $out;
  }

  /**
   * Retrieves a Drupal path or an absolute path and JSON decode the result.
   *
   * @param string $path
   *   Path to request AJAX from.
   * @param array $options
   *   Array of options to pass to url().
   * @param array $headers
   *   Array of headers. Eg array('Accept: application/vnd.drupal-ajax').
   *
   * @return array
   *   Decoded json.
   * Requests a Drupal path in JSON format, and JSON decodes the response.
   */
  protected function drupalGetJSON($path, array $options = array(), array $headers = array()) {
    $headers[] = 'Accept: application/json';
    return Json::decode($this->drupalGet($path, $options, $headers));
  }

  /**
   * Requests a Drupal path in drupal_ajax format and JSON-decodes the response.
   */
  protected function drupalGetAJAX($path, array $options = array(), array $headers = array()) {
    $headers[] = 'Accept: application/vnd.drupal-ajax';
    return Json::decode($this->drupalGet($path, $options, $headers));
  }

  /**
   * Executes a form submission.
   *
   * It will be done as usual POST request with SimpleBrowser.
   *
   * @param $path
   *   Location of the post form. Either a Drupal path or an absolute path or
   *   NULL to post to the current page. For multi-stage forms you can set the
   *   path to NULL and have it post to the last received page. Example:
   *
   *   @code
   *   // First step in form.
   *   $edit = array(...);
   *   $this->drupalPostForm('some_url', $edit, t('Save'));
   *
   *   // Second step in form.
   *   $edit = array(...);
   *   $this->drupalPostForm(NULL, $edit, t('Save'));
   *   @endcode
   * @param  $edit
   *   Field data in an associative array. Changes the current input fields
   *   (where possible) to the values indicated.
   *
   *   When working with form tests, the keys for an $edit element should match
   *   the 'name' parameter of the HTML of the form. For example, the 'body'
   *   field for a node has the following HTML:
   *   @code
   *   <textarea id="edit-body-und-0-value" class="text-full form-textarea
   *    resize-vertical" placeholder="" cols="60" rows="9"
   *    name="body[0][value]"></textarea>
   *   @endcode
   *   When testing this field using an $edit parameter, the code becomes:
   *   @code
   *   $edit["body[0][value]"] = 'My test value';
   *   @endcode
   *
   *   A checkbox can be set to TRUE to be checked and should be set to FALSE to
   *   be unchecked. Multiple select fields can be tested using 'name[]' and
   *   setting each of the desired values in an array:
   *   @code
   *   $edit = array();
   *   $edit['name[]'] = array('value1', 'value2');
   *   @endcode
   *
   *   Note that when a form contains file upload fields, other
   *   fields cannot start with the '@' character.
   * @param $submit
   *   Value of the submit button whose click is to be emulated. For example,
   *   t('Save'). The processing of the request depends on this value. For
   *   example, a form may have one button with the value t('Save') and another
   *   button with the value t('Delete'), and execute different code depending
   *   on which one is clicked.
   *
   *   This function can also be called to emulate an Ajax submission. In this
   *   case, this value needs to be an array with the following keys:
   *   - path: A path to submit the form values to for Ajax-specific processing,
   *     which is likely different than the $path parameter used for retrieving
   *     the initial form. Defaults to 'system/ajax'.
   *   - triggering_element: If the value for the 'path' key is 'system/ajax' or
   *     another generic Ajax processing path, this needs to be set to the name
   *     of the element. If the name doesn't identify the element uniquely, then
   *     this should instead be an array with a single key/value pair,
   *     corresponding to the element name and value. The callback for the
   *     generic Ajax processing path uses this to find the #ajax information
   *     for the element, including which specific callback to use for
   *     processing the request.
   *
   *   This can also be set to NULL in order to emulate an Internet Explorer
   *   submission of a form with a single text field, and pressing ENTER in that
   *   textfield: under these conditions, no button information is added to the
   *   POST data.
   * @param $options
   *   Options to be forwarded to the url generator.
   * @param $headers
   *   An array containing additional HTTP request headers, each formatted as
   *   "name: value".
   * @param $form_html_id
   *   (optional) HTML ID of the form to be submitted. On some pages
   *   there are many identical forms, so just using the value of the submit
   *   button is not enough. For example: 'trigger-node-presave-assign-form'.
   *   Note that this is not the Drupal $form_id, but rather the HTML ID of the
   *   form, which is typically the same thing but with hyphens replacing the
   *   underscores.
   * @param $extra_post
   *   (optional) A string of additional data to append to the POST submission.
   *   This can be used to add POST data for which there are no HTML fields, as
   *   is done by drupalPostAjaxForm(). This string is literally appended to the
   *   POST data, so it must already be urlencoded and contain a leading "&"
   *   (e.g., "&extra_var1=hello+world&extra_var2=you%26me").
   */
  protected function drupalPostForm($path, $edit, $submit, array $options = array(), array $headers = array(), $form_html_id = NULL, $extra_post = NULL) {
    // @todo larowlan refactor around Mink
    $submit_matches = FALSE;
    $ajax = is_array($submit);
    if (isset($path)) {
      $this->drupalGet($path, $options);
    }
    if ($this->parse()) {
      $edit_save = $edit;
      // Let's iterate over all the forms.
      $xpath = "//form";
      if (!empty($form_html_id)) {
        $xpath .= "[@id='" . $form_html_id . "']";
      }
      /** @var \Behat\Mink\Element\NodeElement[] $forms */
      $forms = $this->xpath($xpath);
      foreach ($forms as $form) {
        // We try to set the fields of this form as specified in $edit.
        $edit = $edit_save;
        $post = array();
        $upload = array();
        $submit_element = $this->handleForm($post, $edit, $upload, $ajax ? NULL : $submit, $form);
        $action = $form->getAttribute('action') ? $this->getAbsoluteUrl($form->getAttribute('action')) : $this->getUrl();
        if ($ajax) {
          $action = $this->getAbsoluteUrl(!empty($submit['path']) ? $submit['path'] : 'system/ajax');
          // Ajax callbacks verify the triggering element if necessary, so while
          // we may eventually want extra code that verifies it in the
          // handleForm() function, it's not currently a requirement.
          // @todo this needs to be an Element.
          $submit_element = TRUE;
        }
        // We post only if we managed to handle every field in edit and the
        // submit button matches.
        if (!$edit && ($submit_element || !isset($submit))) {
          $post_array = $post;
          if ($upload) {
            foreach ($upload as $key => $file) {
              $file = drupal_realpath($file);
              if ($file && is_file($file)) {
                // Use the new CurlFile class for file uploads when using PHP
                // 5.5.
                if (class_exists('CurlFile')) {
                  $post[$key] = curl_file_create($file);
                }
                else {
                  // @todo: Drop support for this when PHP 5.5 is required.
                  $post[$key] = '@' . $file;
                }
              }
            }
          }
          $submit_element->press();
          $out = $this->getSession()->getPage()->getContent();
          // Ensure that any changes to variables in the other thread are picked
          // up.
          $this->refreshVariables();

          // Replace original page output with new output from redirected
          // page(s).
          if ($new = $this->checkForMetaRefresh()) {
            $out = $new;
          }

          if ($session_id = $this->getSession()->getCookie($this->session_name)) {
            $this->session_id = $session_id;
          }

          $verbose = 'POST request to: ' . $path;
          $verbose .= '<hr />Ending URL: ' . $this->getUrl();
          if ($this->dumpHeaders) {
            $verbose .= '<hr />Headers: <pre>' . check_plain(var_export(array_map('trim', $this->headers), TRUE)) . '</pre>';
          }
          $verbose .= '<hr />Fields: ' . highlight_string('<?php ' . var_export($post_array, TRUE), TRUE);
          $verbose .= '<hr />' . $out;

          $this->verbose($verbose);
          $this->drupalSetContent($out, $this->getUrl());
          return $out;
        }
      }
      // We have not found a form which contained all fields of $edit.
      foreach ($edit as $name => $value) {
        $this->fail(String::format('Failed to set field @name to @value', array('@name' => $name, '@value' => $value)));
      }
      if (!$ajax && isset($submit)) {
        $this->assertTrue($submit_matches, format_string('Found the @submit button', array('@submit' => $submit)));
      }
      $this->fail(format_string('Found the requested form fields at @path', array('@path' => $path)));
    }
  }

  /**
   * Executes an Ajax form submission.
   *
   * This executes a POST as ajax.js does. The returned JSON data is used to
   * update $this->content via drupalProcessAjaxResponse(). It also returns
   * the array of AJAX commands received.
   *
   * @param $path
   *   Location of the form containing the Ajax enabled element to test. Can be
   *   either a Drupal path or an absolute path or NULL to use the current page.
   * @param $edit
   *   Field data in an associative array. Changes the current input fields
   *   (where possible) to the values indicated.
   * @param $triggering_element
   *   The name of the form element that is responsible for triggering the Ajax
   *   functionality to test. May be a string or, if the triggering element is
   *   a button, an associative array where the key is the name of the button
   *   and the value is the button label. i.e.) array('op' => t('Refresh')).
   * @param $ajax_path
   *   (optional) Override the path set by the Ajax settings of the triggering
   *   element. In the absence of both the triggering element's Ajax path and
   *   $ajax_path 'system/ajax' will be used.
   * @param $options
   *   (optional) Options to be forwarded to the url generator.
   * @param $headers
   *   (optional) An array containing additional HTTP request headers, each
   *   formatted as "name: value". Forwarded to drupalPostForm().
   * @param $form_html_id
   *   (optional) HTML ID of the form to be submitted, use when there is more
   *   than one identical form on the same page and the value of the triggering
   *   element is not enough to identify the form. Note this is not the Drupal
   *   ID of the form but rather the HTML ID of the form.
   * @param $ajax_settings
   *   (optional) An array of Ajax settings which if specified will be used in
   *   place of the Ajax settings of the triggering element.
   *
   * @return
   *   An array of Ajax commands.
   *
   * @see drupalPostForm()
   * @see drupalProcessAjaxResponse()
   * @see ajax.js
   */
  protected function drupalPostAjaxForm($path, $edit, $triggering_element, $ajax_path = NULL, array $options = array(), array $headers = array(), $form_html_id = NULL, $ajax_settings = NULL) {
    // @todo larowlan refactor around Mink
    // Get the content of the initial page prior to calling drupalPostForm(),
    // since drupalPostForm() replaces $this->content.
    if (isset($path)) {
      $this->drupalGet($path, $options);
    }
    $content = $this->content;
    $drupal_settings = $this->drupalSettings;

    $headers[] = 'Accept: application/vnd.drupal-ajax';

    // Get the Ajax settings bound to the triggering element.
    if (!isset($ajax_settings)) {
      if (is_array($triggering_element)) {
        $xpath = '//*[@name="' . key($triggering_element) . '" and @value="' . current($triggering_element) . '"]';
      }
      else {
        $xpath = '//*[@name="' . $triggering_element . '"]';
      }
      if (isset($form_html_id)) {
        $xpath = '//form[@id="' . $form_html_id . '"]' . $xpath;
      }
      $element = $this->xpath($xpath);
      $element_id = $element[0]->getAttribute('id');
      $ajax_settings = $drupal_settings['ajax'][$element_id];
    }

    // Add extra information to the POST data as ajax.js does.
    $extra_post = array();
    if (isset($ajax_settings['submit'])) {
      foreach ($ajax_settings['submit'] as $key => $value) {
        $extra_post[$key] = $value;
      }
    }
    $ajax_html_ids = array();
    foreach ($this->xpath('//*[@id]') as $element) {
      $ajax_html_ids[] = $element->getAttribute('id');
    }
    if (!empty($ajax_html_ids)) {
      $extra_post['ajax_html_ids'] = implode(' ', $ajax_html_ids);
    }
    $extra_post += $this->getAjaxPageStatePostData();
    // Now serialize all the $extra_post values, and prepend it with an '&'.
    $extra_post = '&' . $this->serializePostValues($extra_post);

    // Unless a particular path is specified, use the one specified by the
    // Ajax settings, or else 'system/ajax'.
    if (!isset($ajax_path)) {
      $ajax_path = isset($ajax_settings['url']) ? $ajax_settings['url'] : 'system/ajax';
    }

    // Submit the POST request.
    $return = Json::decode($this->drupalPostForm(NULL, $edit, array('path' => $ajax_path, 'triggering_element' => $triggering_element), $options, $headers, $form_html_id, $extra_post));

    // Change the page content by applying the returned commands.
    if (!empty($ajax_settings) && !empty($return)) {
      $this->drupalProcessAjaxResponse($content, $return, $ajax_settings, $drupal_settings);
    }

    $verbose = 'AJAX POST request to: ' . $path;
    $verbose .= '<br />AJAX controller path: ' . $ajax_path;
    $verbose .= '<hr />Ending URL: ' . $this->getUrl();
    $verbose .= '<hr />' . $this->content;

    $this->verbose($verbose);

    return $return;
  }

  /**
   * Processes an AJAX response into current content.
   *
   * This processes the AJAX response as ajax.js does. It uses the response's
   * JSON data, an array of commands, to update $this->content using equivalent
   * DOM manipulation as is used by ajax.js.
   * It does not apply custom AJAX commands though, because emulation is only
   * implemented for the AJAX commands that ship with Drupal core.
   *
   * @param string $content
   *   The current HTML content.
   * @param array $ajax_response
   *   An array of AJAX commands.
   * @param array $ajax_settings
   *   An array of AJAX settings which will be used to process the response.
   * @param array $drupal_settings
   *   An array of settings to update the value of drupalSettings for the
   *   currently-loaded page.
   *
   * @see drupalPostAjaxForm()
   * @see ajax.js
   */
  protected function drupalProcessAjaxResponse($content, array $ajax_response, array $ajax_settings, array $drupal_settings) {
    // @todo larowlan refactor around Mink
    // ajax.js applies some defaults to the settings object, so do the same
    // for what's used by this function.
    $ajax_settings += array(
      'method' => 'replaceWith',
    );
    // DOM can load HTML soup. But, HTML soup can throw warnings, suppress
    // them.
    $dom = new \DOMDocument();
    @$dom->loadHTML($content);
    // XPath allows for finding wrapper nodes better than DOM does.
    $xpath = new \DOMXPath($dom);
    foreach ($ajax_response as $command) {
      switch ($command['command']) {
        case 'settings':
          $drupal_settings = drupal_merge_js_settings(array($drupal_settings, $command['settings']));
          break;

        case 'insert':
          $wrapperNode = NULL;
          // When a command doesn't specify a selector, use the
          // #ajax['wrapper'] which is always an HTML ID.
          if (!isset($command['selector'])) {
            $wrapperNode = $xpath->query('//*[@id="' . $ajax_settings['wrapper'] . '"]')->item(0);
          }
          // @todo Ajax commands can target any jQuery selector, but these are
          //   hard to fully emulate with XPath. For now, just handle 'head'
          //   and 'body', since these are used by
          //   \Drupal\Core\Ajax\AjaxResponse::ajaxRender().
          elseif (in_array($command['selector'], array('head', 'body'))) {
            $wrapperNode = $xpath->query('//' . $command['selector'])->item(0);
          }
          if ($wrapperNode) {
            // ajax.js adds an enclosing DIV to work around a Safari bug.
            $newDom = new \DOMDocument();
            @$newDom->loadHTML('<div>' . $command['data'] . '</div>');
            $newNode = $dom->importNode($newDom->documentElement->firstChild->firstChild, TRUE);
            $method = isset($command['method']) ? $command['method'] : $ajax_settings['method'];
            // The "method" is a jQuery DOM manipulation function. Emulate
            // each one using PHP's DOMNode API.
            switch ($method) {
              case 'replaceWith':
                $wrapperNode->parentNode->replaceChild($newNode, $wrapperNode);
                break;
              case 'append':
                $wrapperNode->appendChild($newNode);
                break;
              case 'prepend':
                // If no firstChild, insertBefore() falls back to
                // appendChild().
                $wrapperNode->insertBefore($newNode, $wrapperNode->firstChild);
                break;
              case 'before':
                $wrapperNode->parentNode->insertBefore($newNode, $wrapperNode);
                break;
              case 'after':
                // If no nextSibling, insertBefore() falls back to
                // appendChild().
                $wrapperNode->parentNode->insertBefore($newNode, $wrapperNode->nextSibling);
                break;
              case 'html':
                foreach ($wrapperNode->childNodes as $childNode) {
                  $wrapperNode->removeChild($childNode);
                }
                $wrapperNode->appendChild($newNode);
                break;
            }
          }
          break;

        // @todo Add suitable implementations for these commands in order to
        //   have full test coverage of what ajax.js can do.
        case 'remove':
          break;
        case 'changed':
          break;
        case 'css':
          break;
        case 'data':
          break;
        case 'restripe':
          break;
        case 'add_css':
          break;
      }
    }
    $content = $dom->saveHTML();
    $this->drupalSetContent($content);
    $this->drupalSetSettings($drupal_settings);
  }

  /**
   * Perform a POST HTTP request.
   *
   * @param string $path
   *   Drupal path where the request should be POSTed to. Will be transformed
   *   into an absolute path automatically.
   * @param string $accept
   *   The value for the "Accept" header. Usually either 'application/json' or
   *   'application/vnd.drupal-ajax'.
   * @param array $post
   *   The POST data. When making a 'application/vnd.drupal-ajax' request, the
   *   Ajax page state data should be included. Use getAjaxPageStatePostData()
   *   for that.
   * @param array $options
   *   (optional) Options to be forwarded to the url generator. The 'absolute'
   *   option will automatically be enabled.
   *
   * @return
   *   The content returned from the call to curl_exec().
   *
   * @see WebTestBase::getAjaxPageStatePostData()
   * @see WebTestBase::curlExec()
   * @see url()
   */
  protected function drupalPost($path, $accept, array $post, $options = array()) {
    return $this->curlExec(array(
      CURLOPT_URL => url($path, $options + array('absolute' => TRUE)),
      CURLOPT_POST => TRUE,
      CURLOPT_POSTFIELDS => $this->serializePostValues($post),
      CURLOPT_HTTPHEADER => array(
        'Accept: ' . $accept,
        'Content-Type: application/x-www-form-urlencoded',
      ),
    ));
  }

  /**
   * Get the Ajax page state from drupalSettings and prepare it for POSTing.
   *
   * @return array
   *   The Ajax page state POST data.
   */
  protected function getAjaxPageStatePostData() {
    // @todo larowlan refactor around Mink
    $post = array();
    $drupal_settings = $this->drupalSettings;
    if (isset($drupal_settings['ajaxPageState'])) {
      $post['ajax_page_state[theme]'] = $drupal_settings['ajaxPageState']['theme'];
      $post['ajax_page_state[theme_token]'] = $drupal_settings['ajaxPageState']['theme_token'];
      foreach ($drupal_settings['ajaxPageState']['css'] as $key => $value) {
        $post["ajax_page_state[css][$key]"] = 1;
      }
      foreach ($drupal_settings['ajaxPageState']['js'] as $key => $value) {
        $post["ajax_page_state[js][$key]"] = 1;
      }
    }
    return $post;
  }

  /**
   * Serialize POST HTTP request values.
   *
   * Encode according to application/x-www-form-urlencoded. Both names and
   * values needs to be urlencoded, according to
   * http://www.w3.org/TR/html4/interact/forms.html#h-17.13.4.1
   *
   * @param array $post
   *   The array of values to be POSTed.
   *
   * @return string
   *   The serialized result.
   */
  protected function serializePostValues($post = array()) {
    foreach ($post as $key => $value) {
      $post[$key] = urlencode($key) . '=' . urlencode($value);
    }
    return implode('&', $post);
  }

  /**
   * Transforms a nested array into a flat array suitable for WebTestBase::drupalPostForm().
   *
   * @param array $values
   *   A multi-dimensional form values array to convert.
   *
   * @return array
   *   The flattened $edit array suitable for WebTestBase::drupalPostForm().
   */
  protected function translatePostValues(array $values) {
    $edit = array();
    // The easiest and most straightforward way to translate values suitable for
    // WebTestBase::drupalPostForm() is to actually build the POST data string
    // and convert the resulting key/value pairs back into a flat array.
    $query = http_build_query($values);
    foreach (explode('&', $query) as $item) {
      list($key, $value) = explode('=', $item);
      $edit[urldecode($key)] = urldecode($value);
    }
    return $edit;
  }

  /**
   * Runs cron in the Drupal installed by Simpletest.
   */
  protected function cronRun() {
    $this->drupalGet('cron/' . \Drupal::state()->get('system.cron_key'));
  }

  /**
   * Checks for meta refresh tag and if found call drupalGet() recursively.
   *
   * This function looks for the http-equiv attribute to be set to "Refresh" and
   * is case-sensitive.
   *
   * @return
   *   Either the new page content or FALSE.
   */
  protected function checkForMetaRefresh() {
    // @todo larowlan refactor around Mink
    if (strpos($this->drupalGetContent(), '<meta ') && $this->parse()) {
      $refresh = $this->xpath('//meta[@http-equiv="Refresh"]');
      if (!empty($refresh)) {
        // Parse the content attribute of the meta tag for the format:
        // "[delay]: URL=[page_to_redirect_to]".
        if (preg_match('/\d+;\s*URL=(?<url>.*)/i', $refresh[0]->getAttribute('content'), $match)) {
          return $this->drupalGet($this->getAbsoluteUrl(decode_entities($match['url'])));
        }
      }
    }
    return FALSE;
  }

  /**
   * Retrieves only the headers for a Drupal path or an absolute path.
   *
   * @param $path
   *   Drupal path or URL to load into internal browser
   * @param $options
   *   Options to be forwarded to the url generator.
   * @param $headers
   *   An array containing additional HTTP request headers, each formatted as
   *   "name: value".
   *
   * @return
   *   The retrieved headers, also available as $this->drupalGetContent()
   */
  protected function drupalHead($path, array $options = array(), array $headers = array()) {
    // @todo larowlan refactor around Mink
    $options['absolute'] = TRUE;
    $url = $this->container->get('url_generator')->generateFromPath($path, $options);
    $out = $this->curlExec(array(CURLOPT_NOBODY => TRUE, CURLOPT_URL => $url, CURLOPT_HTTPHEADER => $headers));
    // Ensure that any changes to variables in the other thread are picked up.
    $this->refreshVariables();

    if ($this->dumpHeaders) {
      $this->verbose('GET request to: ' . $path .
                     '<hr />Ending URL: ' . $this->getUrl() .
                     '<hr />Headers: <pre>' . check_plain(var_export(array_map('trim', $this->headers), TRUE)) . '</pre>');
    }

    return $out;
  }

  /**
   * Handles form input related to drupalPostForm().
   *
   * Ensure that the specified fields exist and attempt to create POST data in
   * the correct manner for the particular field type.
   *
   * @param array $post
   *   Reference to array of post values.
   * @param array $edit
   *   Reference to array of edit values to be checked against the form.
   * @param array $upload
   *   Array of file uploads
   * @param string $submit
   *   Form submit button value.
   * @param \Behat\Mink\Element\NodeElement $form
   *   Array of form elements.
   *
   * @return \Behat\Mink\Element\NodeElement|bool
   *   The submit button for the form to trigger the POST.
   */
  protected function handleForm(&$post, &$edit, &$upload, $submit, NodeElement $form) {
    // Retrieve the form elements.
    /** @var \Behat\Mink\Element\NodeElement[] $elements */
    $elements = $form->findAll('xpath', './/input[not(@disabled)]|.//textarea[not(@disabled)]|.//select[not(@disabled)]');
    $submit_matches = FALSE;
    foreach ($elements as $element) {
      // SimpleXML objects need string casting all the time.
      $name = $element->getAttribute('name');
      // This can either be the type of <input> or the name of the tag itself
      // for <select> or <textarea>.
      $type = $element->getAttribute('type') ? $element->getAttribute('type') : $element->getTagName();
      $value = $element->getValue();
      $done = FALSE;
      if (isset($edit[$name])) {
        switch ($type) {
          case 'text':
          case 'tel':
          case 'textarea':
          case 'url':
          case 'number':
          case 'range':
          case 'color':
          case 'hidden':
          case 'password':
          case 'email':
          case 'search':
          case 'date':
          case 'time':
          case 'datetime':
          case 'datetime-local';
            $element->setValue($edit[$name]);
            unset($edit[$name]);
            break;
          case 'radio':
            if ($element->getAttribute('name') == $name && $element->getAttribute('value') == $edit[$name]) {
              $element->setValue($edit[$name]);
              unset($edit[$name]);
            }
            break;
          case 'checkbox':
            // To prevent checkbox from being checked.pass in a FALSE,
            // otherwise the checkbox will be set to its value regardless
            // of $edit.
            if ($edit[$name] === FALSE) {
              $element->uncheck();
              unset($edit[$name]);
              continue 2;
            }
            else {
              unset($edit[$name]);
              $element->check();
            }
            break;
          case 'select':
            $new_value = $edit[$name];
            if (is_array($new_value)) {
              // Multiple select box.
              if (!empty($new_value)) {
                foreach ($new_value as $value) {
                  $element->selectOption($value, TRUE);
                }
              }
              else {
                // No options selected: do not include any POST data for the
                // element.
                $done = TRUE;
                unset($edit[$name]);
              }
            }
            else {
              $element->setValue($edit[$name]);
              unset($edit[$name]);
            }
            break;
          case 'file':
            $element->attachFile($edit[$name]);
            unset($edit[$name]);
            break;
        }
      }
      if (!isset($post[$name]) && !$done) {
        switch ($type) {
          case 'textarea':
            $post[$name] = $element->getValue();
            break;
          case 'select':
            $single = !$element->getAttribute('multiple');
            $post[$name] = $element->getValue();
            break;
          case 'file':
            break;
          case 'submit':
          case 'image':
            if (isset($submit) && $submit == $value) {
              $post[$name] = $value;
              $submit_matches = $element;
            }
            break;
          case 'radio':
          case 'checkbox':
            if (!$element->isChecked()) {
              break;
            }
            // Deliberate no break.
          default:
            $post[$name] = $value;
        }
      }
    }
    // An empty name means the value is not sent.
    unset($post['']);
    return $submit_matches;
  }

  /**
   * Builds an XPath query.
   *
   * Builds an XPath query by replacing placeholders in the query by the value
   * of the arguments.
   *
   * XPath 1.0 (the version supported by libxml2, the underlying XML library
   * used by PHP) doesn't support any form of quotation. This function
   * simplifies the building of XPath expression.
   *
   * @param string $xpath
   *   An XPath query, possibly with placeholders in the form ':name'.
   * @param array $args
   *   An array of arguments with keys in the form ':name' matching the
   *   placeholders in the query. The values may be either strings or numeric
   *   values.
   *
   * @return string
   *   An XPath query with arguments replaced.
   */
  protected function buildXPathQuery($xpath, array $args = array()) {
    // Replace placeholders.
    foreach ($args as $placeholder => $value) {
      // XPath 1.0 doesn't support a way to escape single or double quotes in a
      // string literal. We split double quotes out of the string, and encode
      // them separately.
      if (is_string($value)) {
        // Explode the text at the quote characters.
        $parts = explode('"', $value);

        // Quote the parts.
        foreach ($parts as &$part) {
          $part = '"' . $part . '"';
        }

        // Return the string.
        $value = count($parts) > 1 ? 'concat(' . implode(', \'"\', ', $parts) . ')' : $parts[0];
      }

      // Use preg_replace_callback() instead of preg_replace() to prevent the
      // regular expression engine from trying to substitute backreferences.
      $replacement = function ($matches) use ($value) {
        return $value;
      };
      $xpath = preg_replace_callback('/' . preg_quote($placeholder) . '\b/', $replacement, $xpath);
    }
    return $xpath;
  }

  /**
   * Performs an xpath search on the contents of the internal browser.
   *
   * The search is relative to the root element (HTML tag normally) of the page.
   *
   * @param string $xpath
   *   The xpath string to use in the search.
   * @param array $arguments
   *   An array of arguments with keys in the form ':name' matching the
   *   placeholders in the query. The values may be either strings or numeric
   *   values.
   *
   * @return \Behat\Mink\Element\NodeElement[]
   *   The return value of the xpath search. For details on the xpath string
   *   format and return values see the SimpleXML documentation,
   *   http://php.net/manual/function.simplexml-element-xpath.php.
   */
  protected function xpath($xpath, array $arguments = array()) {
    $xpath = $this->buildXPathQuery($xpath, $arguments);
    return $this->getSession()->getPage()->findAll('xpath', $xpath);
  }

  /**
   * Get all option elements, including nested options, in a select.
   *
   * @param $element
   *   The element for which to get the options.
   *
   * @return
   *   Option elements in select.
   */
  protected function getAllOptions(\SimpleXMLElement $element) {
    // @todo larowlan refactor around Mink
    $options = array();
    // Add all options items.
    foreach ($element->option as $option) {
      $options[] = $option;
    }

    // Search option group children.
    if (isset($element->optgroup)) {
      foreach ($element->optgroup as $group) {
        $options = array_merge($options, $this->getAllOptions($group));
      }
    }
    return $options;
  }

  /**
   * Passes if a link with the specified label is found.
   *
   * An optional link index may be passed.
   *
   * @param $label
   *   Text between the anchor tags.
   * @param $index
   *   Link position counting from zero.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The gorup this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE if the assertion succeeded, FALSE otherwise.
   */
  protected function assertLink($label, $index = 0, $message = '', $group = 'Other') {
    $links = $this->xpath('//a[normalize-space(text())=:label]', array(':label' => $label));
    $message = ($message ?  $message : String::format('Link with label %label found.', array('%label' => $label)));
    return $this->assert(isset($links[$index]), $message, $group);
  }

  /**
   * Passes if a link with the specified label is not found.
   *
   * @param $label
   *   Text between the anchor tags.
   * @param $index
   *   Link position counting from zero.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE if the assertion succeeded, FALSE otherwise.
   */
  protected function assertNoLink($label, $message = '', $group = 'Other') {
    $links = $this->xpath('//a[normalize-space(text())=:label]', array(':label' => $label));
    $message = ($message ?  $message : String::format('Link with label %label not found.', array('%label' => $label)));
    return $this->assert(empty($links), $message, $group);
  }

  /**
   * Passes if a link containing a given href (part) is found.
   *
   * @param $href
   *   The full or partial value of the 'href' attribute of the anchor tag.
   * @param $index
   *   Link position counting from zero.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE if the assertion succeeded, FALSE otherwise.
   */
  protected function assertLinkByHref($href, $index = 0, $message = '', $group = 'Other') {
    $links = $this->xpath('//a[contains(@href, :href)]', array(':href' => $href));
    $message = ($message ?  $message : String::format('Link containing href %href found.', array('%href' => $href)));
    return $this->assert(isset($links[$index]), $message, $group);
  }

  /**
   * Passes if a link containing a given href (part) is not found.
   *
   * @param $href
   *   The full or partial value of the 'href' attribute of the anchor tag.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE if the assertion succeeded, FALSE otherwise.
   */
  protected function assertNoLinkByHref($href, $message = '', $group = 'Other') {
    $links = $this->xpath('//a[contains(@href, :href)]', array(':href' => $href));
    $message = ($message ?  $message : String::format('No link containing href %href found.', array('%href' => $href)));
    return $this->assert(empty($links), $message, $group);
  }

  /**
   * Follows a link by name.
   *
   * Will click the first link found with this link text by default, or a later
   * one if an index is given. Match is case sensitive with normalized space.
   * The label is translated label. There is an assert for successful click.
   *
   * @param $label
   *   Text between the anchor tags.
   * @param $index
   *   Link position counting from zero.
   *
   * @return
   *   Page on success, or FALSE on failure.
   */
  protected function clickLink($label, $index = 0) {
    $url_before = $this->getUrl();
    /** @var \Behat\Mink\Element\NodeElement[] $urls */
    $urls = $this->xpath('//a[normalize-space()=:label]', array(':label' => $label));

    if (isset($urls[$index])) {
      $url_target = $this->getAbsoluteUrl($urls[$index]->getAttribute('href'));
    }

    $this->assertTrue(isset($urls[$index]), String::format('Clicked link %label (@url_target) from @url_before', array('%label' => $label, '@url_target' => $url_target, '@url_before' => $url_before)), 'Browser');

    if (isset($url_target)) {
      return $this->drupalGet($url_target);
    }
    return FALSE;
  }

  /**
   * Takes a path and returns an absolute path.
   *
   * @param $path
   *   A path from the internal browser content.
   *
   * @return
   *   The $path with $base_url prepended, if necessary.
   */
  protected function getAbsoluteUrl($path) {
    global $base_url, $base_path;

    $parts = parse_url($path);
    if (empty($parts['host'])) {
      // Ensure that we have a string (and no xpath object).
      $path = (string) $path;
      // Strip $base_path, if existent.
      $length = strlen($base_path);
      if (substr($path, 0, $length) === $base_path) {
        $path = substr($path, $length);
      }
      // Ensure that we have an absolute path.
      if ($path[0] !== '/') {
        $path = '/' . $path;
      }
      // Finally, prepend the $base_url.
      $path = $base_url . $path;
    }
    return $path;
  }

  /**
   * Get the current URL from the cURL handler.
   *
   * @return
   *   The current URL.
   */
  protected function getUrl() {
    return $this->getSession()->getCurrentUrl();
  }

  /**
   * Gets the HTTP response headers of the requested page.
   *
   * Normally we are only interested in the headers returned by the last
   * request. However, if a page is redirected or HTTP authentication is in use,
   * multiple requests will be required to retrieve the page. Headers from all
   * requests may be requested by passing TRUE to this function.
   *
   * @param $all_requests
   *   Boolean value specifying whether to return headers from all requests
   *   instead of just the last request. Defaults to FALSE.
   *
   * @return
   *   A name/value array if headers from only the last request are requested.
   *   If headers from all requests are requested, an array of name/value
   *   arrays, one for each request.
   *
   *   The pseudonym ":status" is used for the HTTP status line.
   *
   *   Values for duplicate headers are stored as a single comma-separated list.
   */
  protected function drupalGetHeaders($all_requests = FALSE) {
    $headers = $this->getSession()->getResponseHeaders();
    return $headers;
  }

  /**
   * Gets the value of an HTTP response header.
   *
   * If multiple requests were required to retrieve the page, only the headers
   * from the last request will be checked by default. However, if TRUE is
   * passed as the second argument, all requests will be processed from last to
   * first until the header is found.
   *
   * @param $name
   *   The name of the header to retrieve. Names are case-insensitive (see RFC
   *   2616 section 4.2).
   * @param $all_requests
   *   Boolean value specifying whether to check all requests if the header is
   *   not found in the last request. Defaults to FALSE.
   *
   * @return
   *   The HTTP header value or FALSE if not found.
   */
  protected function drupalGetHeader($name, $all_requests = FALSE) {
    $headers = $this->drupalGetHeaders();
    if (isset($headers[$name])) {
      return reset($headers[$name]);
    }
    return FALSE;
  }

  /**
   * Gets the current raw HTML of requested page.
   */
  protected function drupalGetContent() {
    // @todo Refactor to use $this->getSession()->getPage()->getContent() once
    //   MinkSession has been sub-classed to allow setting content.
    return $this->content;
  }

  /**
   * Gets the value of drupalSettings for the currently-loaded page.
   *
   * drupalSettings refers to the drupalSettings JavaScript variable.
   */
  protected function drupalGetSettings() {
    return $this->drupalSettings;
  }

  /**
   * Gets an array containing all e-mails sent during this test case.
   *
   * @param $filter
   *   An array containing key/value pairs used to filter the e-mails that are
   *   returned.
   *
   * @return
   *   An array containing e-mail messages captured during the current test.
   */
  protected function drupalGetMails($filter = array()) {
    $captured_emails = \Drupal::state()->get('system.test_mail_collector') ?: array();
    $filtered_emails = array();

    foreach ($captured_emails as $message) {
      foreach ($filter as $key => $value) {
        if (!isset($message[$key]) || $message[$key] != $value) {
          continue 2;
        }
      }
      $filtered_emails[] = $message;
    }

    return $filtered_emails;
  }

  /**
   * Sets the raw HTML content.
   *
   * This can be useful when a page has been fetched outside of the internal
   * browser and assertions need to be made on the returned page.
   *
   * A good example would be when testing HTTP request made by Drupal. After
   * fetching the page the content can be set and page elements can be checked
   * to ensure that the function worked properly.
   */
  protected function drupalSetContent($content, $url = 'internal:') {
    // @todo Subclass MinkSession to include the ability to setPage() for
    //   internal paths.
    $this->content = $content;
    $this->url = $url;
    $this->plainTextContent = FALSE;
    $this->elements = FALSE;
    $this->drupalSettings = array();
    if (preg_match('/var drupalSettings = (.*?);$/m', $content, $matches)) {
      $this->drupalSettings = Json::decode($matches[1]);
    }
  }

  /**
   * Sets the value of drupalSettings for the currently-loaded page.
   *
   * drupalSettings refers to the drupalSettings JavaScript variable.
   */
  protected function drupalSetSettings($settings) {
    $this->drupalSettings = $settings;
  }

  /**
   * Passes if the internal browser's URL matches the given path.
   *
   * @param $path
   *   The expected system path.
   * @param $options
   *   (optional) Any additional options to pass for $path to the url generator.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertUrl($path, array $options = array(), $message = '', $group = 'Other') {
    if (!$message) {
      $message = String::format('Current URL is @url.', array(
        '@url' => var_export($this->container->get('url_generator')->generateFromPath($path, $options), TRUE),
      ));
    }
    $options['absolute'] = TRUE;
    // Paths in query strings can be encoded or decoded with no functional
    // difference, decode them for comparison purposes.
    $actual_url = urldecode($this->getUrl());
    $expected_url = urldecode($this->container->get('url_generator')->generateFromPath($path, $options));
    return $this->assertEqual($actual_url, $expected_url, $message, $group);
  }

  /**
   * Passes if the raw text IS found on the loaded page, fail otherwise.
   *
   * Raw text refers to the raw HTML that the page generated.
   *
   * @param $raw
   *   Raw (HTML) string to look for.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertRaw($raw, $message = '', $group = 'Other') {
    if (!$message) {
      $message = String::format('Raw "@raw" found', array('@raw' => $raw));
    }
    return $this->assert(strpos($this->drupalGetContent(), $raw) !== FALSE, $message, $group);
  }

  /**
   * Passes if the raw text is NOT found on the loaded page, fail otherwise.
   *
   * Raw text refers to the raw HTML that the page generated.
   *
   * @param $raw
   *   Raw (HTML) string to look for.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoRaw($raw, $message = '', $group = 'Other') {
    if (!$message) {
      $message = String::format('Raw "@raw" not found', array('@raw' => $raw));
    }
    return $this->assert(strpos($this->drupalGetContent(), $raw) === FALSE, $message, $group);
  }

  /**
   * Passes if the text IS found on the text version of the page.
   *
   * The text version is the equivalent of what a user would see when viewing
   * through a web browser. In other words the HTML has been filtered out of the
   * contents.
   *
   * @param $text
   *   Plain text to look for.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertText($text, $message = '', $group = 'Other') {
    return $this->assertTextHelper($text, $message, $group, FALSE);
  }

  /**
   * Passes if the text is NOT found on the text version of the page.
   *
   * The text version is the equivalent of what a user would see when viewing
   * through a web browser. In other words the HTML has been filtered out of the
   * contents.
   *
   * @param $text
   *   Plain text to look for.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoText($text, $message = '', $group = 'Other') {
    return $this->assertTextHelper($text, $message, $group, TRUE);
  }

  /**
   * Helper for assertText and assertNoText.
   *
   * It is not recommended to call this function directly.
   *
   * @param $text
   *   Plain text to look for.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   * @param $not_exists
   *   TRUE if this text should not exist, FALSE if it should.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertTextHelper($text, $message = '', $group, $not_exists) {
    if ($this->plainTextContent === FALSE) {
      $this->plainTextContent = Xss::filter($this->drupalGetContent(), array());
    }
    if (!$message) {
      $message = !$not_exists ? String::format('"@text" found', array('@text' => $text)) : String::format('"@text" not found', array('@text' => $text));
    }
    return $this->assert($not_exists == (strpos($this->plainTextContent, $text) === FALSE), $message, $group);
  }

  /**
   * Passes if the text is found ONLY ONCE on the text version of the page.
   *
   * The text version is the equivalent of what a user would see when viewing
   * through a web browser. In other words the HTML has been filtered out of
   * the contents.
   *
   * @param $text
   *   Plain text to look for.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertUniqueText($text, $message = '', $group = 'Other') {
    return $this->assertUniqueTextHelper($text, $message, $group, TRUE);
  }

  /**
   * Passes if the text is found MORE THAN ONCE on the text version of the page.
   *
   * The text version is the equivalent of what a user would see when viewing
   * through a web browser. In other words the HTML has been filtered out of
   * the contents.
   *
   * @param $text
   *   Plain text to look for.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoUniqueText($text, $message = '', $group = 'Other') {
    return $this->assertUniqueTextHelper($text, $message, $group, FALSE);
  }

  /**
   * Helper for assertUniqueText and assertNoUniqueText.
   *
   * It is not recommended to call this function directly.
   *
   * @param $text
   *   Plain text to look for.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   * @param $be_unique
   *   TRUE if this text should be found only once, FALSE if it should be found
   *   more than once.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertUniqueTextHelper($text, $message = '', $group, $be_unique) {
    if ($this->plainTextContent === FALSE) {
      $this->plainTextContent = Xss::filter($this->drupalGetContent(), array());
    }
    if (!$message) {
      $message = '"' . $text . '"' . ($be_unique ? ' found only once' : ' found more than once');
    }
    $first_occurance = strpos($this->plainTextContent, $text);
    if ($first_occurance === FALSE) {
      return $this->assert(FALSE, $message, $group);
    }
    $offset = $first_occurance + strlen($text);
    $second_occurance = strpos($this->plainTextContent, $text, $offset);
    return $this->assert($be_unique == ($second_occurance === FALSE), $message, $group);
  }

  /**
   * Triggers a pass if the Perl regex pattern is found in the raw content.
   *
   * @param $pattern
   *   Perl regex to look for including the regex delimiters.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertPattern($pattern, $message = '', $group = 'Other') {
    if (!$message) {
      $message = String::format('Pattern "@pattern" found', array('@pattern' => $pattern));
    }
    return $this->assert((bool) preg_match($pattern, $this->drupalGetContent()), $message, $group);
  }

  /**
   * Triggers a pass if the perl regex pattern is not found in raw content.
   *
   * @param $pattern
   *   Perl regex to look for including the regex delimiters.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoPattern($pattern, $message = '', $group = 'Other') {
    if (!$message) {
      $message = String::format('Pattern "@pattern" not found', array('@pattern' => $pattern));
    }
    return $this->assert(!preg_match($pattern, $this->drupalGetContent()), $message, $group);
  }

  /**
   * Pass if the page title is the given string.
   *
   * @param $title
   *   The string the title should be.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertTitle($title, $message = '', $group = 'Other') {
    $element = current($this->xpath('//title'));
    $actual = $element->getText();
    if (!$message) {
      $message = String::format('Page title @actual is equal to @expected.', array(
        '@actual' => var_export($actual, TRUE),
        '@expected' => var_export($title, TRUE),
      ));
    }
    return $this->assertEqual($actual, $title, $message, $group);
  }

  /**
   * Pass if the page title is not the given string.
   *
   * @param $title
   *   The string the title should not be.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoTitle($title, $message = '', $group = 'Other') {
    $element = current($this->xpath('//title'));
    $actual = $element->getText();
    if (!$message) {
      $message = String::format('Page title @actual is not equal to @unexpected.', array(
        '@actual' => var_export($actual, TRUE),
        '@unexpected' => var_export($title, TRUE),
      ));
    }
    return $this->assertNotEqual($actual, $title, $message, $group);
  }

  /**
   * Asserts themed output.
   *
   * @param $callback
   *   The name of the theme hook to invoke; e.g. 'links' for links.html.twig.
   * @param $variables
   *   An array of variables to pass to the theme function.
   * @param $expected
   *   The expected themed output string.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertThemeOutput($callback, array $variables = array(), $expected, $message = '', $group = 'Other') {
    $output = _theme($callback, $variables);
    $this->verbose('Variables:' . '<pre>' .  check_plain(var_export($variables, TRUE)) . '</pre>'
      . '<hr />' . 'Result:' . '<pre>' .  check_plain(var_export($output, TRUE)) . '</pre>'
      . '<hr />' . 'Expected:' . '<pre>' .  check_plain(var_export($expected, TRUE)) . '</pre>'
      . '<hr />' . $output
    );
    if (!$message) {
      $message = '%callback rendered correctly.';
    }
    $message = format_string($message, array('%callback' => 'theme_' . $callback . '()'));
    return $this->assertIdentical($output, $expected, $message, $group);
  }

  /**
   * Asserts that a field exists in the current page by the given XPath.
   *
   * @param $xpath
   *   XPath used to find the field.
   * @param $value
   *   (optional) Value of the field to assert.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertFieldByXPath($xpath, $value = NULL, $message = '', $group = 'Other') {
    /** @var \Behat\Mink\Element\NodeElement[] $fields */
    $fields = $this->xpath($xpath);

    // If value specified then check array for match.
    $found = TRUE;
    if (isset($value)) {
      $found = FALSE;
      if ($fields) {
        foreach ($fields as $field) {
          $type = $field->getAttribute('type');
          if ($type == 'radio' || $type == 'checkbox') {
            if ($field->getAttribute('value') == $value && ($field->getAttribute('checked') || $field->isChecked())) {
              $found = TRUE;
            }
          }
          elseif ($field->getValue() == $value) {
            // Input element with correct value.
            $found = TRUE;
          }
        }
      }
    }
    return $this->assertTrue($fields && $found, $message, $group);
  }

  /**
   * Get the selected value from a select field.
   *
   * @param $element
   *   SimpleXMLElement select element.
   *
   * @return
   *   The selected value or FALSE.
   */
  protected function getSelectedItem(\SimpleXMLElement $element) {
    // @todo Refactor to use Mink.
    foreach ($element->children() as $item) {
      if (isset($item['selected'])) {
        return $item['value'];
      }
      elseif ($item->getName() == 'optgroup') {
        if ($value = $this->getSelectedItem($item)) {
          return $value;
        }
      }
    }
    return FALSE;
  }

  /**
   * Asserts that a field does not exist in the current page by the given XPath.
   *
   * @param $xpath
   *   XPath used to find the field.
   * @param $value
   *   (optional) Value of the field to assert.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoFieldByXPath($xpath, $value = NULL, $message = '', $group = 'Other') {
    $fields = $this->xpath($xpath);

    // If value specified then check array for match.
    $found = TRUE;
    if (isset($value)) {
      $found = FALSE;
      if ($fields) {
        foreach ($fields as $field) {
          if ($field->getValue() == $value) {
            $found = TRUE;
          }
        }
      }
    }
    return $this->assertFalse($fields && $found, $message, $group);
  }

  /**
   * Asserts that a field exists with the given name and value.
   *
   * @param $name
   *   Name of field to assert.
   * @param $value
   *   Value of the field to assert.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Browser'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertFieldByName($name, $value = NULL, $message = NULL, $group = 'Browser') {
    if (!isset($message)) {
      if (!isset($value)) {
        $message = String::format('Found field with name @name', array(
          '@name' => var_export($name, TRUE),
        ));
      }
      else {
        $message = String::format('Found field with name @name and value @value', array(
          '@name' => var_export($name, TRUE),
          '@value' => var_export($value, TRUE),
        ));
      }
    }
    return $this->assertFieldByXPath($this->constructFieldXpath('name', $name), $value, $message, $group);
  }

  /**
   * Asserts that a field does not exist with the given name and value.
   *
   * @param $name
   *   Name of field to assert.
   * @param $value
   *   Value of the field to assert.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Browser'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoFieldByName($name, $value = '', $message = '', $group = 'Browser') {
    return $this->assertNoFieldByXPath($this->constructFieldXpath('name', $name), $value, $message ? $message : String::format('Did not find field by name @name', array('@name' => $name)), $group);
  }

  /**
   * Asserts that a field exists with the given id and value.
   *
   * @param $id
   *   Id of field to assert.
   * @param $value
   *   Value of the field to assert.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Browser'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertFieldById($id, $value = '', $message = '', $group = 'Browser') {
    return $this->assertFieldByXPath($this->constructFieldXpath('id', $id), $value, $message ? $message : String::format('Found field by id @id', array('@id' => $id)), $group);
  }

  /**
   * Asserts that a field does not exist with the given id and value.
   *
   * @param $id
   *   Id of field to assert.
   * @param $value
   *   Value of the field to assert.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Browser'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoFieldById($id, $value = '', $message = '', $group = 'Browser') {
    return $this->assertNoFieldByXPath($this->constructFieldXpath('id', $id), $value, $message ? $message : String::format('Did not find field by id @id', array('@id' => $id)), $group);
  }

  /**
   * Asserts that a checkbox field in the current page is checked.
   *
   * @param $id
   *   Id of field to assert.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Browser'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertFieldChecked($id, $message = '', $group = 'Browser') {
    $elements = $this->xpath('//input[@id=:id]', array(':id' => $id));
    return $this->assertTrue(isset($elements[0]) && $elements[0]->isChecked(), $message ? $message : String::format('Checkbox field @id is checked.', array('@id' => $id)), $group);
  }

  /**
   * Asserts that a checkbox field in the current page is not checked.
   *
   * @param $id
   *   Id of field to assert.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Browser'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoFieldChecked($id, $message = '', $group = 'Browser') {
    /** @var \Behat\Mink\Element\NodeElement[] $elements */
    $elements = $this->xpath('//input[@id=:id]', array(':id' => $id));
    return $this->assertTrue(isset($elements[0]) && !$elements[0]->isChecked(), $message ? $message : String::format('Checkbox field @id is not checked.', array('@id' => $id)), $group);
  }

  /**
   * Asserts that a select option in the current page exists.
   *
   * @param $id
   *   Id of select field to assert.
   * @param $option
   *   Option to assert.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Browser'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertOption($id, $option, $message = '', $group = 'Browser') {
    $options = $this->xpath('//select[@id=:id]/option[@value=:option]', array(':id' => $id, ':option' => $option));
    return $this->assertTrue(isset($options[0]), $message ? $message : String::format('Option @option for field @id exists.', array('@option' => $option, '@id' => $id)), $group);
  }

  /**
   * Asserts that a select option in the current page does not exist.
   *
   * @param $id
   *   Id of select field to assert.
   * @param $option
   *   Option to assert.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Browser'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoOption($id, $option, $message = '', $group = 'Browser') {
    $selects = $this->xpath('//select[@id=:id]', array(':id' => $id));
    $options = $this->xpath('//select[@id=:id]/option[@value=:option]', array(':id' => $id, ':option' => $option));
    return $this->assertTrue(isset($selects[0]) && !isset($options[0]), $message ? $message : String::format('Option @option for field @id does not exist.', array('@option' => $option, '@id' => $id)), $group);
  }

  /**
   * Asserts that a select option in the current page is checked.
   *
   * @param $id
   *   Id of select field to assert.
   * @param $option
   *   Option to assert.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Browser'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   *
   * @todo $id is unusable. Replace with $name.
   */
  protected function assertOptionSelected($id, $option, $message = '', $group = 'Browser') {
    $elements = $this->xpath('//select[@id=:id]', array(':id' => $id));
    return $this->assertTrue(isset($elements[0]) && $elements[0]->getValue() == $option, $message ? $message : String::format('Option @option for field @id is selected.', array('@option' => $option, '@id' => $id)), $group);
  }

  /**
   * Asserts that a select option in the current page is not checked.
   *
   * @param $id
   *   Id of select field to assert.
   * @param $option
   *   Option to assert.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Browser'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoOptionSelected($id, $option, $message = '', $group = 'Browser') {
    $elements = $this->xpath('//select[@id=:id]', array(':id' => $id));
    return $this->assertTrue(isset($elements[0]) && $elements[0]->getValue() != $option, $message ? $message : String::format('Option @option for field @id is not selected.', array('@option' => $option, '@id' => $id)), $group);
  }

  /**
   * Asserts that a field exists with the given name or id.
   *
   * @param $field
   *   Name or id of field to assert.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertField($field, $message = '', $group = 'Other') {
    return $this->assertFieldByXPath($this->constructFieldXpath('name', $field) . '|' . $this->constructFieldXpath('id', $field), NULL, $message, $group);
  }

  /**
   * Asserts that a field does not exist with the given name or id.
   *
   * @param $field
   *   Name or id of field to assert.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoField($field, $message = '', $group = 'Other') {
    return $this->assertNoFieldByXPath($this->constructFieldXpath('name', $field) . '|' . $this->constructFieldXpath('id', $field), NULL, $message, $group);
  }

  /**
   * Asserts that each HTML ID is used for just a single element.
   *
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   * @param $ids_to_skip
   *   An optional array of ids to skip when checking for duplicates. It is
   *   always a bug to have duplicate HTML IDs, so this parameter is to enable
   *   incremental fixing of core code. Whenever a test passes this parameter,
   *   it should add a "todo" comment above the call to this function explaining
   *   the legacy bug that the test wishes to ignore and including a link to an
   *   issue that is working to fix that legacy bug.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertNoDuplicateIds($message = '', $group = 'Other', $ids_to_skip = array()) {
    $status = TRUE;
    foreach ($this->xpath('//*[@id]') as $element) {
      $id = $element->getAttribute('id');
      if (isset($seen_ids[$id]) && !in_array($id, $ids_to_skip)) {
        $this->fail(String::format('The HTML ID %id is unique.', array('%id' => $id)), $group);
        $status = FALSE;
      }
      $seen_ids[$id] = TRUE;
    }
    return $this->assert($status, $message, $group);
  }

  /**
   * Helper: Constructs an XPath for the given set of attributes and value.
   *
   * @param $attribute
   *   Field attributes.
   * @param $value
   *   Value of field.
   *
   * @return
   *   XPath for specified values.
   */
  protected function constructFieldXpath($attribute, $value) {
    $xpath = '//textarea[@' . $attribute . '=:value]|//input[@' . $attribute . '=:value]|//select[@' . $attribute . '=:value]';
    return $this->buildXPathQuery($xpath, array(':value' => $value));
  }

  /**
   * Asserts the page responds with the specified response code.
   *
   * @param $code
   *   Response code. For example 200 is a successful page request. For a list
   *   of all codes see http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Browser'; most tests do not override
   *   this default.
   *
   * @return
   *   Assertion result.
   */
  protected function assertResponse($code, $message = '', $group = 'Browser') {
    $curl_code = $this->getSession()->getStatusCode();
    $match = is_array($code) ? in_array($curl_code, $code) : $curl_code == $code;
    return $this->assertTrue($match, $message ? $message : String::format('HTTP response expected !code, actual !curl_code', array('!code' => $code, '!curl_code' => $curl_code)), $group);
  }

  /**
   * Asserts the page did not return the specified response code.
   *
   * @param $code
   *   Response code. For example 200 is a successful page request. For a list
   *   of all codes see http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Browser'; most tests do not override
   *   this default.
   *
   * @return
   *   Assertion result.
   */
  protected function assertNoResponse($code, $message = '', $group = 'Browser') {
    $curl_code = $this->getSession()->getStatusCode();
    $match = is_array($code) ? in_array($curl_code, $code) : $curl_code == $code;
    return $this->assertFalse($match, $message ? $message : String::format('HTTP response not expected !code, actual !curl_code', array('!code' => $code, '!curl_code' => $curl_code)), $group);
  }

  /**
   * Asserts that the most recently sent e-mail message has the given value.
   *
   * The field in $name must have the content described in $value.
   *
   * @param $name
   *   Name of field or message property to assert. Examples: subject, body,
   *   id, ...
   * @param $value
   *   Value of the field to assert.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'E-mail'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertMail($name, $value = '', $message = '', $group = 'E-mail') {
    $captured_emails = \Drupal::state()->get('system.test_mail_collector') ?: array();
    $email = end($captured_emails);
    return $this->assertTrue($email && isset($email[$name]) && $email[$name] == $value, $message, $group);
  }

  /**
   * Asserts that the most recently sent e-mail message has the string in it.
   *
   * @param $field_name
   *   Name of field or message property to assert: subject, body, id, ...
   * @param $string
   *   String to search for.
   * @param $email_depth
   *   Number of emails to search for string, starting with most recent.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertMailString($field_name, $string, $email_depth, $message = '', $group = 'Other') {
    $mails = $this->drupalGetMails();
    $string_found = FALSE;
    for ($i = count($mails) -1; $i >= count($mails) - $email_depth && $i >= 0; $i--) {
      $mail = $mails[$i];
      // Normalize whitespace, as we don't know what the mail system might have
      // done. Any run of whitespace becomes a single space.
      $normalized_mail = preg_replace('/\s+/', ' ', $mail[$field_name]);
      $normalized_string = preg_replace('/\s+/', ' ', $string);
      $string_found = (FALSE !== strpos($normalized_mail, $normalized_string));
      if ($string_found) {
        break;
      }
    }
    if (!$message) {
      $message = format_string('Expected text found in @field of email message: "@expected".', array('@field' => $field_name, '@expected' => $string));
    }
    return $this->assertTrue($string_found, $message, $group);
  }

  /**
   * Asserts that the most recently sent e-mail message has the pattern in it.
   *
   * @param $field_name
   *   Name of field or message property to assert: subject, body, id, ...
   * @param $regex
   *   Pattern to search for.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertMailPattern($field_name, $regex, $message = '', $group = 'Other') {
    $mails = $this->drupalGetMails();
    $mail = end($mails);
    $regex_found = preg_match("/$regex/", $mail[$field_name]);
    if (!$message) {
      $message = format_string('Expected text found in @field of email message: "@expected".', array('@field' => $field_name, '@expected' => $regex));
    }
    return $this->assertTrue($regex_found, $message, $group);
  }

  /**
   * Outputs to verbose the most recent $count emails sent.
   *
   * @param $count
   *   Optional number of emails to output.
   */
  protected function verboseEmail($count = 1) {
    $mails = $this->drupalGetMails();
    for ($i = count($mails) -1; $i >= count($mails) - $count && $i >= 0; $i--) {
      $mail = $mails[$i];
      $this->verbose('Email:<pre>' . print_r($mail, TRUE) . '</pre>');
    }
  }

  /**
   * Creates a mock request and sets it on the generator.
   *
   * This is used to manipulate how the generator generates paths during tests.
   * It also ensures that calls to $this->drupalGet() will work when running
   * from run-tests.sh because the url generator no longer looks at the global
   * variables that are set there but relies on getting this information from a
   * request object.
   *
   * @param bool $clean_urls
   *   Whether to mock the request using clean urls.
   * @param $override_server_vars
   *   An array of server variables to override.
   *
   * @return $request
   *   The mocked request object.
   */
  protected function prepareRequestForGenerator($clean_urls = TRUE, $override_server_vars = array()) {
    $generator = $this->container->get('url_generator');
    $request = Request::createFromGlobals();
    $server = $request->server->all();
    if (basename($server['SCRIPT_FILENAME']) != basename($server['SCRIPT_NAME'])) {
      // We need this for when the test is executed by run-tests.sh.
      // @todo Remove this once run-tests.sh has been converted to use a Request
      //   object.
      $cwd = getcwd();
      $server['SCRIPT_FILENAME'] = $cwd . '/' . basename($server['SCRIPT_NAME']);
      $base_path = rtrim($server['REQUEST_URI'], '/');
    }
    else {
      $base_path = $request->getBasePath();
    }
    if ($clean_urls) {
      $request_path = $base_path ? $base_path . '/user' : 'user';
    }
    else {
      $request_path = $base_path ? $base_path . '/index.php/user' : '/index.php/user';
    }
    $server = array_merge($server, $override_server_vars);

    $request = Request::create($request_path, 'GET', array(), array(), array(), $server);
    $generator->setRequest($request);
    return $request;
  }
}
