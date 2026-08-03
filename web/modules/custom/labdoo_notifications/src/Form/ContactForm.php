<?php

namespace Drupal\labdoo_notifications\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\labdoo_notifications\Service\EmailProcessor;
use Drupal\Component\Utility\EmailValidatorInterface;

/**
 * Provides a contact form.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class ContactForm extends FormBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The email processor service.
   *
   * @var \Drupal\labdoo_notifications\Service\EmailProcessor
   */
  protected EmailProcessor $emailProcessor;

  /**
   * The email validator service.
   *
   * @var \Drupal\Component\Utility\EmailValidatorInterface
   */
  protected EmailValidatorInterface $emailValidator;

  /**
   * Constructs a new ContactForm.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\labdoo_notifications\Service\EmailProcessor $email_processor
   *   The email processor service.
   */
  public function __construct(
    AccountInterface $current_user,
    LanguageManagerInterface $language_manager,
    ConfigFactoryInterface $config_factory,
    EmailProcessor $email_processor,
    EmailValidatorInterface $email_validator
  ) {
    $this->currentUser = $current_user;
    $this->languageManager = $language_manager;
    $this->configFactory = $config_factory;
    $this->emailProcessor = $email_processor;
    $this->emailValidator = $email_validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('language_manager'),
      $container->get('config.factory'),
      $container->get('labdoo_notifications.email_processor'),
      $container->get('email.validator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'labdoo_notifications_contact_form';
  }

  /**
   * Gets the contact reasons.
   *
   * @return array
   *   An array of contact reasons.
   */
  protected function getContactReasons(): array {
    return [
      'general' => $this->t('General question'),
      'laptop' => $this->t('Question about a laptop'),
      'dootrip' => $this->t('Question about a dootrip'),
      'edoovillage' => $this->t('Question about an edoovillage'),
      'hub' => $this->t('Question about a hub'),
      'team' => $this->t('Question about a team'),
      'bug' => $this->t('Report a bug'),
      'other' => $this->t('Other'),
    ];
  }

  /**
   * Gets the contact reasons summary.
   *
   * @return array
   *   An array of contact reasons with descriptions.
   */
  protected function getContactReasonsSummary(): array {
    return [
      'general' => $this->t('Use this option if you have a general question about Labdoo.'),
      'laptop' => $this->t('Use this option if you have a question about a specific laptop.'),
      'dootrip' => $this->t('Use this option if you have a question about a specific dootrip.'),
      'edoovillage' => $this->t('Use this option if you have a question about a specific edoovillage.'),
      'hub' => $this->t('Use this option if you have a question about a specific hub.'),
      'team' => $this->t('Use this option if you have a question about a specific team.'),
      'bug' => $this->t('Use this option if you want to report a bug.'),
      'other' => $this->t('Use this option if your question does not fit in any of the above categories.'),
    ];
  }

  /**
   * Gets the redirect URLs for contact form.
   *
   * @return array
   *   An array of redirect URLs.
   */
  protected function getRedirectUrls(): array {
    return [
      'general' => 'node/2',
      'laptop' => 'content/how-tag-laptop',
      'dootrip' => 'content/how-help-through-dootrip',
      'edoovillage' => 'content/how-create-or-join-edoovillage',
      'hub' => 'content/how-create-or-join-hub',
      'team' => 'content/how-create-or-join-team',
      'bug' => 'node/2',
      'other' => 'node/2',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';

    $form['contact_reason'] = [
      '#type' => 'select',
      '#title' => $this->t('What is your question about?'),
      '#options' => $this->getContactReasons(),
      '#required' => TRUE,
      '#default_value' => 'general',
      '#description' => $this->t('Please select the category that best matches your question.'),
    ];

    $form['contact_reason_summary'] = [
      '#type' => 'item',
      '#markup' => $this->getContactReasonsSummary()['general'],
      '#prefix' => '<div id="contact-reason-summary">',
      '#suffix' => '</div>',
    ];

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Your name'),
      '#required' => TRUE,
      '#default_value' => $this->currentUser->isAuthenticated() ? $this->currentUser->getDisplayName() : '',
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Your email address'),
      '#required' => TRUE,
      '#default_value' => $this->currentUser->isAuthenticated() ? $this->currentUser->getEmail() : '',
    ];

    $form['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#required' => TRUE,
    ];

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#required' => TRUE,
      '#rows' => 5,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send message'),
    ];

    $form['#attached']['library'][] = 'core/drupal.ajax';
    $form['#attached']['library'][] = 'core/jquery.form';

    $form['#attached']['drupalSettings']['contactForm'] = [
      'summaries' => $this->getContactReasonsSummary(),
    ];

    $form['#attached']['drupalSettings']['contactForm']['redirectUrls'] = $this->getRedirectUrls();

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->emailValidator->isValid($form_state->getValue('email'))) {
      $form_state->setErrorByName('email', $this->t('The email address %mail is not valid.', ['%mail' => $form_state->getValue('email')]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $langCode = $this->languageManager->getCurrentLanguage()->getId();

    // Prepare email parameters.
    $params = [
      'NAME' => $values['name'],
      'EMAIL' => $values['email'],
      'SUBJECT' => $values['subject'],
      'MESSAGE' => $values['message'],
      'REASON' => $this->getContactReasons()[$values['contact_reason']],
      'USERNAME' => $values['name'],
      'USEREMAIL' => $values['email'],
      'COUNTRY' => '',
      'CITY' => '',
      'CAMPAIGN' => '',
      'CONTACT_EMAIL' => $this->configFactory->get('system.site')->get('mail'),
    ];

    // Send the administrator / superhub notification.
    $subjectTemplate = $this->emailProcessor->loadTemplate($langCode, 'contact_form_submitted_shub_subject');
    $bodyTemplate = $this->emailProcessor->loadTemplate($langCode, 'contact_form_submitted_shub_body');

    if (empty($subjectTemplate) || empty($bodyTemplate)) {
      // Fallback to default/english if current language template is not found.
      $subjectTemplate = $this->emailProcessor->loadTemplate('en', 'contact_form_submitted_shub_subject');
      $bodyTemplate = $this->emailProcessor->loadTemplate('en', 'contact_form_submitted_shub_body');
    }

    if (!empty($subjectTemplate) && !empty($bodyTemplate)) {
      $subject = $subjectTemplate;
      $body = $bodyTemplate;
      $this->emailProcessor->processParameters($params, $subject);
      $this->emailProcessor->processParameters($params, $body);

      $emailParams = [
        'subject' => $subject,
        'body' => $body,
        'to' => $this->configFactory->get('system.site')->get('mail'),
        'headers' => [
          'Reply-To' => $values['email'],
        ],
      ];

      $this->emailProcessor->sendEmail($emailParams);
    }

    // Send the auto-reply confirmation email to the user.
    $subjectTemplate = $this->emailProcessor->loadTemplate($langCode, 'contact_form_submitted_subject');
    $bodyTemplate = $this->emailProcessor->loadTemplate($langCode, 'contact_form_submitted_body');

    if (empty($subjectTemplate) || empty($bodyTemplate)) {
      // Fallback to default/english if current language template is not found.
      $subjectTemplate = $this->emailProcessor->loadTemplate('en', 'contact_form_submitted_subject');
      $bodyTemplate = $this->emailProcessor->loadTemplate('en', 'contact_form_submitted_body');
    }

    if (!empty($subjectTemplate) && !empty($bodyTemplate)) {
      $subject = $subjectTemplate;
      $body = $bodyTemplate;
      $this->emailProcessor->processParameters($params, $subject);
      $this->emailProcessor->processParameters($params, $body);

      $emailParams = [
        'subject' => $subject,
        'body' => $body,
        'to' => $values['email'],
      ];

      $this->emailProcessor->sendEmail($emailParams);
    }

    // Show the success message.
    $this->messenger()->addStatus($this->t('Your message has been sent.'));

    // Redirect to the appropriate page based on the contact reason.
    $redirectUrls = $this->getRedirectUrls();
    $form_state->setRedirect('<front>');
    if (isset($redirectUrls[$values['contact_reason']])) {
      $form_state->setRedirectUrl(Url::fromUserInput('/' . $redirectUrls[$values['contact_reason']]));
    }
  }

}
