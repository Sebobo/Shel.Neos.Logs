# Log file and exception viewer for Neos CMS   

[![Latest Stable Version](https://poser.pugx.org/shel/neos-logs/v/stable)](https://packagist.org/packages/shel/neos-logs)
[![Total Downloads](https://poser.pugx.org/shel/neos-logs/downloads)](https://packagist.org/packages/shel/neos-logs)
[![License](https://poser.pugx.org/shel/neos-logs/license)](https://packagist.org/packages/shel/neos-logs)

This package provides a new module in the [NeosCMS](https://www.neos.io) 
backend to view logs and exceptions of a Neos instance.

Usually it's recommended to use other, more powerful tools for this use case 
like [Sentry](http://sentry.io) or [Kibana](https://www.elastic.co/de/products/kibana), 
but there are cases in which those tools cannot be used.

This package was built to help in situations where one has to quickly investigate something, 
e.g. to understand an error the client had with their site.

## Installation

Run this in your site package:

    composer require --no-update shel/neos-logs
    
Then run `composer update` in your project root.

## Usage

After the installation, you will have a new backend module in Neos - available only for administrators - 
that will allow you to view all local log and exception files.

Lines in the logfiles can be filtered by their level. You can also change the number of lines that should be visible.

## Examples

Overview screen:

![Overview](Documentation/overview.jpg)

Viewing a logfile

![Logfile](Documentation/logfile.jpg)

## Contributions

Contributions are very welcome! 

Please create detailed issues and PRs.  

**If you use this package and want to support or speed up its development, [get in touch with me](mailto:hyphens@helzle.it).**

Or you can also support me directly via [patreon](https://www.patreon.com/shelzle).

## License

See [License](./LICENSE.txt)
