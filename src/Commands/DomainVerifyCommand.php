<?php

namespace Pantheon\TerminusDomainChallenge\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;

/**
 * Verify the domain status.
 */
class DomainVerifyCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;


}