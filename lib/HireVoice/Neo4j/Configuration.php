<?php
/**
 * Copyright (C) 2012 Louis-Philippe Huberdeau
 *
 * Permission is hereby granted, free of charge, to any person obtaining a
 * copy of this software and associated documentation files (the "Software"),
 * to deal in the Software without restriction, including without limitation
 * the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included
 * in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
 * DEALINGS IN THE SOFTWARE.
 */

namespace HireVoice\Neo4j;

use Everyman\Neo4j\Client;
use Everyman\Neo4j\Transport;
use HireVoice\Neo4j\PathFinder\PathFinder;

class Configuration
{

    const MAX_RECONNECT = 2;

    private $transport = 'default';
    private $host = 'localhost';
    private $port = 7474;
    private $proxyDir = '/tmp';
    private $debug = false;
    private $annotationReader;
    private $username;
    private $password;
    private $slaves;

    private $pathfinderAlgorithm = null;
    private $pathfinderMaxDepth = null;

    function __construct(array $configs = array())
    {
        if (isset($configs['transport'])) {
            $this->transport = $configs['transport'];
        }

        if (isset($configs['host'])) {
            $this->host = $configs['host'];
        }

        if (isset($configs['port'])) {
            $this->port = (int) $configs['port'];
        }

        if (isset($configs['slaves'])) {
            $this->slaves = $configs['slaves'];
        }

        if (isset($configs['debug'])) {
            $this->debug = (bool) $configs['debug'];
        }

        if (isset($configs['proxy_dir'])) {
            $this->proxyDir = $configs['proxy_dir'];
        }

        if (isset($configs['annotation_reader'])) {
            $this->annotationReader = $configs['annotation_reader'];
        }

        if (isset($configs['username'], $configs['password'])) {
            $this->username = $configs['username'];
            $this->password = $configs['password'];
        }

        if (isset($configs['pathfinder_algorithm'])) {
            PathFinder::validateAlgorithm($configs['pathfinder_algorithm']);
            $this->pathfinderAlgorithm = $configs['pathfinder_algorithm'];
        }

        if (isset($configs['pathfinder_maxdepth'])) {
            $this->pathfinderMaxDepth = (int) $configs['pathfinder_maxdepth'];
        }
    }

    function getClient()
    {
        $transport = $this->getTransport();
        $transport->setAuth($this->username, $this->password);

        return new Client($transport);
    }

    private function checkConnection($host, $port)
    {
        $url = 'http://' . $host . ':' . $port . '/db/data/';
        $client = new \GuzzleHttp\Client();

        $request = new \GuzzleHttp\Message\Request('GET', $url, array(
            'Accept' => 'application/json; charset=UTF-8'
        ));

        try {
            $response = $client->send($request);
            return $response->getStatusCode() == 200;
        } catch (\Exception $e) {

        }

        return false;
    }

    private function getTransport()
    {
        $host = $this->host;
        $port = $this->port;

        if (!empty($this->slaves)) {
            $servers = array_merge($this->slaves, array(array(
                'host' => $host,
                'port' => $port
            )));

            $trys = 0;
            while (true) {
                if ($trys >= self::MAX_RECONNECT) {

                }
                $key = array_rand($servers);
                $slave = $servers[$key];

                $host = $slave['host'];
                $port = $slave['port'];

                if ($this->checkConnection($host, $port)) {
                    break;
                }

                if ($trys >= self::MAX_RECONNECT) {
                    throw new \Exception('could not connect');
                }

                $trys++;
            }
        }

        switch ($this->transport) {
            case 'stream':
                return new Transport\Stream($host, $port);
            case 'curl':
            default:
                return new Transport\Curl($host, $port);
        }
    }

    function getProxyFactory()
    {
        return new Proxy\Factory($this->proxyDir, $this->debug);
    }

    function getMetaRepository()
    {
        return new Meta\Repository($this->annotationReader);
    }

    function configurePathFinder(PathFinder $finder)
    {
        if ($this->pathfinderAlgorithm) {
            $finder->setAlgorithm($this->pathfinderAlgorithm);
        }

        if ($this->pathfinderMaxDepth) {
            $finder->setMaxDepth($this->pathfinderMaxDepth);
        }
    }
}

