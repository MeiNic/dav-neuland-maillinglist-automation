<?php
/**
 * This file is part of the ZBateson\MailMimeParser project.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 *
 * Modified by dav-neuland on 29-September-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace DavMlm\Vendor\ZBateson\MailMimeParser\Message\Factory;

use DavMlm\Vendor\ZBateson\MailMimeParser\Message\PartChildrenContainer;

/**
 * Creates PartChildrenContainer instances.
 *
 * @author Zaahid Bateson
 */
class PartChildrenContainerFactory
{
    public function newInstance() : PartChildrenContainer
    {
        return new PartChildrenContainer();
    }
}
