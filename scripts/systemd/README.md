# Drupal Systemd Queue Workers

## Motivation
Drupal's default queue processing via web-based Cron is often insufficient for high-traffic or resource-heavy systems. Processing long-running tasks within the PHP-FPM/Nginx request cycle can lead to:
- **Timeouts:** Web requests are interrupted before finishing the queue.
- **Resource Exhaustion:** Parallel web requests and queue workers competing for the same PHP-FPM pool.
- **Starvation:** One heavy queue (e.g., Google Maps API) blocking other critical tasks.
- **Lack of Isolation:** Failures in API calls or heavy computations affecting site performance.

This system moves queue processing to **Systemd Timers**, providing a robust, isolated, and monitorable background processing architecture.

## Architecture
- **Isolated Workers:** Each queue can run as its own Systemd service.
- **Sandboxing:** Services run with limited privileges and restricted filesystem access.
- **Resource Control:** CPU and Memory limits are enforced via CGroups.
- **Frequency Tiers:**
    - **Fast (1 min):** For critical recomputations and cache invalidations.
    - **Slow (15 min):** For external API calls (e.g., Google Maps) to stay within rate limits and quotas.

## Files
- `drupal-queue@.service`: Template for standard/fast workers.
- `drupal-queue@.timer`: Periodic trigger for standard workers.
- `drupal-queue-slow@.service`: Template for slow/API-constrained workers.
- `drupal-queue-slow@.timer`: Periodic trigger for slow workers.

## Installation

1. **Prerequisites:**
   - Ensure the user `ubuntu` exists and has access to the project root at `/var/www/html/labdoo`.
   - Ensure AWS CLI is configured if using the CloudWatch metrics script.

2. **Copy Units:**
   ```bash
   sudo cp /var/www/html/labdoo/scripts/systemd/drupal-queue* /etc/systemd/system/
   sudo systemctl daemon-reload
   ```

3. **Enable Fast Workers (1 minute interval):**
   ```bash
   sudo systemctl enable --now drupal-queue@labdoo_hub_recompute.timer
   sudo systemctl enable --now drupal-queue@labdoo_dootronics_recompute.timer
   sudo systemctl enable --now drupal-queue@labdoo_edoovillage_recompute.timer
   sudo systemctl enable --now drupal-queue@labdoo_common_cache_invalidation.timer
   ```

4. **Enable Slow Workers (15 minute interval):**
   ```bash
   sudo systemctl enable --now drupal-queue-slow@labdoo_dootrip_geocoding.timer
   ```

## Operations

### Checking Status
```bash
# List all active timers
systemctl list-timers --all | grep drupal

# Check a specific queue service
systemctl status drupal-queue@labdoo_hub_recompute.service
```

### Viewing Logs
```bash
journalctl -u drupal-queue@labdoo_hub_recompute.service -f
```

### Manual Execution
```bash
sudo -u ubuntu DRUSH_OPTIONS_URI=https://v3.labdoo.org /var/www/html/labdoo/vendor/bin/drush queue:run [queue_name]
```

## Monitoring
A monitoring script is provided in `scripts/queue_metrics.php`. It should be run via cron to push metrics to AWS CloudWatch.

```cron
*/5 * * * * /var/www/html/labdoo/vendor/bin/drush scr /var/www/html/labdoo/scripts/queue_metrics.php
```
