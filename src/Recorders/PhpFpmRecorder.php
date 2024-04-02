<?php

namespace Maantje\Pulse\PhpFpm\Recorders;

use hollodotme\FastCGI\Client;
use hollodotme\FastCGI\Requests\GetRequest;
use hollodotme\FastCGI\SocketConnections\NetworkSocket;
use hollodotme\FastCGI\SocketConnections\UnixDomainSocket;
use Illuminate\Config\Repository;
use Illuminate\Support\Str;
use JsonException;
use Laravel\Pulse\Events\SharedBeat;
use Laravel\Pulse\Pulse;
use RuntimeException;

class PhpFpmRecorder
{
    /**
     * The events to listen for.
     *
     * @var class-string
     */
    public string $listen = SharedBeat::class;

    /**
     * Create a new recorder instance.
     */
    public function __construct(
        protected Pulse $pulse,
        protected Repository $config
    ) {
        //
    }

    /**
     * @throws JsonException
     */
    public function record(SharedBeat $event): void
    {
        $status = $this->fpmStatus();

        $server = $this->config->get('pulse.recorders.'.self::class.'.server_name', gethostname());
        $slug = Str::slug($server);

        $this->pulse->record('active processes', $slug, $status['active processes'], $event->time)->avg()->onlyBuckets();
        $this->pulse->record('total processes', $slug, $status['total processes'], $event->time)->avg()->onlyBuckets();
        $this->pulse->record('idle processes', $slug, $status['idle processes'], $event->time)->avg()->onlyBuckets();
        $this->pulse->record('listen queue', $slug, $status['listen queue'], $event->time)->avg()->onlyBuckets();

        $this->pulse->set('php_fpm', $slug, json_encode([
            ...$status,
            'name' => $server,
        ]));
    }

    /**
     * @throws JsonException
     */
    private function fpmStatus(): array
    {
        [$sock, $url] = $this->fpmConnectionInfo();

        $response = $this->sendRequest($sock, $url);

        return json_decode( $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function sendRequest($sock, $url)
    {
        $client = new Client();

        $request = new GetRequest($url['path'].'?json', '');

        if ($sock) {
            $connection = new UnixDomainSocket($sock);

            return $client->sendRequest($connection, $request);
        }

        $connection = new NetworkSocket($url['host'], $url['port']);

        return $client->sendRequest($connection, $request);
    }

    private function fpmConnectionInfo(): array
    {
        $statusPath = $this->config->get('pulse.recorders.'.self::class.'.status_path', 'localhost:9000/status');
        $url = parse_url($statusPath);
        $sock = false;

        if (preg_match('|^unix:(.*.sock)(/.*)$|', $statusPath, $reg)) {
            $url  = parse_url($reg[2]);
            $sock = $reg[1];

            if (!file_exists($sock)) {
                throw new RuntimeException("UDS $sock not found");
            } else if (!is_writable($sock)) {
                throw new RuntimeException("UDS $sock is not writable");
            }
        }

        if (!$url || !isset($url['path'])) {
            throw new RuntimeException('Malformed URI');
        }

        return [$sock, $url];
    }
}
