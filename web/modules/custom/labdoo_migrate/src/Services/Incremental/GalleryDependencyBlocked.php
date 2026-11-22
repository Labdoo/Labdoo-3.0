<?php

declare(strict_types=1);

namespace Drupal\labdoo_migrate\Services\Incremental;

/**
 * Expected preflight blocker; database/infrastructure failures are not hidden.
 */
final class GalleryDependencyBlocked extends \RuntimeException {}
