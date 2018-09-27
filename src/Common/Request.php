<?php

namespace CloudDoctor\Common;

use GuzzleHttp\Client as GuzzleClient;

class Request
{
    /** @var GuzzleClient */
    protected $guzzle;

    public function __construct()
    {
        $this->guzzle = new GuzzleClient([]);
    }

    public function postJson(string $endpoint, array $json): \StdClass
    {
        $endpoint = ltrim($endpoint, "/");
        return json_decode($this->guzzle->request('POST', $endpoint, ['json' => $json])->getBody()->getContents(), false);
    }

    public function deleteJson(string $endpoint): \StdClass
    {
        $endpoint = ltrim($endpoint, "/");
        return json_decode($this->guzzle->request('DELETE', $endpoint)->getBody()->getContents(), false);
    }

    public function getJson(string $endpoint): \StdClass
    {
        return json_decode($this->get($endpoint), false);
    }

    public function get(string $endpoint): string
    {
        $endpoint = ltrim($endpoint, "/");
        return $this->guzzle->request('GET', $endpoint)->getBody()->getContents();
    }
}