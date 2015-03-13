<?php

// basic setup
error_reporting(E_ALL);
ini_set('display_errors', 1);

$handle = @fopen('config.json', 'r');
if ($handle == false) {
    exit('No configuration found.');
}
$config = fread($handle, filesize('config.json'));
$config = @json_decode($config, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    exit('Could not parse configuration file.');
}

// setup configuration
date_default_timezone_set($config['timezone']);
$bot_name = $config['bot_name'];

require_once __DIR__ . '/vendor/autoload.php';

use Aura\Sql\ExtendedPdo;

$pdo = new ExtendedPdo(
    $config['database']['connection'],
    $config['database']['username'],
    $config['database']['password']
);

use GuzzleHttp\Client,
    GuzzleHttp\Subscriber\Oauth\Oauth1;

$rest_client = new Client([ 
    'base_url'  => 'https://api.twitter.com/1.1/', 
    'defaults'  => ['auth' => 'oauth'], 
]); 
$rest_client->getEmitter()->attach(
    new Oauth1([
        'consumer_key'     => $config['rest_twitter']['consumer_key'],
        'consumer_secret'  => $config['rest_twitter']['consumer_secret'],
        'token'            => $config['rest_twitter']['token'],
        'token_secret'     => $config['rest_twitter']['token_secret'],
    ])
);

$upload_client = new Client([
    'base_url'  => 'https://upload.twitter.com/1.1/',
    'defaults'  => ['auth' => 'oauth'],
]);
$upload_client->getEmitter()->attach(
    new Oauth1([
        'consumer_key'     => $config['rest_twitter']['consumer_key'],
        'consumer_secret'  => $config['rest_twitter']['consumer_secret'],
        'token'            => $config['rest_twitter']['token'],
        'token_secret'     => $config['rest_twitter']['token_secret'],
    ])
);

$streaming_client = new Client([ 
    'base_url'  => 'https://userstream.twitter.com/1.1/',
    'defaults'  => ['auth' => 'oauth'],
]);
$streaming_client->getEmitter()->attach(
    new Oauth1([
        'consumer_key'     => $config['streaming_twitter']['consumer_key'],
        'consumer_secret'  => $config['streaming_twitter']['consumer_secret'],
        'token'            => $config['streaming_twitter']['token'],
        'token_secret'     => $config['streaming_twitter']['token_secret'],
    ])
);

use Bigstock\OAuth2API\Client as BigstockApi;

$bigstock_api = new BigstockApi();
$bigstock_api->setClientCredentials(
    $config['bigstock']['api_id'],
    $config['bigstock']['secret']
);

// fetch the endpoint
try {
    $result = $streaming_client->get('user.json', ['stream' => true]);
} catch (Exception $e) {
    exit('Could not fetch the user stream.');
}

if ($result->getStatusCode() != 200) {
    exit("User stream responded with {$result->getStatusCode()}.");
}
$stream = $result->getBody();

// loop through the result
$line = '';
while (!$stream->eof()) {
    $line .= $stream->read(1);
    while (strstr($line, "\r\n") !== false) {
        list($message, $line) = explode("\r\n", $line, 2);
        $message = json_decode($message, true);
        if (
            isset($message['in_reply_to_screen_name']) &&
            $message['in_reply_to_screen_name'] == $bot_name
        ) {
            $request_count = $pdo->fetchValue("
                SELECT
                    COUNT(1) AS count
                FROM
                    request_log
                WHERE
                    screen_name = :screen_name AND
                    date_time >= :lookback_time
            ", [
                'screen_name'    => $message['user']['screen_name'],
                'lookback_time'  => (new DateTime('-4 hours'))->format('c'),
            ]);
            if ($request_count > 10) {
                echo "Throttling {$message['user']['screen_name']} for too many requests.", PHP_EOL;
                continue;
            }

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
                    if (
                        $entity_type == 'user_mentions' &&
                        $entity['screen_name'] != 'fetchmeaphoto'
                    ) {
                        $user_mentions[] = $entity['screen_name'];
                    }
                }
            }
            $query = str_replace('?', '', $query);
            $query = trim($query);
            if (empty($query)) {
                $query = '*';
            }

            $response = $bigstock_api->request("/search?q={$query}&limit=10&thumb_size=large_thumb");
            if (
                $response->response_code = 200 &&
                $response->message == 'success'
            ) {
                $image_id = rand(0, ($response->data->paging->items - 1));
                $image = $response->data->images[$image_id];

                $return_message = '.';
                foreach ($user_mentions as $user) {
                    $return_message .= "@{$user} ";
                }
                $return_message .= substr($image->title, 0, 50);
                $return_message .= " http://www.bigstockphoto.com/image-{$image->id}/";

                $remote_image = $image->large_thumb->url;
                $return_image = tempnam('/tmp', 'twitter-upload');
                copy($remote_image, $return_image);

                try {
                    $upload_response = $upload_client->post(
                        'media/upload.json',
                        [
                            'body' => [
                                'media'  => fopen($return_image, 'r'),
                            ],
                        ]
                    );
                    $media_id = $upload_response->json()['media_id_string'];

                    $rest_client->post(
                        'statuses/update.json',
                        [
                            'body' => [
                                'status'                 => $return_message,
                                'in_reply_to_status_id'  => $message['id_str'],
                                'media_ids'              => $media_id,
                            ]
                        ]
                    );
                } catch (Exception $e) {
                    error_log('Could not post a tweet with media.');
                }
            } else {
                $return_message = '.';
                foreach ($user_mentions as $user) {
                    $return_message .= "@{$user} ";
                }
                $return_message .= "sorry,  did not find an image for your search ({$query}). Please try again :(";
                $return_image = '';

                $rest_client->post(
                    'statuses/update.json',
                    [
                        'body' => [
                            'status'                 => $return_message,
                            'in_reply_to_status_id'  => $message['id_str'],
                        ],
                    ]
                );
            }
            $pdo->perform("
                INSERT INTO
                    request_log (`tweet_id`, `text`, `screen_name`, `date_time`)
                VALUES
                    (:tweet_id, :text, :screen_name, :date_time)
            ", [
                'tweet_id'     => $message['id_str'],
                'text'         => $message['text'],
                'screen_name'  => $message['user']['screen_name'],
                'date_time'    => (new DateTime())->format('c'),
            ]);
            $pdo->disconnect();
        }
    }
}

exit('End.');
