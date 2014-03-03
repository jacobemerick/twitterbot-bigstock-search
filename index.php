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

$request = $client->get('user.json');

$factory = new PhpStreamRequestFactory();
$stream = $factory->fromRequest($request);
$line = '';

while (!$stream->feof()) {
    $line .= $stream->readLine(512);
    while (strstr($line, "\r\n") !== false) {
        list($message, $line) = explode("\r\n", $line, 2);
        $message = json_decode($message, true);
        if (isset($message['in_reply_to_screen_name']) && $message['in_reply_to_screen_name'] === 'fetchmeaphoto') {
            $query = $message['text'];
            $query = substr($message['text'], 15);

            include_once __DIR__ . '/vendor/jacobemerick/bigstock-api-services/src/service/SearchService.php';
            $bigstock_request = new BigstockAPI\Service\SearchService('API_ACCOUNT');
            $bigstock_request->addTerm($query);
            $bigstock_request->setLimit(1);
            $response = $bigstock_request->fetchJSON();


            $response_client = new Client('https://api.twitter.com/1.1');
            
            $oauth = new OauthPlugin(array(
                'consumer_key'     => 'KEY',
                'consumer_secret'  => 'SECRET',
                'token'            => 'TOKEN',
                'token_secret'     => 'TOKEN_SECRET',
            ));

            $response_client->addSubscriber($oauth);

            if ($response->response_code = 200 && $response->message == 'success') {
                $return_message = '';
                $return_message .= ".@{$message['user']['screen_name']} ";
                $return_message .= substr($response->data->images[0]->title, 0, 50);
                $return_message .= " http://www.bigstockphoto.com/image-{$response->data->images[0]->id}/";

                $remote_image = $response->data->images[0]->small_thumb->url;
                $return_image = tempnam('/tmp', 'twitter-upload');
                copy($remote_image, $return_image);

                $response_tweet = $response_client->post('statuses/update_with_media.json', array(), array(
                    'status' => $return_message,
                    'in_reply_to_status_id' => $message['id_str'],
                ))->addPostFile('media', $return_image);
            } else {
                $return_message = '';
                $return_message .= ".@{$message['user']['screen_name']} ";
                $return_message .= 'sorry, did not find an image that fit your request. Please try again :(';
                $return_image = '';

                $response_tweet = $response_client->post('statuses/update.json', array(), array(
                    'status' => $return_message,
                    'in_reply_to_status_id' => $message['id_str'],
                ));
            }
            $response_tweet->send();
        }
    }
}

exit('ALL DONE');
