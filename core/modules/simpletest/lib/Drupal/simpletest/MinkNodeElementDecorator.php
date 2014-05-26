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
      // Attempt to access first child.
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
      // Attempt to access first child.
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
    // @see SimpleXMLElement::children()
    if ($method == 'children') {
      $child_nodes = $this->nodeElement->findAll('css', '*');
      $children = [];
      foreach ($child_nodes as $child_node) {
        $children[] = static::decorate($child_node);
      }
      return MinkNodeElementCollectionDecorator::decorate($children);
    }
    // @see SimpleXMLElement::attributes()
    if ($method == 'attributes') {
      // This limits us to the Goutte driver here, but this whole class is a BC
      // shim and so it will be removed in its entirety.
      $xpath = $this->nodeElement->getXpath();
      $reflection = new \ReflectionClass($this->nodeElement->getSession()->getDriver());
      $method = $reflection->getMethod('getCrawler');
      $method->setAccessible(TRUE);
      $crawler = $method->invoke($this->nodeElement->getSession()->getDriver());
      $element = $crawler->filterXPath($xpath)->eq(0)->getNode(0);
      $attributes = array();
      foreach ($element->attributes as $name => $attribute) {
        $attributes[$name] = $attribute->value;
      }
      return $attributes;
    }
    // @see SimpleXMLElement::getName()
    if ($method == 'getName') {
      $method = 'getTagName';
    }
    // @see SimpleXMLElement::asXML()
    if ($method == 'asXML') {
      $method = 'getHtml';
    }
    // @see SimpleXMLElement::xpath()
    if ($method == 'xpath') {
      $method = 'getXpath';
    }
    return call_user_func_array(array($this->nodeElement, $method), $arguments);
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
      return $children[0];
    }
    return MinkNodeElementCollectionDecorator::decorate($children);
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return $this->nodeElement->getText();
  }

}
