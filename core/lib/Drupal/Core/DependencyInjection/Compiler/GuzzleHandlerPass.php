<?php

/**
 * @file
 * Contains \Drupal\Core\DependencyInjection\Compiler\GuzzleHandlerPass.
 */

namespace Drupal\Core\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class GuzzleHandlerPass implements CompilerPassInterface {

  /**
   * {@inheritdoc}
   */
  public function process(ContainerBuilder $container) {
    foreach ($container->findTaggedServiceIds('http_client_handler') as $id => $attributes) {
      $container->getDefinition('http_handler_stack')
        ->addMethodCall('push', [new Reference($id)]);
    }
  }

}
