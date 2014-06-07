<?php

/**
 * @file
 * Contains \Drupal\menu_ui\Form\MenuDeleteForm.
 */

namespace Drupal\menu_ui\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Url;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a confirmation form for deletion of a custom menu.
 */
class MenuDeleteForm extends EntityConfirmFormBase {

  /**
   * The menu link storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $menuTree;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Constructs a new MenuDeleteForm.
   *
   * @param \Drupal\Core\Menu\MenuLinkTreeInterface $menu_tree
   *   The menu link manager.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   */
  public function __construct(MenuLinkTreeInterface $menu_tree, Connection $connection) {
    $this->menuTree = $menu_tree;
    $this->connection = $connection;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('menu.link_tree'),
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return t('Are you sure you want to delete the custom menu %title?', array('%title' => $this->entity->label()));
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelRoute() {
    return $this->entity->urlInfo('edit-form');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    $caption = '';
    $num_links = $this->menuTree->countMenuLinks($this->entity->id());
    if ($num_links) {
      $caption .= '<p>' . format_plural($num_links, '<strong>Warning:</strong> There is currently 1 menu link in %title. It will be deleted (system-defined items will be reset).', '<strong>Warning:</strong> There are currently @count menu links in %title. They will be deleted (system-defined links will be reset).', array('%title' => $this->entity->label())) . '</p>';
    }
    $caption .= '<p>' . t('This action cannot be undone.') . '</p>';
    return $caption;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function submit(array $form, array &$form_state) {
    $form_state['redirect_route'] = new Url('menu_ui.overview_page');

    // Locked menus may not be deleted.
    if ($this->entity->isLocked()) {
      return;
    }

    // Delete all links to the overview page for this menu.
    // @todo - there ought to be a better way.
    $menu_links = $this->menuTree->loadLinksByRoute('menu_ui.menu_edit', array('menu' => $this->entity->id()), TRUE);
    foreach ($menu_links as $id => $link) {
      $this->menuTree->deleteLink($id);
    }

    // Delete the custom menu and all its menu links.
    $this->entity->delete();

    $t_args = array('%title' => $this->entity->label());
    drupal_set_message(t('The custom menu %title has been deleted.', $t_args));
    watchdog('menu', 'Deleted custom menu %title and all its menu links.', $t_args, WATCHDOG_NOTICE);
  }
}
