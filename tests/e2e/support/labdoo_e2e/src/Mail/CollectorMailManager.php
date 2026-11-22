<?php

namespace Drupal\labdoo_e2e\Mail;

use Drupal\mailsystem\MailsystemManager;

/**
 * Routes every test message to the local mail collector.
 */
final class CollectorMailManager extends MailsystemManager {

  /**
   * {@inheritdoc}
   */
  public function getInstance(array $options) {
    return $this->createInstance('test_mail_collector');
  }

}
