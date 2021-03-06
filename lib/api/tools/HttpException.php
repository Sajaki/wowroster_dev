<?php
/**
 * WoWRoster.net WoWRoster
 *
 * @copyright  2002-2011 WoWRoster.net
 * @license    http://www.gnu.org/licenses/gpl.html   Licensed under the GNU General Public License v3. * @package    WoWRoster

 */

/**
 * HTTP Resource Exception
 */
class HttpException extends Exception {
	public function __construct($message=null, $code=500, Exception $previous = null) {
		if (is_array($message) && isset($message['status'], $message['reason'])) {
			$message = $message['status']. ": " . $message['reason'];
		} elseif($message === null) {
			$message = 'Unknown error occurred.';
		}
		parent::__construct($message, $code, $previous);
	}
}
