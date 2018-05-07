<?php
namespace Grav\Plugin\FlexObjects;

use Grav\Common\Cache;
use Grav\Common\Data\Blueprint;
use Grav\Common\Debugger;
use Grav\Common\Grav;
use Grav\Framework\Cache\Adapter\DoctrineCache;
use Grav\Framework\Cache\Adapter\MemoryCache;
use Grav\Framework\Cache\CacheInterface;
use Grav\Plugin\FlexObjects\Storage\SimpleStorage;
use Grav\Plugin\FlexObjects\Storage\StorageInterface;
use Psr\SimpleCache\InvalidArgumentException;
use RuntimeException;

/**
 * Class FlexType
 * @package Grav\Plugin\FlexObjects
 */
class FlexType
{
    /** @var string */
    protected $type;
    /** @var string */
    protected $blueprint_file;
    /** @var Blueprint */
    protected $blueprint;
    /** @var FlexIndex */
    protected $index;
    /** @var FlexCollection */
    protected $collection;
    /** @var bool */
    protected $enabled;
    /** @var object */
    protected $storage;
    /** @var CacheInterface */
    protected $cache;

    protected $objectClassName;
    protected $collectionClassName;

    /**
     * FlexType constructor.
     * @param string $type
     * @param string $blueprint_file
     * @param bool $enabled
     */
    public function __construct($type, $blueprint_file, $enabled = false)
    {
        $this->type = $type;
        $this->blueprint_file = $blueprint_file;
        $this->enabled = (bool)$enabled;
    }

    /**
     * @return bool
     */
    public function isEnabled()
    {
        return $this->enabled;
    }

    /**
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return string
     */
    public function getTitle()
    {
        return $this->getBlueprint()->get('title', ucfirst($this->getType()));
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->getBlueprint()->get('description', '');
    }

    /**
     * @param string|null $name
     * @param mixed $default
     * @return mixed
     */
    public function getConfig($name = null, $default = null)
    {
        $path = 'config' . ($name ? '/' . $name : '');

        return $this->getBlueprint()->get($path, $default);
    }

    /**
     * @return Blueprint
     */
    public function getBlueprint()
    {
        if (null === $this->blueprint) {
            $this->blueprint = (new Blueprint($this->blueprint_file))->load();
            if ($this->blueprint->get('type') === 'flex-objects') {
                $blueprintBase = (new Blueprint('plugin://flex-objects/blueprints/flex-objects.yaml'))->load();
                $this->blueprint->extend($blueprintBase, true);
            }
            $this->blueprint->init();
            if (empty($this->blueprint->fields())) {
                throw new RuntimeException(sprintf('Blueprint for %s is missing', $this->type));
            }
        }

        return $this->blueprint;
    }

    /**
     * @return string
     */
    public function getBlueprintFile()
    {
        return $this->blueprint_file;
    }

    /**
     * @param array|null $keys
     * @return FlexCollection|FlexIndex
     */
    public function getCollection(array $keys = null)
    {
        if (null !== $keys) {
            return $this->loadCollection($keys);
        }

        return clone $this->getIndex();
    }

    public function loadCollection(array $entries)
    {
        return $this->createCollection($this->loadObjects($entries));
    }

    /**
     * @param array $entries
     * @return FlexObject[]
     */
    public function loadObjects(array $entries)
    {
        /** @var Debugger $debugger */
        $debugger = Grav::instance()['debugger'];
        $debugger->startTimer('flex-objects', sprintf('Initializing %d Flex Objects', \count($entries)));

        $storage = $this->getStorage();
        $cache = $this->getCache();

        // Get storage keys for the objects.
        $keys = [];
        foreach ($entries as $key => $value) {
            $keys[\is_array($value) ? $value[0] : $key] = $key;
        }

        // Fetch rows from the cache.
        try {
            $rows = $cache->getMultiple(array_keys($keys));
        } catch (InvalidArgumentException $e) {
            $rows = [];
        }

        // Read missing rows from the storage.
        $updated = [];
        $rows = $storage->readRows($rows, $updated);

        // Store updated rows to the cache.
        if ($updated) {
            try {
                $cache->setMultiple($updated);
            } catch (InvalidArgumentException $e) {
                // TODO: log about the issue.
            }
        }

        // Create objects from the rows.
        $list = [];
        foreach ($rows as $storageKey => $row) {
            $key = $keys[$storageKey];
            $object = $this->createObject($row, $key, false);
            $list[$key] = $object->setStorageKey($storageKey)->setTimestamp($entries[$key][1] ?? $entries[$key]);
        }

        $debugger->stopTimer('flex-objects');

        return $list;
    }

    /**
     * @param array $data
     * @param string|null $key
     * @return FlexObject
     */
    public function update(array $data, $key = null)
    {
        $object = null !== $key ? $this->getCollection()->get($key) : null;

        if (null === $object) {
            $key = null;

            $object = $this->createObject($data, $key);

            $this->getStorage()->createRows([$object->prepareStorage()]);
        } else {
            $object->update($data);

            $this->getStorage()->updateRows([$object->getStorageKey() => $object->prepareStorage()]);
        }

        try {
            $this->getCache()->clear();
        } catch (InvalidArgumentException $e) {
            // Caching failed, but we can ignore that for now.
        }

        return $object;
    }

    /**
     * @param string $key
     * @return FlexObject|null
     */
    public function remove($key)
    {
        $object = null !== $key ? $this->getCollection()->get($key) : null;
        if (!$object) {
            return null;
        }

        $this->getStorage()->deleteRows([$object->getStorageKey() => $object->prepareStorage()]);

        try {
            $this->getCache()->clear();
        } catch (InvalidArgumentException $e) {
            // Caching failed, but we can ignore that for now.
        }

        return $object;
    }

    /**
     * @return CacheInterface
     */
    public function getCache()
    {
        if (null === $this->cache) {
            try {
                /** @var Cache $gravCache */
                $gravCache = Grav::instance()['cache'];

                $this->cache = new DoctrineCache($gravCache->getCacheDriver(), 'flex-objects-' . $this->getType(), 60);
            } catch (\Exception $e) {
                $this->cache = new MemoryCache('flex-objects-' . $this->getType());
            }
        }

        return $this->cache;
    }


    /**
     * @param string|null $key
     * @return string
     */
    public function getStorageFolder($key = null)
    {
        return $this->getStorage()->getStoragePath($key);
    }

    /**
     * @param string|null $key
     * @return string
     */
    public function getMediaFolder($key = null)
    {
        return $this->getStorage()->getMediaPath($key);
    }

    /**
     * @return StorageInterface
     */
    public function getStorage()
    {
        if (!$this->storage) {
            $this->storage = $this->createStorage();
        }

        return $this->storage;
    }

    /**
     * @param array $data
     * @param string $key
     * @param bool $validate
     * @return FlexObject
     */
    public function createObject(array $data, $key, $validate = true)
    {
        $className = $this->objectClassName ? $this->objectClassName : $this->getObjectClass();

        return new $className($data, $key, $this, $validate);
    }

    /**
     * @param array $entries
     * @return FlexCollection
     */
    public function createCollection(array $entries)
    {
        $className = $this->collectionClassName ? $this->collectionClassName : $this->getCollectionClass();

        return new $className($entries, $this);
    }

    /**
     * @return string
     */
    public function getObjectClass()
    {
        if (!$this->objectClassName) {
            $this->objectClassName = $this->getConfig('data/object', 'Grav\\Plugin\\FlexObjects\\FlexObject');
        }
        return $this->objectClassName;

    }

    /**
     * @return string
     */
    public function getCollectionClass()
    {
        if (!$this->collectionClassName) {
            $this->collectionClassName = $this->getConfig('data/collection', 'Grav\\Plugin\\FlexObjects\\FlexCollection');
        }
        return $this->collectionClassName;
    }

    /**
     * @return StorageInterface
     */
    protected function createStorage()
    {
        $this->collection = $this->createCollection([]);

        $storage = $this->getConfig('data/storage');

        if (!\is_array($storage)) {
            $storage = ['options' => ['folder' => $storage]];
        }

        $className = isset($storage['class']) ? $storage['class'] : SimpleStorage::class;
        $options = isset($storage['options']) ? $storage['options'] : [];

        return new $className($options);
    }

    /**
     * @return FlexIndex
     */
    protected function getIndex()
    {
        if (null === $this->index) {
            /** @var Debugger $debugger */
            $debugger = Grav::instance()['debugger'];
            $debugger->startTimer('flex-keys', 'Loading Flex Index');

            $storage = $this->getStorage();
            $cache = $this->getCache();

            try {
                $keys = $cache->get('__keys');
            } catch (InvalidArgumentException $e) {
                $keys = null;
            }

            if (null === $keys) {
                $className = $this->getObjectClass();
                $keys = $className::createIndex($storage->getExistingKeys());
                try {
                    $cache->set('__keys', $keys);
                } catch (InvalidArgumentException $e) {
                    // TODO: log about the issue.
                }
            }

            $this->index = new FlexIndex($keys, $this);

            $debugger->stopTimer('flex-keys');
        }

        return $this->index;
    }
}
