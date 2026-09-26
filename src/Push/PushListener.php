<?php

declare(strict_types=1);

namespace Orch8\Push;

use Orch8\Exception\Orch8Exception;
use Orch8\Worker\LoopHook;
use Orch8\Worker\Worker;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * A tiny non-blocking HTTP/1.1 listener that runs inside the worker loop and
 * turns verified push deliveries into queue claims (WORKER_PROTOCOL §8).
 *
 * Every `POST` (any path) is verified against the raw body; invalid → `401`,
 * malformed → `400`, valid → `202` and then `Worker::claimFromPush()`.
 * Requests must carry `Content-Length` (the engine always sends it).
 *
 * ```php
 * $worker = new Worker($url, $key, $tenant, new WorkerOptions(polling: false));
 * $worker->handle('render', $fn);
 * $listener = new PushListener(new PushRequestHandler($secret), '0.0.0.0', 8081);
 * $listener->listen();
 * $worker->addLoopHook($listener)->run();
 * ```
 *
 * For PHP-FPM / framework apps use {@see PushRequestHandler} in your route instead.
 */
final class PushListener implements LoopHook
{
    private const MAX_BODY = 1_048_576;
    private const MAX_HEADER = 16_384;
    private const IDLE_TIMEOUT_SECS = 10.0;

    /** @var resource|null */
    private $server = null;
    /** @var array<int, array{sock: resource, buf: string, since: float}> */
    private array $conns = [];
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly PushRequestHandler $handler,
        private readonly string $host = '0.0.0.0',
        private int $port = 8081,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /** Bind the listening socket (call before Worker::run(); ready to accept afterwards). */
    public function listen(): void
    {
        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server(sprintf('tcp://%s:%d', $this->host, $this->port), $errno, $errstr);
        if ($server === false) {
            throw new Orch8Exception(sprintf('push listener could not bind %s:%d: %s', $this->host, $this->port, $errstr));
        }
        stream_set_blocking($server, false);
        $this->server = $server;
        $name = stream_socket_get_name($server, false);
        if (is_string($name) && preg_match('/:(\d+)$/', $name, $m) === 1) {
            $this->port = (int) $m[1];
        }
    }

    public function port(): int
    {
        return $this->port;
    }

    public function streams(): array
    {
        $out = [];
        if ($this->server !== null) {
            $out[] = $this->server;
        }
        foreach ($this->conns as $c) {
            $out[] = $c['sock'];
        }

        return $out;
    }

    public function tick(Worker $worker): void
    {
        if ($this->server === null) {
            return;
        }
        if ($worker->isStopping()) {
            $this->close();

            return;
        }
        while (($conn = @stream_socket_accept($this->server, 0)) !== false) {
            stream_set_blocking($conn, false);
            $this->conns[(int) $conn] = ['sock' => $conn, 'buf' => '', 'since' => microtime(true)];
        }
        foreach ($this->conns as $id => $c) {
            $chunk = @fread($c['sock'], 65536);
            if (is_string($chunk) && $chunk !== '') {
                $this->conns[$id]['buf'] .= $chunk;
            }
            $this->process($id, $worker);
        }
    }

    public function close(): void
    {
        foreach ($this->conns as $c) {
            @fclose($c['sock']);
        }
        $this->conns = [];
        if ($this->server !== null) {
            @fclose($this->server);
            $this->server = null;
        }
    }

    private function process(int $id, Worker $worker): void
    {
        $c = $this->conns[$id];
        $headerEnd = strpos($c['buf'], "\r\n\r\n");
        if ($headerEnd === false) {
            if (strlen($c['buf']) > self::MAX_HEADER) {
                $this->respond($id, 431);
            } elseif (feof($c['sock']) || microtime(true) - $c['since'] > self::IDLE_TIMEOUT_SECS) {
                $this->drop($id);
            }

            return;
        }
        $lines = explode("\r\n", substr($c['buf'], 0, $headerEnd));
        $requestLine = array_shift($lines);
        $method = strtoupper((string) strtok((string) $requestLine, ' '));
        $headers = [];
        foreach ($lines as $line) {
            $p = strpos($line, ':');
            if ($p !== false) {
                $headers[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            }
        }
        $length = (int) ($headers['content-length'] ?? 0);
        if ($length > self::MAX_BODY) {
            $this->respond($id, 413);

            return;
        }
        $body = substr($c['buf'], $headerEnd + 4);
        if (strlen($body) < $length) {
            if (feof($c['sock']) || microtime(true) - $c['since'] > self::IDLE_TIMEOUT_SECS) {
                $this->drop($id);
            }

            return;
        }
        $body = substr($body, 0, $length);
        if ($method !== 'POST') {
            $this->respond($id, 405);

            return;
        }
        $result = $this->handler->handleRaw(
            $headers['x-orch8-timestamp'] ?? null,
            $headers['x-orch8-signature'] ?? null,
            $body,
        );
        $this->respond($id, $result->status);
        if ($result->envelope !== null) {
            $worker->claimFromPush($result->envelope);
        } else {
            $this->logger->warning('orch8 push rejected', ['status' => $result->status, 'error' => $result->error]);
        }
    }

    private function respond(int $id, int $status): void
    {
        $reason = [202 => 'Accepted', 400 => 'Bad Request', 401 => 'Unauthorized', 405 => 'Method Not Allowed', 413 => 'Payload Too Large', 431 => 'Request Header Fields Too Large'][$status] ?? 'Status';
        $sock = $this->conns[$id]['sock'];
        stream_set_blocking($sock, true);
        @fwrite($sock, sprintf("HTTP/1.1 %d %s\r\nContent-Length: 0\r\nConnection: close\r\n\r\n", $status, $reason));
        $this->drop($id);
    }

    private function drop(int $id): void
    {
        @fclose($this->conns[$id]['sock']);
        unset($this->conns[$id]);
    }
}
