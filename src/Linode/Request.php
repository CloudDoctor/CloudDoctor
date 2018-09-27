<?php

namespace CloudDoctor\Linode;

use GuzzleHttp\Client as GuzzleClient;

class Request extends \CloudDoctor\Common\Request
{

    public function __construct($config)
    {
        parent::__construct();
        $this->guzzle = new GuzzleClient([
            'base_uri' => 'https://api.linode.com/v4/',
            'headers' => [
                'User-Agent' => 'CloudDoctor',
                'Accept' => 'application/json',
                'Authorization' => "Bearer {$config['api-key']}",
            ],
        ]);
    }
}