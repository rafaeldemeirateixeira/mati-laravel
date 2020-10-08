<?php

namespace WeDevBr\Mati;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use LogicException;
use TypeError;
use WeDevBr\Mati\Support\Contracts\IdentityInputInterface;
use WeDevBr\Mati\Support\Contracts\MatiClientInterface;

/**
 * Mati HTTP client
 *
 * @author Gabriel Mineiro <gabrielpfgmineiro@gmail.com>
 */
class MatiHttpClient implements MatiClientInterface
{
    /**
     * Bearer token used in API calls
     *
     * @var string
     */
    protected $access_token;

    public function __construct(string $access_token = null)
    {
        $this->access_token = $access_token;
    }

    /**
     * Set an access token to be used by the requests
     *
     * @param string $access_token
     * @return self
     */
    public function withToken(string $access_token): self
    {
        $this->access_token = $access_token;
        return $this;
    }

    /**
     * Get an access token from the OAuth service
     *
     * @param string $client_id
     * @param string $client_user
     * @throws RequestException
     * @return Response
     */
    public function getAccessToken(string $client_id, string $client_secret): Response
    {
        return Http::withBasicAuth($client_id, $client_secret)
            ->asForm()
            ->post($this->getAuthURL(), ['grant_type' => 'client_credentials'])
            ->throw();
    }

    /**
     * Create a new identity for a user that will be verified
     *
     * @param array|null $metadata Key/Value pair of data to identify the user
     * @param string|null $flowId
     * @param string|null $user_ip
     * @throws RequestException|LogicException
     * @return Response
     */
    public function createIdentity($metadata = null, $flowId = null, $user_ip = null): Response
    {
        if (!$this->access_token) {
            throw new LogicException('No access token given to create identity');
        }

        $payload = [];
        $request = Http::withToken($this->access_token);

        if ($metadata) {
            $payload['metadata'] = $metadata;
        }

        if ($flowId) {
            $payload['flowId'] = $flowId;
        }

        if ($user_ip) {
            $request->withHeaders(['X-Forwarded-For' => $user_ip]);
        }

        return $request->post($this->getApiUrl() . '/identities', $payload)
            ->throw();
    }

    /**
     * Send an input for a document, selfie or other file required during a process
     *
     * @param string $identity_id
     * @param IdentityInputInterface[]|Collection $inputs
     *
     * @throws LogicException|RequestException
     * @return Response
     */
    public function sendInput(string $identity_id, $inputs): Response
    {
        if (!$this->access_token) {
            throw new LogicException('No access token given to send input');
        }

        $inputs_collection = null;

        if (is_array($inputs)) {
            $inputs_collection = collect($inputs);
        } elseif ($inputs instanceof Collection) {
            $inputs_collection = $inputs;
        } else {
            throw new TypeError('Inputs param must be an array or a Collection');
        }

        if (
            !$inputs_collection->every(function ($input) {
                return $input instanceof IdentityInputInterface;
            })
        ) {
            throw new TypeError('Every item of inputs must be instance of IdentityInputInterface');
        }

        $request = Http::withToken($this->access_token)
            ->asMultipart();

        foreach ($inputs_collection as $input) {
            $request->attach('document', $input->getFileContents(), $input->getFileName());
        }

        return $request->post(
            $this->getApiUrl() . "/identities/$identity_id/send-input",
            ['inputs' => $inputs_collection->toJson()]
        )
            ->throw();
    }

    /**
     * Get auth API URL
     *
     * @return string
     */
    protected function getAuthUrl()
    {
        return config('mati')['auth_url'];
    }

    /**
     * Get REST API URL
     *
     * @return string
     */
    protected function getApiUrl()
    {
        return config('mati')['api_url'];
    }
}
