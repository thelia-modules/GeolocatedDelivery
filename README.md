# GeolocatedDelivery

This module allow you to make geolocated delivery from the store

It's using the GEO-API-GOUV to located the address you enter into the field.

## Installation

### Manually

* Copy the module into ```<thelia_root>/local/modules/``` directory and be sure that the name of the module is GeolocatedDelivery.
* Activate it in your thelia administration panel

### Composer

Add it in your main thelia composer.json file

```
composer require thelia/geolocated-delivery-module:~1.0
```

## Usage

Just enter the price for a radius for each area in the configuration page of the module.  
You can create as many radius you want.
You can associate an optional taxe rule to the module to include taxes for the shipment.

# customization

You can customize the mails sent by the module in the **Mailing templates** configuration page in the back-office. The
template used is called `mail_geolocated_delivery`.
