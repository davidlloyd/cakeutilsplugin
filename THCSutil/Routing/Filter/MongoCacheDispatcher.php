<?php
/**
 */

App::uses('DispatcherFilter', 'Routing');

/**
 * This filter will check wheter the response was previously cached in the file system
 * and served it back to the client if appropriate.
 *
 */
class MongoCacheDispatcher extends DispatcherFilter {

/**
 * Default priority for all methods in this filter
 * This filter should run before the request gets parsed by router
 *
 * @var int
 */
	public $priority = 3;

/**
 * Root directory or name for this app.
 */
	public $base = null;

	public $ignoreRoutePrefix = null;

	public $requestParams = null;
	public $sessionParams = null;

	protected $data = null;

	protected $extraData = null;

/**
 * Construct
 */
	public __construct() {
	}

	protected _init(&$event) {
		$this->event = &$event;
		$this->data  = &$event->data;

		$this->base = Configure::read('App.base');
		if (!$this->base) {
			$doc = $_SERVER['DOCUMENT_ROOT'];
			$doc = explode('/app/',$doc);
			$this->base= array_pop(explode('/',$doc[0]));
		}

		$this->ignoreRoutePrefix = Configure::read('Routing.prefixes');
		$this->sessionParams = Configure::read('Cache.sessionParams');
		$this->sessionDefaults = Configure::read('Cache.sessionDefaults');
		$this->requestParams = Hash::merge( $event->data['request']->query, $event->data['request']->data );

		$path = trim( $this->data['request']->here(), '/' );
		if ($path !=='' && $path[0]==='/') {
			$path = substr($path, 1);
		}

		$pos = strpos($path.'/', $base.'/');
		if ($pos!==false) {
			$path = trim(substr($path, $pos+strlen($base)+1), '/');
		}

		if ($path === '') {
			$path = '_home';
		}

		$this->path = $path;
	}

	protected isCacheable() {
		if (Configure::read('Cache.check') !== true) {
			return false;
		}

		// early optout for non-cacheable prefixes
		if ($this->ignoreRoutePrefix) {
			$prefix = explode('/',$path);
			$prefix = $prefix[0];
			if (in_array($prefix, $this->ignoreRoutePrefix) {
				return false;
			}
		}
		return true;
	}

	protected getSessionValue($key, $data) {
		$pos = strpos($key, '{');
		$end = strpos($key, '}');
		if ($pos !== false && $pos < $end) {
			$v = $this->getSessionValue( substr($key, $pos+1), $data );
			return $this->getSessionValue( substr($key, 0, $pos) . $v . substr($key, $end+1), $data);
		} else {
			if (isset($data[$key]))
				return $data[$key];
			if (isset($this->requestParams[$key]))
				return $this->requestParams[$key];
			if (CakeSession::check($key))
				return CakeSession::read($key);
			if (isset($this->sessionDefaults[$key]))
				return $this->sessionDefaults[$key];
		}
		return null;
	}
	protected getSessionData() {
		$data = array();

		foreach ($this->sessionParams as $key => $param) {
			if (is_numeric($key))
				$key = $param;

			$data[$key] = $this->getSessionValue($param, $data);
		}
		return $data;
	}

/**
 * Checks whether the response was cached and set the body accordingly.
 *
 * @param CakeEvent $event containing the request and response object
 * @return CakeResponse with cached content if found, null otherwise
 */
	public function beforeDispatch(CakeEvent $event) {
		if (Configure::read('Cache.check') !== true) {
			return;
		}
		$this->data = & $event->data;

		$this->_init(&$event);

		if (! $this->isCacheable())
			return;

		// if ($base !== '')
		// 	$base = $base + '/';

		// $prefix = Configure::read('Cache.viewPrefix');
		// if ($prefix) {
		// 	$path = $prefix . '_' . $base.$path;
		// } else {
		// 	$path = $base.$path;
		// }
		// $path = strtolower(Inflector::slug($path));

		// $obsViewCache->check($base, $uri);
		
		// $path = $obsViewCache->path;  // just a nice update for debugging

		App::uses('CakeSession', 'Model/Datasource');

		App::uses('THCSutil/Cache2', 'Cache');

			// $ctrl = new AppController();
			// $ctrl->Session = $this->session;

		$sessionData = $this->getSessionData();

		$data = Hash::merge( $this->requestParams, $sessionData );

		foreach ($data as $key=>$value) {
			if (!in_array($key, $this->ignoreParams)) {  
				if (in_array($key, $this->specialParams)) {
						$conditions[$key] = array('$in'=>array(''.$value, ''));
				} else {
					$conditions[$key] = ''.$value;
				}
			}
		}
		$conditions['_key'] = $this->base . $this->path;

//
// DO NOT USE USER SPECIFIC VIEW CACHES!
//
if (isset($this->conditions['pgroup'])) {
	$pg = ':'.$this->conditions['pgroup'].':';
	if (strpos($pg, ':w:')!==false) 
		return;
}

		$emit = Cache2::read($conditions, Configure::read('Cache.viewcache'));

		if ($emit)
			$this->emit($emit);

	}

}
