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
