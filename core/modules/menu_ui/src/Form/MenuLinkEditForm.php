<?php

/**
 * @file
 * Contains \Drupal\menu_ui\Form\MenuLinkEditForm.
 */

namespace Drupal\menu_ui\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuLinkInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a generic edit form for all menu link plugin types.
 */
class MenuLinkEditForm extends FormBase {

  /**
   * The menu tree service.
   *
   * @var \Drupal\Core\Menu\MenuLinkTreeInterface
   */
  protected $menuTree;

  /**
   * Constructs a MenuLinkEditForm object.
   *
   * @param \Drupal\Core\Menu\MenuLinkTreeInterface $menu_tree
   *   The menu tree service.
   */
  public function __construct(MenuLinkTreeInterface $menu_tree) {
    $this->menuTree = $menu_tree;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('menu.link_tree')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'menu_link_edit';
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Menu\MenuLinkInterface $menu_link_plugin
   *   The plugin instance to use for this form.
   */
  public function buildForm(array $form, array &$form_state, MenuLinkInterface $menu_link_plugin = NULL) {

    $form['menu_link_id'] = array(
      '#type' => 'value',
      '#value' => $menu_link_plugin->getPluginId(),
    );

    $form['#plugin_form'] = $this->menuTree->getPluginForm($menu_link_plugin);

    $form += $form['#plugin_form']->buildEditForm($form, $form_state);

    $form['actions'] = array('#type' => 'actions');
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => t('Save'),
      '#button_type' => 'primary',
    );
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, array &$form_state) {
    $form['#plugin_form']->validateEditForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, array &$form_state) {
    $link = $form['#plugin_form']->submitEditForm($form, $form_state);

    drupal_set_message($this->t('The menu link has been saved.'));
    $form_state['redirect_route'] = array(
      'route_name' => 'menu_ui.menu_edit',
      'route_parameters' => array(
        'menu' => $link->getMenuName(),
      ),
    );
  }

}
