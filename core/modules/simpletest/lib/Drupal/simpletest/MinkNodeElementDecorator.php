<?php

/**
 * @file
 * Contains a BC shim to decorate \Behat\Mink\Element\NodeElement.
 */

namespace Drupal\simpletest;

use Behat\Mink\Element\NodeElement;

class MinkNodeElementDecorator implements \ArrayAccess, \Iterator {

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
   * @param \Behat\Mink\Element\NodeElement $node_element
   *   The node element to decorate.
   *
   * @return \Drupal\simpletest\MinkNodeElementDecorator
   *   The decorated element.
   */
  public static function decorate(NodeElement $node_element) {
    return new static($node_element);
  }

  /**
   * Constructs a new MinkNodeElementDecorator.
   *
   * @param \Behat\Mink\Element\NodeElement $node_element
   *   The node element to decorate.
   */
  public function __construct(NodeElement $node_element) {
    $this->nodeElements[] = $node_element;
  }

  /**
   * {@inheritdoc}
   */
  public function offsetExists($offset) {
    if (is_int($offset)) {
      // Attempt to access first child.
      $children = $this->nodeElements[0]->findAll('css', '*');
      return isset($children[$offset]);
    }
    else {
      // Property access.
      return (bool) $this->nodeElements[0]->getAttribute($offset);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function offsetGet($offset) {
    if (is_int($offset)) {
      // Attempt to access first child.
      $children = $this->nodeElements[0]->findAll('css', '*');
      if (isset($children[$offset])) {
        return static::decorate($children[$offset]);
      }
      return NULL;
    }
    else {
      // Property access.
      return $this->nodeElements[0]->getAttribute($offset);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function offsetSet($offset, $value) {}

  /**
   * {@inheritdoc}
   */
  public function offsetUnset($offset) {}

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
    $elements = $this->nodeElement->findAll('css', "*> " . $name);
    $children = array();
    foreach ($elements as $element) {
      $children[] = static::decorate($element);
    }
    if (count($children) === 1) {
      $children = $children[0];
    }
    return $children;
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
  public function __get($name) {
    $elements = [];
    foreach ($this->nodeElements as $node_element) {
      $elements += $node_element->findAll('css', $name);
    }
    $children = FALSE;
    foreach ($elements as $element) {
      if (!$children) {
        $children = static::decorate($element);
      }
      else {
        $children->addDecorated($element);
      }
    }

    return $children;
  }

  /**
   * Adds an additional \Behat\Mink\Element\NodeElement to be decorated.
   *
   * @param \Behat\Mink\Element\NodeElement $element
   *   The element to decorate.
   *
   * @return self
   */
  public function addDecorated(NodeElement $element) {
    $this->nodeElements[] = $element;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function current() {
    return static::decorate($this->nodeElements[$this->position]);
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

}
