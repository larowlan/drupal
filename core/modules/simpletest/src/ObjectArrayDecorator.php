<?php
namespace Drupal\simpletest;

/**
 * Decorate an array to support object property access for the keys.
 */
class ObjectArrayDecorator implements \ArrayAccess, \Iterator, \Countable {
  /**
   * Array being decorated.
   */
  protected $data;

  /**
   * The position of the iterator.
   */
  protected $position = 0;

  /**
   * Default constructor.
   */
  private function __construct(array $data) {
    $this->data = $data;
  }

  /**
   * Decorates an array to support object property access.
   *
   * @param array $data
   *
   * @return \Drupal\simpletest\ObjectArrayDecorator
   *   The decorated array.
   */
  public static function decorate(array $data) {
    return new static($data);
  }

  /**
   * {@inheritDoc}
   */
  public function offsetExists($offset) {
    return isset($this->data[$offset]);
  }

  /**
   * {@inheritDoc}
   */
  public function offsetGet($offset) {
    return $this->data[$offset];
  }

  /**
   * {@inheritDoc}
   */
  public function offsetSet($offset, $value) {
    $this->data[$offset] = $value;
  }

  /**
   * {@inheritDoc}
   */
  public function offsetUnset($offset) {
    unset($this->data[$offset]);
  }

  /**
   * {@inheritdoc}
   */
  public function current() {
    return $this->data[$this->position];
  }

  /**
   * {@inheritdoc}
   */
  public function next() {
    $this->position++;
  }

  /**
   * {@inheritdoc}
   */
  public function key() {
    return $this->position;
  }

  /**
   * {@inheritdoc}
   */
  public function valid() {
    return isset($this->data[$this->position]);
  }

  /**
   * {@inheritdoc}
   */
  public function rewind() {
    $this->position = 0;
  }

  /**
   * {@inheritdoc}
   */
  public function count() {
    return count($this->data);
  }

  /**
   * {@inheritdoc}
   */
  public function __get($name) {
    return $this->data[$name];
  }
}
