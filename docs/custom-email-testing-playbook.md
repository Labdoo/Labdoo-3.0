# Custom Email Testing Playbook (Labdoo)

This playbook explains how to test **all custom code paths** that send emails in this project and how to debug delivery with Amazon SES.

## 1. Scope: custom email sending points

The custom module `labdoo_notifications` is the central source of custom outbound emails.

### 1.1 Core send service

- `web/modules/custom/labdoo_notifications/src/Service/EmailProcessor.php`
  - Main sender: `sendEmail(array $emailParams)`
  - Uses Drupal Mail Manager (`plugin.manager.mail`) with module key `labdoo_notifications`.
  - Applies test mode logic from `labdoo_notifications.settings` (`test_mode`, `test_mode_recipients`).

### 1.2 Triggers in custom code

- `web/modules/custom/labdoo_notifications/src/Form/ContactForm.php`
  - Contact form submission sends:
    1. Notification to site mailbox (`system.site:mail`)
    2. Confirmation to user email

- `web/modules/custom/labdoo_notifications/labdoo_notifications.module`
  - `hook_node_presave()`
    - Laptop notifications
    - Dootrip notifications (presave path)
  - `hook_node_insert()`
    - Dootrip notifications (`insert`)
    - Optional Dootrip announcement (`announce`) when `field_announce_this_dootrip = 1`
    - Team activity notifications for `team_page`, `event`, `team_task`
  - `hook_comment_insert()`
    - Team activity comment notifications for `team_page`, `event`, `team_task`
  - `hook_user_insert()`
    - User registration notification
  - `hook_cron()`
    - Dootrip expiration notifications (`expired`) for first expiration and reminders (+5/+10/+15 days)

- `web/modules/custom/labdoo_notifications/src/Service/NotificationManager.php`
  - Business logic for all notification event types:
    - Laptop: `tagged`, `delivered`, `updated`
    - Dootrip: `insert`, `update`, `expired`, `announce`
    - Team activity (node + comment)
    - User registration

## 2. Environment and prerequisites

## 2.1 Required configuration checks

1. Ensure module is enabled:

```bash
drush pml --status=enabled --type=module | grep labdoo_notifications
```

2. Check sender and default mailbox:

```bash
drush cget system.site mail
```

3. Check mail backend routing:

```bash
drush cget system.mail interface
drush cget mailsystem.settings
```

4. Check SES module configuration (if SES is used in this environment):

```bash
drush cget amazon_ses.settings
```

In this repository config, `amazon_ses.settings.yml` contains `queue: true`, so sending can be queued.

5. Check custom test mode behavior:

```bash
drush cget labdoo_notifications.settings
```

Important fields:
- `test_mode` (bool)
- `test_mode_recipients` (comma-separated forced recipients)

## 2.2 Logging and observability setup

Enable practical log visibility before running test cases:

```bash
drush ws --count=50 --severity=Warning
```

Optional (live stream while testing):

```bash
drush ws --tail
```

## 2.3 Test data recommendations

Prepare:
- 2+ user accounts with valid emails
- 1 laptop node
- 1 dootrip node
- 1 team node and one `team_page`/`event`/`team_task`
- At least one comment-ready team content item

## 3. Test cases by custom use case

For each case, capture:
- Trigger action performed
- Target entity ID
- Expected recipients (`to` + `Bcc` logic)
- Result in Drupal logs
- SES evidence (Section 5)

### 3.1 Contact form submission (internal notification + user confirmation)

Code path:
- `ContactForm::submitForm()`
- `EmailProcessor::sendEmail()` (called twice)

Steps:
1. Open contact page in browser.
2. Fill `name`, `email`, `subject`, `message`, and `contact_reason`.
3. Submit form.
4. Verify success message: “Your message has been sent.”
5. Verify that two emails are attempted:
   - one to `system.site:mail`
   - one to submitter email

Expected result:
- Both emails are accepted by mail backend.
- No warning about missing templates.

Negative variant:
- Enter invalid email in `email` field and verify validation blocks submission.

### 3.2 Laptop notification: tagged

Code path:
- `hook_node_presave()` → laptop processor → `NotificationManager::sendLaptopEventEmail()`
- Event condition: new laptop with status `Tagged`

Steps:
1. Create a new `laptop` node.
2. Set `field_status = Tagged` before first save.
3. Save node.
4. Confirm email attempt for event `tagged`.
Expected recipients logic:
- `to = system.site:mail`
- `Bcc` includes site mail plus related users (manager/dootrip manager/edoovillage manager/author) when available.

### 3.3 Laptop notification: delivered

Event condition:
- Existing laptop status changes to `Delivered`.

Steps:
1. Edit an existing `laptop` node.
2. Change `field_status` from non-delivered to `Delivered`.
3. Save.
4. Confirm email event `delivered`.

### 3.4 Laptop notification: updated

Event condition:
- Existing laptop status changes (and is not the specific `Delivered` branch).

Steps:
1. Edit existing `laptop` node.
2. Change `field_status` to a different value.
3. Save.
4. Confirm email event `updated`.

### 3.5 Dootrip notification: insert

Code path:
- `hook_node_insert()` for `dootrip` → `sendDootripEventEmail(..., 'insert')`

Steps:
1. Create a new `dootrip` node.
2. Save.
3. Confirm event `insert` email attempt.

Expected recipient behavior:
- `to = system.site:mail`
- `Bcc` includes site mailbox + dootrip-related users from helper logic.

### 3.6 Dootrip notification: announce

Condition:
- On dootrip creation, `field_announce_this_dootrip = 1`.

Steps:
1. Create a new `dootrip` with announce checkbox/flag enabled.
2. Save.
3. Confirm a second dootrip email with event `announce`.

Expected additional recipients:
- Managers in destination country (hub/edoovillage) appended by notification manager logic.

### 3.7 Dootrip notification: update

Code path:
- `hook_node_presave()` for dootrip triggers processor with operation `presave`.

Steps:
1. Edit an existing dootrip.
2. Change meaningful content (title/dates/origin/destination).
3. Save.
4. Confirm event mapped to update flow in notification manager.

### 3.8 Dootrip notification: expired (cron)

Code path:
- `hook_cron()` selects expired dootrips and sends `expired` notifications.

Steps:
1. Prepare a `dootrip` with:
   - `field_arrival_date < today`
   - `field_status_dootrip = DS0`
   - `field_expired = 0`
2. Run cron:

```bash
drush cron
```

3. Confirm first expiration email and that `field_expired` is set to `1`.
4. For reminder runs, create fixtures with `arrival_date = today -5`, `-10`, `-15` days and run cron again.

Expected result:
- `expired` email attempt exists for each matching item.

### 3.9 Team activity notification: node creation

Code path:
- `hook_node_insert()` for `team_page`, `event`, `team_task`

Steps:
1. Create a node of each type (`team_page`, `event`, `team_task`).
2. Save each.
3. Confirm one team activity email attempt per creation.

### 3.10 Team activity notification: comment creation

Code path:
- `hook_comment_insert()` on team-related nodes

Steps:
1. Open a `team_page` or `team_task` node.
2. Add a comment.
3. Confirm team comment email attempt.

### 3.11 User registration notification

Code path:
- `hook_user_insert()` → user processor → notification manager

Steps:
1. Register a new user (UI or scripted).
2. Confirm custom registration email attempt.
3. Verify default Drupal mail `user_register_no_approval_required` is suppressed by `hook_mail_alter()`.

## 4. Edge-case and safety checks

### 4.1 Test mode recipient override

1. Enable test mode and set controlled recipients:

```bash
drush cset labdoo_notifications.settings test_mode 1 -y
drush cset labdoo_notifications.settings test_mode_recipients "qa@example.org" -y
drush cr
```

2. Run one scenario (for example contact form).
3. Verify all outgoing mails are redirected to configured test recipients.

Reset after tests:

```bash
drush cset labdoo_notifications.settings test_mode 0 -y
drush cset labdoo_notifications.settings test_mode_recipients "" -y
drush cr
```

### 4.2 Missing template behavior

Expected behavior from custom code:
- If template subject/body is missing, email is not sent and a warning is logged.

Validation:
1. Temporarily break one template key in controlled environment.
2. Trigger corresponding event.
3. Verify warning in logs and no send attempt.

### 4.3 Missing `to` field protection

`hook_mail_alter()` blocks sending when `to` is empty and logs warning.

Validation:
1. Use a controlled local test to force empty target.
2. Verify message gets blocked (`send = FALSE`) and warning appears.

## 5. Amazon SES debugging playbook (AWS CLI)

Use this section to determine whether email is **queued**, **sent/accepted by SES**, or **failed**.

Set region/profile first:

```bash
export AWS_PROFILE=your-profile
export AWS_REGION=eu-west-1
```

### 5.1 Verify SES account and sender readiness

```bash
aws sesv2 get-account
aws sesv2 list-email-identities
aws sesv2 get-email-identity --email-identity noreply@labdoo.org
```

Check for:
- account in production/sandbox
- identity verified
- sending not paused

### 5.2 Check Drupal-side queue status (queued vs not queued)

Because `amazon_ses.settings` has `queue: true`, first verify if messages are still pending in Drupal queue:

```bash
drush sqlq "SELECT name, number_of_items FROM queue WHERE name LIKE '%amazon_ses%';"
drush sqlq "SELECT item_id, created, expire FROM queue_item qi JOIN queue q ON qi.name = q.name WHERE q.name LIKE '%amazon_ses%' ORDER BY item_id DESC LIMIT 20;"
```

Interpretation:
- Items present and increasing: **queued (not yet processed)**
- Items drained after cron/worker run: forwarded to SES path

Process queue immediately:

```bash
drush cron
```

If available in your setup, run queue worker directly for faster checks:

```bash
drush queue:run amazon_ses_mail_queue
```

### 5.3 Verify SES event destinations (where status events go)

List SES configuration sets:

```bash
aws sesv2 list-configuration-sets
```

Inspect one set:

```bash
aws sesv2 get-configuration-set --configuration-set-name <CONFIG_SET_NAME>
aws sesv2 get-configuration-set-event-destinations --configuration-set-name <CONFIG_SET_NAME>
```

You need at least one event destination to observe delivery states (`SEND`, `DELIVERY`, `BOUNCE`, `COMPLAINT`, `REJECT`, `RENDERING_FAILURE`).

### 5.4 If events go to CloudWatch Logs

List candidate log groups:

```bash
aws logs describe-log-groups --query "logGroups[?contains(logGroupName, 'ses') || contains(logGroupName, 'email')].logGroupName" --output table
```

Read latest events (replace log group):

```bash
aws logs tail /aws/ses/events --since 30m --follow
```

Filter by recipient/message id pattern:

```bash
aws logs filter-log-events \
  --log-group-name /aws/ses/events \
  --filter-pattern '"recipient@example.org" || "MessageId" || "eventType"' \
  --start-time $((($(date +%s)-3600)*1000))
```

### 5.5 If events go to SNS

List topics and inspect subscriptions:

```bash
aws sns list-topics
aws sns list-subscriptions-by-topic --topic-arn <TOPIC_ARN>
```

If SNS forwards to SQS, inspect queue messages:

```bash
aws sqs receive-message \
  --queue-url <QUEUE_URL> \
  --max-number-of-messages 10 \
  --wait-time-seconds 5
```

### 5.6 If events go to Kinesis Firehose / S3

```bash
aws firehose list-delivery-streams
aws firehose describe-delivery-stream --delivery-stream-name <STREAM_NAME>
aws s3 ls s3://<SES_EVENTS_BUCKET>/<optional-prefix>/ --recursive | tail
```

### 5.7 Delivery-state interpretation matrix

- **Queued**
  - Evidence: pending items in Drupal `amazon_ses` queue; no corresponding SES event yet.
- **Sent (accepted by SES)**
  - Evidence: SES event `SEND` exists; usually followed by `DELIVERY` if destination accepted.
- **Failed**
  - Evidence: one or more of:
    - Drupal warning/error logs during send attempt
    - SES events `BOUNCE`, `REJECT`, `COMPLAINT`, `RENDERING_FAILURE`
    - identity/configuration-set issues from SES CLI checks

## 6. Suggested execution order for full regression

1. Enable observability (Section 2.2).
2. Run contact form test.
3. Run laptop tests (`tagged`, `delivered`, `updated`).
4. Run dootrip tests (`insert`, `announce`, `update`, `expired`).
5. Run team node/comment tests.
6. Run user registration test.
7. Run test mode override test.
8. For each run, classify state with SES debug flow (Section 5).

## 7. Evidence checklist (for QA report)

For each use case include:
- Date/time and environment
- Triggered entity/user ID
- Expected recipients and actual recipients
- Relevant Drupal log lines
- Queue evidence (`queue` / `queue_item` query output)
- SES evidence (event record or failure reason)
- Final verdict: `Queued`, `Sent`, or `Failed`
