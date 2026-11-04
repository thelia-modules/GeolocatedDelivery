# 2.0.1

- An IPv4 visitor seen in IPv6 form no longer shares the rate limit of every other IPv4
  visitor: an IPv4-mapped address (`::ffff:1.2.3.4`, what a dual-stack server gives) and an
  address under the NAT64 well-known prefix (`64:ff9b::/96`) count as their IPv4. Version
  2.0.0 cut them to `::/64` and `64:ff9b::/64`.
- The other IPv6 forms that carry the IPv4 of the visitor count as that IPv4 too:
  IPv4-compatible (`::1.2.3.4`), IPv4-translated (`::ffff:0:1.2.3.4`), 6to4 (`2002::/16`)
  and Teredo (`2001::/32`, the client IPv4 decoded from the last 32 bits; its /64 is the
  Teredo server, shared by all its clients). `::` and `::1` keep sharing `::/64`.
- The NAT64 local-use prefix `64:ff9b:1::/48` (RFC 8215) is read as a /48 of RFC 6052:
  the IPv4 in bytes 6, 7, 9 and 10, with byte 8 zero. An address whose byte 8 is not zero,
  and the NAT64 prefixes of a network, keep their /64. Addresses that only look like a
  known form (`2001:db8::/32`, `2001:1::/32`, `::ffff:0:0:0:1`) keep their /64 too.
- The local-use prefix cut to a /96, `64:ff9b:1::a.b.c.d` (bytes 6 to 11 zero), is read as
  that IPv4, as under the well-known prefix; read as a /48, every client behind it got
  `0.0.0.0` and shared one limit.
- A visitor over his limit is turned away before the shop counter is looked at, so he no
  longer takes the lock every other visitor waits for.
- The locks of the rate limiter expire after 5 seconds instead of 300: with a store shared
  between servers, a process that died while holding one held the checkout for five
  minutes.
- A shop counter written when the limit was higher (more hits than the limit allows now)
  counts as exhausted, instead of letting the visitor's token be spent on a refused call.
- The price of a slice is written to the administration log with a point whatever the
  locale of the server (`4.50`, not `4,50`).
- Tests: both ways of creating a rate limiter lock are checked for the 5 second lifetime,
  and a guard fails when `LockFactory` gains a public method `RateLimiterLockFactory` does
  not override (it never builds the store of its parent).
- README: the lock store and the cache must be shared between servers, `framework.lock`
  is required, and `flock` leaves one file per counter in the temporary directory. On a
  store that expires locks, a counter update longer than 5 seconds lets a second request
  in, at a cost of at most one token per overlap. The visitor key follows
  `Request::getClientIp()`: with a `trusted_proxies` too wide, a forged `X-Forwarded-For`
  uses up the limit of any IP.

# 2.0.0

Port to Thelia 3.

- The module is now a carrier of its own, "Local delivery". Version 1.0.1 changed the
  option of CustomDelivery instead.
- The price is the slice of the distance to the nearest store. Version 1.0.1 took the
  first store in the database that had a matching slice.
- Only the postal code and the city of the customer are sent to the address service, and
  a located town is cached for thirty days. Stores are still located from their full
  address, once, when they are saved.
- A failure of the address service no longer breaks the checkout: the local delivery is
  not offered, the other carriers are, and the failure is logged. Requests are limited to
  3 seconds, and a failure is cached too: a minute after a technical failure, an hour for
  a town the service does not know, so an outage costs one wait per town and per minute
  instead of one per page. The quote and the option of the checkout never throw either: a
  database or cache failure turns the local delivery down and logs the class of the error.
- Calls to the address service are rate limited: 10 new towns per minute and per visitor
  (hashed IP, an IPv6 counted by its /64), 40 calls per second for the shop. The counters
  are taken under the shop lock, a refusal costs no token of the other limit, and is not
  cached; a refusal of the shop limit is logged as a warning.
- The address service is called with a client of its own, which logs no URL (the town and
  the postal code of the customer) and follows no redirection. A postal code longer than
  10 characters or a town longer than 80 is not sent.
- The price goes through the core postage tax rule (the module's own, otherwise the
  shop-wide delivery rule). Without one, it is charged as entered, as before.
- Back-office page rebuilt for the Twig back office: stores and slices are added and
  deleted with the session token and the update right on the module, and refusals are
  explained on the page. A slice price is between 0 and 100000. Every change is written
  to the administration log. A warning shows while the module serves no shipping zone.
- The install and update scripts create the tables only when missing: activating the
  module again, or updating from 1.0.1, keeps the stores and slices.

Upgrading from 1.0.1:

- The prices of the slices now go through a tax rule: the one set for this module on the
  tax rules page, otherwise the shop-wide delivery tax rule. Version 1.0.1 charged the
  price as entered and ignored every tax rule. A shop that entered its prices tax included
  and has a 20 % delivery tax rule charges 6.00 instead of 5.00 after the update. Enter the
  slice prices without tax when a rule applies (5.00 tax included at 20 % is 4.17 before
  tax). Without any rule, nothing changes.

Removed:

- the shipping e-mail `mail_geolocated_delivery` and its templates (the core sends
  `order_shipped`). The message row created by 1.0.1 is left in the database; delete it
  from the back office if you want;
- the settings of 1.0.1: the tracking URL, used only by that e-mail, and the method and
  tax rule, which the price never read;
- the Smarty back-office templates, the loops, the forms and the OpenApi listener;
- `Config/routing.xml`, `Config/sqldb.map` and `Config/config.xml`, which Thelia 3 no
  longer needs.

Breaking changes, for code that extends, calls or templates against the module:

- Controllers `Controller\ModuleController`, `Controller\RadiusController` and
  `Controller\StoreController` are removed. `Controller\ConfigurationController` replaces
  them, with the routes `geolocateddelivery.store.create`, `geolocateddelivery.store.delete`,
  `geolocateddelivery.radius.create` and `geolocateddelivery.radius.delete` under
  `/admin/module/GeolocatedDelivery`.
- Forms `Form\ConfigurationForm`, `Form\RadiusForm` and `Form\StoreForm` are removed. The
  back-office page posts plain fields checked by the controller; there is no form to extend.
- Loops `geolocated.delivery.store` (`Loop\GeolocatedDeliveryStoreLoop`) and
  `geolocated.delivery.radius` (`Loop\GeolocatedDeliveryRadiusLoop`) are removed, with no
  replacement. A template that used them must read `Model\GeolocatedDeliveryStoreQuery` and
  `Model\GeolocatedDeliveryRadiusQuery` instead.
- Listeners `EventListeners\GeolocatedDeliveryListener` (shipping e-mail, delivery options)
  and `EventListeners\ApiListener` (OpenApi delivery options) are removed.
  `EventListeners\DeliveryOptionListener` and `EventListeners\PostageListener` replace the
  options part, on the core events `MODULE_DELIVERY_GET_OPTIONS` and
  `MODULE_DELIVERY_GET_POSTAGE`. The e-mail has no replacement.
- `Utils\GeolocManager` is removed (`getGeolocFromAddress()`, `getRadius()`, `getDistance()`,
  `check()`). `Service\Geocoder`, `Service\DistanceCalculator` and `Service\RadiusQuote`
  take over the location, the distance and the price.
- Hooks: `Hook\HookManager::onAccountOrderAfterProducts()` (tracking link on the order page)
  and `onModuleConfigJs()` (hook `module.config-js`) are removed, with no replacement.
  `onModuleConfiguration()` now returns `void`.
- Constants of `GeolocatedDelivery` removed: `CONFIG_PICKING_METHOD`, `CONFIG_TAX_RULE_ID`,
  `CONFIG_TRACKING_URL`, `DEFAULT_PICKING_METHOD`, `DEFAULT_TRACKING_URL`, `METHOD_MAX_RADIUS`,
  `METHOD_MIN_RADIUS`, `METHOD_PRICE`, `METHOD_WEIGHT` (the settings they named are gone, see
  above) and `MESSAGE_DOMAIN`, replaced by `DOMAIN_NAME` (`geolocateddelivery`, not
  `geolocated_delivery`) and `BACK_OFFICE_DOMAIN`.
- Methods of `GeolocatedDelivery` removed: `getConfig()`, `trans()` and the `$translator`
  property. They have no replacement. `getDeliveryMode()` is now inherited from
  `AbstractDeliveryModuleWithState` and still returns `delivery`.
- Types made explicit: `getPostage()` and `isValidDelivery()` take `?State $state = null`,
  `postActivation()` and `update()` take `?ConnectionInterface $con = null`. These were
  implicitly nullable before, so callers are not affected. A class that overrides them must
  declare the nullable type.
- Return types added: `getPostage(): OrderPostage|float`, `isValidDelivery(): bool`,
  `handleVirtualProductDelivery(): bool`. A class that extends the module and overrides
  them must declare a compatible return type.

# 1.0.1

- Fix radius calculation.
