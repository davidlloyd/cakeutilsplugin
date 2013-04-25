<?php

App::uses('DispatcherFilter', 'Routing');

class GetPostMergeDispatcher extends DispatcherFilter {

/**
 * Default priority for all methods in this filter
 * This filter should run before the request gets parsed by router
 *
 * @var int
 */
	public $priority = 5;

/**
 * Merge POST, URL & GET data into the data container.
 *
 * @param CakeEvent $event containing the request and response object
 * @return CakeResponse with cached content if found, null otherwise
 */
	public function beforeDispatch(CakeEvent $event) {

		if (Configure::read('GetPostMerge.disabled') !== true) {

			$event->data['request']->data = Hash::merge(
													$event->data['request']->query, 
													$event->data['request']->data
													);
		}
	}
}
