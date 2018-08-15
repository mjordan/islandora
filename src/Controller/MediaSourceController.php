<?php

namespace Drupal\islandora\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Session\AccountInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaTypeInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\islandora\MediaSource\MediaSourceService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Class MediaSourceController.
 *
 * @package Drupal\islandora\Controller
 */
class MediaSourceController extends ControllerBase {

  /**
   * Service for business logic.
   *
   * @var \Drupal\islandora\MediaSource\MediaSourceService
   */
  protected $service;

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * MediaSourceController constructor.
   *
   * @param \Drupal\islandora\MediaSource\MediaSourceService $service
   *   Service for business logic.
   * @param \Drupal\Core\Database\Connection $database
   *   Database connection.
   */
  public function __construct(
    MediaSourceService $service,
    Connection $database
  ) {
    $this->service = $service;
    $this->database = $database;
  }

  /**
   * Controller's create method for dependecy injection.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The App Container.
   *
   * @return \Drupal\islandora\Controller\MediaSourceController
   *   Controller instance.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('islandora.media_source_service'),
      $container->get('database')
    );
  }

  /**
   * Updates a source file for a Media.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media whose source file you want to update.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   204 on success.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   */
  public function put(MediaInterface $media, Request $request) {
    $content_type = $request->headers->get('Content-Type', "");

    if (empty($content_type)) {
      throw new BadRequestHttpException("Missing Content-Type header");
    }

    // Since we update both the Media and its File, do this in a transaction.
    $transaction = $this->database->startTransaction();

    try {

      $this->service->updateSourceField(
        $media,
        $request->getContent(TRUE),
        $content_type
      );

      return new Response("", 204);
    }
    catch (HttpException $e) {
      $transaction->rollBack();
      throw $e;
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      throw new HttpException(500, $e->getMessage());
    }
  }

  /**
   * Adds a Media to a Node using the specified field.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The Node to which you want to add a Media.
   * @param \Drupal\media\MediaTypeInterface $media_type
   *   Media type for new media.
   * @param \Drupal\taxonomy\TermInterface $taxonomy_term
   *   Term from the 'Behavior' vocabulary to give to new media.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   201 on success with a Location link header.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   */
  public function putToNode(
    NodeInterface $node,
    MediaTypeInterface $media_type,
    TermInterface $taxonomy_term,
    Request $request
  ) {
    $content_type = $request->headers->get('Content-Type', "");

    if (empty($content_type)) {
      throw new BadRequestHttpException("Missing Content-Type header");
    }

    $content_location = $request->headers->get('Content-Location', "");

    // Since we create both a Media and its File,
    // start a transaction.
    $transaction = $this->database->startTransaction();

    try {
      $media = $this->service->putToNode(
        $node,
        $media_type,
        $taxonomy_term,
        $request->getContent(TRUE),
        $content_type,
        $content_location
      );

      // We return the media if it was newly created.
      if ($media) {
        $response = new Response("", 201);
        $response->headers->set("Location", $media->url('canonical', ['absolute' => TRUE]));
      }
      else {
        $response = new Response("", 204);
      }
      return $response;
    }
    catch (HttpException $e) {
      $transaction->rollBack();
      throw $e;
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      throw new HttpException(500, $e->getMessage());
    }
  }

  /**
   * Checks for permissions to update a node and create media.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Account for user making the request.
   * @param \Drupal\Core\Routing\RouteMatch $route_match
   *   Route match to get Node from url params.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Access result.
   */
  public function putToNodeAccess(AccountInterface $account, RouteMatch $route_match) {
    // We'd have 404'd already if node didn't exist, so no need to check.
    // Just hack it out of the route match.
    $node = $route_match->getParameter('node');
    return AccessResult::allowedIf($node->access('update', $account) && $account->hasPermission('create media'));
  }

}
