# Geolocated Delivery

A local delivery carrier for Thelia 3. The price depends on the distance between the
delivery address and the nearest of your stores, measured as the crow flies. Addresses are
located with the Base Adresse Nationale, so the module works for France only.

Version 2 targets Thelia 3. Version 1.0.1 is the Thelia 2 module.

## Installation

```
composer require thelia/geolocated-delivery-module:^2.0
```

Then activate the module in the back office (Modules).

Updating from version 1.0.1 keeps your stores and radius slices: the tables are created
only when they do not exist.

## Configuration

1. Attach the module to the shipping zones it serves (Configuration, Shipping zones). The
   checkout never asks a carrier outside its zones. Postal code rules on a zone narrow it
   further. The configuration page of the module warns you while no zone is attached.
2. On the configuration page of the module (Modules, Local delivery, Configure), add
   your stores. A store is located from its full address when you save it. If the address
   cannot be located, the store is not saved and the page tells you so.
3. Add the radius slices: a distance from, a distance to (whole kilometres) and a price.
   The first slice containing the distance gives the price, bounds included. A slice whose
   upper distance is not above its lower distance, or priced below 0 or above 100000, is
   refused.

Stores and slices are added and deleted one by one. Changing them requires the update
right on the module, and each change is written to the administration log (resources
`geolocateddelivery.store` and `geolocateddelivery.radius`).

## Price and tax

The price of a slice goes through the postage tax rule of the core: the rule set for this
module on the tax rules page, otherwise the shop-wide delivery tax rule. With no rule, the
price is charged as entered. With a rule, the price is before tax: enter the slice prices
without tax.

Updating from 1.0.1 changes what the shop charges when such a rule exists. Version 1.0.1
charged the price as entered and ignored every tax rule; version 2 adds the tax. A slice
entered at 5.00 tax included, with a 20 % delivery tax rule, is charged 6.00 after the
update. Before updating, check the tax rules page: if a rule applies to this module or to
deliveries of the whole shop, enter the slice prices again without tax (5.00 tax included
at 20 % is 4.17 before tax).

## How a quote is made

For each quote, the module reads the delivery address the cart holds (a guest's address
included), provided it is in the country being quoted. It then locates the town, measures
the distance to every located store, keeps the shortest, and finds its slice.

The local delivery is not offered when:

- the cart has no delivery address in that country;
- there is no located store, or no slice contains the distance;
- the town cannot be located, or the address service is down or too slow (2 seconds to
  answer, 3 seconds in all);
- the rate limit refuses the call (see below).

In those cases the other carriers are offered as usual, and a line is written to the log.
The answer for a town is cached, failures included, so that an outage does not make every
checkout page wait on the service:

- a located town is kept for thirty days;
- a town the service does not know is asked for again after an hour;
- after a technical failure (no answer, an HTTP error, an answer without usable
  coordinates), the service is asked again after a minute.

Within one request, the service is asked only once.

The postage estimate of the cart page uses the same rule. Before the customer gives an
address, it cannot price the local delivery and leaves it out.

The module listens to `TheliaEvents::MODULE_DELIVERY_GET_POSTAGE` after the core (priority
64, the core answers at 128): it never offers a carrier the core turned down. It adds its
option to `TheliaEvents::MODULE_DELIVERY_GET_OPTIONS`, which the Flexy checkout needs to list
the carrier.

## Security and load: rate limits

Any visitor can make the shop call the address service, one new town after another. Two
limits apply, counted only when the service is really about to be called: a town already
in the cache costs nothing, and locating a store from the back office is not limited.

- Per visitor: `GeocodingRateLimiter::VISITOR_LIMIT` new towns (10) per
  `GeocodingRateLimiter::VISITOR_INTERVAL` (1 minute). The visitor is the client IP of the
  request, hashed with the kernel secret: no IP is stored in the cache in clear. An IPv6
  visitor is counted by the /64 prefix of the address, since one connection usually gets a
  whole /64; an IPv4 address counts whole. An IPv6 address that carries the IPv4 of the
  visitor counts as that IPv4: the IPv4-mapped form (`::ffff:1.2.3.4`) a dual-stack server
  gives its IPv4 clients, the IPv4-translated (`::ffff:0:1.2.3.4`) and IPv4-compatible
  (`::1.2.3.4`) forms, the NAT64 well-known prefix (`64:ff9b::/96`) a translator in front
  of an IPv6-only server uses, 6to4 (`2002::/16`, the IPv4 of the site after the prefix)
  and Teredo (`2001::/32`, whose /64 is the Teredo server: the IPv4 of the client is decoded
  from the last 32 bits instead). The unspecified address `::` and the loopback `::1` are
  not IPv4 addresses: they keep their /64, `::/64`, and share it. The NAT64 local-use prefix
  `64:ff9b:1::/48` is read as a /48 of RFC 6052 (the IPv4 in bytes 6, 7, 9 and 10, byte 8
  zero); an address whose byte 8 is not zero keeps its /64. A NAT64 prefix of the network
  itself cannot be recognised: it is counted by its /64, which holds part of the IPv4 or
  none of it, and many IPv4 clients behind it may share one limit.
- For the whole shop: `GeocodingRateLimiter::SHOP_LIMIT` calls (40) per
  `GeocodingRateLimiter::SHOP_INTERVAL` (1 second). The address service allows 50 calls
  per second and per IP, and its documentation advises a cap at 40 or 45
  (https://adresse.data.gouv.fr/outils/api-doc/adresse). Without a request (a command or a
  scheduled task), only this limit applies.

The client IP is only as reliable as the proxy configuration of the shop:

- behind a reverse proxy or a CDN that is not declared in `framework.trusted_proxies` (the
  `TRUSTED_PROXIES` variable of a Symfony project), every visitor has the IP of the proxy
  and they all share the per-visitor limit;
- the other way round, a `trusted_proxies` wider than the real proxies (`REMOTE_ADDR`,
  `0.0.0.0/0`, a whole private range a visitor can reach) makes Symfony believe the
  `X-Forwarded-For` header a visitor writes himself: he can then claim a new IP with each
  request and get around the per-visitor limit. The shop limit still holds. Declare only
  the addresses of your own proxies.

Both limits are sliding windows, kept in the application cache. A fixed window would let
twice the limit through around the change of window. Each counter is read and written
under a lock of the shop's lock factory (`framework.lock`, the store `LOCK_DSN` names),
so that requests in parallel cannot all take the last token. Those locks expire after
`RateLimiterLockFactory::LOCK_LIFETIME_SECONDS` (5 seconds) instead of the 300 seconds of
Symfony's default: with a shared store, a lock left by a process that died would otherwise
hold the checkout of every visitor for five minutes. The other side of it: on a store that
expires locks, a read and write of a counter that takes longer than 5 seconds lets a second
request in before it ends, which costs at most one token per overlap; with `flock`, whose
locks do not expire, this cannot happen.

Both limits are looked at before any token is taken, the visitor's first: a visitor over his
limit is turned away without touching the shop counter, whose lock all visitors share. A
call the shop refuses costs the visitor nothing, and a call the visitor's limit refuses
costs the shop nothing.

Configuring the lock:

- `framework.lock` must be configured (`LOCK_DSN`); the core already needs it for the
  checkout, and without it the container does not compile.
- With several web servers, `LOCK_DSN` and the application cache must point to a store all
  of them share (Redis, a database). With a store local to each server (`flock`, `semaphore`,
  a filesystem cache), each server counts on its own: the shop limit becomes 40 calls per
  second per server, and two servers already go past the 50 per second the address service
  allows.
- With `flock`, the store writes one file per counter in the temporary directory, one per
  visitor key included, and never removes them; clean the `sf.*.lock` files of the temporary
  directory from time to time on a busy shop.

A refused call sends nothing to the service: the local delivery is not offered for that
address. The refusal is not cached, so a visitor over the limit does not deprive the other
customers of the same town. It is logged with neither the IP nor the address: at the info
level when a visitor reaches his limit, at the warning level when the whole shop reaches
its own, which is worth a look. The limits are constants of the module, not settings.

The geocoder has its own HTTP client, built without a logger: the framework client logs
the URL of every request on the `http_client` channel, and the URL carries the town and the
postal code of the customer. It follows no redirection. A postal code longer than 10
characters or a town longer than 80 is not sent at all.

Known limits, not handled by the module:

- there is no cap per cart: a visitor who changes IP (a mobile network, a pool of proxies)
  gets a new per-visitor allowance each time, up to the shop limit;
- the visitor is whatever `Request::getClientIp()` returns: with a `trusted_proxies` wider
  than the real proxies, a forged `X-Forwarded-For` header picks that IP, so a visitor can
  use up the limit of any other IP, whatever the form of the address (IPv4, IPv6, or an
  IPv6 form carrying an IPv4);
- there is no circuit breaker: during an outage, each new town still waits for the timeout
  once a minute (the failure is cached for a minute), within the rate limits.

## Personal data

To locate a delivery address, the module sends the postal code and the city of the
customer to the Base Adresse Nationale (`https://api-adresse.data.gouv.fr/search/`), a
public service run by the French State. The street, the name and the other details of the
customer are never sent. The answer is kept in the shop cache under a hash of the postal
code and the city: thirty days for a located town, less for a failure (see above).

Stores are sent with their full address, which is not personal data.

Mention this transfer in the privacy policy of your shop.

## Tests

Copy the module into `local/modules/GeolocatedDelivery` of a Thelia 3 checkout, run
`composer test:prepare`, then from the project root:

```
APP_ENV=test php vendor/bin/phpunit -c local/modules/GeolocatedDelivery/phpunit.xml.dist
```

The tests never call the address service: they replace it with a mock HTTP client.
