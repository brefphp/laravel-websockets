# Bref Laravel WebSockets

WebSockets for Laravel on AWS Lambda, with [Bref](https://bref.sh) and API Gateway.

No server to run: API Gateway holds the connections, Lambda runs on events. Laravel's broadcasting works as documented: `ShouldBroadcast` events, `routes/channels.php`, and Laravel Echo with `pusher-js`, including private channels, presence channels and client events (whispers).

## Why?

Laravel's WebSocket server, [Reverb](https://laravel.com/docs/reverb), is a long-running process: it cannot run on Lambda. The alternatives are a server to operate, or a third-party service like Pusher, billed per plan even when nothing happens.

API Gateway WebSockets are serverless, but they do not speak the protocol of Laravel Echo. This package implements that protocol on API Gateway, so you keep Laravel's documented API and pay only for what you use.

## Installation

```bash
composer require bref/laravel-websockets
npm install laravel-echo pusher-js
```

### 1. Enable broadcasting in Laravel

Add the channels file in `bootstrap/app.php`:

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    channels: __DIR__.'/../routes/channels.php',
    // ...
)
```

And create `routes/channels.php` as described in [the Laravel documentation](https://laravel.com/docs/broadcasting#defining-authorization-callbacks).

Do not run `php artisan install:broadcasting`: it asks to pick one of Laravel's drivers.

### 2. Deploy the WebSocket API

In `serverless.yml`:

```yaml
provider:
    # ...
    environment:
        BROADCAST_CONNECTION: api-gateway
        BREF_WEBSOCKET_API_ID: !Ref WebsocketsApi
        BREF_WEBSOCKET_STAGE: ${sls:stage}
    iam:
        role:
            statements:
                - Effect: Allow
                  Action: execute-api:ManageConnections
                  Resource: !Sub 'arn:aws:execute-api:${AWS::Region}:${AWS::AccountId}:${WebsocketsApi}/*'
                - Effect: Allow
                  Action: lambda:InvokeFunction
                  Resource: !Sub 'arn:aws:lambda:${AWS::Region}:${AWS::AccountId}:function:${self:service}-${sls:stage}-websocket'

functions:
    # ...
    websocket:
        handler: Bref\LaravelWebsockets\Handler
        runtime: php-85
        timeout: 28
        events:
            - websocket: $connect
            - websocket: $disconnect
            - websocket: $default
```

If your application runs in a VPC, give this function the same `vpc` configuration as your other functions. Publishing events requires internet access, which VPC functions get through a NAT Gateway.

Then pick where subscriptions are stored.

### 3. Store subscriptions

API Gateway holds the connections. The application stores which connection listens to which channel, in your database or in DynamoDB:

| | Database (default) | DynamoDB |
|---|---|---|
| Use it for | Applications with a database and little traffic, back offices, preview environments | Many connections, frequent reconnections, or no database |
| Setup | Run `php artisan migrate` | Add a table and permissions to `serverless.yml` |
| Cost | Nothing more | Less than $3/month for 1000 connections |
| Time per operation | About 3 to 4 ms | About 3 to 4 ms |
| First operation of a request | 12 to 29 ms (connecting) | 34 to 47 ms (connecting) |
| Reconnection bursts | Load your database, with one SQL connection per concurrent Lambda | No impact on your database |
| Expired rows | Purged by the package | Purged by DynamoDB |

The database is the simplest choice: it is already there. Each browser tab left open writes to it every 2 hours, which keeps a database that pauses when idle (like Aurora Serverless) awake.

To use DynamoDB:

```yaml
provider:
    environment:
        BREF_WEBSOCKET_STORE: dynamodb
        BREF_WEBSOCKET_DYNAMODB_TABLE: !Ref WebsocketSubscriptions
    iam:
        role:
            statements:
                - Effect: Allow
                  Action: [dynamodb:Query, dynamodb:GetItem, dynamodb:PutItem, dynamodb:DeleteItem]
                  Resource:
                      - !GetAtt WebsocketSubscriptions.Arn
                      - !Sub '${WebsocketSubscriptions.Arn}/index/*'

resources:
    Resources:
        WebsocketSubscriptions:
            Type: AWS::DynamoDB::Table
            Properties:
                BillingMode: PAY_PER_REQUEST
                AttributeDefinitions:
                    - { AttributeName: channel, AttributeType: S }
                    - { AttributeName: connectionId, AttributeType: S }
                KeySchema:
                    - { AttributeName: channel, KeyType: HASH }
                    - { AttributeName: connectionId, KeyType: RANGE }
                GlobalSecondaryIndexes:
                    - IndexName: by-connection
                      KeySchema:
                          - { AttributeName: connectionId, KeyType: HASH }
                      Projection: { ProjectionType: ALL }
                TimeToLiveSpecification:
                    AttributeName: expiresAt
                    Enabled: true
```

### 4. Connect Echo

Deploying displays the URL of the API, like `wss://abc123.execute-api.eu-west-3.amazonaws.com/prod`. Give its host and stage to your frontend:

```ini
VITE_WEBSOCKET_HOST=abc123.execute-api.eu-west-3.amazonaws.com
VITE_WEBSOCKET_STAGE=prod
```

```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: 'bref',
    wsHost: import.meta.env.VITE_WEBSOCKET_HOST,
    // The trailing "?" is required, it keeps the rest of the URL out of the path
    wsPath: `/${import.meta.env.VITE_WEBSOCKET_STAGE}?`,
    wsPort: 80,
    wssPort: 443,
    forceTLS: true,
    enabledTransports: ['ws', 'wss'],
    // Ping every 9 minutes instead of 2: each ping costs a Lambda invocation and 2 messages
    activityTimeout: 540000,
});
```

`key` can be any value. From there, follow [the Laravel documentation](https://laravel.com/docs/broadcasting).

### Restrict origins

By default, any website can open a connection. To allow only your domains:

```yaml
provider:
    environment:
        BREF_WEBSOCKET_ALLOWED_ORIGINS: 'example.com,*.example.com'
```

### Local development

The `api-gateway` driver needs AWS. Set `BROADCAST_CONNECTION=log` in your local `.env`.

## API Gateway or a small Reverb server

API Gateway charges per message delivered, about $1.25 to $1.50 per million all included (eu-west-3), and almost nothing when idle. What counts is **connections × events**, more than the number of connections.

| Use case | Messages per month | API Gateway | Recommendation |
|---|---|---|---|
| Notifications: 20 connections, 1 event/minute | 0.9 million | $1 to $2 | API Gateway |
| Live back office: 20 connections, 1 event/second, office hours | 12.7 million | $19 | API Gateway |
| Dashboard on a screen: 20 connections, 1 event/second, 24/7 | 52 million | $78 | Either, depending on your budget |
| Public website: 1000 connections, 10 events/minute | 432 million | $545 | A Reverb server |

Start with API Gateway. Above about 50 million messages per month, or when an event regularly reaches many people, run [Laravel Reverb](https://laravel.com/docs/reverb) on a small always-on server: a [Lightsail](https://aws.amazon.com/lightsail/) container (nano, $7/month with 500 GB of transfer included) handled 1000 connections and 10 events/minute at about 1% CPU. Your events, channels and Echo code stay the same, only the configuration changes:

- install the `uv` PHP extension: without it, Reverb stops at about 1000 connections,
- set `BROADCAST_CONNECTION=reverb` and the `REVERB_*` variables,
- remove `wsPath` and set `wsHost` to the server's URL in Echo,
- use queued broadcasts (`ShouldBroadcast`), as events cannot be sent while the server restarts.

One server is a single point of failure: clients reconnect by themselves, but events sent during an outage are lost.

## Limits

- Events over 127 KB throw a `BroadcastException`: API Gateway refuses them.
- Events are billed per 32 KB: a 33 KB event counts as two messages per connection.
- [Encrypted private channels](https://laravel.com/docs/broadcasting#encrypted-private-channels) are not supported.
- API Gateway closes connections after 2 hours: `pusher-js` reconnects and subscribes again by itself. Events sent during those few hundred milliseconds are lost.
- API Gateway accepts 500 new connections per second by default, per account and region.
- If Lambda throttles the function, a `pusher:subscribe` message can be lost, and `pusher-js` does not send it again. Make sure the function has enough concurrency for your reconnections.
- Events sent in parallel have no ordering guarantee.

## Contributing

```bash
composer install
composer test
```

To run the stores against real databases:

```bash
docker run -d --rm --name lw-mysql -p 33306:3306 -e MYSQL_DATABASE=test -e MYSQL_USER=test -e MYSQL_PASSWORD=test -e MYSQL_RANDOM_ROOT_PASSWORD=1 mysql:8.4
docker run -d --rm --name lw-pg -p 35432:5432 -e POSTGRES_DB=test -e POSTGRES_USER=test -e POSTGRES_PASSWORD=test postgres:17-alpine
docker run -d --rm --name lw-ddb -p 38000:8000 amazon/dynamodb-local:latest

TEST_DB_DRIVER=mysql TEST_DB_PORT=33306 composer test:integration -- --filter DatabaseStoreTest
TEST_DB_DRIVER=pgsql TEST_DB_PORT=35432 composer test:integration -- --filter DatabaseStoreTest
TEST_DYNAMODB_ENDPOINT=http://127.0.0.1:38000 composer test:integration -- --filter DynamoDbStoreTest
```
