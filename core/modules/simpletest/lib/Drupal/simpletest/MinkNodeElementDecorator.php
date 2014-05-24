<?php

/**
 * @file
 * Contains a BC shim to decorate \Behat\Mink\Element\NodeElement.
 */

namespace Drupal\simpletest;

use Behat\Mink\Element\NodeElement;

class MinkNodeElementDecorator implements \ArrayAccess {

  /**
   * The decorated node element.
   *
   * @var \Behat\Mink\Element\NodeElement
   */
  protected $nodeElement;

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
    $this->nodeElement = $node_element;
  }

  /**
   * {@inheritdoc}
   */
  public function offsetExists($offset) {
    if (is_int($offset)) {
      // Attempt to access child.
      $children = $this->nodeElement->findAll('css', '*');
      return isset($children[$offset]);
    }
    else {
      // Property access.
      return (bool) $this->nodeElement->getAttribute($offset);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function offsetGet($offset) {
    if (is_int($offset)) {
      // Attempt to access child.
      $children = $this->nodeElement->findAll('css', '*');
      if (isset($children[$offset])) {
        return static::decorate($children[$offset]);
      }
      return NULL;
    }
    else {
      // Property access.
      return $this->nodeElement->getAttribute($offset);
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
    return call_user_func_array(array($this->nodeElement, $method), $arguments);
  }

  /**
   * {@inheritdoc}
   */
  public function __get($name) {
    $elements = $this->nodeElement->findAll('css', $name);
    $children = array();
    foreach ($elements as $element) {
      $children[] = static::decorate($element);
    }

    // @todo, decorate this as well.
    return $children;
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return $this->nodeElement->getText();
  }

}
