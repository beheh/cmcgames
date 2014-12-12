<?php

require('./vendor/autoload.php');

header('Content-Type: text/plain');

$response = Sulphur\ResponseFactory::fromUrl('league.clonkspot.org');

$references = $response->where('Title')->contains('CMC');
$valid = array();
foreach($references as $reference) {
	if($reference) {
		$valid[] = $reference;
	}
}

foreach($valid as $reference) {
	echo $reference->Title."\n";
}
