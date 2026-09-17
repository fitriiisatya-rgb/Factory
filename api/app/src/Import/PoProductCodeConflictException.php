<?php

declare(strict_types=1);

namespace Amor\Api\Import;

/**
 * Thrown by PoResolver::createProductFromUnresolved() when the raw legacy
 * code being used to create a brand-new product already belongs to a
 * DIFFERENT product — a genuine data conflict, never resolved by guessing.
 * A distinct class (not a plain RuntimeException) so the wizard/API can
 * show the specific PRODUCT_CODE_CONFLICT outcome the task requires. One
 * class per file — required for the autoloader (autoload.php maps
 * Amor\Api\X to src/X.php) to find it at all.
 */
final class PoProductCodeConflictException extends \RuntimeException
{
}
