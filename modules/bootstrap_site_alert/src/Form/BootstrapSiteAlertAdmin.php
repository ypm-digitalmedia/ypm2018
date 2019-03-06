<?php

/**
 * @file
 * Contains \Drupal\bootstrap_site_alert\Form\BootstrapSiteAlertAdmin.
 */

namespace Drupal\bootstrap_site_alert\Form;

use Drupal\Component\Utility\Random;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Cache\Cache;
use Symfony\Component\DependencyInjection\ContainerInterface;

class BootstrapSiteAlertAdmin extends FormBase {
  
  /**
   * The Drupal state storage service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructs a new UpdateManagerUpdate object.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(StateInterface $state) {
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('state')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'bootstrap_site_alert_admin';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, \Drupal\Core\Form\FormStateInterface $form_state) {

    $form['description'] = [
      '#markup' => t('<h3>Use this form to setup the bootstrap site alert.</h3>
                  <p>Make sure you select the checkbox if you want to turn the alerts on</p>')
      ];

    $form['bootstrap_site_alert_active'] = [
      '#type' => 'checkbox',
      '#title' => t('If Checked, Bootstrap Site Alert is Active.'),
      '#default_value' => $this->state->get('bootstrap_site_alert_active', 0),
    ];

    $form['bootstrap_site_alert_severity'] = [
      '#type' => 'select',
      '#title' => t('Severity'),
      '#options' => [
        'alert-success' => t('Success'),
        'alert-info' => t('Info'),
        'alert-warning' => t('Warning'),
        'alert-danger' => t('Danger'),
      ],
      '#empty_option' => t('- SELECT -'),
      '#default_value' => $this->state->get('bootstrap_site_alert_severity', NULL),
      '#required' => TRUE,
    ];

    $form['bootstrap_site_alert_dismiss'] = [
      '#type' => 'checkbox',
      '#title' => t('Make this alert dismissable?'),
      '#default_value' => $this->state->get('bootstrap_site_alert_dismiss', 0),
    ];

    $form['bootstrap_site_alert_no_admin'] = [
      '#type' => 'checkbox',
      '#title' => t('Hide this alert on admin pages?'),
      '#default_value' => $this->state->get('bootstrap_site_alert_no_admin', 0),
    ];

    // Need to load the text_format default a little differently.
    $message = $this->state->get('bootstrap_site_alert_message');

    $form['bootstrap_site_alert_message'] = [
      '#type' => 'text_format',
      '#title' => t('Alert Message'),
      '#default_value' => isset($message['value']) ? $message['value'] : NULL,
      '#required' => TRUE,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Alert Message'),
      '#button_type' => 'primary',
    ];

    // By default, render the form using theme_system_config_form().
    $form['#theme'] = 'system_config_form';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Save the values to the state.
    foreach ($form_state->getValues() as $key => $value) {
      if (strpos($key, 'bootstrap_') !== FALSE) {
        $this->state->set($key, $value);
      }
    }

    // Save a random key so that we can use it to track a 'dismiss' action for
    // this particular alert.
    $random = new Random();
    $this->state->set('bootstrap_site_alert_key', $random->string(16, TRUE));


    // Flushes the pages after save.
    Cache::invalidateTags(['rendered']);
  }
}
