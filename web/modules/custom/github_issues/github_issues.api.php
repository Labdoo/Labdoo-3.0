<?php

/**
 * @file
 * Documents the GitHub issue metadata extension hook.
 */

/**
 * Alters issue metadata before GitHub submission.
 *
 * @param array $metadata
 *   The GitHub metadata.
 *   Valid keys: source, author, team.
 */
function hook_github_issues_set_metadata(array &$metadata): void {
}
