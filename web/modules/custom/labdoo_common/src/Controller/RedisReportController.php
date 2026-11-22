<?php

declare(strict_types=1);

namespace Drupal\labdoo_common\Controller;

use Drupal\redis\Controller\ReportController;

/**
 * Reads report metrics using commands permitted to restricted cache users.
 */
final class RedisReportController extends ReportController {

  /**
   * {@inheritdoc}
   */
  protected function info(): array {
    if ($this->redis === FALSE) {
      throw new \LogicException('Redis report metrics require a connected client.');
    }
    if ($this->redis instanceof \RedisCluster) {
      $master = current($this->redis->_masters());
      $raw = $this->redis->info($master);
      $size = $this->redis->dbSize($master);
    }
    else {
      $raw = $this->redis->info();
      $size = $this->redis->dbSize();
    }
    if (!is_array($raw)) {
      throw new \UnexpectedValueException('Redis INFO returned an invalid response.');
    }
    // Predis groups INFO metrics by section; PhpRedis returns a flat array.
    // Both provide the memory policy, so CONFIG GET is unnecessary.
    $info = $raw;
    foreach ($raw as $section) {
      if (is_array($section)) {
        $info += $section;
      }
    }
    foreach (['maxmemory', 'maxmemory_policy'] as $required) {
      if (!array_key_exists($required, $info)) {
        throw new \UnexpectedValueException('Redis INFO omitted a required memory metric: ' . $required);
      }
    }
    return [
      'redis_version' => $info['redis_version'],
      'valkey_version' => $info['valkey_version'] ?? NULL,
      'redis_mode' => $info['redis_mode'] ?? NULL,
      'connected_clients' => $info['connected_clients'],
      'db_size' => $size,
      'used_memory' => $info['used_memory'],
      'used_memory_human' => $info['used_memory_human'],
      'maxmemory_policy' => $info['maxmemory_policy'],
      'maxmemory' => $info['maxmemory'],
      'uptime_in_seconds' => $info['uptime_in_seconds'] ?? NULL,
      'total_net_output_bytes' => $info['total_net_output_bytes'] ?? NULL,
      'total_net_input_bytes' => $info['total_net_input_bytes'] ?? NULL,
      'total_commands_processed' => $info['total_commands_processed'] ?? NULL,
      'total_connections_received' => $info['total_connections_received'] ?? NULL,
    ];
  }

}
