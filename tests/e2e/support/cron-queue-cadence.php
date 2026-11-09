<?php

/** Read-only scheduler tests plus a reversible isolated configuration test. */
require __DIR__ . '/assert-isolated.php';

use Drupal\Component\Datetime\Time;
use Drupal\ultimate_cron\Entity\CronJob;
use Drupal\ultimate_cron\Plugin\ultimate_cron\Scheduler\Simple;
use Symfony\Component\HttpFoundation\RequestStack;

$testContainer = \Drupal::getContainer();
$testOriginalTime = \Drupal::time();
$testClock = new class(new RequestStack()) extends Time {
  public int $now;
  public function getRequestTime() { return $this->now; }
};
$testJob = CronJob::create(['id' => 'queue_cadence_schedule_test', 'status' => TRUE]);
$testBase = (int) (floor(time() / 300) * 300);
$testJob->log_entry = (object) ['start_time' => $testBase + 5];
$testNew = new Simple(['rules' => ['*/5 * * * *'], 'catch_up' => 0], 'simple', []);
$testOld = new Simple(['rules' => ['* * * * *'], 'catch_up' => 0], 'simple', []);
$testCases = [];
$testContainer->set('datetime.time', $testClock);
try {
  $testClock->now = $testBase + 180;
  $testCases['old_one_minute_rule_warns_at_three_minutes'] = $testOld->isBehind($testJob) > 0;
  $testCases['aligned_five_minute_rule_not_behind_at_three_minutes'] = $testNew->isBehind($testJob) === FALSE;
  $testClock->now = $testBase + 360;
  $testCases['missed_five_minute_tick_warns_at_six_minutes'] = $testNew->isBehind($testJob) === 60;
  $testCases['not_due_between_ticks'] = Simple::shouldRun(['*/5 * * * *'], $testBase + 5, $testBase + 180, 0) === FALSE;
  $testCases['due_at_next_existing_os_tick'] = (bool) Simple::shouldRun(['*/5 * * * *'], $testBase + 5, $testBase + 305, 0);
  $testCases['catch_up_zero_still_recovers_after_long_outage'] = (bool) Simple::shouldRun(['*/5 * * * *'], $testBase + 5, $testBase + 86400 + 4, 0);
  $testJob->disable();
  $testCases['disabled_mail_job_never_reports_behind'] = $testNew->isBehind($testJob) === FALSE;
}
finally {
  $testContainer->set('datetime.time', $testOriginalTime);
}
foreach ($testCases as $name => $passed) {
  if (!$passed) {
    throw new RuntimeException('Actual Ultimate Cron scheduler test failed: ' . $name);
  }
}
$testFactory = \Drupal::configFactory();
$testName = 'ultimate_cron.job.ultimate_cron_queue_labdoo_cadence_disabled_test';
$testTransaction = \Drupal::database()->startTransaction();
$testBefore = [];
foreach ($testFactory->listAll('ultimate_cron.job.ultimate_cron_queue_') as $name) {
  $testBefore[$name] = $testFactory->getEditable($name)->getRawData();
}
$testBackup = \Drupal\Core\Site\Settings::get('labdoo_e2e_state') . '/cron-cadence-backup-' . bin2hex(random_bytes(8)) . '.json';
$testResult = NULL;
try {
  $testFactory->getEditable($testName)->setData(['id' => 'ultimate_cron_queue_labdoo_cadence_disabled_test', 'status' => FALSE, 'callback' => 'ultimate_cron.queue_worker:queueCallback', 'launcher' => ['id' => 'serial'], 'scheduler' => ['id' => 'simple', 'configuration' => ['rules' => ['* * * * *'], 'catch_up' => 0]], 'logger' => ['id' => 'database']])->save();
  putenv('LABDOO_CRON_APPLY=1');
  putenv('LABDOO_CRON_BACKUP=' . $testBackup);
  ob_start();
  try {
    require dirname(__DIR__, 3) . '/scripts/align-queue-cron-cadence.php';
    $testResult = json_decode(ob_get_contents(), TRUE, 512, JSON_THROW_ON_ERROR);
  }
  finally {
    ob_end_clean();
  }
  if ($testResult['count'] < 1 || !$testResult['counters_unchanged'] || !$testResult['no_callbacks_launched']) {
    throw new RuntimeException('The queue scheduling configuration update was not exercised safely.');
  }
  $testDisabled = $testFactory->getEditable($testName);
  if ($testDisabled->get('status') !== FALSE || $testDisabled->get('scheduler.configuration.rules') !== ['*/5 * * * *'] || $testDisabled->get('scheduler.configuration.catch_up') !== 0) {
    throw new RuntimeException('The disabled job state or catch-up semantics changed.');
  }
  foreach ($testResult['changes'] as $change) {
    if (!$change['launcher_unchanged'] || !$change['catch_up_unchanged']) {
      throw new RuntimeException('A launcher or catch-up window changed.');
    }
  }
  putenv('LABDOO_CRON_RESTORE=' . $testBackup);
  putenv('LABDOO_CRON_BACKUP=' . $testBackup . '.inverse.json');
  ob_start();
  try {
    require dirname(__DIR__, 3) . '/scripts/align-queue-cron-cadence.php';
    $testInverse = json_decode(ob_get_contents(), TRUE, 512, JSON_THROW_ON_ERROR);
  }
  finally {
    ob_end_clean();
  }
  if ($testInverse['count'] !== $testResult['count'] || $testFactory->getEditable($testName)->get('scheduler.configuration.rules') !== ['* * * * *']) {
    throw new RuntimeException('The configuration backup inverse did not restore the scheduling rules.');
  }
}
finally {
  $testTransaction->rollBack();
  unset($testTransaction);
  $testFactory->reset();
  \Drupal::entityTypeManager()->getStorage('ultimate_cron_job')->resetCache();
}
foreach ($testBefore as $name => $before) {
  if ($testFactory->getEditable($name)->getRawData() !== $before) {
    throw new RuntimeException('The isolated configuration test was not fully rolled back.');
  }
}
echo 'PROGRAM_JSON:' . json_encode(['passed' => TRUE, 'actual_scheduler_cases' => $testCases, 'configuration_jobs_exercised' => $testResult['count'], 'disabled_state_preserved' => TRUE, 'backup_inverse_tested' => TRUE, 'configuration_rolled_back' => TRUE, 'no_callbacks_launched' => TRUE]);
