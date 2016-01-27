# PHP Manta

[php-manta](http://joyent.github.com/php-manta) is a community-maintained PHP SDK for interacting with Joyent's 
Manta system.

[![MPL licensed](https://img.shields.io/badge/license-MPL_2.0-blue.svg)](https://github.com/joyent/php-manta/blob/master/LICENSE)

## Required PHP Framework Features
 * [OpenSSL](http://php.net/manual/en/openssl.installation.php)
 * [JSON](http://php.net/manual/en/json.installation.php) - Installed by default in newest versions
 * PHP 5.6+ or HHVM

## Configuration

Php-manta can be configured using its constructor or by passing nulls and letting 
environment variables and defaults configure the client. Here's a list of available
environment variables and their defaults.

| Default                              | Environment Variable      |
|--------------------------------------|---------------------------|
| https://us-east.manta.joyent.com:443 | MANTA_URL                 |
|                                      | MANTA_USER                |
|                                      | MANTA_KEY_ID              |
| $home/.ssh/id_rsa                    | MANTA_KEY_PATH            |
| 20                                   | MANTA_TIMEOUT             |
| 3                                    | MANTA_HTTP_RETRIES        |
| GuzzleHttp\Handler\StreamHandler     | MANTA_HTTP_HANDLER        |
| false                                | MANTA_NO_AUTH             |

## Usage

For usage examples, see the directory `examples` for some sample scripts that
use the API.

## Contributing
We are seeking active contributors right now. Pull requests are welcome.

## Credits
Kudos to the original author of php-manta - [Robert Bates](https://twitter.com/arpieb). He developed the library 
to be used with the Drupal [Backup & Migrate Manta plugin](https://www.drupal.org/project/backup_migrate_manta) 
and did all of the initial heavy lifting. On January 4th 2016, Robert transferred ownership of the repository to
Joyent and changed the license from the GPLv3 to the MPLv2.

## License
PHP Manta is licensed under the MPLv2. Please see the `LICENSE` file for more details.
