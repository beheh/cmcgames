<?php

require('../vendor/autoload.php');

class CMCGames {

	private static $masterserver = 'league.clonkspot.org';
	private static $template_file = __DIR__.'/../static/basic.css';
	private static $cache_file = __DIR__.'/../cache/cache.css';

	public static function handleRequest() {
		$i = 0;
		while(self::isLocked()) {
			sleep(1);
			$i++;
			if($i >= 10) {
				self::respond('/'.'* Cache file locked since '.date('Y-m-d H:i:s', filemtime(self::$cache_file.'.lock')).', aborting (current time '.date('Y-m-d H:i:s').') *'.'/');
				exit();
			}
		}
		if(file_exists(self::$cache_file) && filemtime(self::$cache_file) > time() - (1 * 60)) {
			return self::respondWithCache();
		}
		if(!is_writeable(dirname(self::$cache_file))) {
			self::respond('/'.'* Couldn\'t write to cache *'.'/');
			exit();
		}
		register_shutdown_function('CMCGames::unlock');
		self::lock();
		$http_response = self::fetchResponse();
		$status = null;
		switch($http_response->getStatusCode()) {
			case 304:
				// not modified
				self::respondWithCache();
				break;
			case 200:
				// expected response
				$response = Sulphur\ResponseFactory::fromString($http_response->getBody());
				$references = $response->where('Title')->contains('CMC');
				switch(count($references)) {
					case 0:
						$status = 'Keine Runden verfügbar. Hoste jetzt ein Spiel, um hier zu erscheinen.';
						break;
					case 1:
						$status = 'Folgende Runde ist jetzt verfügbar:';
						break;
					default:
						$status = 'Folgende Runden sind jetzt verfügbar:';
						break;
				}
				break;
			default:
				// some error
				$status = 'Fehler bei der Verbindung zum Masterserver.';
				break;
		}
		$content = file_get_contents(self::$template_file).PHP_EOL;

		$content .= '#cmc-dynamic-game-status:after { content: \''.$status.'\'; }'.PHP_EOL.PHP_EOL;
		$content .= '/'.'* Cached at '.date('Y-m-d H:i:s').' *'.'/';
		self::writeCache($content);
		self::unlock();
		self::respond($content);
	}

	protected static function fetchResponse() {
		$headers = array(
			'Accept' => 'text/plain',
			'User-Agent' => 'CMCGames/1.1',
		);
		if(file_exists(self::$cache_file.'.time')) {
			$headers['If-Modified-Since'] = file_get_contents(self::$cache_file.'.time');
		}
		$client = new GuzzleHttp\Client();
		$result = $client->get(self::$masterserver, array('timeout' => 5, 'headers' => $headers));
		file_put_contents(self::$cache_file.'.time', $result->getHeader('Date'));
		return $result;
	}

	protected static function isLocked() {
		return file_exists(self::$cache_file.'.lock');
	}

	protected static function lock() {
		file_put_contents(self::$cache_file.'.lock', time());
	}

	public static function unlock() {
		if(file_exists(self::$cache_file.'.lock')) {
			unlink(self::$cache_file.'.lock');
		}
	}

	protected static function respondWithCache() {
		self::respond(file_get_contents(self::$cache_file));
		exit();
	}

	protected static function writeCache($content) {
		file_put_contents(self::$cache_file, $content);
	}

	protected static function respond($content) {
		header('Content-Type: text/css; charset=utf-8');
		echo $content;
	}

}

CMCGames::handleRequest();
