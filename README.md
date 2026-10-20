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
   upper distance is not above its lower distance, or with a negative price, is refused.

Stores and slices are added and deleted one by one. Changing them requires the update
right on the module.

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
  answer, 3 seconds in all).

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
