<?php

namespace Drupal\url_redirect\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Path\PathMatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Drupal\Core\Path\CurrentPathStack;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use \Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Component\Utility\Html;
use Drupal\Core\Session\AccountProxy;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationManager;
use Drupal\Core\Entity\Query\QueryFactory;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;

class RedirectSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Current path stack service.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected $currentPathStack;

  /**
   * Path matcher service.
   *
   * @var \Drupal\Core\Path\PathMatcher
   */
  protected $pathMatcher;

  /**
   * Entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * Current user service.
   *
   * @var \Drupal\Core\Session\AccountProxy
   */
  protected $currentUser;

  /**
   * Entity query service.
   *
   * @var \Drupal\Core\Entity\Query\QueryFactory
   */
  protected $queryFactory;

  /**
   * RedirectSubscriber constructor.
   *
   * @param \Drupal\Core\Path\CurrentPathStack $currentPathStack
   *  Current path stack service.
   * @param \Drupal\Core\Path\PathMatcher $pathMatcher
   *  Path matcher service.
   * @param \Drupal\Core\Entity\EntityTypeManager $entityTypeManager
   *  Entity type manager service.
   * @param \Drupal\Core\Session\AccountProxy $currentUser
   *  Current user service.
   * @param \Drupal\Core\StringTranslation\TranslationManager $stringTranslation
   *  Translation manager service.
   * @param \Drupal\Core\Entity\Query\QueryFactory $queryFactory
   *  Entity query service.
   */
  public function __construct(CurrentPathStack $currentPathStack, PathMatcher $pathMatcher, EntityTypeManager $entityTypeManager, AccountProxy $currentUser, TranslationManager $stringTranslation, QueryFactory $queryFactory) {
    $this->currentPathStack = $currentPathStack;
    $this->pathMatcher = $pathMatcher;
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
    $this->stringTranslation = $stringTranslation;
    $this->queryFactory = $queryFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // This needs to be executed before RouterListener::onKernelRequest() which has 32
    // priority Otherwise, that aborts the request if no matching route is found.
    $events[KernelEvents::REQUEST][] = ['requestRedirect', 33];
    $events[KernelEvents::EXCEPTION][] = ['exceptionRedirect', 1];
    return $events;
  }

  /**
   * Perform redirect for access denied exceptions. Without this callback,
   * if a user has a custom page to display on 403 (access denied) on
   * admin/config/system/site-information, another redirection will take
   * place before the redirection for the KernelEvents::REQUEST event.
   * It results in infinite redirection and an error.
   *
   * @param GetResponseForExceptionEvent $event
   */
  public function exceptionRedirect(GetResponseForExceptionEvent $event) {
    $exception = $event->getException();
    if ($exception instanceof HttpExceptionInterface && $event->getException()->getStatusCode() === 403) {
      $this->doRedirect($event);
    }
  }

  /**
   * Perform redirect for http request.
   *
   * @param GetResponseEvent $event
   */
  public function requestRedirect(GetResponseEvent $event) {
    $this->doRedirect($event);
  }

  /**
   * Set response to redirection.
   *
   * @param GetResponseEvent $event
   */
  protected function doRedirect(GetResponseEvent $event) {
    global $base_url;
    $path_matches = FALSE;
    // Check URL path in url_redirect entity.
    $path = ($this->pathMatcher->isFrontPage()) ? Html::escape('<front>') : HTML::escape($event->getRequest()->getRequestUri());
    $wildcards = $this->getPatterns();
    foreach ($wildcards as $wildcard_path) {
      $wildcard_path_load = $this->entityTypeManager->getStorage('url_redirect')->load($wildcard_path);
      $path_matches = \Drupal::service('path.matcher')->matchPath($path, $wildcard_path_load->get_path());
      if ($path_matches) {
        $wildcard_path_key = $wildcard_path;
        break;
      }
    }
    $url_redirect = $this->getRedirect($path);
    if (!$url_redirect) {
      $url_redirect = $this->getRedirect(substr($path, 1));
    }
    if ($url_redirect || $path_matches) {
      $id = array_keys($url_redirect);
      if(!$id){
        $id[0] = $wildcard_path_key;
      }
      $successful_redirect = FALSE;
      /** @var \Drupal\url_redirect\Entity\UrlRedirect $url_redirect_load */
      $url_redirect_load = $this->entityTypeManager->getStorage('url_redirect')->load($id[0]);
      $check_for = $url_redirect_load->get_checked_for();
      // Check for Role.
      if ($check_for == 'Role') {
        $role_check_array = $url_redirect_load->get_roles();
        $user_role_check_array = $this->currentUser->getRoles();
        $checked_result = array_intersect($role_check_array, $user_role_check_array);
        $checked_result = ($url_redirect_load->get('negate')) ? $url_redirect_load->get('negate') : $checked_result;
        if ($checked_result) {
          $successful_redirect = TRUE;
          if ($this->url_redirect_is_external($url_redirect_load->get_redirect_path())) {
            $event->setResponse(new TrustedRedirectResponse($url_redirect_load->get_redirect_path(), 301));
          }
          else {
            if (empty($url_redirect_load->get_redirect_path()) || ($url_redirect_load->get_redirect_path() == '<front>')) {
              $event->setResponse(new TrustedRedirectResponse($base_url, 301));
            }
            else {
              $event->setResponse(new TrustedRedirectResponse($base_url . '/' . $url_redirect_load->get_redirect_path(), 301));
            }
          }
        }
      }
      // Check for User.
      elseif ($check_for == 'User') {
        $redirect_users = $url_redirect_load->get_users();
        if ($redirect_users) {
          $uids = array_column($redirect_users, 'target_id', 'target_id');
          $uid_in_list = isset($uids[$this->currentUser->id()]);
          $redirect_user = ($url_redirect_load->get('negate')) ? $url_redirect_load->get('negate') : $uid_in_list;
          if ($redirect_user) {
            $successful_redirect = TRUE;
            if ($this->url_redirect_is_external($url_redirect_load->get_redirect_path())) {
              $event->setResponse(new TrustedRedirectResponse($url_redirect_load->get_redirect_path(), 301));
            }
            else {
              if (empty($url_redirect_load->get_redirect_path()) || ($url_redirect_load->get_redirect_path() == '<front>')) {
                $event->setResponse(new TrustedRedirectResponse($base_url, 301));
              }
              else {
                $event->setResponse(new TrustedRedirectResponse($base_url . '/' . $url_redirect_load->get_redirect_path(), 301));
              }
            }
          }
        }
      }
      if ($successful_redirect) {
        $message = $url_redirect_load->get_message();
        if ($message == $this->t('Yes')) {
          drupal_set_message($this->t("You have been redirected to '@link_path'.", array('@link_path' => $url_redirect_load->get_redirect_path())));
        }
      }
    }
  }

  /**
   * Get redirection.
   *
   * @param string $path
   *  Source path.
   * @return array
   */
  protected function getRedirect($path) {
    $queryResult = $this->queryFactory->get('url_redirect')
        ->condition('path', $path)
        ->condition('status', 1)
        ->execute();
    return $queryResult;
  }

  /**
   * Get redirection.
   *
   * @param string $path
   *  Source path.
   * @return array
   */
  protected function getPatterns() {
    $queryResult = \Drupal::entityQuery('url_redirect')
        ->condition("path", "*", "CONTAINS")
        ->condition('status', 1)
        ->execute();
    return $queryResult;
  }

  /**
   * Check for external URL.
   * 
   * @param type $path
   * @return type
   */
  public function url_redirect_is_external($path) {
    // check for http:// or https://
    if (preg_match("`https?://`", $path)) {
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

}
