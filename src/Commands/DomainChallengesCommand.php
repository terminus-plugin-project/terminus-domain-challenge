<?php

namespace Pantheon\TerminusDomainChallenge\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;

/**
 * Get Information Related to the Domains Added.
 */
class DomainChallengesCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;

    /**
     * Look up Challenge Records for the Provided Domains.
     *
     * @authorize
     * @filter-output
     *
     * @command domain:dns:challenge
     *
     * @field-labels
     *     domain: Domain
     *     http_status: HTTP Status
     *     http_key: HTTP Name
     *     http_token: HTTP Token
     *     dns_status: DNS Status
     *     dns_key: DNS Key
     *     dns_token: DNS Token
     * @param string $site_env Site & environment in the format `site-name.env`
     *
     * @usage <site>.<env> Displays recommended DNS Challenges for <site>'s <env> environment.
     *
     * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
     *
     * @throws \Pantheon\Terminus\Exceptions\TerminusException
     */
    public function getDomainChallenges($site_env)
    {
        $env = $this->getEnv($site_env);
        $domains = $env->getDomains()->filter(
            function ($domain) {
                return $domain->get('type') === 'custom';
            }
        )->all();

        $data = [];
        foreach ($domains as $domain) {
            $acmeValues = (array)$domain->get('acme_preauthorization_challenges');
            array_walk($acmeValues, function (&$item) {
                $item = (array)$item;
            });

            $data[] = [
                'domain' => $domain->id,
                'http_status' => $acmeValues['http-01']['status'],
                'http_key' => $acmeValues['http-01']['verification_key'],
                'http_token' => $acmeValues['http-01']['token'],
                'dns_status' => $acmeValues['dns-01']['status'],
                'dns_key' => $acmeValues['dns-01']['verification_key'],
                'dns_token' => $acmeValues['dns-01']['token'],
            ];
        }

        if (count($data) === 0) {
            $this->log->warning("You have no Domains that match the following.");
        }

        // Sort by Domain Name.
        usort($data, function ($a, $b) {
            if ($a['domain'] === $b['domain']) {
                return 0;
            }
            return $a['domain'] > $b['domain'] ? 1 : -1;
        });
        
        return new RowsOfFields($data);
    }
}
