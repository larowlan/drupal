<?php

/**
 * @file
 * Provides BC decorator for  collection of \Behat\Mink\Element\NodeElements.
 */

namespace Drupal\simpletest;

use Behat\Mink\Element\NodeElement;

class MinkNodeElementCollectionDecorator implements \ArrayAccess, \Iterator, \Countable {

  /**
   * The decorated node element.
   *
   * @var \Behat\Mink\Element\NodeElement[]
   */
  protected $nodeElements = [];

  /**
   * The position of the iterator.
   */
  protected $position = 0;

  /**
   * Decorates an existing node element.
   *
   * @param array $node_elements
   *
   * @return \Drupal\simpletest\MinkNodeElementCollectionDecorator
   *   The decorated element.
   */
  public static function decorate(array $node_elements) {
    return new static($node_elements);
  }

  /**
   * Constructs a new MinkNodeElementDecorator.
   *
   * @param array $node_elements
   *   The node element to decorate.
   */
  public function __construct(array $node_elements) {
    $this->nodeElements = $node_elements;
  }

  /**
   * {@inheritdoc}
   */
  public function offsetExists($offset) {
    return isset($this->nodeElements[$offset]);
  }

  /**
   * {@inheritdoc}
   */
  public function offsetGet($offset) {
    return $this->nodeElements[$offset];
  }

  /**
   * {@inheritdoc}
   */
  public function offsetSet($offset, $value) {}

  /**
   * {@inheritdoc}
   */
  public function offsetUnset($offset) {
    unset($this->nodeElements[$offset]);
  }

  /**
   * {@inheritdoc}
   */
  public function __call($method, $arguments) {
    return call_user_func_array(array($this->nodeElements[0], $method), $arguments);
  }

  /**
   * {@inheritdoc}
   */
  public function __get($name) {
    return $this->nodeElements[0]->{$name};
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return $this->nodeElements[0]->getText();
  }

  /**
   * {@inheritdoc}
   */
  public function current() {
    return $this->nodeElements[$this->position];
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
    return isset($this->nodeElements[$this->position]);
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
    return count($this->nodeElements);
  }
}
