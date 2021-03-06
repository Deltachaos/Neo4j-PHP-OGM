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

use Doctrine\Common\EventManager;
use Everyman\Neo4j\Client,
    Everyman\Neo4j\Node,
    Everyman\Neo4j\Relationship,
    Everyman\Neo4j\Label,
    Everyman\Neo4j\Index\NodeIndex,
    Everyman\Neo4j\Gremlin\Query as InternalGremlinQuery,
    Everyman\Neo4j\Cypher\Query as InternalCypherQuery;
use Everyman\Neo4j\Index\NodeFulltextIndex;
use HireVoice\Neo4j\Event as Events;
use HireVoice\Neo4j\Event\Event;

/**
 * The entity manager handles the communication with the database server and
 * keeps track of the various entities in the system.
 */
class EntityManager
{
    const ENTITY_CREATE = 'entity.create';
    const RELATION_CREATE = 'relation.create';
    const RELATION_UPDATE = 'relation.update';
    const QUERY_RUN = 'query.run';
    const NODE_INDEX = 'node';
    const FULLTEXT_INDEX = 'fulltext';

    private $client;
    private $metaRepository;
    private $proxyFactory;
    private $batch;

    private $entities = array();

    private $entitiesToRemove = array();

    private $nodes = array();
    private $repositories = array();
    private $indexes = array();

    private $loadedNodes = array();

    private $dateGenerator;

    /**
     * @var EventManager
     */
    private $eventManager;

    private $pathFinder;

    /**
     * Initialize the entity manager using the provided configuration.
     * Configuration options are detailed in the Configuration class.
     *
     * @param Configuration|array $configuration Various information about how the entity manager should behave.
     */
    function __construct($configuration = null)
    {
        if (is_null($configuration)) {
            $configuration = new Configuration;
        } elseif (is_array($configuration)) {
            $configuration = new Configuration($configuration);
        } elseif (!$configuration instanceof Configuration) {
            throw new Exception('Provided argument must be a Configuration object or an array.');
        }

        $this->proxyFactory = $configuration->getProxyFactory();
        $this->client = $configuration->getClient();
        $this->metaRepository = $configuration->getMetaRepository();

        $this->dateGenerator = function () {
            $currentDate = new \DateTime;

            return $currentDate->format('Y-m-d H:i:s');
        };

        $this->pathFinder = new PathFinder\PathFinder;
        $this->pathFinder->setEntityManager($this);
        $configuration->configurePathFinder($this->pathFinder);
    }

    /**
     * @param EventManager $eventManager
     */
    public function setEventManager(EventManager $eventManager)
    {
        $this->eventManager = $eventManager;
    }

    /**
     * @return EventManager
     */
    public function getEventManager()
    {
        if ($this->eventManager === null) {
            $this->eventManager = new EventManager();
        }
        return $this->eventManager;
    }

    /**
     * Includes an entity to persist on the next flush. Persisting entities will cause
     * relations to be followed to discover other entities. Relation traversal will happen
     * during the flush.
     *
     * @param object $entity Any object providing the correct Entity annotations.
     */
    function persist($entity)
    {
        $meta = $this->getMeta($entity);

        $hash = $this->getHash($entity);
        $this->entities[$hash] = $entity;
    }

    function remove($entity)
    {
        $hash = $this->getHash($entity);
        $this->entitiesToRemove[$hash] = $entity;
    }

    private function removeEntities()
    {
        $this->begin();
        foreach ($this->entitiesToRemove as $entity){
            $this->dispatchEvent(new Events\PreRemove($entity));
            $meta = $this->getMeta($entity);
            $pk = $meta->getPrimaryKey();
            $id = $pk->getValue($entity);
            if ($id !== null){
                $node = $this->client->getNode($id);

                if($node){
                    $class = $meta->getName();
                    $index = $this->getRepository($class)->getIndex();
                    $index->remove($node);

                    $relationships = $node->getRelationships();
                    foreach ($relationships as $relationship){
                        $relationship->delete();
                    }

                    $node->delete();
                }
            }
            $this->dispatchEvent(new Events\PostRemove($entity));
        }

        $this->entitiesToRemove = Array();

        $this->commit();
    }

    /**
     * Commit changes in the object model into the database. Relations will be traversed
     * to discover additional entities. To include an object in the unit of work, use the
     * persist() method.
     */
    function flush()
    {
        $this->discoverEntities();
        $this->writeEntities();
        $this->writeRelations();
        $this->writeIndexes();

        $this->removeEntities();

        $this->entities = array();
        $this->nodes = array();
    }

    /**
     * Searches a single entity by ID for a given class name. The result will be provided
     * as a proxy node to handle lazy loading of relations.
     *
     * @param string $class The fully qualified class name
     * @param int $id The node ID
     * @return object The entity object
     */
    function find($class, $id)
    {
        return $this->getRepository($class)->find($id);
    }

    /**
     * Searches a single entity by ID, regardless of the class used. The result will be
     * provided as a proxy
     *
     * @param int $id The node ID
     */
    function findAny($id)
    {
        if ($node = $this->client->getNode($id)) {
            return $this->load($node);
        }

        return false;
    }

    /**
     * @access private
     */
    function load($node)
    {
        if (! isset($this->loadedNodes[$node->getId()])) {
            $em = $this;

            $entity = $this->proxyFactory->fromNode($node, $this->metaRepository, function ($node) use ($em) {
                return $em->load($node);
            });

            $this->loadedNodes[$node->getId()] = $entity;
            $this->nodes[$this->getHash($entity)] = $node;
        }

        return $this->loadedNodes[$node->getId()];
    }

    /**
     * Reload an entity. Exchanges an raw entity or an invalid proxy with an initialized
     * proxy.
     *
     * @param object $entity Any entity or entity proxy
     */
    function reload($entity)
    {
        if ($entity instanceof Proxy\Entity) {
            return $this->load($this->findAny($entity->getId()));
        } else {
            return $this->find(get_class($entity), $entity->getId());
        }
    }

    /**
     * Clear entity cache.
     */
    function clear()
    {
        $this->loadedNodes = array();
    }

    /**
     * Provide a Gremlin query builder.
     *
     * @param string $query Initial query fragment.
     * @return Query\Gremlin
     */
    function createGremlinQuery($query = null)
    {
        $q = new Query\Gremlin($this);

        if ($query) {
            $q->add($query);
        }

        return $q;
    }

    /**
     * Raw gremlin query execution. Used by Query\Gremlin.
     *
     * @param string $string The query string.
     * @param array $parameters The arguments to bind with the query.
     * @return \Everyman\Neo4j\Query\ResultSet
     */
    function gremlinQuery($string, $parameters)
    {
        try {
            $start = microtime(true);

            $query = new InternalGremlinQuery($this->client, $string, $parameters);
            $this->dispatchEvent(new Events\PreStmtExecute($query, $parameters));
            $rs = $query->getResultSet();

            $time = microtime(true) - $start;
            $this->dispatchEvent(new Events\PostStmtExecute($query, $parameters, $time));

            if (count($rs) === 1
                && is_string($rs[0][0])
                && strpos($rs[0][0], 'Exception') !== false
            ) {
                throw new Exception("An error was detected: {$rs[0][0]}", 0, null, $query);
            }

            return $rs;
        } catch (\Everyman\Neo4j\Exception $e) {
            throw new Exception("An error was detected: {$e->getMessage()}", 0, null, $query);
        }
    }

    /**
     * Provide a Cypher query builder.
     *
     * @return Query\Cypher
     */
    function createCypherQuery()
    {
        return new Query\Cypher($this);
    }

    /**
     * Raw cypher query execution.
     *
     * @param string $string The query string.
     * @param array $parameters The arguments to bind with the query.
     * @return \Everyman\Neo4j\Query\ResultSet
     */
    function cypherQuery($string, array $parameters = array())
    {
        try {
            $start = microtime(true);

            $query = new InternalCypherQuery($this->client, $string, $parameters);
            $this->dispatchEvent(new Events\PreStmtExecute($query, $parameters));
            $rs = $query->getResultSet();

            $time = microtime(true) - $start;
            $this->dispatchEvent(new Events\PostStmtExecute($query, $parameters, $time));

            return $rs;
        } catch (\Everyman\Neo4j\Exception $e) {
            $message = $e->getMessage();
            if (preg_match('/\[message\] => (.*)/', $message, $parts)) {
                throw new Exception('Query execution failed: ' . $parts[1], 0, $e, $query);
            } else {
                throw new Exception('Query execution failed: ' . $message, 0, $e, $query);
            }
        }
    }

    /**
     * Obtain an entity repository for a single class. The repository provides
     * multiple methods to access nodes and can be extended per entity by
     * specifying the correct annotation.
     *
     * @param string $class Fully qualified class name
     * @return Repository
     */
    function getRepository($class)
    {
        if (! isset($this->repositories[$class])) {
            $meta = $this->metaRepository->fromClass($class);
            $repositoryClass = $meta->getRepositoryClass();
            $repository = new $repositoryClass($this, $meta);

            if (! $repository instanceof Repository) {
                throw new Exception("Requested repository class $repositoryClass does not extend the base repository class.");
            }

            $this->repositories[$class] = $repository;
        }

        return $this->repositories[$class];
    }

    /**
     * Register an event listener for a given event.
     *
     * @param string $eventName The event to listen, available as constants.
     * @deprecated
     */
    function registerEvent($eventName, $callback)
    {
        trigger_error(
            'Function HireVoice\Neo4j\EntityManager::registerEvent is deprecated. Use the Doctrine EventManager to register listeners.',
            E_DEPRECATED
        );
    }

    /**
     * Dispatches a doctrine event
     *
     * @see \Doctrine\Common\EventManager::dispatchEvent
     * @param Event $event
     * @throws \RuntimeException
     */
    private function dispatchEvent(Event $event)
    {
        $this->getEventManager()->dispatchEvent($event->getEventName(), $event);
    }

    private function discoverEntities()
    {
        do {
            $entities = $this->entities;

            foreach ($entities as $entity) {
                $this->discoverEntitiesOn($entity);
            }

        } while (count($this->entities) != count($entities));
    }

    private function discoverEntitiesOn($entity)
    {
        $em = $this;

        $this->traverseRelations($entity, function ($entry) use ($em) {
            $em->persist($entry);
        });
    }

    private function traverseRelations($entity, $addCallback, $removeCallback = null)
    {
        $meta = $this->getMeta($entity);

        foreach ($meta->getManyToManyRelations() as $property) {
            if ($property->isTraversed()) {
                $list = $property->getValue($entity);

                foreach ($list as $entry) {
                    $relation = $property->getName();
                    if ($entry instanceof Relation) {
                        $relation = $entry;
                        $relation->setType($property->getName());
                        $entry = $entry->getTarget();
                    }
                    $addCallback($entry, $relation);
                }

                if ($removeCallback && $list instanceof Extension\ArrayCollection) {
                    foreach ($list->getRemovedElements() as $entry) {
                        $relation = $property->getName();
                        if ($entry instanceof Relation) {
                            $relation = $entry;
                            $relation->setType($property->getName());
                            $entry = $entry->getTarget();
                        }

                        $removeCallback($entry, $relation);
                    }
                }
            }
        }

        foreach ($meta->getManyToOneRelations() as $property) {
            if ($property->isTraversed()) {
                if ($entry = $property->getValue($entity)) {
                    $relation = $property->getName();
                    if ($entry instanceof Relation) {
                        $relation = $entry;
                        $relation->setType($property->getName());
                        $entry = $entry->getTarget();
                    }

                    if ($removeCallback) {
                        $this->removePreviousRelations($entity, $relation, $entry);
                    }
                    $addCallback($entry, $relation);
                }
            }
        }
    }

    private function writeEntities()
    {
        $this->begin();
        foreach ($this->entities as $entity) {
            $this->dispatchEvent(new Events\PrePersist($entity));
            $hash = $this->getHash($entity);
            $this->nodes[$hash] = $this->createNode($entity)->save();
            $this->dispatchEvent(new Events\PostPersist($entity));
        }
        $this->commit();

        // Write the primary key
        foreach ($this->entities as $entity) {
            $hash = $this->getHash($entity);
            $meta = $this->getMeta($entity);
            $pk = $meta->getPrimaryKey();

            $nodeId = $this->nodes[$hash]->getId();
            if ($pk->getValue($entity) !== $nodeId) {
                $pk->setValue($entity, $nodeId);

                if ($meta->getLabels()) {
                    $labels = array();
                    foreach ($meta->getLabels() as $label) {
                        $labels[] = new Label($this->client, $label);
                    }

                    $this->client->addLabels($this->nodes[$hash], $labels);
                }
            }
        }
    }

    private function createNode($entity)
    {
        $meta = $this->getMeta($entity);

        $pk = $meta->getPrimaryKey();
        $id = $pk->getValue($entity);

        if ($id !== null) {
            $node = $this->client->getNode($id);
        } else {
            $node = $this->client->makeNode()
                ->setProperty('class', $meta->getName());
        }

        foreach ($meta->getProperties() as $property) {
            $result = $property->getValue($entity);

            $node->setProperty($property->getName(), $result);
        }

        $currentDate = $this->getCurrentDate();

        if ($id === null) {
            $node->setProperty('creationDate', $currentDate);
        }

        $node->setProperty('updateDate', $currentDate);

        return $node;
    }

    private function writeRelations()
    {
        $this->begin();
        foreach ($this->entities as $entity) {
            $this->writeRelationsFor($entity);
        }
        $this->commit();
    }

    private function begin()
    {
        $this->batch = $this->client->startBatch();
    }

    private function commit()
    {
        if (count($this->batch->getOperations())) {
            $this->client->commitBatch();
        } else {
            $this->client->endBatch();
        }

        $this->batch = null;
    }

    private function writeRelationsFor($entity)
    {
        $em = $this;

        $addCallback = function ($entry, $relation) use ($entity, $em) {
            $em->addRelation($relation, $entity, $entry);
        };
        $removeCallback = function ($entry, $relation) use ($entity, $em) {
            $em->removeRelation($relation, $entity, $entry);
        };

        $this->traverseRelations($entity, $addCallback, $removeCallback);
    }

    /**
     * @access private
     */
    function addRelation($relation, $a, $b)
    {
        $relationName = $relation;
        $forceCreate = false;
        if ($relation instanceof Relation) {
            $relationName = $relation->getType();
            $forceCreate = $relation->isForceCreate();
        } else {
            $relation = null;
        }

        $a = $this->getLoadedNode($a);
        $b = $this->getLoadedNode($b);

        if (!$forceCreate) {
            $existing = $this->getRelationsFrom($a, $relationName);

            foreach ($existing as $r) {
                if (basename($r['end']) == $b->getId()) {

                    $relationship = $this->client->getRelationship(basename($r['self']));
                    $relationship->setProperty('updateDate', $this->getCurrentDate());

                    if ($relation !== null) {
                        foreach ($relation->getProperties() as $key => $value) {
                            $relationship->setProperty($key, $value);
                        }
                    }

                    $this->dispatchEvent(new Events\PreRelationUpdate($a, $b, $relationName, $relationship));

                    $relationship->save();

                    return;
                }
            }
        }

        $relationship = $a->relateTo($b, $relationName)
            ->setProperty('creationDate', $this->getCurrentDate())
            ->setProperty('updateDate', $this->getCurrentDate());

        $this->dispatchEvent(new Events\PreRelationCreate($a, $b, $relationName, $relationship));

        if ($relation !== null) {
            foreach ($relation->getProperties() as $key => $value) {
                $relationship->setProperty($key, $value);
            }
        }

        $relationship->save();

        $this->dispatchEvent(new Events\PostRelationCreate($a, $b, $relationName, $relationship));
    }

    /**
     * @access private
     */
    function removeRelation($name, $a, $b)
    {
        $a = $this->getLoadedNode($a);
        $b = $this->getLoadedNode($b);

        $this->dispatchEvent(new Events\PreRelationRemove($a, $b, $name));

        $existing = $this->getRelationsFrom($a, $name);

        foreach ($existing as $r) {
            if (basename($r['end']) == $b->getId()) {
                $this->deleteRelationship($r);
                $this->dispatchEvent(new Events\PostRelationRemove($a, $b, $name));
                return;
            }
        }
    }

    private function removePreviousRelations($from, $relation, $exception)
    {
        $node = $this->getLoadedNode($from);

        foreach ($this->getRelationsFrom($node, $relation) as $r) {
            if (basename($r['end']) != $exception->getId()) {
                $this->deleteRelationship($r);
            }
        }
    }

    private function deleteRelationship($r)
    {
        if ($relationship = $this->client->getRelationship(basename($r['self'])))  {
            $relationship->delete();
        }
    }

    private function getLoadedNode($entity)
    {
        return $this->nodes[$this->getHash($entity)];
    }

    private function getRelationsFrom($node, $relation)
    {
        // Cache sequential calls for the same element
        static $loaded = null, $existing;
        if ($loaded !== $node) {
            $command = new Extension\GetNodeRelationshipsLight($this->client, $node);
            $existing = $command->execute();
            $loaded = $node;
        }

        return array_filter($existing, function ($entry) use ($relation) {
            return $entry['type'] == $relation;
        });
    }

    /**
     * @param $indexName
     * @param string $type
     *
     * @return NodeIndex
     */
    function createIndex($indexName, $type = self::NODE_INDEX)
    {
        $indexName = str_replace('\\', '.', $indexName);
        if (! isset($this->indexes[$indexName])) {
            $newIndex = $this->createIndexInstance($indexName, $type);
            $newIndex->save();
            $this->indexes[$indexName] = $newIndex;
        }

        return $this->indexes[$indexName];
    }

    private function createIndexInstance($indexName, $type)
    {
        if ($type === self::FULLTEXT_INDEX) {
            return new NodeFulltextIndex($this->client, $indexName);

        } else {
            return new NodeIndex($this->client, $indexName);
        }
    }

    private function getHash($object)
    {
        return spl_object_hash($object);
    }

    /**
     * @param $entity
     */
    private function index($entity)
    {
        $meta = $this->getMeta($entity);
        $node = $this->getLoadedNode($entity);

        foreach ($meta->getIndexedProperties() as $property) {
            foreach ($property->getIndexes() as $index) {
                $realIndex = $this->createIndex($index->name, $index->type);
                $realIndex->add($node, $index->field, $property->getValue($entity));
            }
        }

        $class = $meta->getName();
        $mainIndex = $this->createIndex($class);
        $mainIndex->add($node, 'id', $entity->getId());
    }

    private function writeIndexes()
    {
        $this->begin();
        foreach ($this->entities as $entity) {
            $this->index($entity);
        }

        foreach ($this->indexes as $index) {
            $index->save();
        }

        $this->commit();
    }

    public function getMeta($entity)
    {
        return $this->metaRepository->fromClass(get_class($entity));
    }

    private function getCurrentDate()
    {
        $gen = $this->dateGenerator;
        return $gen();
    }

    /**
     * Alter how dates are generated. Primarily used for test cases.
     */
    function setDateGenerator(\Closure $generator)
    {
        $this->dateGenerator = $generator;
    }

    /**
     * Returns the Client
     *
     * @return \Everyman\Neo4j\Client
     */
    public function getClient()
    {
        return $this->client;
    }

    public function getPathFinder()
    {
        return clone $this->pathFinder;
    }
}

