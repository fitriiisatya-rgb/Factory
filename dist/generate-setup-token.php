<?php

declare(strict_types=1);

/**
 * Convenience helper: prints one random hex string suitable for
 * app/config/config.php's SETUP_TOKEN value. Not part of the deployed
 * package — run it locally (or in cPanel Terminal, if available) and paste
 * the output into config.php by hand. Never writes anything to a file
 * itself, so there is nothing here that could leak a token to the wrong
 * place.
 *
 * Usage: php dist/generate-setup-token.php
 */

echo bin2hex(random_bytes(32)) . "\n";
