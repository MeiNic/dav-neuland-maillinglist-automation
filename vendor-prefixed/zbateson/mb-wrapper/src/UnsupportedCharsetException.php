<?php

/**
 * This file is part of the ZBateson\MailMimeParser project.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 * Modified by dav-neuland on 29-September-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace DavMlm\Vendor\ZBateson\MbWrapper;

use RuntimeException;

/**
 * Exception thrown if MbWrapper can't convert from or two a specified charset.
 *
 * @author Zaahid Bateson
 */
class UnsupportedCharsetException extends RuntimeException
{
}
