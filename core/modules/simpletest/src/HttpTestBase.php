<?php
namespace Drupal\simpletest;

use Drupal\Component\Utility\String;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\ResponseInterface;

abstract class HttpTestBase extends FunctionalTestBase {

  /**
   * The current raw content.
   *
   * @var string
   */
  protected $content;

  /**
   * The plain-text content of raw $content (text nodes).
   *
   * @var string
   */
  protected $plainTextContent;

  /**
   * Indicates that headers should be dumped if verbose output is enabled.
   *
   * @var bool
   */
  protected $dumpHeaders = FALSE;

  /**
   * The guzzle client.
   *
   * @var GuzzleClient
   */
  protected $guzzle;

  /**
   * The cookie jar.
   *
   * @var CookieJar
   */
  protected $cookieJar;

  /**
   * The last response.
   *
   * @var ResponseInterface
   */
  protected $response;

  /**
   * The default MIME type to use for http requests.
   *
   * @var string
   */
  protected $defaultMimeType;

  /**
   * The current user logged in using Guzzle.
   *
   * @var bool
   */
  protected $loggedInUser = FALSE;

  /**
   * The current session ID, if available.
   */
  protected $session_id = NULL;

  /**
   * Gets the current raw content.
   */
  protected function getRawContent() {
    return $this->content;
  }

  /**
   * Sets the raw content (e.g. HTML).
   *
   * @param string $content
   *   The raw content to set.
   */
  protected function setRawContent($content) {
    $this->content = $content;
    $this->plainTextContent = NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->initGuzzle();
  }

  /**
   * Initialize guzzle.
   */
  protected function initGuzzle() {
    global $base_url;
    $defaults = array(
      'timeout' => 30,
      'verify' => FALSE,
      'headers' => array(
        'User-Agent' => $this->databasePrefix,
      ),
    );
    $this->guzzle = new GuzzleClient(array('base_url' => $base_url, 'defaults' => $defaults));
    $this->cookieJar = new CookieJar();
  }

  /**
   * Takes a path and returns an absolute path.
   *
   * @param $path
   *   A path from the internal browser content.
   *
   * @return string
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
   * @return string
   *   The raw content from GET request.
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

    $this->response = $this->guzzle->get($url, array('headers' => $headers));
    $out = $this->response->getBody();
    $headers = $this->response->getHeaders();

    // Ensure that any changes to variables in the other thread are picked up.
    $this->refreshVariables();

    $verbose = 'GET request to: ' . $path .
      '<hr />Ending URL: ' . $this->response->getEffectiveUrl();
    if ($this->dumpHeaders) {
      $verbose .= '<hr />Headers: <pre>' . String::checkPlain(var_export(array_map('trim', $headers), TRUE)) . '</pre>';
    }
    $verbose .= '<hr />' . $out;

    $this->verbose($verbose);
    return $out;
  }

  /**
   * Post request.
   *
   * @param $url
   * @param array|null $body
   * @return string
   */
  protected function drupalPost($url, $body = NULL) {
    $request_options = array();
    if ($body) {
      $request_options['body'] = $body;
    }

    $request = $this->guzzle->createRequest('POST', $url, $request_options);
    if (preg_match('/simpletest\d+/', $this->databasePrefix, $matches)) {
      $request->setHeader('User-Agent', drupal_generate_test_ua($matches[0]));
    }
    try {
      $this->response = $this->guzzle->send($request);
      $this->setRawContent($this->response->getBody());
    }
    catch (RequestException $e) {
      $this->response = $e->getResponse();
      if ($this->response == NULL) {
        $this->fail($e->getMessage());
      }
      else {
        $this->setRawContent((string) $this->response->getBody());
      }
    }

    foreach ($this->cookieJar as $cookie_value) {
      // @todo handle cookie values.
    }

    $request_header_string = '<pre>';
    foreach ($request->getHeaders() as $name => $values) {
      $request_header_string .= $name . ": " . implode(", ", $values) . "\n";
    }
    $request_header_string .= '</pre>';

    $header_string = '<pre>';
    foreach ($this->response->getHeaders() as $name => $values) {
      $header_string .= $name . ": " . implode(", ", $values) . "\n";
    }
    $header_string .= '</pre>';

    $this->verbose('POST request to: ' . $url .
      '<hr />Code: ' . $this->response->getStatusCode() .
      '<hr />Request headers: ' . $request_header_string .
      '<hr />Request body: ' . $request->getBody() .
      '<hr />Response headers: ' . $header_string .
      '<hr />Response body: ' . $this->content);

    return $this->content;
  }

  /**
   * Helper function to issue a HTTP request with Guzzle.
   *
   * @param string $url
   *   The relative URL, e.g. "entity/node/1"
   * @param string $method
   *   HTTP method, one of GET, POST, PUT or DELETE.
   * @param array $body
   *   Either the body for POST and PUT or additional URL parameters for GET.
   * @param string $mime_type
   *   The MIME type of the transmitted content.
   * @param array $auth
   *   (optional) Authentication details - array containing username, password
   *   and type. Defaults to NULL.
   * @param string|bool $token
   *   (optional) CSRF Token for authenticating request. Defaults to NULL. Pass
   *   FALSE to skip auto-generation.
   *
   * @return string
   *   Response body.
   */
  protected function httpRequest($url, $method, $body = NULL, $mime_type = NULL, $auth = NULL, $token = NULL) {
    if (!isset($mime_type)) {
      $mime_type = $this->defaultMimeType;
    }
    if (!isset($token) && !in_array($method, array('GET', 'HEAD', 'OPTIONS', 'TRACE'))) {
      // GET the CSRF token first for writing requests.
      $token = $this->drupalGet('rest/session/token');
    }
    $this->guzzle->setDefaultOption('auth', $auth);
    $headers = [];
    switch ($method) {
      case 'GET':
        // Set query if there are additional GET parameters.
        $options = isset($body) ? array('absolute' => TRUE, 'query' => $body) : array('absolute' => TRUE);
        $url = url($url, $options);
        $headers = array(
          'Accept' => $mime_type,
        );
        break;

      case 'POST':
      case 'PUT':
      case 'PATCH':
        $url = url($url, array('absolute' => TRUE));
        $headers = array(
          'Content-Type' => $mime_type,
          'X-CSRF-Token' => $token,
        );
        break;

      case 'DELETE':
        $url = url($url, array('absolute' => TRUE));
        $headers = array(
          'X-CSRF-Token' => $token,
        );
        break;
    }

    $request_options = array(
      'cookies' => $this->cookieJar,
      'allow_redirects' => FALSE,
      'timeout' => 30,
    );
    if ($body) {
      $request_options['body'] = $body;
    }

    $request = $this->guzzle->createRequest($method, $url, $request_options);
    foreach ($headers as $key => $value) {
      if ($value) {
        $request->addHeader($key, $value);
      }
    }
    if (preg_match('/simpletest\d+/', $this->databasePrefix, $matches)) {
      $request->setHeader('User-Agent', drupal_generate_test_ua($matches[0]));
    }
    try {
      $this->response = $this->guzzle->send($request);
      $this->setRawContent($this->response->getBody());
    }
    catch (RequestException $e) {
      $this->response = $e->getResponse();
      if ($this->response == NULL) {
        $this->fail($e->getMessage());
      }
      else {
        $this->setRawContent($this->response->getBody());
      }
    }

    foreach ($this->cookieJar as $cookie_value) {
      // @todo handle cookie values.
    }

    $header_string = '';
    foreach ($this->response->getHeaders() as $name => $values) {
      $header_string .= $name . ": " . implode(", ", $values) . "\n";
    }

    $this->verbose($method . ' request to: ' . $url .
      '<hr />Code: ' . $this->response->getStatusCode() .
      '<hr />Response headers: ' . $header_string .
      '<hr />Response body: ' . $this->content);

    return $this->content;
  }

  /**
   * Asserts the page responds with the specified response code.
   *
   * @param string $expected_code
   *   Response code. For example 200 is a successful page request. For a list
   *   of all codes see http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Browser'; most tests do not override
   *   this default.
   *
   * @return bool
   *   Assertion result.
   */
  protected function assertResponse($expected_code, $message = '', $group = 'Browser') {
    $actual_code = $this->response->getStatusCode();
    $match = is_array($expected_code) ? in_array($actual_code, $expected_code) : $actual_code == $expected_code;
    return $this->assertTrue($match, $message ? $message : String::format('HTTP response expected !expected_code, actual !actual_code', array('!actual_code' => $actual_code, '!expected_code' => $expected_code)), $group);
  }

  /**
   * Passes if the text IS found on the text version of the page.
   *
   * The text version is the equivalent of what a user would see when viewing
   * through a web browser. In other words the HTML has been filtered out of the
   * contents.
   *
   * @param string $text
   *   Plain text to look for.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return bool
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
   * @param string $text
   *   Plain text to look for.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return bool
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
   * @param string $text
   *   Plain text to look for.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default. Defaults to 'Other'.
   * @param bool $not_exists
   *   (optional) TRUE if this text should not exist, FALSE if it should.
   *   Defaults to TRUE.
   *
   * @return bool
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertTextHelper($text, $message = '', $group = 'Other', $not_exists = TRUE) {
    if (!$message) {
      $message = !$not_exists ? String::format('"@text" found', array('@text' => $text)) : String::format('"@text" not found', array('@text' => $text));
    }
    return $this->assert($not_exists == (strpos($this->getTextContent(), (string) $text) === FALSE), $message, $group);
  }

  /**
   * Retrieves the plain-text content from the current raw content.
   */
  protected function getTextContent() {
    if (!isset($this->plainTextContent)) {
      $this->plainTextContent = Xss::filter($this->getRawContent(), array());
    }
    return $this->plainTextContent;
  }

  /**
   * Check if a HTTP response header exists and has the expected value.
   *
   * @param string $header
   *   The header key, example: Content-Type
   * @param string $value
   *   The header value.
   * @param string $message
   *   (optional) A message to display with the assertion.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return bool
   *   TRUE if the assertion succeeded, FALSE otherwise.
   */
  protected function assertHeader($header, $value, $message = '', $group = 'Browser') {
    $header_values = $this->response->getHeader($header, TRUE);
    return $this->assertTrue(in_array($value, $header_values), $message ? $message : 'HTTP response header ' . $header . ' with value ' . $value . ' found.', $group);
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
   * @param AccountInterface $account
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
    $this->drupalPost('user', $edit);

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
   * Logs a user out of the internal browser and confirms.
   *
   * Confirms logout by checking the login page.
   */
  protected function drupalLogout() {
    // Make a request to the logout page, and redirect to the user page, the
    // idea being if you were properly logged out you should be seeing a login
    // screen.
    $this->guzzle->get('user/logout', array('query' => array('destination' => 'user')));
    $this->assertResponse(200, 'User was logged out.');
    // @todo Assert login page
    $pass = TRUE;
    //$pass = $this->assertField('name', 'Username field found.', 'Logout');
    //$pass = $pass && $this->assertField('pass', 'Password field found.', 'Logout');

    if ($pass) {
      // @see WebTestBase::drupalUserIsLoggedIn()
      unset($this->loggedInUser->session_id);
      $this->loggedInUser = FALSE;
      $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    }
  }
}
