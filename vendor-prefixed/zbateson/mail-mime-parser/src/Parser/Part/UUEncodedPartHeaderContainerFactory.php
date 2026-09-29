<?php
/**
 * This file is part of the ZBateson\MailMimeParser project.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 * Modified by dav-neuland on 29-September-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace DavMlm\Vendor\ZBateson\MailMimeParser\Parser\Part;

use Psr\Log\LoggerInterface;
use DavMlm\Vendor\ZBateson\MailMimeParser\Header\HeaderFactory;

/**
 * Creates UUEncodedPartHeaderContainer instances.
 *
 * @author Zaahid Bateson
 */
class UUEncodedPartHeaderContainerFactory
{
    public function __construct(
        protected readonly LoggerInterface $logger,
        protected readonly HeaderFactory $headerFactory
    ) {
    }

    /**
     * Creates and returns a UUEncodedPartHeaderContainer.
     */
    public function newInstance(int $mode, string $filename) : UUEncodedPartHeaderContainer
    {
        $container = new UUEncodedPartHeaderContainer($this->logger, $this->headerFactory);
        $container->setUnixFileMode($mode);
        $container->setFilename($filename);
        return $container;
    }
}
