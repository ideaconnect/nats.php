# NATS JetStream Client for PHP

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![CI](https://github.com/idct/nats-jetstream-php-client/actions/workflows/ci.yml/badge.svg)](https://github.com/idct/nats-jetstream-php-client/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/github/release/idct/nats-jetstream-php-client.svg)](https://github.com/idct/nats-jetstream-php-client/releases)
[![Total Downloads](https://img.shields.io/packagist/dt/idct/nats-jetstream-php-client.svg)](https://packagist.org/packages/idct/nats-jetstream-php-client)

A PHP client for [NATS](https://nats.io/) with full JetStream, Key-Value, and Microservices support. Targets **NATS 2.12+**.

## About This Fork

This library was originally forked from [basis-company/nats.php](https://github.com/basis-company/nats.php) in February 2026, driven by the need for timely updates and new features required by [idct/symfony-nats-messenger](https://github.com/ideaconnect/symfony-nats-messenger/).

Since then, the library has taken its own development direction and **will continue to diverge** from the original. The intent is to actively develop this library to provide comprehensive, production-ready NATS support for PHP — including modern NATS 2.12+ features, improved extensibility, and better code quality.

See the [ROADMAP](UPGRADE_ROADMAP.md) for the full development plan.

> **Note:** The library currently retains the original `Basis\Nats\` namespace for backward compatibility. A namespace migration is planned — see the roadmap for details.

Contributions, feedback, and bug reports are welcome.

## Features

- **Core NATS** — publish/subscribe, request/reply, queue groups
- **JetStream** — streams, durable & ephemeral consumers, ack/nak/term/progress
- **Key-Value Store** — get, put, update, delete, purge, history
- **Microservices** — service discovery, endpoint groups, ping/info/stats
- **Message Scheduling** — `@at`, `@every`, cron expressions (NATS 2.12+)
- **Authentication** — user/pass, token, NKey (Ed25519), JWT, TLS client certificates
- **Headers** — full HMSG/HPUB support, message deduplication via `Nats-Msg-Id`
- **Reconnection** — automatic reconnect with configurable delay strategies

## Requirements

- PHP **>= 8.2**
- NATS Server **>= 2.10** (2.12+ recommended for full feature support)
- `ext-sodium` or `paragonie/sodium_compat` (only for NKey authentication)

## Table of Contents

- [Installation](#installation)
- [Connecting](#connecting)
  - [Connecting with TLS](#connecting-with-tls)
  - [Connecting with JWT](#connecting-with-jwt)
- [Publish Subscribe](#publish-subscribe)
- [Request Response](#request-response)
- [JetStream API Usage](#jetstream-api-usage)
- [Message Scheduling](#message-scheduling)
- [Microservices](#microservices)
- [Key Value Storage](#key-value-storage)
- [Performance](#performance)
- [Configuration Options](#configuration-options)
- [Roadmap](#roadmap)
- [Contributing](#contributing)
- [Acknowledgements](#acknowledgements)

## Installation
The recommended way to install the library is through [Composer](http://getcomposer.org):
```bash
composer require idct/nats-jetstream-php-client
```

The NKeys functionality requires Ed25519, which is provided in `libsodium` extension or `sodium_compat` package.

## Connecting
```php
use Basis\Nats\Client;
use Basis\Nats\Configuration;

// you can override any default configuration key using constructor
$configuration = new Configuration(
    host: 'nats-service',
    user: 'basis',
    pass: 'secret',
);

// delay configuration options are changed via setters
// default delay mode is constant - first retry be in 1ms, second in 1ms, third in 1ms
$configuration->setDelay(0.001);

// linear delay mode - first retry be in 1ms, second in 2ms, third in 3ms, fourth in 4ms, etc...
$configuration->setDelay(0.001, Configuration::DELAY_LINEAR);

// exponential delay mode - first retry be in 10ms, second in 100ms, third in 1s, fourth if 10 seconds, etc...
$configuration->setDelay(0.01, Configuration::DELAY_EXPONENTIAL);


$client = new Client($configuration);
$client->ping(); // true

```

### Connecting with TLS
Typically, when connecting to a cluster with TLS enabled the connection settings do not change. The client lib will automatically switch over to TLS 1.2. However, if you're using a self-signed certificate you may have to point to your local CA file using the tlsCaFile setting.

When connecting to a nats cluster that requires the client to provide TLS certificates use the tlsCertFile and tlsKeyFile to point at your local TLS certificate and private key file.

Nats Server documentation for:
- [Enabling TLS](https://docs.nats.io/running-a-nats-service/configuration/securing_nats/tls)
- [Enabling TLS Authentication](https://docs.nats.io/running-a-nats-service/configuration/securing_nats/auth_intro/tls_mutual_auth)
- [Creating self-signed TLS certs for Testing](https://docs.nats.io/running-a-nats-service/configuration/securing_nats/tls#self-signed-certificates-for-testing)

Connection settings when connecting to a nats server that has TLS and TLS Client verify enabled.
```php
use Basis\Nats\Client;
use Basis\Nats\Configuration;

// you can override any default configuration key using constructor
$configuration = new Configuration(
    host: 'tls-service-endpoint',
    tlsCertFile: "./certs/client-cert.pem",
    tlsKeyFile: "./certs/client-key.pem",
    tlsCaFile: "./certs/ca-cert.pem",
);

$configuration->setDelay(0.001);

$client = new Client($configuration);
$client->ping(); // true
```

## Connecting with JWT

To use NKey with JWT, simply provide them in the `Configuration` options as `jwt` and `nkey`.
You can also provide a credentials file with `CredentialsParser`

```php
use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Basis\Nats\NKeys\CredentialsParser;

$configuration = new Configuration(
    ...CredentialsParser::fromFile($credentialPath),
    host: 'localhost',
    port: 4222,
);

$client = new Client($configuration);
```

## Publish Subscribe

```php
// queue usage example
$queue = $client->subscribe('test_subject');

$client->publish('test_subject', 'hello');
$client->publish('test_subject', 'world');

// optional message fetch
// if there are no updates null will be returned
$message1 = $queue->fetch();
echo $message1->payload . PHP_EOL; // hello

// locks until message is fetched from subject
// to limit lock timeout, pass optional timeout value
$message2 = $queue->next();
echo $message2->payload . PHP_EOL; // world

$client->publish('test_subject', 'hello');
$client->publish('test_subject', 'batching');

// batch message fetching, limit argument is optional
$messages = $queue->fetchAll(10);
echo count($messages);

// fetch all messages that are published to the subject client connection
// queue will stop message fetching when another subscription receives a message
// in advance you can time limit batch fetching
$queue->setTimeout(1); // limit to 1 second
$messages = $queue->fetchAll();

// reset subscription
$client->unsubscribe($queue);

// callback hell example
$client->subscribe('hello', function ($message) {
    var_dump('got message', $message); // tester
});

$client->publish('hello', 'tester');
$client->process();

// if you want to append some headers, construct payload manually
use Basis\Nats\Message\Payload;

$payload = new Payload('tester', [
    'Nats-Msg-Id' => 'payload-example'
]);

$client->publish('hello', $payload);

```

## Request Response
There is a simple wrapper over publish and feedback processing, so payload can be constructed manually same way.
```php
$client->subscribe('hello.request', function ($name) {
    return "Hello, " . $name;
});

// async interaction
$client->request('hello.request', 'Nekufa1', function ($response) {
    var_dump($response); // Hello, Nekufa1
});

$client->process(); // process request

// sync interaction (block until response get back)
$client->dispatch('hello.request', 'Nekufa2'); // Hello, Nekufa2
```

## JetStream Api Usage
```php
use Basis\Nats\Stream\RetentionPolicy;
use Basis\Nats\Stream\StorageBackend;

$accountInfo = $client->getApi()->getInfo(); // account_info_response object

$stream = $client->getApi()->getStream('mailer');

$stream->getConfiguration()
    ->setRetentionPolicy(RetentionPolicy::WORK_QUEUE)
    ->setStorageBackend(StorageBackend::MEMORY)
    ->setSubjects(['mailer.greet', 'mailer.bye']);

// stream is created with given configuration
$stream->create();

// and put some tasks so workers would be doing something
$stream->put('mailer.greet', 'nekufa@gmail.com');
$stream->put('mailer.bye', 'nekufa@gmail.com');

var_dump($stream->info()); // can stream info

// this should be set in your worker
$greeter = $stream->getConsumer('greeter');
$greeter->getConfiguration()->setSubjectFilter('mailer.greet');
// consumer would be created would on first handle call
$greeter->handle(function ($address) {
    mail($address, "Hi there!");
});

var_dump($greeter->info()); // can consumer info

$goodbyer = $stream->getConsumer('goodbyer');
$goodbyer->getConfiguration()->setSubjectFilter('mailer.bye');
$goodbyer->create(); // create consumer if you don't want to handle anything right now
$goodbyer->handle(function ($address) {
    mail($address, "See you later");
});

// you can configure batching and iteration count using chain api
$goodbyer
    ->setBatching(2) // how many messages would be requested from nats stream
    ->setIterations(3) // how many times message request should be sent
    ->handle(function () {
        // if you need to break on next iteration simply call interrupt method
        // batch will be processed to the end and the handling would be stopped
        // $goodbyer->interrupt();
    });

// consumer can be used via queue interface
$queue = $goodbyer->getQueue();
while ($message = $queue->next()) {
    if (rand(1, 10) % 2 == 0) {
        mail($message->payload, "See you later");
        $message->ack();
    } else {
        // not ack with 1 second timeout
        $message->nack(1);
    }
    // stop processing
    if (rand(1, 10) % 2 == 10) {
        // don't forget to unsubscribe
        $client->unsubscribe($queue);
        break;
    }
}

// use fetchAll method to batch process messages
// let's set batch size to 50
$queue = $goodbyer->setBatching(50)->create()->getQueue();

// fetching 100 messages provides 2 stream requests
// limit message fetching to 1 second
// it means no more that 100 messages would be fetched
$messages = $queue->setTimeout(1)->fetchAll(100);

$recipients = [];
foreach ($messages as $message) {
    $recipients[] = (string) $message->payload;
}

mail_to_all($recipients, "See you later");

// ack all messages
foreach ($messages as $message) {
    $message->ack();
}


// you also can create ephemeral consumer
// the only thing that ephemeral consumer is created as soon as object is created
// you have to create full consumer configuration first
use Basis\Nats\Consumer\Configuration as ConsumerConfiguration;
use Basis\Nats\Consumer\DeliverPolicy;

$configuration = (new ConsumerConfiguration($stream->getName()))
    ->setDeliverPolicy(DeliverPolicy::NEW)
    ->setSubjectFilter('mailer.greet');

$ephemeralConsumer = $stream->createEphemeralConsumer($configuration);

// now you can use ephemeral consumer in the same way as durable consumer
$ephemeralConsumer->handle(function ($address) {
    mail($address, "Hi there!");
});

// the only difference - you don't have to remove it manually, it will be deleted by NATS when socket connection is closed
// be aware that NATS will not remove that consumer immediately, process can take few seconds
var_dump(
    $ephemeralConsumer->getName(),
    $ephemeralConsumer->info(),
);

// if you need to append some headers, construct payload manually
use Basis\Nats\Message\Payload;

$payload = new Payload('nekufa@gmail.com', [
    'Nats-Msg-Id' => 'single-send'
]);

$stream->put('mailer.bye', $payload);

```

## Message Scheduling

Requires **NATS Server >= 2.12**. See [ADR-51](https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-51.md) and the [NATS 2.12 release notes](https://nats.io/blog/nats-server-2.12-release/) for background.

Message scheduling lets you publish a message now and have the server deliver it at a specific time or on a recurring schedule. To use it, create a stream with `allowMsgSchedules` enabled, then publish to any of its subjects with two headers:
- `Nats-Schedule` — when/how often to deliver (see formats below)
- `Nats-Schedule-Target` — the subject the scheduled message should be delivered to

**Schedule formats**

| Format | Example | Description |
| --- | --- | --- |
| `@at <RFC 3339>` | `@at 2026-06-01T12:00:00Z` | Deliver at a specific UTC time |
| Cron expression | `0 9 * * 1-5` | Standard cron (e.g. weekdays at 09:00) |
| `@every <duration>` | `@every 30s` | Repeat every duration (Go syntax: `s`, `m`, `h`) |
| Predefined | `@hourly`, `@daily`, `@weekly`, `@monthly`, `@yearly` | Named intervals |

Messages delivered by the scheduler carry a `Nats-Scheduler` response header.

```php
use Basis\Nats\Message\Payload;
use Basis\Nats\Stream\Configuration as StreamConfiguration;

// 1. Create a stream with scheduling support enabled
$stream = $client->getApi()->getStream('tasks');
$stream->getConfiguration()
    ->setSubjects(['tasks.inbox', 'tasks.scheduled'])
    ->setAllowMsgSchedules(true);
$stream->create();

// 2. Publish a message scheduled for 30 seconds from now
$deliverAt = (new \DateTimeImmutable('+30 seconds'))->format(\DateTimeInterface::RFC3339);
$stream->put('tasks.inbox', new Payload('hello', [
    'Nats-Schedule'        => '@at ' . $deliverAt,
    'Nats-Schedule-Target' => 'tasks.scheduled',
]));

// 3. Consume scheduled deliveries
$consumer = $stream->getConsumer('scheduled-worker');
$consumer->getConfiguration()->setSubjectFilter('tasks.scheduled');
$consumer->handle(function (Payload $payload) {
    echo 'Received at ' . date('H:i:s') . ': ' . $payload . PHP_EOL;
    echo 'Scheduler: ' . $payload->getHeader('Nats-Scheduler') . PHP_EOL;
});
```

For a recurring schedule use a cron expression or `@every` instead:
```php
// Deliver every 10 minutes
$stream->put('tasks.inbox', new Payload('ping', [
    'Nats-Schedule'        => '@every 10m',
    'Nats-Schedule-Target' => 'tasks.scheduled',
]));
```

## Microservices
The services feature provides a simple way to create microservices that leverage NATS.

In the example below, you will see an example of creating an index function for the posts microservice. The request can
be accessed under "v1.posts" and then individual post by "v1.posts.{post_id}".
```php
// Define a service
$service = $client->service(
    'PostsService',
    'This service is responsible for handling all things post related.',
    '1.0'
);

// Create the version group
$version = $service->addGroup('v1');

// Create the index posts endpoint handler
class IndexPosts implements \Basis\Nats\Service\EndpointHandler {

    public function handle(\Basis\Nats\Message\Payload $payload): array
    {
        // Your application logic
        return [
            'posts' => []
        ];
    }
}

// Create the index endpoint
$version->addEndpoint("posts", IndexPosts::class);

// Create the service group
$posts = $version->addGroup('posts');

// View post endpoint
$posts->addEndpoint(
    '*',
    function (\Basis\Nats\Message\Payload $payload) {
        $postId = explode('.', $payload->subject);
        $postId = $postId[count($postId)-1];

        return [
            'post' => []
        ];
    }
);

// Run the service
$service->run();
```


## Key Value Storage
```php
$bucket = $client->getApi()->getBucket('bucket_name');

// basics
$bucket->put('username', 'nekufa');
echo $bucket->get('username'); // nekufa

// safe update (given revision)
$entry = $bucket->getEntry('username');
echo $entry->value; // nekufa
$bucket->update('username', 'bazyaba', $entry->revision);

// delete value
$bucket->delete('username');

// purge value history
$bucket->purge('username');

// get bucket stats
var_dump($bucket->getStatus());

// in advance, you can fetch all bucket values
$bucket->update('email', 'nekufa@gmail.com');
var_dump($bucket->getAll()); // ['email' => 'nekufa@gmail.com', 'username' => 'nekufa']
```

## Performance

Benchmarks on an AMD Ryzen 5 3600X with NATS running in Docker show approximately **370k msg/s** for publishing and **360k msg/s** for receiving in non-verbose mode.

Run the performance test suite on your own environment:

```bash
# start a local NATS instance (e.g. using the bundled Docker setup)
cd docker && docker compose up -d && cd ..

# install dependencies
composer install

# run the benchmark
export NATS_HOST=0.0.0.0
export NATS_PORT=4222
export NATS_CLIENT_LOG=1
composer run perf-test
```

## Configuration Options

The following is the list of configuration options and default values.

| Option              | Default    | Description                                                                                                                                                                                 |
| ------------------- | ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `inboxPrefix`       | `"_INBOX"` | Sets de prefix for automatically created inboxes                                                                                                                                            |
| `jwt`               |            | Token for [JWT Authentication](https://docs.nats.io/running-a-nats-service/configuration/securing_nats/auth_intro/jwt). Alternatively you can use [CredentialsParser](#connecting-with-jwt) |
| `nkey`              |            | Ed25519 based public key signature used for [NKEY Authentication](https://docs.nats.io/running-a-nats-service/configuration/securing_nats/auth_intro/nkey_auth).                            |
| `pass`              |            | Sets the password for a connection.                                                                                                                                                         |
| `pedantic`          | `false`    | Turns on strict subject format checks.                                                                                                                                                      |
| `pingInterval`      | `2`        | Number of seconds between client-sent pings.                                                                                                                                                |
| `port`              | `4222`     | Port to connect to (only used if `servers` is not specified).                                                                                                                               |
| `timeout`           | 1          | Number of seconds the client will wait for a connection to be established.                                                                                                                  |
| `token`             |            | Sets a authorization token for a connection.                                                                                                                                                |
| `tlsHandshakeFirst` | `false`    | If true, the client performs the TLS handshake immediately after connecting, without waiting for the server’s INFO message.                                                                 |
| `tlsKeyFile`        |            | TLS 1.2 Client key file path.                                                                                                                                                               |
| `tlsCertFile`       |            | TLS 1.2 Client certificate file path.                                                                                                                                                       |
| `tlsCaFile`         |            | TLS 1.2 CA certificate filepath.                                                                                                                                                            |
| `user`              |            | Sets the username for a connection.                                                                                                                                                         |
| `verbose`           | `false`    | Turns on `+OK` protocol acknowledgements.                                                                                                                                                   |

## Roadmap

This library is under active development. See [ROADMAP.md](ROADMAP.md) for the full plan covering:

- Bug fixes and code quality improvements
- Modern PHP 8.2+ features (native enums, typed properties)
- Architectural changes for extensibility (interfaces, custom exceptions, decoupled components)
- NATS 2.12+ feature completeness (Object Store, ordered consumers, multi-server clusters, drain mode)
- Expanded test coverage and CI modernization

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Ensure `composer static-analysis` and `composer test` pass.
4. Submit a pull request

Use the dockerized NATS instances from the `docker/` folder for running functional tests.

## Acknowledgements

This library is built on the excellent foundation laid by **Dmitry Krokhin** ([@nekufa](https://github.com/nekufa)) and the contributors to [basis-company/nats.php](https://github.com/basis-company/nats.php).

A huge thank you to Dmitry for creating and open-sourcing `nats.php` — the core protocol implementation, JetStream support, NKey authentication, and overall architecture that this library builds upon are all his work. Without that foundation, this fork would not exist.

If your use case is covered by the original library and its release cadence works for you, please consider using and contributing to [basis-company/nats.php](https://github.com/basis-company/nats.php) directly.

# 💖 Love my work? Support it! 🚀

* 💝 **Sponsor**: https://github.com/sponsors/ideaconnect
* ☕ **Buy me a coffee**: https://buymeacoffee.com/idct
* 🪙 **BTC**: bc1qntms755swm3nplsjpllvx92u8wdzrvs474a0hr
* 💎 **ETH**: 0x08E27250c91540911eD27F161572aFA53Ca24C0a
* ⚡ **TRX**: TVXWaU4ScNV9RBYX5RqFmySuB4zF991QaE
* 🚀 **LTC**: LN5ApP1Yhk4iU9Bo1tLU8eHX39zDzzyZxB
