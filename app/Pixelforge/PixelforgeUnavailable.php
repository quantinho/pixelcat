<?php

namespace App\Pixelforge;

use RuntimeException;

/**
 * We don't know what happened: a timeout, a connection error or an unexpected response.
 * For a create request, Pixelforge may have created (and will bill) the generation,
 * so the caller must resend with the same Idempotency-Key, never give up silently.
 */
class PixelforgeUnavailable extends RuntimeException {}
