<?php

class MongoCacheEngine extends CacheEngine {

	protected $_mongo = null;
	protected $_collection = null;

	private $_key = null;

/**
 * Initialize the Cache Engine
 *
 * Called automatically by the cache frontend
 * To reinitialize the settings call Cache::engine('EngineName', [optional] settings = array());
 *
 * @param array $settings array of setting for the engine
 * @return boolean True if the engine has been successfully initialized, false if not
 */
	public function init($settings = array()) {
		$settings += $this->settings + array(
			'dbconfig' => 'default',
			'model' => 'THCSutil/MongoCacheModel',
			'collection' => 'cache',
			'encode' => true,
			'duration' => 3600,
			'probability' => 100,
			'groups' => array()
		);

		if (!isset($settings['prefix'])) {
			$settings['prefix'] = Inflector::slug(APP_DIR) . '_';
		}

		if (!is_numeric($settings['duration'])) {
			$settings['duration'] = strtotime($settings['duration']) - time();
		}

		parent::init($settings);

		$this->_mongo = ConnectionManager::getDataSource($this->settings['dbconfig']);
		if (!$this->_mongo->isConnected()) {
			$this->_mongo->connect();
		}
		$db = $this->_mongo->connection->selectDB($this->_mongo->config['database']);
		$this->collection = $db->selectCollection($this->settings['collection']);

		return $this->_mongo && $this->collection;
	}

	public function key($key) {
		if (empty($key))
			return false;

		if (is_string($key))
			$key = array('_key'=>$key);

		$this->key = $key;

		// we are expected to return something. Consider returning something simpler.
		return strval($key);
	}

	protected function _optionsToConditions($options, $writeMode) {
		if (is_array($options)) {
			$options = $options;
		} else if (is_string($options)) {
			$options = array('_key'=>$options);
		} else {
			return false;
		}

		if (!isset($options['_key'])) {
			if (isset($options['key'])) {
				$options['_key'] = $options['key'];
				unset($options['key']);
			}
		}

		if ($writeMode) {
			if (!isset($options['_grp'])) {
				if (isset($options['groups'])) {
					$options['_grp'] = array($options['groups']);
					unset($options['groups']);
				} else if (isset($this->settings['groups'])) {
					$options['_grp'] = $this->settings['groups'];
				} else
					$options['_grp'] = null;
			}
			if (is_string($options['_grp'])) {
				$options['_grp'] = array($options['_grp']);
			} else if (empty($options['_grp'])) {
				$options['_grp'] = array();
			}
			// not an else
			if (is_array($options['_grp'])) {
				$groups = array();
				foreach ($options['_grp'] as $group) {
					$sequence = $this->read(array('_key'=>"group.".$group, '_grp'=>null));
					if ($sequence === false) {
						$sequence = (time()&0x000fffff)<<4;
						$this->write(array('_key'=>"group.".$group, '_grp'=>null), 
									intval($sequence), 
									max($this->settings['duration'], 60*5));
					}
					$groups[] = "group.$group=$sequence";
				}
				$options['_grp'] = implode(',', $groups);
			}

			if (!isset($options['_exp'])) {
				if (isset($options['duration'])) {
					if (!is_numeric($options['duration'])) {
						$options['duration'] = strtotime($options['duration']) - time();
					}
				} else {
					$options['duration'] = $this->settings['duration'];
				}
				$options['_exp'] = intval(time() + $options['duration']);
				unset($options['duration']);
			}
		}
	}

/**
 * Write data for key into cache. When using memcache as your cache engine
 * remember that the Memcache pecl extension does not support cache expiry times greater
 * than 30 days in the future. Any duration greater than 30 days will be treated as never expiring.
 *
 * @param string $key Identifier for the data
 * @param mixed $value Data to be cached
 * @param integer $duration How long to cache the data, in seconds
 * @return boolean True if the data was successfully cached, false on failure
 * @see http://php.net/manual/en/memcache.set.php
 */
	public function write($key, $value, $duration) {
		$key = $this->key;

		if (!isset($key['_exp']) && !empty($duration)) {
			$key['_exp'] = $duration;
		}

		$options = $this->_optionsToConditions($key)
		if (!isset($options['_key']))
			return false;

		$options['_key'] = $this->settings['prefix'] . $options['_key'];

		$res = $this->coll->insert(array_merge($options, 
								'_dat'=>$value)), array('safe'=>1));

		if ($res['err']) {
		} else {
		}
		return $res['ok'];
	}

/**
 * Read a key from the cache
 *
 * @param string $key Identifier for the data
 * @return mixed The cached data, or false if the data doesn't exist, has expired, or if there was an error fetching it
 */
	public function read($key) {
		$key = $this->key;

		$data = false;

		// save for later. Group read/write will kill this class variable
		$settings = $this->settings;

		$options = $this->_optionsToConditions($key, false);
		if (!isset($options['_key']))
			return false;
		$options['_key'] = $this->settings['prefix'] . $options['_key'];

		$res = $this->coll->findOne($options);
		if ($res) {
			// found a candidate, check for expirations
			$ok = true;
			$groups = explode(',', $res['_grp']);
			foreach ($groups as $group) {
				$group = explode('=', $group);
				$sequence = Cache::read(array('_key'=>"group.".$group[0], 'groups'=>null), 'persist');
				if ($sequence != $group[1]) {
					$ok = false;
					break;
				}
			}

			if (!$ok || $res['_exp'] < time()) {
				$cache->coll->remove($options); 
			} else {
				$data = $res['_dat'];
			}
		}

		if (isset($settings['probability']) && $settings['probability'] > 0 && rand(1,100)>=$settings['probability']) {
			$this->clear(true);
		}

		// restore
		$this->settings = $settings;

		return $data;
	}

/**
 * Increments the value of an integer cached key
 *
 * @param string $key Identifier for the data
 * @param integer $offset How much to increment
 * @return New incremented value, false otherwise
 * @throws CacheException when you try to increment with compress = true
 */
	public function increment($key, $offset = 1) {
		if ($this->settings['compress']) {
			throw new CacheException(
				__d('cake_dev', 'Method increment() not implemented for compressed cache in %s', __CLASS__)
			);
		}
		if ($this->settings['encode']) {
			throw new CacheException(
				__d('cake_dev', 'Method increment() not implemented for encoded cache in %s', __CLASS__)
			);
		}

		$key = $this->key;

		$options = $this->_optionsToConditions($key)
		if (!isset($options['_key']))
			return false;

		$options['_key'] = $this->settings['prefix'] . $options['_key'];

		$res = $this->coll->update($options, 
								array('$inc'=>array('_dat'=>$offset)), array('safe'=>1));

		if ($res['err']) {
		} else {
			return $this->read($key);
		}
		return !!$res['ok'];
	}

/**
 * Decrements the value of an integer cached key
 *
 * @param string $key Identifier for the data
 * @param integer $offset How much to subtract
 * @return New decremented value, false otherwise
 * @throws CacheException when you try to decrement with compress = true
 */
	public function decrement($key, $offset = 1) {
		return $this->increment($key, -1*$offset);
	}

/**
 * Delete a key from the cache
 *
 * @param string $key Identifier for the data
 * @return boolean True if the value was successfully deleted, false if it didn't exist or couldn't be removed
 */
	public function delete($key) {
		return $this->_delete($this->key);
	}

	public function _delete($key) {
		$cond = $this->_optionsToConditions($key, false);

		$res = $this->coll->remove($cond, array('safe'=>1)); 
		return $res['ok'];
	}

/**
 * Delete all keys from the cache
 *
 * @param boolean $check
 * @return boolean True if the cache was successfully cleared, false otherwise
 */
	public function clear($check) {
		$cond = array();
		if ($check) {
			$cond['_exp'] = array('$lt',time());
		}

		return $this->_delete($cond);
	}

/**
 * Returns the `group value` for each of the configured groups
 * If the group initial value was not found, then it initializes
 * the group accordingly.
 *
 * @return array
 */
	public function groups() {
		/*
		if (empty($this->_compiledGroupNames)) {
			foreach ($this->settings['groups'] as $group) {
				$this->_compiledGroupNames[] = $this->settings['prefix'] . $group;
			}
		}

		$groups = $this->_Memcache->get($this->_compiledGroupNames);
		if (count($groups) !== count($this->settings['groups'])) {
			foreach ($this->_compiledGroupNames as $group) {
				if (!isset($groups[$group])) {
					$this->_Memcache->set($group, 1, false, 0);
					$groups[$group] = 1;
				}
			}
			ksort($groups);
		}

		$result = array();
		$groups = array_values($groups);
		foreach ($this->settings['groups'] as $i => $group) {
			$result[] = $group . $groups[$i];
		}

		return $result;
		*/
		return false;
	}

/**
 * Increments the group value to simulate deletion of all keys under a group
 * old values will remain in storage until they expire.
 *
 * @return boolean success
 */
	public function clearGroup($group) {

		return (bool)$this->increment($group);
	}

}