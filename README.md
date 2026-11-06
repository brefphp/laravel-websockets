# Bref Laravel WebSockets

WebSockets for Laravel on AWS Lambda, with [Bref](https://bref.sh) and API Gateway.

This package adds an `api-gateway` broadcasting driver to Laravel, and speaks the protocol of Laravel Echo on API Gateway WebSockets.

## Installation

```bash
composer require bref/laravel-websockets
```

## Documentation

Read the documentation on [bref.sh](https://bref.sh/docs/laravel/websockets).

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
