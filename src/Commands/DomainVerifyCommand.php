<?php

namespace Pantheon\TerminusDomainChallenge\Commands;

use GuzzleHttp\Exception\ClientException;
use http\Env;
use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Exceptions\TerminusNotFoundException;
use Pantheon\Terminus\Models\Environment;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;

/**
 * Verify the domain status.
 */
class DomainVerifyCommand extends TerminusCommand implements SiteAwareInterface
{
    use SiteAwareTrait;

    /**
     * Verify the domain has been updated with the proper settings.
     *
     * @authorize
     *
     * @command domain:dns:verify:dns
     * @alias domain-verify-dns
     *
     * @param string $site_env Site & environment in the format `site-name.env`
     * @param string $domain Domain that needs to be validated.
     *
     * @usage <site>.<env> <domain> Verifies your ownership of <domain> by querying for a DNS TXT record.
     */
    public function verifyDns(string $site_env, string $domain)
    {
        $this->verifyChallenge($site_env, $domain, 'dns-01');
    }

    /**
     * Verify the domain has been updated with the proper settings.
     *
     * @authorize
     *
     * @command domain:dns:verify:file
     * @alias domain-verify-file
     *
     * @param string $site_env Site & environment in the format `site-name.env`
     * @param string $domain Domain that needs to be validated.
     *
     * @usage <site>.<env> <domain> Verifies your ownership of <domain> by querying for a DNS TXT record.
     */
    public function verifyFile(string $site_env, string $domain)
    {
        $this->verifyChallenge($site_env, $domain, 'http-01');
    }

    /**
     * Return the domain for the provided environment.
     *
     * @param string $site_env
     * @param string $domain
     *
     * @throws TerminusException
     */
    protected function getDomain(string $site_env, string $domain)
    {
        $env = $this->getEnv($site_env);
        $url = $env->getUrl() . '/domains/' . rawurlencode($domain);

        try {
            $data = $env->request()->request($url, ['method' => 'get', 'query' => ['acme_version' => 2]]);
        } catch (ClientException $e) {
            // Detect if this is just because the input domain is not on the site-env
            if ($e->getCode() == 404) {
                throw new TerminusNotFoundException(
                    "The domain {domain} has not been added to the site and environment.",
                    ['domain' => $domain],
                    $e->getCode()
                );
            }
            throw $e;
        }

        return $data['data'];
    }

    /**
     * @param Environment $env
     * @param string $domain
     * @param string $challenge_type
     * @return void
     * @throws TerminusException
     * @throws TerminusNotFoundException
     */
    protected function verifyChallenge(string $site_env, string $domain, string $challenge_type) {
        // Check if it's already been verified, and note current challenges.
        $data = $this->getDomain($site_env, $domain);

        // This happens if the domain status object could not be built by the deadline.
        // In this case, launch ownership verification, but we may not be able to poll.
        if (empty($data->{'ownership_status'})) {
            $status = "failed";
        } else {
            $status = $data->{'ownership_status'}->{'preprovision_result'}->status;
        }

        $current_challenge = '';
        if (!empty($data->acme_preauthorization_challenges)) {
            $current_challenge = $data->acme_preauthorization_challenges->$challenge_type->verification_value;
        }

        switch ($status) {
            case "success":
                $this->log()->notice("Ownership verification for {domain} is complete!", ['domain' => $domain]);
                return;
            case "failed":
                try {
                    $this->startVerification($site_env, $domain, $challenge_type);
                } catch (TerminusNotFoundException $e) {
                    $command = "terminus domain:add $site_env $domain";
                    $this->log()->notice('The domain {domain} has not been added to this site and environment. Use the command {command} to add it.', compact('domain', 'command'));
                    throw new TerminusException('Cannot verify challenge for missing domain.');
                }

                $this->log()->notice('The challenge for {domain} is being verified...', compact('domain'));
                break;
            case "in_progress":
                // The third possibility, we'll just start polling in this case.
        }

        $pollFailures = 0;
        for ($polls = 0; $polls < 15; $polls++) {
            sleep(10);
            try {
                $data = $this->getDomain($site_env, $domain);
            } catch (\Exception $e) {
                $pollFailures++;
                if ($pollFailures > 3) {
                    throw $e;
                }
                continue;
            }

            if (empty($data->{'ownership_status'})) {
                $pollFailures++;
                if ($pollFailures > 10) {
                    throw new TerminusException("Due to an error, we are temporarily unable to verify domain ownership.");
                }
                continue;
            }

            $status = $data->{'ownership_status'}->{'preprovision_result'}->status;
            switch ($status) {
                case 'success':
                    $this->log()->notice('Ownership verification is complete!');
                    $this->log()->notice('Your HTTPS certificate will be deployed to Pantheon\'s Global CDN shortly.');
                    return;
            }
        }

        $this->handleVerificationFailed($site_env, $domain, $current_challenge, $data, $challenge_type);
    }

    /**
     * Handle the verification error to the end user.
     *
     * @param string $site_env
     * @param string $domain
     * @param string $current_challenge
     * @param mixed $data
     * @param string $challenge_type
     *
     * @return mixed
     *
     * @throws TerminusException
     */
    protected function handleVerificationFailed(
        string $site_env,
        string $domain,
        string $current_challenge,
        mixed $data,
        string $challenge_type
    ) {
        // Display rich error information if we have any.
        $preprovision_result = $data->{'ownership_status'};
        $preprovision_result = $preprovision_result->{'preprovision_result'};
        $pantheon_docs = 'https://pantheon.io/docs/guides/launch/domains';
        $support_ref = '';
        // @todo Need to check if this is still relevant.
        if (!empty($preprovision_result->last_preprovision_problem)) {
            $problem = $preprovision_result->last_preprovision_problem;
            if (!empty($problem->PantheonDocsLink)) {
                $pantheon_docs = $problem->PantheonDocsLink;
            }
            if (!empty($problem->SupportReference)) {
                $support_ref = " with reference \"" . $problem->SupportReference . '"';
            }
            if (!empty($problem->PantheonTitle)) {
                $this->log()->notice($problem->PantheonTitle);
            }
            if (!empty($problem->PantheonDetail)) {
                $this->log()->notice($problem->PantheonDetail);
            }
            if (!empty($problem->PantheonActionItem)) {
                $this->log()->notice($problem->PantheonActionItem);
            }

            if (!empty($problem->Detail) || !empty($problem->ProblemType)) {
                $this->log()->notice('');
                $detail = '';
                if (!empty($problem->ProblemType)) {
                    $detail = $detail . "\n" . $problem->ProblemType;
                }
                if (!empty($problem->Detail)) {
                    $detail = $detail . "\n" . $problem->Detail;
                }
                $this->log()->notice("Raw verification result:$detail");
            }
        } else {
            $this->log()->notice('Double-check that your challenge is being served correctly.');
        }

        $this->log()->notice('See {link} for assistance', ['link' => $pantheon_docs]);
        $this->log()->notice("or contact Pantheon Support$support_ref.");

        // Warn if ownership verification has become unavailable.
        // (Typically user has attempted more times than LE allows per hour)
        if ($data->ownership_status->status == 'unavailable' && !empty($data->ownership_status->message)) {
            $this->log()->warning($data->ownership_status->message);
        }

        // Warn if the challenge had to be changed.
        if (!empty($data->acme_preauthorization_challenges)) {
            $new_challenge = $data->acme_preauthorization_challenges->$challenge_type->verification_value;
            if ($new_challenge != $current_challenge) {
                $this->log()->warning('The old challenge cannot be tried again.');
                if ($challenge_type == 'dns-01') {
                    $txt_record = $data->{'acme_preauthorization_challenges'}->{'dns-01'}->{'verification_value'};
                    $this->log()->warning("Please update your DNS to serve the new challenge below:\n$txt_record");
                }
                if ($challenge_type == 'http-01') {
                    $this->log()->warning('Please run {command} again to obtain a new challenge file.',
                        ['command' => "terminus domain:dns:challenge $site_env --filter='domain=$domain'"]
                    );
                }
            }
        }

        throw new TerminusException('Ownership verification was not successful.');
    }

    /**
     * Sends a request to trigger backend async verification of the challenge.
     *
     * @param string $site_env
     * @param string $domain
     * @param string $challenge_type
     *
     * @throws TerminusNotFoundException
     */
    protected function startVerification(string $site_env, string $domain, string $challenge_type)
    {
        $env = $this->getEnv($site_env);
        $url = $env->getUrl() . '/domains/' . rawurlencode($domain) . '/' . 'verify-ownership';
        $body = [
            'challenge_type' => $challenge_type,
            'client' => 'terminus-plugin', // Only in case we want statistics
        ];
        try {
            $env->request()->request($url, ['method' => 'POST', 'form_params' => $body]);
        } catch (ClientException $e) {
            // Detect if this is just because the input domain is not on the site-env
            if ($e->getCode() == 404) {
                throw new TerminusNotFoundException(
                    "The domain {domain} has not been added to the site and environment.",
                    ['domain' => $domain],
                    $e->getCode()
                );
            }
            throw $e;
        }
    }
}