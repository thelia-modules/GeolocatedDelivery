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
  (hashed IP), 40 calls per second for the shop. A refused call is not cached.
- The price goes through the core postage tax rule (the module's own, otherwise the
  shop-wide delivery rule). Without one, it is charged as entered, as before.
- Back-office page rebuilt for the Twig back office: stores and slices are added and
  deleted with the session token and the update right on the module, and refusals are
  explained on the page. A warning shows while the module serves no shipping zone.
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

# 1.0.1

- Fix radius calculation.
