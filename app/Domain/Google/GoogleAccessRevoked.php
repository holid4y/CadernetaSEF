<?php

declare(strict_types=1);

namespace App\Domain\Google;

use RuntimeException;

// The person removed the access in their Google account, or it expired:
// only connecting again brings it back.
final class GoogleAccessRevoked extends RuntimeException {}
