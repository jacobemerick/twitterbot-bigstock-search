<?php

require_once __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use GuzzleHttp\Stream;
use GuzzleHttp\Adapter\Curl\CurlAdapter;
use GuzzleHttp\Message\MessageFactory;
use GuzzleHttp\Post\PostFile;
use Bigstock\OAuth2API\Client as BigstockClient;

$client = new Client(['base_url' => 'https://userstream.twitter.com/1.1/']);

$oauth = new Oauth1([
    'consumer_key'     => 'qZcHgaYs38jT3rzcraIA',
    'consumer_secret'  => 'WQYHOhntZ8X0UVuWnUFQrobUQc00JiXIQm6ZKNIZK8',
    'token'            => '2365888927-jzxye6WgMaGYHc1ep7BxusAzaiqpD6gi65Mee0x',
    'token_secret'     => 'uJxEvsunnbxrxXWi0qBXcTt2aQKyEcHZ3BYuNlnMXZLiE',
]);

$client->getEmitter()->attach($oauth);
$request = $client->get('user.json', ['auth' => 'oauth', 'stream' => true]);
$stream = $request->getBody();

$bigstock = new BigstockClient();
$bigstock->setClientCredentials(572402, '3a0099c7858a6d63174678743801e75e93e6e8cd');

$line = '';
while (!$stream->eof()) {
    $line .= $stream->read(1);
    while (strstr($line, "\r\n") !== false) {
        list($message, $line) = explode("\r\n", $line, 2);
        $message = json_decode($message, true);
        if (isset($message['in_reply_to_screen_name']) && $message['in_reply_to_screen_name'] === 'fetchmeaphoto') {
            $query = $message['text'];
            $query = substr($message['text'], 15);

            $response = $bigstock->request('search', array('q' => $query));

            $response_client = new Client(['base_url' => 'https://api.twitter.com/1.1/', 'adapter' => new CurlAdapter(new MessageFactory())]);
            
            $oauth = new Oauth1([
                'consumer_key'     => 'qZcHgaYs38jT3rzcraIA',
                'consumer_secret'  => 'WQYHOhntZ8X0UVuWnUFQrobUQc00JiXIQm6ZKNIZK8',
                'token'            => '2365888927-jzxye6WgMaGYHc1ep7BxusAzaiqpD6gi65Mee0x',
                'token_secret'     => 'uJxEvsunnbxrxXWi0qBXcTt2aQKyEcHZ3BYuNlnMXZLiE',
            ]);

            $response_client->getEmitter()->attach($oauth);

            if (false && $response->response_code = 200 && $response->message == 'success') {
                $return_message = '';
                $return_message .= ".@{$message['user']['screen_name']} ";
                $return_message .= substr($response->data->images[0]->title, 0, 50);
                $return_message .= " http://www.bigstockphoto.com/image-{$response->data->images[0]->id}/";

                $remote_image = $response->data->images[0]->small_thumb->url;
                $return_image = tempnam('/tmp', 'twitter-upload');
                copy($remote_image, $return_image);

                $response_tweet = $response_client->post('statuses/update_with_media.json', [
                    'body' => [
                        'status'                 => $return_message,
                        'in_reply_to_status_id'  => $message['id_str'],
                    ]
                ])->addPostFile('media', fopen($return_image, 'r'));
            } else {
                $return_message = '';
                $return_message .= ".@{$message['user']['screen_name']} ";
                $return_message .= 'sorry, did not find an image that fit your request. Please try again :(';
                $return_image = '';

                $response_tweet = $response_client->post('statuses/update.json', [
                    'body' => [
                        'status'                 => $return_message,
                        'in_reply_to_status_id'  => $message['id_str'],
                    ],
                ]);
            }
            //$response_client->send($response_tweet);
        }
    }
}

exit('ALL DONE');
