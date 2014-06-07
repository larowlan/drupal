<?php

/**
 * @file
 * Contains \Drupal\Core\Menu\Form\MenuLinkDefaultForm.
 */

namespace Drupal\Core\Menu\Form;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an edit form for static menu links.
 *
 * @see \Drupal\Core\Menu\MenuLinkDefault
 */
class MenuLinkDefaultForm implements MenuLinkFormInterface, ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The edited menu link.
   *
   * @var \Drupal\Core\Menu\MenuLinkInterface
   */
  protected $menuLink;

  /**
   * The menu link tree.
   *
   * @var \Drupal\Core\Menu\MenuLinkTreeInterface
   */
  protected $menuTree;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The module data from system_get_info().
   *
   * @var array
   */
  protected $moduleData;

  /**
   * Constructs a new MenuLinkDefaultForm.
   *
   * @param \Drupal\Core\Menu\MenuLinkTreeInterface $menu_tree
   *   The menu link tree.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler;
   */
  public function __construct(MenuLinkTreeInterface $menu_tree, TranslationInterface $string_translation, ModuleHandlerInterface $module_handler) {
    $this->menuTree = $menu_tree;
    $this->stringTranslation = $string_translation;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('menu.link_tree'),
      $container->get('string_translation'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function setMenuLinkInstance(MenuLinkInterface $menu_link) {
    $this->menuLink = $menu_link;
  }

  /**
   * {@inheritdoc}
   */
  public function buildEditForm(array &$form, array &$form_state) {
    $form['#title'] = $this->t('Edit menu link %title', array('%title' => $this->menuLink->getTitle()));

    $provider = $this->menuLink->getProvider();
    $form['info'] = array(
      '#type' => 'item',
      '#title' => $this->t('This link is provided by the @name module. The label and path cannot be edited.', array('@name' => $this->getModuleName($provider))),
    );
    $form['path'] = array(
      'link' => $this->menuLink->build(),
      '#type' => 'item',
      '#title' => $this->t('Link'),
    );

    $form['enabled'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Enable'),
      '#description' => $this->t('Menu links that are not enabled will not be listed in any menu.'),
      '#default_value' => !$this->menuLink->isHidden(),
    );
    $form['expanded'] = array(
      '#type' => 'checkbox',
      '#title' => t('Show as expanded'),
      '#description' => $this->t('If selected and this menu link has children, the menu will always appear expanded.'),
      '#default_value' => $this->menuLink->isExpanded(),
    );
    $delta = max(abs($this->menuLink->getWeight()), 50);
    $form['weight'] = array(
      '#type' => 'number',
      '#min' => -$delta,
      '#max' => $delta,
      '#default_value' => $this->menuLink->getWeight(),
      '#title' => $this->t('Weight'),
      '#description' => $this->t('Link weight among links in the same menu at the same depth.'),
    );

    $options = $this->menuTree->getParentSelectOptions($this->menuLink->getPluginId());
    $menu_parent = $this->menuLink->getMenuName() . ':' . $this->menuLink->getParent();

    if (!isset($options[$menu_parent])) {
      // Put it at the top level in the current menu.
      $menu_parent = $this->menuLink->getMenuName() . ':';
    }
    $form['menu_parent'] = array(
      '#type' => 'select',
      '#title' => $this->t('Parent link'),
      '#options' => $options,
      '#default_value' => $menu_parent,
      '#description' => $this->t('The maximum depth for a link and all its children is fixed at !maxdepth. Some menu links may not be available as parents if selecting them would exceed this limit.', array('!maxdepth' => $this->menuTree->maxDepth())),
      '#attributes' => array('class' => array('menu-title-select')),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function extractFormValues(array &$form, array &$form_state) {
    $new_definition = array();
    $new_definition['hidden'] = $form_state['values']['enabled'] ? 0 : 1;
    $new_definition['weight'] = (int) $form_state['values']['weight'];
    $new_definition['expanded'] = $form_state['values']['expanded'] ? 1 : 0;
    list($menu_name, $parent) = explode(':', $form_state['values']['menu_parent'], 2);
    if (!empty($menu_name)) {
      $new_definition['menu_name'] = $menu_name;
    }
    if (isset($parent)) {
      $new_definition['parent'] = $parent;
    }
    return $new_definition;
  }

  /**
   * {@inheritdoc}
   */
  public function validateEditForm(array &$form, array &$form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitEditForm(array &$form, array &$form_state) {
    $new_definition = $this->extractFormValues($form, $form_state);

    return $this->menuTree->updateLink($this->menuLink->getPluginId(), $new_definition);
  }

  /**
   * Helper function to get a module name.
   *
   * This function is horrible, but core has nothing better until we add a
   * a method to the ModuleHandler that handles this nicely.
   * @see - https://drupal.org/node/2281989
   */
  protected function getModuleName($module) {
    // Gather module data.
    if (!isset($this->moduleData)) {
      $this->moduleData = system_get_info('module');
    }
    // If the module exists, return its human-readable name.
    if (isset($this->moduleData[$module])) {
      return $this->t($this->moduleData[$module]['name']);
    }
    // Otherwise, return the machine name.
    return $module;
  }
}
