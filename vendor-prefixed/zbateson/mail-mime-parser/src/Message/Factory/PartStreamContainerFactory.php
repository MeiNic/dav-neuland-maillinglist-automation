<?php
/**
 * This file is part of the ZBateson\MailMimeParser project.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 * Modified by dav-neuland on 29-September-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace DavMlm\Vendor\ZBateson\MailMimeParser\Message\Factory;

use Psr\Log\LoggerInterface;
use DavMlm\Vendor\ZBateson\MailMimeParser\Message\PartStreamContainer;
use DavMlm\Vendor\ZBateson\MailMimeParser\Stream\StreamFactory;
use DavMlm\Vendor\ZBateson\MbWrapper\MbWrapper;

/**
 * Creates PartStreamContainer instances.
 *
 * @author Zaahid Bateson
 */
class PartStreamContainerFactory
{
    public function __construct(
        protected readonly LoggerInterface $logger,
        protected readonly StreamFactory $streamFactory,
        protected readonly MbWrapper $mbWrapper,
        protected readonly bool $throwExceptionReadingPartContentFromUnsupportedCharsets
    ) {
    }

    public function newInstance() : PartStreamContainer
    {
        return new PartStreamContainer(
            $this->logger,
            $this->streamFactory,
            $this->mbWrapper,
            $this->throwExceptionReadingPartContentFromUnsupportedCharsets
        );
    }
}
