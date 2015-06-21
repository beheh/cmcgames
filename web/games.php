<?php

require('../vendor/autoload.php');

class CMCGames {

	private static $masterserver = 'league.clonkspot.org';
	private static $template_file = __DIR__.'/../static/basic.css';
	private static $cache_file = __DIR__.'/../cache/cache.css';
	private static $max_references = 3;
	private static $scen_regex = '/(ModernCombat.c4f\\\\\\\\)?(.*)\.c4s/';

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

		$reference_markup = '';
		$http_response = self::fetchResponse();
		$status = null;
		switch($http_response->getStatusCode()) {
			case 304:
				// not modified
				self::respondWithCache();
				break;
			case 200:
				// expected response
				$parser = new Sulphur\Parser();
				$response = $parser->parse($http_response->getBody());

				$references = array();
				foreach($response->all() as $reference) {
					$valid = false;
					$playercount = 0;
					if(!$reference->first('PlayerInfos')) {
						continue;
					}
					foreach($reference->first('PlayerInfos')->all('Client') as $client) {
						$playercount += count($client->all('Player'));
					}
					if($playercount < 1)
						continue;
					foreach($reference->all('Resource') as $resource) {
						if($resource->Filename === "ModernCombat.c4d") {
							$valid = true;
							break;
						}
					}
					if($valid) {
						$references[] = $reference;
					}
				}

				// update status text
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

				// filter rounds
				usort($references, function($a, $b) {
					if($a->PasswordNeeded && !$b->PasswordNeeded) {
						return 1;
					}
					if($b->PasswordNeeded && !$a->PasswordNeeded) {
						return -1;
					}
					return $a->StartTime - $b->StartTime;
				});

				// detail rounds
				for($i = 1; $i <= self::$max_references; $i++) {
					if($i > count($references)) {
							$reference_markup .= '#cmc-dynamic-game'.$i.' { display: none; }'.PHP_EOL;
						continue;
					}
					$reference = $references[$i - 1];
					$reference_markup .= '#cmc-dynamic-game'.$i.'-image { ';
					$filename = $reference->first('Scenario')->Filename;
					$matches = array();
					if(stripos($reference->Title, 'Open Beta') !== false) {
						$reference_markup .= 'background-image: url(\'img/Betatest.png\');';
					}
					else if(preg_match(self::$scen_regex, $filename, $matches) && file_exists('img/'.basename($matches[2]).'.png')) {
						$reference_markup .= 'background-image: url(\'img/'.basename($matches[2]).'.png\');';
					}
					else {
						$reference_markup .= 'background-image: url(\'img/Unknown.png\');';
					}
					$reference_markup .= ' }'.PHP_EOL;
					$title = self::decodeSpecialChars(strip_tags($reference->Title));
					if(strpos($title, 'CMC -') === 0) {
						$title = trim(substr($title, 5));
					}
					$reference_markup .= '#cmc-dynamic-game'.$i.'-title::after { content: \''.$title.'\'; }'.PHP_EOL;
					$reference_markup .= '#cmc-dynamic-game'.$i.'-host::after { content: \'auf '.self::escape($reference->first('Client')->Name).'\'; }'.PHP_EOL;
					$state = 'Unbekannt';
					switch($reference->State) {
						case 'Lobby':
							$state = 'In der Lobby (seit '.self::textTime(time() - $reference->StartTime).')';
							break;
						case 'Running':
							$state = 'Im Spiel (seit '.self::textTime($reference->Time).')';
							break;
						case 'Paused':
							$state = 'Pausiert (Spiel läuft seit '.self::textTime(time() - $reference->StartTime).')';
							break;
					}
					$reference_markup .= '#cmc-dynamic-game'.$i.'-status::after { content: \''.$state.'\'; }'.PHP_EOL;
					$players = array();
					foreach($reference->first('PlayerInfos')->all('Client') as $client) {
						foreach($client->all('Player') as $player) {
							$players[] = trim(self::escape($player->Name));
						}
					}
					$reference_markup .= '#cmc-dynamic-game'.$i.'-playercount::after { content: \''.count($players).'\'; }'.PHP_EOL;
					$reference_markup .= '#cmc-dynamic-game'.$i.'-players::after { content: \''.implode(', ', $players).'\'; }'.PHP_EOL;
				}

				break;
			default:
				// some error
				$status = 'Fehler bei der Verbindung zum Masterserver.';
				break;
		}
		$content = file_get_contents(self::$template_file).PHP_EOL;

		$content .= '#cmc-dynamic-game-status::after { content: \''.$status.'\'; }'.PHP_EOL;
		if(!empty($reference_markup)) {
			$content .= PHP_EOL.$reference_markup;
		}
		$content .= PHP_EOL.'/'.'* Cached at '.date('Y-m-d H:i:s').' *'.'/';

		self::writeCache($content);
		self::unlock();
		self::respond($content);
	}

	public static function escape($string) {
		$string = str_replace('\\', '\\\\', $string);
		$string = self::decodeSpecialChars($string);
		$string = str_replace('\'', '\\\'', $string);
		return $string;
	}

	public static function decodeSpecialChars($string) {
		$string = preg_replace_callback('/(^|[^\\\])\\\([0-9]+)/m', function($m) {
			return $m[1].chr(octdec($m[2]));
		}, $string);
		$string = str_replace('\\\\', '\\', $string);
		return mb_convert_encoding($string, 'UTF-8', 'ISO-8859-1');
	}

	public static function textTime($time) {
		$string = 'gerade eben';
		if($time > 2 * 60) {
			$string = round($time / 60).' Minuten';
		}
		if($time > 60 * 60) {
			$string = 'über einer Stunde';
		}
		if($time > 2 * 60 * 60) {
			$string = 'über '.round($time / 60 / 60).' Stunden';
		}
		if($time > 24 * 60 * 60) {
			$string = 'einer Ewigkeit';
		}

		return $string;
	}

	protected static function fetchResponse() {
		$headers = array(
			'Accept' => 'text/plain',
			'User-Agent' => get_class().'/1.1',
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
