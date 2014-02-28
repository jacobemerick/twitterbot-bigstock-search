<?php

require_once __DIR__ . '/vendor/autoload.php';

use Guzzle\Http\Client;
use Guzzle\Plugin\Oauth\OauthPlugin;
use Guzzle\Stream\PhpStreamRequestFactory;

$client = new Client('https://userstream.twitter.com/1.1');

$oauth = new OauthPlugin(array(
    'consumer_key'     => 'KEY',
    'consumer_secret'  => 'SECRET',
    'token'            => 'TOKEN',
    'token_secret'     => 'TOKEN_SECRET',
));

$client->addSubscriber($oauth);

$request = $client->post('user.json', array(), array('with' => 'user'));

//echo '<pre>'; var_dump($request); exit;

$factory = new PhpStreamRequestFactory();
$stream = $factory->fromRequest($request);

while (!$stream->feof()) {
    $line = $stream->readLine();
    $data = json_decode($line, true);
//    echo '<pre>'; var_dump($data); echo '</pre>'; echo '<hr />';
}

exit('ALL DONE');