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

namespace HireVoice\Neo4j\PathFinder;

use Everyman\Neo4j\Relationship;
use HireVoice\Neo4j\EntityManager;
use Everyman\Neo4j\PathFinder as PathFinderImpl;
use HireVoice\Neo4j\Proxy\Entity as Proxy;

/**
 * Path Finder implements path finding functions
 *
 * @author Alex Belyaev <lex@alexbelyaev.com>
 */
class PathFinder
{
    protected $entityManager;

    protected $relationship;

    protected $maxDepth = null;

    protected $algorithm = null;

    public static function validateAlgorithm($name)
    {
        $algorithms = array(PathFinderImpl::AlgoShortest, PathFinderImpl::AlgoAll, PathFinderImpl::AlgoAllSimple);

        if (! in_array($name, $algorithms)) {
            throw new Exception(sprintf("Invalid path finding algorithm \"%s\"", $name));

        }
    }

    public function setEntityManager(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function __clone()
    {
        return array(
            'entityManager',
            'maxDepth',
            'algorithm',
        );
    }

    public function setMaxDepth($depth)
    {
        $this->maxDepth = (int) $depth;

        return $this;
    }

    public function setAlgorithm($algorithm)
    {
        self::validateAlgorithm($algorithm);
        $this->algorithm = $algorithm;

        return $this;
    }

    public function setRelationship($relationship)
    {
        $this->relationship = $relationship;

        return $this;
    }

    public function findPaths($a, $b)
    {
        $paths = $this->preparePaths($a, $b)->getPaths();

        $pathObjects = array();
        foreach ($paths as $path){
            $pathObjects[] = new Path($path, $this->entityManager);
        }

        return $pathObjects;
    }

    public function findSinglePath($a, $b)
    {
        $path = $this->preparePaths($a, $b)->getSinglePath();

        if ($path) {
            return new Path($path, $this->entityManager);
        }
    }

    protected function preparePaths($a, $b)
    {
        if (! $a instanceof Proxy) {
            $a = $this->entityManager->reload($a);
        }

        if (! $b instanceof Proxy) {
            $b = $this->entityManager->reload($b);
        }

        $startNode = $a->__getNode();
        $endNode = $b->__getNode();

        if (null === $this->relationship){
            $paths = $startNode->findPathsTo($endNode);
        } else {
            $paths = $startNode->findPathsTo($endNode, $this->relationship);
        }

        if ($this->maxDepth !== null) $paths->setMaxDepth($this->maxDepth);
        if ($this->algorithm !== null) $paths->setAlgorithm($this->algorithm);

        return $paths;
    }
}

