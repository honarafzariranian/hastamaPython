<?php

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$config = config('broadcasting.connections.reverb');
$options = $config['options'];

echo "connection: ".json_encode($config['key'])." app_id=".json_encode($config['app_id'])."\n";
echo "options: ".json_encode($options)."\n";

$pusher = new Pusher\Pusher(
    $config['key'],
    $config['secret'],
    $config['app_id'],
    [
        'host' => $options['host'] ?? '127.0.0.1',
        'port' => $options['port'] ?? 8080,
        'scheme' => $options['scheme'] ?? 'http',
        'useTLS' => (bool) ($options['useTLS'] ?? false),
        'timeout' => 5,
    ],
);

// 1. Can we publish an event at all?
try {
    $response = $pusher->trigger('call-display', 'display.message', ['type' => 'probe', 'data' => ['hello' => 'دنیا']]);
    echo "trigger: ".json_encode($response)."\n";
} catch (Throwable $e) {
    echo "trigger FAILED: ".$e::class.': '.$e->getMessage()."\n";
}

// 2. Does the channel-info endpoint exist?  This is what `display_count` needs.
foreach (['/channels', '/channels/call-display'] as $path) {
    try {
        $info = $pusher->get($path);
        echo "get {$path}: ".json_encode($info)."\n";
    } catch (Throwable $e) {
        echo "get {$path} FAILED: ".$e::class.': '.$e->getMessage()."\n";
    }
}
