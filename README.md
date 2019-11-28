# S3 Communicator

This is a PHP class that we wrote, to simplify the CRUD operations for us to communicate with Amazon S3 buckets.

## Usage

#### Composer
This file has a composer.json file with some requirements in it.  Don't forget to run a composer install when you use this in your project.

#### _settings.php
This file has been added to the gitingore on purpose.  It contains the region, key, and secret, associated with the bucket you want to use.
It's contents should look like this:
```
<?php 
define("S3_REGION", "...");
define("S3_KEY", "...");
define("S3_SECRET", "...");
?>
```
