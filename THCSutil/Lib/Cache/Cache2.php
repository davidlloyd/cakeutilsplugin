<?php

class Cache2 extends Cache {

/**
 * Delete all keys from the cache belonging to the same group. Different from Cache by deleting the group from all cache engines.
 *
 * @param string $group name of the group to be cleared
 * @return boolean True if the cache group was successfully cleared from all engines, false otherwise
 */
	public static function clearGroup($group) {
		$success = true;
		foreach (self::$_engines as $name => $engine) {
			$success = $engine->clearGroup($group) && $success;
			self::set(null, $name);
		}
		return $success;
	}

}