<?php
namespace Bitrix\Main\Data\LocalStorage\Storage;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Data\ICacheEngine;

class CacheStorage implements StorageInterface
{
	private const CACHE_DIR = 'local-session';

	/** @var string */
	private $baseDir;
	/** @var ICacheEngine */
	private $cacheEngine;

	public function __construct(ICacheEngine $cacheEngine)
	{
		$this->cacheEngine = $cacheEngine;
		$this->baseDir = Application::getPersonalRoot() . '/cache/';
	}

	public function read(string $key, int $ttl)
	{
		$filename = '/' . Cache::getPath($key);
		if ($this->cacheEngine->read($value, $this->baseDir, self::CACHE_DIR, $filename, $ttl))
		{
			return $value;
		}

		return null;
	}

	public function write(string $key, $value, int $ttl)
	{
		$filename = '/' . Cache::getPath($key);
		$this->cacheEngine->write($value, $this->baseDir, self::CACHE_DIR, $filename, $ttl);
	}
}