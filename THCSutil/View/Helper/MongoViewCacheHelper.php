<?php

App::import('Helper', 'Cache');

class MongoViewCacheHelper extends CacheHelper {

	function cache($file, $out, $cache = false) {

		OBSViewCache::$Instance->write(OBSViewCache::$Instance->isCacheable($this->action, $this->cacheAction), $out);

		return $out;
	}	

};
