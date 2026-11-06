<?php

namespace Drupal\labdoo_e2e\Mail;

final class CollectorMailManager extends \Drupal\mailsystem\MailsystemManager {
  public function getInstance(array $options) {
    return $this->createInstance('test_mail_collector');
  }
}
