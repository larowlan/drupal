<?php
namespace Drupal\simpletest;

use Drupal\Component\Utility\Crypt;
use Drupal\Component\Utility\String;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Database\Database;
use Drupal\Core\Session\UserSession;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;

abstract class FunctionalTestBase extends TestBase {
  /**
   * The profile to install as a basis for testing.
   *
   * @var string
   */
  protected $profile = 'testing';

  /**
   * The batch of the original parent site.
   *
   * @var array
   */
  protected $originalBatch;

  /**
   * The root user.
   *
   * @var UserSession
   */
  protected $root_user;

  /**
   * The kernel used in this test.
   *
   * @var \Drupal\Core\DrupalKernel
   */
  protected $kernel;

  /**
   * The config directories used in this test.
   */
  protected $configDirectories = array();

  /**
   * The current session name, if available.
   */
  protected $session_name = NULL;

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

    // Some tests (SessionTest and SessionHttpsTest) need to examine whether the
    // proper session cookies were set on a response. Because the child site
    // uses the same session name as the test runner, it is necessary to make
    // that available to test-methods.
    $this->session_name = $this->originalSessionName;

    // Reset the static batch to remove Simpletest's batch operations.
    $batch = &batch_get();
    $batch = array();

    // Get parameters for install_drupal() before removing global variables.
    $parameters = $this->installParameters();

    // Prepare installer settings that are not install_drupal() parameters.
    // Copy and prepare an actual settings.php, so as to resemble a regular
    // installation.
    // Not using File API; a potential error must trigger a PHP warning.
    $directory = DRUPAL_ROOT . '/' . $this->siteDirectory;
    copy(DRUPAL_ROOT . '/sites/default/default.settings.php', $directory . '/settings.php');

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
    // Allow for test-specific overrides.
    $settings_testing_file = DRUPAL_ROOT . '/' . $this->originalSite . '/settings.testing.php';
    if (file_exists($settings_testing_file)) {
      // Copy the testing-specific settings.php overrides in place.
      copy($settings_testing_file, $directory . '/settings.testing.php');
      // Add the name of the testing class to settings.php and include the
      // testing specific overrides
      file_put_contents($directory . '/settings.php', "\n\$test_class = '" . get_class($this) ."';\n" . 'include DRUPAL_ROOT . \'/\' . $site_path . \'/settings.testing.php\';' ."\n", FILE_APPEND);
    }
    $settings_services_file = DRUPAL_ROOT . '/' . $this->originalSite . '/testing.services.yml';
    if (file_exists($settings_services_file)) {
      // Copy the testing-specific service overrides in place.
      copy($settings_services_file, $directory . '/services.yml');
    }

    // Since Drupal is bootstrapped already, install_begin_request() will not
    // bootstrap into DRUPAL_BOOTSTRAP_CONFIGURATION (again). Hence, we have to
    // reload the newly written custom settings.php manually.
    Settings::initialize($directory);

    // Execute the non-interactive installer.
    require_once DRUPAL_ROOT . '/core/includes/install.core.inc';
    install_drupal($parameters);

    // Import new settings.php written by the installer.
    Settings::initialize($directory);
    foreach ($GLOBALS['config_directories'] as $type => $path) {
      $this->configDirectories[$type] = $path;
    }

    // After writing settings.php, the installer removes write permissions
    // from the site directory. To allow drupal_generate_test_ua() to write
    // a file containing the private key for drupal_valid_test_ua(), the site
    // directory has to be writable.
    // TestBase::restoreEnvironment() will delete the entire site directory.
    // Not using File API; a potential error must trigger a PHP warning.
    chmod($directory, 0777);

    $request = \Drupal::request();
    $this->kernel = DrupalKernel::createFromRequest($request, drupal_classloader(), 'prod', TRUE);
    $this->kernel->prepareLegacyRequest($request);
    // Force the container to be built from scratch instead of loaded from the
    // disk. This forces us to not accidently load the parent site.
    $container = $this->kernel->rebuildContainer();

    $config = $container->get('config.factory');

    // Manually create and configure private and temporary files directories.
    // While these could be preset/enforced in settings.php like the public
    // files directory above, some tests expect them to be configurable in the
    // UI. If declared in settings.php, they would no longer be configurable.
    file_prepare_directory($this->private_files_directory, FILE_CREATE_DIRECTORY);
    file_prepare_directory($this->temp_files_directory, FILE_CREATE_DIRECTORY);
    $config->get('system.file')
      ->set('path.private', $this->private_files_directory)
      ->set('path.temporary', $this->temp_files_directory)
      ->save();

    // Manually configure the test mail collector implementation to prevent
    // tests from sending out emails and collect them in state instead.
    // While this should be enforced via settings.php prior to installation,
    // some tests expect to be able to test mail system implementations.
    $config->get('system.mail')
      ->set('interface.default', 'test_mail_collector')
      ->save();

    // By default, verbosely display all errors and disable all production
    // environment optimizations for all tests to avoid needless overhead and
    // ensure a sane default experience for test authors.
    // @see https://drupal.org/node/2259167
    $config->get('system.logging')
      ->set('error_level', 'verbose')
      ->save();
    $config->get('system.performance')
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
      $success = $container->get('module_handler')->install($modules, TRUE);
      $this->assertTrue($success, String::format('Enabled modules: %modules', array('%modules' => implode(', ', $modules))));
      $this->rebuildContainer();
    }

    // Reset/rebuild all data structures after enabling the modules, primarily
    // to synchronize all data structures and caches between the test runner and
    // the child site.
    // Affects e.g. file_get_stream_wrappers().
    // @see \Drupal\Core\DrupalKernel::bootCode()
    // @todo Test-specific setUp() methods may set up further fixtures; find a
    //   way to execute this after setUp() is done, or to eliminate it entirely.
    $this->resetAll();
    $this->kernel->prepareLegacyRequest($request);

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
   * @todo Fix https://www.drupal.org/node/2021959 so that module enable/disable
   *   changes are immediately reflected in \Drupal::getContainer(). Until then,
   *   tests can invoke this workaround when requiring services from newly
   *   enabled modules to be immediately available in the same request.
   */
  protected function rebuildContainer() {
    // Maintain the current global request object.
    $request = \Drupal::request();
    // Rebuild the kernel and bring it back to a fully bootstrapped state.
    $this->container = $this->kernel->rebuildContainer();

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
   * @param array $override_server_vars
   *   An array of server variables to override.
   *
   * @return \Symfony\Component\HttpFoundation\Request $request
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
    $this->container->get('request_stack')->push($request);
    $generator->updateFromRequest();
    return $request;
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
        $assigned_permissions = entity_load('user_role', $role->id())->getPermissions();
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
   * Returns whether a given user account is logged in.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user account object to check.
   *
   * @return bool
   *   TRUE if the user account is logged in, FALSE otherwise.
   */
  protected function drupalUserIsLoggedIn($account) {
    if (!isset($account->session_id)) {
      return FALSE;
    }
    // The session ID is hashed before being stored in the database.
    // @see \Drupal\Core\Session\SessionHandler::read()
    return (bool) db_query("SELECT sid FROM {users} u INNER JOIN {sessions} s ON u.uid = s.uid WHERE s.sid = :sid", array(':sid' => Crypt::hashBase64($account->session_id)))->fetchField();
  }
}
