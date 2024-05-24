# Terminus Domain Challenge

[![CircleCI](https://circleci.com/gh/terminus-plugin-project/terminus-domain-challenge.svg?style=shield)](https://circleci.com/gh/terminus-plugin-project/terminus-domain-challenge)
[![Actively Maintained](https://img.shields.io/badge/Pantheon-Actively_Maintained-yellow?logo=pantheon&color=FFDC28)](https://pantheon.io/docs/oss-support-levels#actively-maintained-support)

[![Terminus v3.x Compatible](https://img.shields.io/badge/terminus-3.x-green.svg)](https://github.com/terminus-plugin-project/tree/1.x)

A simple plugin for Terminus-CLI to get the Domain DNS challenge based on a site.

Adds command 'domain:dns:challenge' to Terminus. Learn more about Terminus Plugins in the
[Terminus Plugins documentation](https://pantheon.io/docs/terminus/plugins)

## Configuration

These commands require no configuration

## Usage

* `terminus domain:dns:challenge <site>.<env>`
* `terminus domain:dns:challenge <site>.<env> --filter="domain=www.example.com"`

## Installation

To install this plugin using Terminus 3:

```sh
terminus self:plugin:install terminus-domain-challenge
```

## Testing

This project includes four testing targets:

* `composer lint`: Syntax-check all php source files.
* `composer cs`: Code-style check.
* `composer unit`: Run unit tests with phpunit
* `composer functional`: Run functional test with bats

To run all tests together, use `composer test`.

Note that prior to running the tests, you should first run:

* `composer install`
* `composer install-tools`

## Help

Run `terminus help domain:dns:challenge` for help.
