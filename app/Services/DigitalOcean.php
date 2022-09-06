<?php

namespace App\Services;

use Exception;
use http\Exception\InvalidArgumentException;
use Illuminate\Support\Facades\Http;

class DigitalOcean
{
    /**
     * @var string
     */
    private string $_API_TOKEN;

    /**
     * @var string
     */
    protected static string $_DROPLETS = 'https://api.digitalocean.com/v2/droplets';

    protected static string $_REGIONS = 'https://api.digitalocean.com/v2/regions';

    protected static string $_SIZES = 'https://api.digitalocean.com/v2/sizes';

    protected static string $_IMAGES = 'https://api.digitalocean.com/v2/images';

    protected static string $_KEYS = 'https://api.digitalocean.com/v2/account/keys';

    /**
     * DigitalOcean constructor.
     *
     * @param  string  $api_token DO API token
     */
    public function __construct(string $api_token)
    {
        $this->_API_TOKEN = $api_token;
    }

    /**
     * @return null
     *
     * @throws Exception
     */
    public function getRegions()
    {
        $response = $this->get(self::$_REGIONS, []);

        if ($response->status() != 200) {
            throw new Exception('Invalid response code.');
        }

        return $response->json()['regions'];
    }

    /**
     * @return null
     *
     * @throws Exception
     */
    public function getSizes()
    {
        $response = $this->get(self::$_SIZES.'?per_page=200');

        if ($response->status() != 200) {
            throw new Exception('Invalid response code.');
        }

        return $response->json()['sizes'];
    }

    /**
     * @param  string  $type
     * @return null
     *
     * @throws \Exception
     */
    public function getImages(string $type = 'distribution')
    {
        $response = $this->get(self::$_IMAGES."?type=$type", []);

        if ($response->status() != 200) {
            throw new Exception('Invalid response code.');
        }

        return $response->json()['images'];
    }

    /**
     * Gets all droplets
     *
     * @return array | null
     *
     * @throws \Exception
     */
    public function getDroplets()
    {
        $response = $this->get(self::$_DROPLETS, []);

        if ($response->status() != 200) {
            throw new Exception('Invalid response code.');
        }

        return $response->json()['droplets'];
    }

    /**
     * Gets information about a single droplet
     *
     * @param $droplet_id
     * @return Json
     *
     * @throws Exception
     */
    public function getDroplet($droplet_id)
    {
        $response = $this->get(self::$_DROPLETS.'/'.$droplet_id, []);

        if (! in_array($response->status(), [200, 202, 204])) {
            throw new Exception('Invalid response code.');
        }

        return $response->json()['droplet'];
    }

    /**
     * Creates a new droplet.
     *
     * @param  array  $params Droplet parameters. The only mandatory item is 'name'.
     * @return mixed
     *
     * @throws \Exception
     */
    public function createDroplet(array $params)
    {
        if (! isset($params['name'])) {
            throw new InvalidArgumentException("Missing the 'name' parameter.");
        }

        $response = $this->post(self::$_DROPLETS, $params);

        if (! in_array($response->status(), [200, 202, 204])) {
            throw new Exception('An API error occurred.');
        }

        return $response->json();
    }

    /**
     * Creates a new SSH.
     *
     * @todo fix an issue where if the key exists it will throw an error
     *
     * @param  array  $params
     * @return mixed
     *
     * @throws \Exception
     */
    public function createSSH(array $params)
    {
        if (! isset($params['name']) || ! isset($params['public_key'])) {
            throw new Exception("Missing the 'name' or 'key' parameter.");
        }

        $response = $this->post(self::$_KEYS, $params);

        if (! in_array($response->status(), [201])) {
            throw new Exception('An API error occurred.');
        }

        return $response->json()['ssh_key'];
    }

    /**
     * Delete an SSH Key
     *
     * @param  string  $fingerprint
     * @return bool
     *
     * @throws \Exception
     */
    public function deleteSSHKey(string $fingerprint)
    {
        $response = $this->delete(self::$_KEYS.'/'.$fingerprint);

        if (! in_array($response->status(), [204])) {
            return false;
        }

        return true;
    }

    /**
     * Destroys a Droplet
     *
     * @param $droplet_id
     * @return bool
     *
     * @throws Exception
     */
    public function destroyDroplet($droplet_id): bool
    {
        $response = $this->delete(self::$_DROPLETS.'/'.$droplet_id);

        if (! in_array($response->status(), [200, 202, 204])) {
            return false;
        }

        return true;
    }

    /**
     * Makes a GET query
     *
     * @param  string  $endpoint API endpoint
     * @param  array  $custom_headers optional custom headers
     * @return mixed
     */
    public function get(string $endpoint, array $custom_headers = [])
    {
        return Http::withToken($this->_API_TOKEN)
            ->get($endpoint, $custom_headers);
    }

    /**
     * Makes a POST query
     *
     * @param  string  $endpoint
     * @param  array  $params
     * @return \GuzzleHttp\Promise\PromiseInterface|\Illuminate\Http\Client\Response
     */
    public function post(string $endpoint, array $params)
    {
        return Http::withToken($this->_API_TOKEN)
            ->post($endpoint, $params);
    }

    /**
     * Makes a DELETE query
     *
     * @param  string  $endpoint
     * @return \GuzzleHttp\Promise\PromiseInterface|\Illuminate\Http\Client\Response
     */
    public function delete(string $endpoint)
    {
        return Http::withToken($this->_API_TOKEN)
            ->delete($endpoint);
    }
}
