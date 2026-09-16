<?php

declare(strict_types=1);

namespace Amor\Api;

/**
 * Thrown by Database::migrationPdo() when MIGRATION_DB_* isn't configured
 * yet. A distinct class (not a plain RuntimeException) so callers (the
 * upgrade wizard) can catch specifically this "not set up yet" case and
 * show a friendly one-time-setup message, separate from a genuine
 * connection failure. One class per file — required for the autoloader
 * (autoload.php maps Amor\Api\X to src/X.php) to find it at all.
 */
final class MigrationCredentialsMissing extends \RuntimeException
{
}
