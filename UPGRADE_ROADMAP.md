# ROADMAP

> **idct/nats-jetstream-php-client** — forked from `basis-company/nats.php` in February 2026.
>
> This roadmap is based on a full audit of the codebase performed on 2026-02-27.
> It targets NATS 2.12+ compatibility, improved extensibility, and production-grade robustness.

---

## Phase 0 — Bug Fixes (Immediate)

Critical issues found during the codebase audit that should be resolved before any feature work.

- [x] **Fix `Client::unsubscribeRequests()` inverted logic** — the method sends an unsubscribe message when `$this->requestsSubscribed` is `false` (i.e. when *not* subscribed), then sets it to `true`. The condition is inverted.
- [x] **Fix `ServiceEndpoint::$num_requests` off-by-one** — `$num_requests` is initialized to `1` instead of `0` in both the constructor and `resetStats()`, causing every endpoint to report one extra request.
- [x] **Fix `Stream\Configuration::toArray()` null multiplication** — `$this->getDuplicateWindow() * 1_000_000_000` will throw a `TypeError` if `getDuplicateWindow()` returns `null`.
- [x] **Add missing `declare(strict_types=1)`** to all 7 files in `src/Service/`: `Service.php`, `ServiceEndpoint.php`, `ServiceGroup.php`, `EndpointHandler.php`, `Response/Info.php`, `Response/Ping.php`, `Response/Stats.php`.
- [x] **Fix README badges** — currently point to upstream `basis-company/nats.php` instead of this fork.

---

## Phase 1 — Code Quality & Modernization

Bring the codebase up to modern PHP 8.2+ standards and improve developer experience.

### 1.1 Native PHP Enums

Replace the 6 abstract-class pseudo-enums with native PHP 8.2+ backed enums:

- [ ] `Stream\DiscardPolicy` (currently abstract class with string constants)
- [ ] `Stream\RetentionPolicy`
- [ ] `Stream\StorageBackend`
- [ ] `Consumer\AckPolicy`
- [ ] `Consumer\DeliverPolicy`
- [ ] `Consumer\ReplayPolicy`

### 1.2 Type Safety

- [ ] Add return type declarations to all methods currently missing them (e.g. `Configuration::getName()`, `Consumer\Configuration` getters, `Bucket::delete()`, `Bucket::purge()`, `Connection::sendMessage()`, `Connection::setTimeout()`).
- [ ] Type `Connection::$socket` and `$context` properties properly (currently untyped).
- [ ] Implement `\Stringable` interface on `Message\Payload` (already has `__toString()`).
- [ ] Type `Client::process()` return value properly or split into typed methods.
- [ ] Review `Payload::__get()` magic — consider replacing with explicit, typed accessors.

### 1.3 Visibility — Private to Protected

Too many `private` properties prevent subclassing and adapter creation in libraries like `symfony-nats-messenger`. Change to `protected` where subclassing is reasonable:

- [ ] `Client`: `$handlers`, `$subscriptions`, `$services`, `$skipInvalidMessages`, `$requestsSubject`, `$requestsSubscribed`, `$nextRid`, `$requestsSid`.
- [ ] `Connection`: `$socket`, `$context`, `$activityAt`, `$pingAt`, `$pongAt`, `$prolongateTill`, `$packetSize`, `$authenticator`, `$config`.
- [ ] `Consumer\Consumer`: `$batching`, `$expires`, `$iterations`, `$interrupt`, `$handler`.
- [ ] `Stream\Stream`: `$configuration`, `$consumers`.
- [ ] `KeyValue\Bucket`: `$stream`, `$streamName`, `$streamConfiguration`.
- [ ] `Consumer\Configuration` and `Stream\Configuration`: all private properties.

### 1.4 Deprecations Cleanup

- [ ] Remove or properly deprecate the legacy `$options`/`$options2`/`$options3` array parameters in `Configuration` constructor. Add `@deprecated` annotations and a target removal version if kept temporarily.

### 1.5 Tooling & Dependencies

- [ ] **Upgrade PHPUnit** from `^9.5` to `^10` or `^11` (PHPUnit 9 is EOL for PHP 8.2+; remove deprecated config attributes like `convertErrorsToExceptions`).
- [ ] **Upgrade Monolog** from `^2.3.5` to `^3`.
- [ ] **Consolidate CS tools** — both `phpcs.xml` (PHPCS) and `.php-cs-fixer.php` (PHP-CS-Fixer) exist; pick one and remove the other.
- [ ] **Add PHPStan** (level 6+) alongside or replacing Phan for static analysis — PHPStan has broader ecosystem adoption and better IDE integration.

---

## Phase 2 — Architecture & Extensibility

Restructure the library for extensibility, testability, and decoupled integration by downstream libraries.

### 2.1 Interfaces / Contracts

Currently only **one interface** exists (`Service\EndpointHandler`). Add contracts for all major components:

- [ ] `ClientInterface` — publish, subscribe, request, dispatch, process.
- [ ] `ConnectionInterface` — connect, send, receive, close, reconnect.
- [ ] `StreamInterface` — create, delete, update, purge, info, put, publish, getLastMessage.
- [ ] `ConsumerInterface` — create, delete, info, handle, getPosition, setPosition.
- [ ] `BucketInterface` — get, put, update, delete, purge, getAll, status.
- [ ] `ConfigurationInterface` — getters for host, port, auth, TLS, timeouts.

### 2.2 Custom Exception Hierarchy

Replace generic `Exception` / `LogicException` throws with a library-specific hierarchy:

```
NatsException (base)
├── ConnectionException      — socket errors, TLS failures, EOF
│   └── AuthenticationException — NKey/JWT/user-pass failures
├── TimeoutException         — request/dispatch/consumer timeouts
├── ApiException             — JetStream API error responses (with code + description)
├── StreamException          — stream-specific failures
├── ConsumerException        — consumer-specific failures
└── ConfigurationException   — invalid configuration values
```

- [ ] Create exception classes in `src/Exception/`.
- [ ] Migrate all `throw new Exception(...)` and `throw new LogicException(...)` call sites.
- [ ] Include NATS error codes in `ApiException` as structured data.

### 2.3 Decoupling

- [ ] **Decouple `Connection` from `Client`** — `Connection` currently takes a full `Client` in its constructor and accesses `$client->configuration`, `$client->getName()`, `$client->getSubscriptions()`. It should accept `ConfigurationInterface` + minimal callback interface instead.
- [ ] **Decouple `Msg::setClient()`** — the full `Client` is injected just for the `reply()` method. Replace with a reply-capable interface (e.g. `ReplyableInterface` with `publish()` or a reply callback).
- [ ] **Decouple `Stream`, `Consumer`, `Bucket`** — accept `ClientInterface` instead of concrete `Client`.

### 2.4 Event System

Add a lightweight event/hook mechanism for connection lifecycle and message processing:

- [ ] Events: `connecting`, `connected`, `disconnected`, `reconnecting`, `reconnected`, `error`, `messageSent`, `messageReceived`.
- [ ] Support callable listeners or a PSR-14 EventDispatcher interface.

### 2.5 Namespace Migration

- [ ] Plan migration from `Basis\Nats\` to `Idct\Nats\` (or chosen namespace).
- [ ] Provide a backward-compatibility alias layer (`class_alias`) in a transition period.
- [ ] Document migration steps for downstream users.

---

## Phase 3 — NATS 2.12+ Feature Completeness

Fill in missing NATS features to achieve comprehensive protocol coverage.

### 3.1 Object Store (High Priority)

- [ ] Implement `ObjectStore\Bucket` — CRUD for large objects, chunked storage via JetStream.
- [ ] Implement `ObjectStore\ObjectInfo` — metadata model.
- [ ] Implement `ObjectStore\ObjectResult` — read result with streaming support.
- [ ] Add watch/list capabilities for object store buckets.
- [ ] Add functional tests with the dockerized NATS instance.

### 3.2 Multi-Server / Cluster Connection (High Priority)

- [ ] Parse `connect_urls` from the server `INFO` message.
- [ ] Implement connection failover / round-robin across multiple seed URLs.
- [ ] Support passing multiple server URLs in `Configuration`.
- [ ] Handle cluster topology changes at runtime.

### 3.3 Ordered Consumers

- [ ] Implement ordered consumer creation (ephemeral, single-active, auto-recreate on heartbeat gaps).
- [ ] Track consumer sequence state for gap detection and recreation.

### 3.4 Consumer Enhancements

- [ ] `filter_subjects` (multi-subject filter — currently only singular `filter_subject`).
- [ ] `backoff` — exponential backoff configuration for redeliveries.
- [ ] `metadata` — arbitrary key-value metadata on consumers.
- [ ] `pause_until` / resume — consumer pause and resume API.

### 3.5 Stream Enhancements

- [ ] Stream mirroring and sourcing configuration (`mirror`, `sources` in `Stream\Configuration`).
- [ ] Stream `metadata`.
- [ ] Stream `compression` option.
- [ ] Subject transforms (`subject_transform` config).
- [ ] Purge by subject and/or sequence (currently only full purge).
- [ ] Delete message by sequence number.
- [ ] Direct Get API (`DIRECT.GET` for direct message retrieval without consumer).

### 3.6 Connection & Protocol Enhancements

- [ ] **Drain mode** — graceful connection shutdown: stop accepting new messages, flush pending, close.
- [ ] **No-responders detection** — handle NATS `no responders` status on request-reply.
- [ ] **Reconnect buffer** — queue pending messages during reconnect window.
- [ ] **Heartbeat-based stall detection** — actively monitor idle heartbeat on consumers and connections (currently configurable but not enforced client-side).
- [ ] **Typed `PubAck`** — parse and return a structured `PubAck` response from JetStream publish (stream name, sequence, duplicate flag).
- [ ] **Max reconnect attempts** — `Connection::init()` reconnect loop is currently infinite; add configurable max attempts.
- [ ] **Backpressure** — `Queue` accumulates messages in an unbounded array; add a configurable max-queue-size.

---

## Phase 4 — Testing & CI

### 4.1 Unit Test Coverage

Add unit tests for all currently untested classes (no live NATS server required):

- [ ] `Api`
- [ ] `Queue`
- [ ] `Stream\Stream`
- [ ] `Consumer\Consumer`
- [ ] `KeyValue\Bucket`, `KeyValue\Configuration`, `KeyValue\Entry`, `KeyValue\Status`
- [ ] `Service\Service`, `Service\ServiceEndpoint`, `Service\ServiceGroup`
- [ ] `Service\Response\Info`, `Service\Response\Ping`, `Service\Response\Stats`
- [ ] `Message\Payload`, `Message\Publish`, `Message\Connect`, `Message\Subscribe`, `Message\Unsubscribe`

> With interfaces from Phase 2, mocking `Connection` and `Client` becomes straightforward for isolated unit tests.

### 4.2 Test Suite Separation

- [ ] Split into separate PHPUnit test suites: `unit` (no NATS), `functional` (needs NATS), `performance`.
- [ ] Run unit tests in CI without Docker/NATS for fast feedback.
- [ ] Run functional tests in a separate CI job with the NATS matrix.

### 4.3 CI Modernization

- [ ] Upgrade `actions/checkout` from `v2` to `v4`.
- [ ] Add Composer dependency caching (`actions/cache`).
- [ ] **Parallelize CI jobs** — `editorconfig-verify`, `php-cs-verify`, and `static-analysis` are currently sequential; they are independent and should run in parallel.
- [ ] Add code coverage thresholds / quality gates (fail CI if coverage drops below X%).
- [ ] Add matrix for new features (Object Store tests, multi-server tests).
- [ ] Consider adding mutation testing (e.g. Infection) for test quality measurement.

---

## Phase 5 — Documentation & Developer Experience

### 5.1 README Overhaul

- [ ] Replace all badges to point to this fork (`idct/nats-jetstream-php-client`).
- [ ] Fix code examples (TLS syntax error, etc.).
- [ ] Add sections for new features (Object Store, ordered consumers, multi-server).
- [ ] Add quick-start section with minimal working example.

### 5.2 New Documentation

- [ ] **CHANGELOG.md** — document all changes from the upstream fork and going forward.
- [ ] **UPGRADING.md** — migration guide from `basis-company/nats` to this fork, including namespace changes when they happen.
- [ ] **CONTRIBUTING.md** — contribution guidelines, coding standards, test requirements.
- [ ] **API documentation** — class-level PHPDoc reference (consider automated generation with phpDocumentor or similar).

### 5.3 Inline Documentation

- [ ] Add/improve PHPDoc blocks on all public methods and classes.
- [ ] Document all configuration options with types, defaults, and valid ranges.
- [ ] Add `@throws` annotations to all methods that throw exceptions.

---

## Priority & Sequencing

| Phase | Priority | Estimated Effort | Dependencies |
|-------|----------|-----------------|--------------|
| **Phase 0** — Bug Fixes | **Critical** | Small | None |
| **Phase 1** — Code Quality | **High** | Medium | Phase 0 |
| **Phase 2** — Architecture | **High** | Large | Phase 1 (enums, types) |
| **Phase 3** — NATS Features | **Medium** | Large | Phase 2 (interfaces, exceptions) |
| **Phase 4** — Testing & CI | **Medium** | Medium | Phase 2 (interfaces for mocking) |
| **Phase 5** — Documentation | **Ongoing** | Small-Medium | Parallel with all phases |

> Phases 0 and 1 can be released as a minor version bump.
> Phase 2 will be a major version bump (breaking changes: namespace, interfaces, exceptions).
> Phase 3 features can be released incrementally after Phase 2.
> Phase 4 and 5 should progress continuously alongside all other phases.

---

## Non-Goals (Out of Scope)

- **Async / event-loop support** (ReactPHP, Amp, Swoole) — desirable long-term but a fundamental architecture change; not planned for this roadmap cycle.
- **Full protocol parser rewrite** — the current message parsing works; improvements should be incremental.
- **Dropping PHP 8.2 support** — 8.2 is the minimum and will remain so for the duration of this roadmap.
