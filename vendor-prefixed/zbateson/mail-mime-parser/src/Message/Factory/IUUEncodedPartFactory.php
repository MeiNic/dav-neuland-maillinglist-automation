<?php
/**
 * This file is part of the ZBateson\MailMimeParser project.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 * Modified by dav-neuland on 29-September-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace DavMlm\Vendor\ZBateson\MailMimeParser\Message\Factory;

use DavMlm\Vendor\ZBateson\MailMimeParser\Message\IMimePart;
use DavMlm\Vendor\ZBateson\MailMimeParser\Message\IUUEncodedPart;
use DavMlm\Vendor\ZBateson\MailMimeParser\Message\UUEncodedPart;

/**
 * Responsible for creating UUEncodedPart instances.
 *
 * @author Zaahid Bateson
 */
class IUUEncodedPartFactory extends IMessagePartFactory
{
    /**
     * Constructs a new UUEncodedPart object and returns it
     */
    public function newInstance(?IMimePart $parent = null) : IUUEncodedPart
    {
        $streamContainer = $this->partStreamContainerFactory->newInstance();
        $part = new UUEncodedPart(
            null,
            null,
            $parent,
            $this->logger,
            $streamContainer,
            $this->defaultFallbackCharset
        );
        $streamContainer->setStream($this->streamFactory->newMessagePartStream($part));
        return $part;
    }
}
