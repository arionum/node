# node

The Arionum (ARO) cryptocurrency node.




**NOTIFICATION AroDev 30.07.2026**

The repository is no longer maintained by its original developers.

A full backup of the blockchain at the height 3456135 is available at https://github.com/arionum/node/releases/tag/bootstrap-3456135

This can be used to restart the blockchain if all nodes are shutdown.

The code is released under the MIT license, anyone is free to fork it and use it as he sees fit.

I would like to thank everyone who supported this project for the past 8 years.

I wish you all the best!

/AroDev



## Install

**Hardware Requirements:**
```
2GB RAM
1 CPU Core
50GB DISK
```
**Requirements:**

- PHP 7.2
  - PDO extension
  - GMP extension
  - BCMath extension
- MySQL/MariaDB

1. Install MySQL or MariaDB and create a database and a user.
2. Rename `include/config-sample.inc.php` to  `include/config.inc.php` and set the DB login data
3. Change permissions to tmp and `tmp/db-update` to 777 (`chmod 777 tmp -R`)
4. Access the http://ip-or-domain and refresh once

## Usage

This app should only be run in the main directory of the domain/subdomain, ex: http://111.111.111.111

The node should have a public IP and be accessible over internet.

## Links

- Official website: https://www.arionum.com
- Block explorer: https://arionum.info
- Forums: https://forum.arionum.com

## Development Fund

Coin | Address
---- | --------
[ARO]: | 5WuRMXGM7Pf8NqEArVz1NxgSBptkimSpvuSaYC79g1yo3RDQc8TjVtGH5chQWQV7CHbJEuq9DmW5fbmCEW4AghQr
[LTC]: | LWgqzbXGeucKaMmJEvwaAWPFrAgKiJ4Y4m
[BTC]: | 1LdoMmYitb4C3pXoGNLL1VRj7xk3smGXoU
[ETH]: | 0x4B904bDf071E9b98441d25316c824D7b7E447527
[BCH]: | qrtkqrl3mxzdzl66nchkgdv73uu3rf7jdy7el2vduw

If you'd like to support the Arionum development, you can donate to the addresses listed above.

[aro]: https://arionum.com
[ltc]: https://litecoin.org
[btc]: https://bitcoin.org
[eth]: https://ethereum.org
[bch]: https://www.bitcoincash.org
