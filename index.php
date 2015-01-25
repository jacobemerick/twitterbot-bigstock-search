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
            $user_mentions = [$message['user']['screen_name']];
            foreach ($message['entities'] as $entity_type => $entities) {
                foreach ($entities as $entity) {
                    $length = $entity['indices'][1] - $entity['indices'][0];
                    $query = substr_replace(
                        $query,
                        str_repeat('?', $length),
                        $entity['indices'][0],
                        $length
                    );
                    if ($entity_type == 'user_mentions' && $entity['screen_name'] != 'fetchmeaphoto') {
                        $user_mentions[] = $entity['screen_name'];
                    }
                }
            }
            $query = str_replace('?', '', $query);
            $query = trim($query);
            if (empty($query)) {
                $query = '*';
            }

            include_once __DIR__ . '/vendor/jacobemerick/bigstock-api-services/src/service/SearchService.php';
            $bigstock_request = new BigstockAPI\Service\SearchService('API_ACCOUNT');
            $bigstock_request->addTerm($query);
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
                $image_id = rand(0, ($response->data->paging->items - 1));
                $return_message = '.';
                foreach ($user_mentions as $user) {
                    $return_message .= "@{$user} ";
                }
                $return_message .= substr($response->data->images[$image_id]->title, 0, 50);
                $return_message .= " http://www.bigstockphoto.com/image-{$response->data->images[$image_id]->id}/";

                $remote_image = $response->data->images[$image_id]->small_thumb->url;
                $return_image = tempnam('/tmp', 'twitter-upload');
                copy($remote_image, $return_image);

                $response_tweet = $response_client->post('statuses/update_with_media.json', array(), array(
                    'status' => $return_message,
                    'in_reply_to_status_id' => $message['id_str'],
                ))->addPostFile('media', $return_image);
            } else {
                $return_message = '.';
                foreach ($user_mentions as $user) {
                    $return_message .= "@{$user}";
                }
                $return_message .= "sorry, I did not find an image for your search ({$query}). Please try again :(";
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
