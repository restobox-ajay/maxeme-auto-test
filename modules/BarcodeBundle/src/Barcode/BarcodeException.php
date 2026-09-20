<?php

declare(strict_types=1);

namespace BarcodeBundle\Barcode;

/**
 * A refusal the person at the screen can act on (#607).
 *
 * Every message this carries names the value it would not accept and says what to do instead,
 * because the alternative on a barcode screen is a product silently carrying an identifier that
 * scans as something else.
 */
final class BarcodeException extends \RuntimeException
{
}
