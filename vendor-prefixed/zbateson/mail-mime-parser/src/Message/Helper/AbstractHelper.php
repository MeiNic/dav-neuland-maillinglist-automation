<?php
/**
 * This file is part of the ZBateson\MailMimeParser project.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 * Modified by dav-neuland on 29-September-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace DavMlm\Vendor\ZBateson\MailMimeParser\Message\Helper;

use DavMlm\Vendor\ZBateson\MailMimeParser\Message\Factory\IMimePartFactory;
use DavMlm\Vendor\ZBateson\MailMimeParser\Message\Factory\IUUEncodedPartFactory;

/**
 * Base class for message helpers.
 *
 * @author Zaahid Bateson
 */
abstract class AbstractHelper
{
    public function __construct(
        protected readonly IMimePartFactory $mimePartFactory,
        protected readonly IUUEncodedPartFactory $uuEncodedPartFactory
    ) {
    }

    /**
     * Replaces control characters in the passed header value with a space.
     */
    protected function stripControlChars(string $value) : string
    {
        return \preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '';
    }
}
