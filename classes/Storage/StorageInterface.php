<?php
namespace Grav\Plugin\FlexObjects\Storage;

/**
 * Class BuildStorage
 * @package Grav\Plugin\RevKit\Repositories\Builds
 */
interface StorageInterface
{
    /**
     * Storage constructor.
     *
     * @param string $path
     * @param string $filePattern
     * @param string $extension
     */
    public function __construct(array $options);

    /**
     * Returns list of all stored keys in [key => timestamp] pairs.
     *
     * @return  array
     */
    public function getExistingKeys();

    /**
     * Create new rows.
     *
     * @param  array  $rows  Array of [key => row] pairs.
     * @return array  Returns created rows. Note that existing rows will fail to save and have null value.
     */
    public function createRows(array $rows);

    /**
     * Read rows. If you pass object or array as value, that value will be used to save I/O.
     *
     * @param  array  $rows  Array of [key => row] pairs.
     * @param  array  $fetched  Optional variable for storing only fetched items.
     * @return array  Returns rows. Note that non-existing rows have null value.
     */
    public function readRows(array $rows, &$fetched = null);

    /**
     * Update existing rows.
     *
     * @param  array  $rows  Array of [key => row] pairs.
     * @return array  Returns updated rows. Note that non-existing rows will fail to save and have null value.
     */
    public function updateRows(array $rows);

    /**
     * Delete rows.
     *
     * @param  array  $rows  Array of [key => row] pairs.
     * @return array  Returns deleted rows. Note that non-existing rows have null value.
     */
    public function deleteRows(array $rows);

    /**
     * Replace rows regardless if they exist or not.
     *
     * All rows should have a specified key for this to work.
     *
     * @param  array $rows  Array of [key => row] pairs.
     * @return array  Returns both created and updated rows.
     */
    public function replaceRows(array $rows);

    /**
     * Get filesystem path from the key.
     *
     * @param  string $key
     * @return string
     */
    public function getPathFromKey($key);
}