<?php
/**
 * This file is part of the ZBateson\MailMimeParser project.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 * Modified by dav-neuland on 29-September-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace DavMlm\Vendor\ZBateson\MailMimeParser\Header\Part;

use Psr\Log\LoggerInterface;
use DavMlm\Vendor\ZBateson\MailMimeParser\Header\IHeaderPart;
use DavMlm\Vendor\ZBateson\MbWrapper\MbWrapper;

/**
 * Represents one parameter in a parsed 'Received' header, e.g. the FROM or VIA
 * part.
 *
 * Note that FROM and BY actually get parsed into a sub-class,
 * ReceivedDomainPart which keeps track of other sub-parts that can be parsed
 * from them.
 *
 * @author Zaahid Bateson
 */
class ReceivedPart extends NameValuePart
{
    /**
     * @param IHeaderPart[] $children
     */
    public function __construct(
        LoggerInterface $logger,
        MbWrapper $charsetConverter,
        string $name,
        array $children
    ) {
        parent::__construct($logger, $charsetConverter, [], $children);
        $this->name = $name;
    }
}
