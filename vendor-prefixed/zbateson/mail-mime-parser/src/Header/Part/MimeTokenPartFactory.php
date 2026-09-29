<?php
/**
 * This file is part of the ZBateson\MailMimeParser project.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 * Modified by dav-neuland on 29-September-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace DavMlm\Vendor\ZBateson\MailMimeParser\Header\Part;

use DavMlm\Vendor\ZBateson\MailMimeParser\Header\IHeaderPart;

/**
 * Extends HeaderPartFactory to instantiate MimeTokens for its
 * newInstance method.
 *
 * @author Zaahid Bateson
 */
class MimeTokenPartFactory extends HeaderPartFactory
{
    /**
     * Creates and returns a MimeToken.
     */
    public function newInstance(string $value) : IHeaderPart
    {
        return $this->newMimeToken($value);
    }
}
