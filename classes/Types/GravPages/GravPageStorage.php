<?php

declare(strict_types=1);

namespace Grav\Plugin\FlexObjects\Types\GravPages;

use Grav\Common\Debugger;
use Grav\Common\Grav;
use Grav\Framework\Flex\Storage\FolderStorage;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

/**
 * Class GravPageStorage
 * @package Grav\Plugin\FlexObjects\Types\GravPages
 */
class GravPageStorage extends FolderStorage
{
    protected $ignore_files;
    protected $ignore_folders;
    protected $ignore_hidden;
    protected $recurse;
    protected $base_path;

    protected $flags;
    protected $regex;

    protected function initOptions(array $options): void
    {
        parent::initOptions($options);

        $this->flags = \FilesystemIterator::KEY_AS_FILENAME | \FilesystemIterator::CURRENT_AS_FILEINFO
            | \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS;

        $grav = Grav::instance();

        $config = $grav['config'];
        $this->ignore_hidden = (bool)$config->get('system.pages.ignore_hidden');
        $this->ignore_files = (array)$config->get('system.pages.ignore_files');
        $this->ignore_folders = (array)$config->get('system.pages.ignore_folders');
        $this->recurse = $options['recurse'] ?? true;
        $this->regex = '/(\.([\w\d_-]+))?\.md$/D';
    }

    /**
     * @param string $key
     * @param bool $variations
     * @return array
     */
    public function parseKey(string $key, bool $variations = true): array
    {
        if (mb_strpos($key, '|') !== false) {
            [$key, $params] = explode('|', $key, 2);
        } else {
            $params = '';
        }
        $key = ltrim($key, '/');

        $keys = parent::parseKey($key, false) + ['params' => $params];

        if ($variations) {
            $keys += $this->parseParams($key, $params);
        }

        return $keys;
    }

    public function readFrontmatter(string $key): string
    {
        $path = $this->getPathFromKey($key);
        $file = $this->getFile($path);
        try {
            $frontmatter = $file->frontmatter();
        } catch (\RuntimeException $e) {
            $frontmatter = 'ERROR: ' . $e->getMessage();
        }

        return $frontmatter;
    }

    public function readRaw(string $key): string
    {
        $path = $this->getPathFromKey($key);
        $file = $this->getFile($path);
        try {
            $raw = $file->raw();
        } catch (\RuntimeException $e) {
            $raw = 'ERROR: ' . $e->getMessage();
        }

        return $raw;
    }

    /**
     * @param array $keys
     * @param bool $includeParams
     * @return string
     */
    public function buildStorageKey(array $keys, bool $includeParams = true): string
    {
        $key = $keys['key'] ?? null;
        if (null === $key) {
            $key = $keys['parent_key'] ?? '';
            if ($key !== '') {
                $key .= '/';
            }
            $order = $keys['order'] ?? 0;
            $folder = $keys['folder'] ?? 'undefined';
            $key .= $order ? sprintf('%02d.%s', $order, $folder) : $folder;
        }

        $params = $includeParams ? $this->buildStorageKeyParams($keys) : '';

        return $params ? "{$key}|{$params}" : $key;
    }

    /**
     * @param array $keys
     * @return string
     */
    public function buildStorageKeyParams(array $keys): string
    {
        $params = $keys['template'] ?? '';
        $language = $keys['lang'] ?? '';
        if ($language) {
            $params .= '.' . $language;
        }

        return $params;
    }

    /**
     * @param array $keys
     * @return string
     */
    public function buildFolder(array $keys): string
    {
        return $this->dataFolder . '/' . $this->buildStorageKey($keys, false);
    }

    /**
     * @param array $keys
     * @return string
     */
    public function buildFilename(array $keys): string
    {
        $file = $this->buildStorageKeyParams($keys);

        // Template is optional; if it is missing, we need to have to load the object metadata.
        if ($file && $file[0] === '.') {
            $meta = $this->getObjectMeta($this->buildStorageKey($keys, false));
            $file = ($meta['template'] ?? 'folder') . $file;
        }

        return $file . $this->dataExt;
    }

    /**
     * @param array $keys
     * @return string
     */
    public function buildFilepath(array $keys): string
    {
        return $this->buildFolder($keys) . '/' . $this->buildFilename($keys);
    }

    /**
     * @param array $row
     * @return array
     */
    public function extractKeysFromRow(array $row): array
    {
        $meta = $row['__META'] ?? null;
        $storageKey = $row['storage_key'] ?? $meta['storage_key']  ?? '';
        $keyMeta = $storageKey !== '' ? $this->extractKeysFromStorageKey($storageKey) : null;
        $parentKey = $row['parent_key'] ?? $meta['parent_key'] ?? $keyMeta['parent_key'] ?? '';
        $order = $row['order'] ?? $meta['order']  ?? $keyMeta['order'] ?? '';
        $folder = $row['folder'] ?? $meta['folder']  ?? $keyMeta['folder'] ?? '';
        $template = $row['template'] ?? $meta['template'] ?? $keyMeta['template'] ?? '';
        $lang = $row['lang'] ?? $meta['lang'] ?? $keyMeta['lang'] ?? '';

        $keys = [
            'key' => null,
            'params' => null,
            'parent_key' => $parentKey,
            'order' => (int)$order,
            'folder' => $folder,
            'template' => $template,
            'lang' => $lang
        ];

        $keys['key'] = $this->buildStorageKey($keys, false);
        $keys['params'] = $this->buildStorageKeyParams($keys);

        return $keys;
    }

    /**
     * @param string $key
     * @return array
     */
    public function extractKeysFromStorageKey(string $key): array
    {
        if (mb_strpos($key, '|') !== false) {
            [$key, $params] = explode('|', $key, 2);
            [$template, $language] = mb_strpos($params, '.') !== false ? explode('.', $params, 2) : [$params, ''];
        } else {
            $params = $template = $language = '';
        }
        $objectKey = basename($key);
        if (preg_match('|^(\d+)\.(.+)$|', $objectKey, $matches)) {
            [, $order, $folder] = $matches;
        } else {
            [$order, $folder] = ['', $objectKey];
        }
        $parentKey = ltrim(dirname('/' . $key), '/');

        return [
            'key' => $key,
            'params' => $params,
            'parent_key' => $parentKey,
            'order' => (int)$order,
            'folder' => $folder,
            'template' => $template,
            'lang' => $language
        ];
    }

    /**
     * @param string $key
     * @param string $params
     * @return array
     */
    protected function parseParams(string $key, string $params): array
    {
        if (mb_strpos($params, '.') !== false) {
            [$template, $language] = explode('.', $params, 2);
        } else {
            $template = $params;
            $language = '';
        }

        if ($template === '') {
            $meta = $this->getObjectMeta($key);
            $template = $meta['template'] ?? 'folder';
        }

        return [
            'file' => $template . ($language ? '.' . $language : ''),
            'template' => $template,
            'lang' => $language
        ];
    }

    /**
     * Prepares the row for saving and returns the storage key for the record.
     *
     * @param array $row
     */
    protected function prepareRow(array &$row): void
    {
        // Remove keys used in the filesystem.
        unset($row['parent_key'], $row['order'], $row['folder'], $row['template'], $row['lang']);
    }

    /**
     * Page storage supports moving and copying the pages and their languages.
     *
     * $row['__META']['copy'] = true       Use this if you want to copy the whole folder, otherwise it will be moved
     * $row['__META']['clone'] = true      Use this if you want to clone the file, otherwise it will be renamed
     *
     * @param string $key
     * @param array $row
     * @return array
     */
    protected function saveRow(string $key, array $row): array
    {
        $grav = Grav::instance();

        /** @var Debugger $debugger */
        $debugger = $grav['debugger'];

        try {
            // Initialize all key-related variables.
            $newKeys = $this->extractKeysFromRow($row);
            $newKey = $this->buildStorageKey($newKeys);
            $newFolder = $this->buildFolder($newKeys);
            $newFilename = $this->buildFilename($newKeys);
            $newFilepath = "{$newFolder}/{$newFilename}";

            // Check if the row already exists.
            $oldKey = $row['__META']['storage_key'] ?? null;
            if (is_string($oldKey)) {
                // Initialize all old key-related variables.
                $oldKeys = $this->extractKeysFromRow(['__META' => $row['__META']]);
                $oldFolder = $this->buildFolder($oldKeys);
                $oldFilename = $this->buildFilename($oldKeys);

                // Check if folder has changed.
                if ($oldFolder !== $newFolder && file_exists($oldFolder)) {
                    $isCopy = $row['__META']['copy'] ?? false;
                    if ($isCopy) {
                        $this->copyRow($oldKey, $newKey);
                        $debugger->addMessage("Page copied: {$oldFolder} => {$newFolder}", 'debug');
                    } else {
                        $this->renameRow($oldKey, $newKey);
                        $debugger->addMessage("Page moved: {$oldFolder} => {$newFolder}", 'debug');
                    }
                }

                // Check if filename has changed.
                if ($oldFilename !== $newFilename) {
                    // Get instance of the old file (we have already copied/moved it).
                    $oldFilepath = "{$newFolder}/{$oldFilename}";
                    $file = $this->getFile($oldFilepath);

                    // Rename the file if we aren't supposed to clone it.
                    $isClone = $row['__META']['clone'] ?? false;
                    if (!$isClone && $file->exists()) {
                        /** @var UniformResourceLocator $locator */
                        $locator = $grav['locator'];
                        $toPath = $locator->isStream($newFilepath) ? $locator->findResource($newFilepath, true, true) : $newFilepath;
                        $success = $file->rename($toPath);
                        if (!$success) {
                            throw new \RuntimeException("Changing page template failed: {$oldFilepath} => {$newFilepath}");
                        }
                        $debugger->addMessage("Page template changed: {$oldFilename} => {$newFilename}", 'debug');
                    } else {
                        $file = null;
                        $debugger->addMessage("Page template created: {$newFilename}", 'debug');
                    }
                }
            }

            // Clean up the data to be saved.
            $this->prepareRow($row);
            unset($row['__META'], $row['__ERROR']);

            if (!isset($file)) {
                $file = $this->getFile($newFilepath);
            }

            $file->save($row);
            $debugger->addMessage("Page saved: {$newFilepath}", 'debug');

            /** @var UniformResourceLocator $locator */
            $locator = Grav::instance()['locator'];
            if ($locator->isStream($newFolder)) {
                $locator->clearCache();
            }
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(sprintf('Flex saveRow(%s): %s', $file->filename(), $e->getMessage()));
        }

        $row['__META'] = $this->getObjectMeta($newKey, true);

        return $row;
    }

    protected function canDeleteFolder(string $key): bool
    {
        $keys = $this->extractKeysFromStorageKey($key);
        if ($keys['lang']) {
            return false;
        }

        return true;
    }

    /**
     * Get key from the filesystem path.
     *
     * @param  string $path
     * @return string
     */
    protected function getKeyFromPath(string $path): string
    {
        if ($this->base_path) {
            $path = $this->base_path . '/' . $path;
        }

        return $path;
    }

    /**
     * Returns list of all stored keys in [key => timestamp] pairs.
     *
     * @return array
     */
    protected function buildIndex(): array
    {
        return $this->getIndexMeta();
    }

    /**
     * @param string $key
     * @param bool $reload
     * @return array
     */
    protected function getObjectMeta(string $key, bool $reload = false): array
    {
        $keys = $this->extractKeysFromStorageKey($key);
        $key = $keys['key'];

        if ($reload || !isset($this->meta[$key])) {
            /** @var UniformResourceLocator $locator */
            $locator = Grav::instance()['locator'];
            if (mb_strpos($key, '@@') === false) {
                $path = $locator->findResource($this->getStoragePath($key), true, true);
            } else {
                $path = null;
            }

            $modified = 0;
            $markdown = [];
            $children = [];

            if ($path && file_exists($path)) {
                $modified = filemtime($path);
                $iterator = new \FilesystemIterator($path, $this->flags);

                /** @var \SplFileInfo $info */
                foreach ($iterator as $k => $info) {
                    // Ignore all hidden files if set.
                    if ($k === '' || ($this->ignore_hidden && $k[0] === '.')) {
                        continue;
                    }

                    if ($info->isDir()) {
                        // Ignore all folders in ignore list.
                        if ($this->ignore_folders && \in_array($k, $this->ignore_folders, true)) {
                            continue;
                        }

                        $children[$k] = false;
                    } else {
                        // Ignore all files in ignore list.
                        if ($this->ignore_files && \in_array($k, $this->ignore_files, true)) {
                            continue;
                        }

                        $timestamp = $info->getMTime();

                        // Page is the one that matches to $page_extensions list with the lowest index number.
                        if (preg_match($this->regex, $k, $matches)) {
                            $mark = $matches[2] ?? '';
                            $ext = $matches[1] ?? '';
                            $ext .= $this->dataExt;
                            $markdown[$mark][basename($k, $ext)] = $timestamp;
                        }

                        $modified = max($modified, $timestamp);
                    }
                }
            }

            $rawRoute = trim(preg_replace(GravPageIndex::PAGE_ROUTE_REGEX, '/', "/{$key}"), '/');
            $route = GravPageIndex::normalizeRoute($rawRoute);

            ksort($markdown, SORT_NATURAL);
            ksort($children, SORT_NATURAL);

            $file = array_key_first($markdown[''] ?? reset($markdown) ?: []);

            $meta = [
                'key' => $route,
                'storage_key' => $key,
                'template' => $file,
                'storage_timestamp' => $modified,
            ];
            if ($markdown) {
                $meta['markdown'] = $markdown;
            }
            if ($children) {
                $meta['children'] = $children;
            }
            $meta['checksum'] = md5(json_encode($meta));

            // Cache meta as copy.
            $this->meta[$key] = $meta;
        } else {
            $meta = $this->meta[$key];
        }

        $params = $keys['params'];
        if ($params) {
            $language = $keys['lang'];
            $template = $keys['template'] ?: array_key_first($meta['markdown'][$language]) ?? $meta['template'];
            $meta['exists'] = ($template && !empty($meta['children'])) || isset($meta['markdown'][$language][$template]);
            $meta['storage_key'] .= '|' . $params;
            $meta['template'] = $template;
            $meta['lang'] = $language;
        }

        return $meta;
    }

    protected function getIndexMeta(): array
    {
        $queue = [''];
        $list = [];
        do {
            $current = array_pop($queue);
            $meta = $this->getObjectMeta($current);
            $storage_key = $meta['storage_key'];

            if (!empty($meta['children'])) {
                $prefix = $storage_key . ($storage_key !== '' ? '/' : '');

                foreach ($meta['children'] as $child => $value) {
                    $queue[] = $prefix . $child;
                }
            }

            $list[$storage_key] = $meta;
        } while ($queue);

        ksort($list, SORT_NATURAL);

        // Update parent timestamps.
        foreach (array_reverse($list) as $storage_key => $meta) {
            if ($storage_key !== '') {
                $parentKey = dirname($storage_key);
                if ($parentKey === '.') {
                    $parentKey = '';
                }

                $parent = &$list[$parentKey];
                $basename = basename($storage_key);

                if (isset($parent['children'][$basename])) {
                    $timestamp = $meta['storage_timestamp'];
                    $parent['children'][$basename] = $timestamp;
                    if ($basename && $basename[0] === '_') {
                        $parent['storage_timestamp'] = max($parent['storage_timestamp'], $timestamp);
                    }
                }
            }
        }

        return $list;
    }

    /**
     * @return string
     */
    protected function getNewKey(): string
    {
        throw new \RuntimeException('Generating random key is disabled for pages');
    }
}
